<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireSuperAdminCustomerPaymentVerificationReviewer();
$reviewerId = (int) ($reviewer['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
$action = strtolower(trim((string) ($data['action'] ?? '')));
$reason = trim((string) ($data['reason'] ?? ''));
if ($submissionId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    sendResponse(false, 'A valid submission and decision are required.', [], [], 400);
}
if ($action === 'reject' && $reason === '') {
    sendResponse(false, 'A rejection reason is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT submitter_user_id, existing_customer_id, linked_customer_id, linked_vehicle_id,
                linked_payment_id, status, reviewer_user_id, submission_data
         FROM customer_payment_verification_submissions
         WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Verification submission not found.');
    }
    $oldStatus = (string) $row['status'];
    $submissionJson = null;
    if ($action === 'approve') {
        if ($oldStatus === 'Approved') {
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Submission is already approved.', ['status' => 'Approved']);
        }
        if ($oldStatus === 'Applying') {
            if ((int) ($row['reviewer_user_id'] ?? 0) !== $reviewerId) {
                $transfer = $conn->prepare(
                    "UPDATE customer_payment_verification_submissions
                     SET reviewer_user_id = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND status = 'Applying'"
                );
                if (!$transfer) {
                    throw new RuntimeException('Unable to assign approval to the Super Admin.');
                }
                $transfer->bind_param('ii', $reviewerId, $submissionId);
                if (!$transfer->execute()) {
                    $transfer->close();
                    throw new RuntimeException('Unable to assign approval to the Super Admin.');
                }
                $transfer->close();
                insertCustomerPaymentVerificationHistory(
                    $conn,
                    $submissionId,
                    'Applying',
                    'Applying',
                    'Approval resumed by Super Admin.',
                    $reviewerId
                );
            }
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Approval can be resumed.', ['status' => 'Applying']);
        }
        if ($oldStatus !== 'Pending Verification' && $oldStatus !== 'Applying') {
            throw new RuntimeException('Only a pending submission can be approved.');
        }
        if ($oldStatus === 'Pending Verification' && (int) ($row['existing_customer_id'] ?? 0) > 0) {
            $submissionData = decodeCustomerPaymentSubmission((string) $row['submission_data']);
            $customerId = (int) $row['existing_customer_id'];
            $customerStmt = $conn->prepare(
                'SELECT id, platform_id, username, primary_mobile_no, secondary_mobile_no,
                        email, location, pincode, status, created_at, updated_at
                 FROM customers WHERE id = ? FOR UPDATE'
            );
            if (!$customerStmt) {
                throw new RuntimeException('Unable to capture existing customer details before approval.');
            }
            $customerStmt->bind_param('i', $customerId);
            if (!$customerStmt->execute()) {
                $error = $customerStmt->error;
                $customerStmt->close();
                throw new RuntimeException('Unable to capture existing customer details before approval: ' . $error);
            }
            $originalCustomer = $customerStmt->get_result()->fetch_assoc();
            $customerStmt->close();
            if (!$originalCustomer) {
                throw new RuntimeException('The existing customer record was not found before approval.');
            }
            $submissionData['_rejection_snapshot']['customer'] = $originalCustomer;
            $submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($submissionJson === false) {
                throw new RuntimeException('Unable to save the existing customer rollback snapshot.');
            }
        }
        $newStatus = 'Applying';
        $storedReason = null;
        $message = 'Approval started. Complete the commit steps to finish approval.';
    } else {
        if ($oldStatus === 'Applying') {
            $submissionData = decodeCustomerPaymentSubmission((string) $row['submission_data']);
            rejectCustomerPaymentVerificationApplication(
                $conn,
                $submissionId,
                (int) ($row['existing_customer_id'] ?? 0),
                (int) ($row['linked_customer_id'] ?? 0),
                (int) ($row['linked_vehicle_id'] ?? 0),
                $submissionData
            );
        } elseif ($oldStatus !== 'Pending Verification') {
            throw new RuntimeException('Only a pending or safely reversible approval can be rejected.');
        }
        $newStatus = 'Rejected';
        $storedReason = $reason;
        $message = 'Submission rejected.';
    }
    $stmt = $conn->prepare(
        'UPDATE customer_payment_verification_submissions
         SET status = ?, rejection_reason = ?, reviewer_user_id = ?,
             decided_at = IF(? = \'Rejected\', CURRENT_TIMESTAMP, decided_at),
             submission_data = COALESCE(?, submission_data),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('ssissi', $newStatus, $storedReason, $reviewerId, $newStatus, $submissionJson, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save verification decision.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, $oldStatus, $newStatus, $storedReason, $reviewerId);
    $conn->commit();
    $conn->close();
    sendResponse(true, $message, ['status' => $newStatus]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
