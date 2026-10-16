<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['customers.add', 'customers.edit']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['submission_data']) || !is_array($data['submission_data'])) {
    sendResponse(false, 'Invalid customer draft data.', [], [], 400);
}

$submissionId = max(0, (int) ($data['submission_id'] ?? 0));
$existingCustomerId = max(0, (int) ($data['existing_customer_id'] ?? 0));
$step1 = $data['submission_data']['step1'] ?? $data['submission_data']['customer'] ?? [];
$submissionJson = json_encode($data['submission_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($submissionJson === false || strlen($submissionJson) > 2000000) {
    sendResponse(false, 'Customer draft is too large or could not be encoded.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    if ($existingCustomerId === 0 && is_array($step1)) {
        $username = trim((string) ($step1['username'] ?? ''));
        $primaryMobile = trim((string) ($step1['primary_mobile_no'] ?? ''));
        if ($username !== '' && $primaryMobile !== '') {
            $stmt = $conn->prepare(
                'SELECT id, platform_id, username, primary_mobile_no,
                        secondary_mobile_no, email, location, pincode, status
                 FROM customers
                 WHERE LOWER(TRIM(username)) = LOWER(?)
                   AND TRIM(primary_mobile_no) = ?
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->bind_param('ss', $username, $primaryMobile);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existing) {
                throw new RuntimeException(json_encode([
                    'code' => 'EXISTING_CUSTOMER_SAME_MOBILE',
                    'customer_id' => (int) $existing['id'],
                    'customer' => $existing
                ]));
            }
            $stmt = $conn->prepare(
                'SELECT id FROM customers
                 WHERE LOWER(TRIM(username)) = LOWER(?)
                   AND TRIM(primary_mobile_no) <> ?
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->bind_param('ss', $username, $primaryMobile);
            $stmt->execute();
            $differentMobile = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($differentMobile) {
                throw new RuntimeException('USERNAME_EXISTS_DIFFERENT_MOBILE');
            }
        }
    }
    $conn->begin_transaction();
    $wasRejectedSubmission = false;
    if ($submissionId > 0) {
        $stmt = $conn->prepare(
            "SELECT submitter_user_id, existing_customer_id, status, submission_data
             FROM customer_payment_verification_submissions
             WHERE id = ? FOR UPDATE"
        );
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $draft = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$draft || (int) $draft['submitter_user_id'] !== $userId) {
            throw new RuntimeException('Customer draft was not found for this user.');
        }
        if ($draft['status'] === 'Pending Verification') {
            if (!hash_equals((string) $draft['submission_data'], $submissionJson)) {
                throw new RuntimeException('A submitted verification request cannot be edited.');
            }
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Customer draft is already pending verification.', ['submission_id' => $submissionId]);
        }
        if (!in_array($draft['status'], ['Draft', 'Rejected'], true)) {
            throw new RuntimeException('A submitted verification request cannot be edited.');
        }
        $wasRejectedSubmission = $draft['status'] === 'Rejected';
        $existingCustomerId = (int) ($draft['existing_customer_id'] ?? $existingCustomerId);
        $stmt = $conn->prepare(
            "UPDATE customer_payment_verification_submissions
             SET submission_data = ?,
                 status = 'Draft',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND submitter_user_id = ?"
        );
        $stmt->bind_param('sii', $submissionJson, $submissionId, $userId);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO customer_payment_verification_submissions
                (submitter_user_id, existing_customer_id, status, submission_data)
             VALUES (?, NULLIF(?, 0), 'Draft', ?)"
        );
        $stmt->bind_param('iis', $userId, $existingCustomerId, $submissionJson);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save customer draft.');
    }
    if ($submissionId === 0) {
        $submissionId = (int) $stmt->insert_id;
    }
    $stmt->close();
    if ($wasRejectedSubmission) {
        insertCustomerPaymentVerificationHistory(
            $conn,
            $submissionId,
            'Rejected',
            'Draft',
            'Submitter started correcting the rejected submission.',
            $userId
        );
    }
    $conn->commit();
    $conn->close();

    sendResponse(true, 'Customer draft saved.', ['submission_id' => $submissionId]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $message = $error->getMessage();
    if ($message === 'USERNAME_EXISTS_DIFFERENT_MOBILE') {
        sendResponse(false, 'Username already exists with a different mobile number.', ['code' => $message], [], 409);
    }
    $duplicate = json_decode($message, true);
    if (is_array($duplicate) && ($duplicate['code'] ?? '') === 'EXISTING_CUSTOMER_SAME_MOBILE') {
        sendResponse(false, 'Customer already exists with the same username and mobile number.', $duplicate, [], 409);
    }
    sendResponse(false, $message, [], [], 400);
}
