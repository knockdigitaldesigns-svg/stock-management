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
$customerId = (int) ($data['customer_id'] ?? 0);
$vehicleId = (int) ($data['vehicle_id'] ?? 0);
$paymentId = (int) ($data['payment_id'] ?? 0);
if ($submissionId <= 0 || $customerId <= 0) {
    sendResponse(false, 'Submission and customer IDs are required to complete approval.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT status, reviewer_user_id, existing_customer_id, linked_customer_id, submission_data
         FROM customer_payment_verification_submissions WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Verification submission not found.');
    }
    $submissionData = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    $isCourierSubmission = ($submissionData['source'] ?? '') === 'courier';
    if ($paymentId <= 0 && !$isCourierSubmission) {
        throw new RuntimeException('Payment ID is required to complete this customer payment approval.');
    }
    if ($isCourierSubmission) {
        $requestIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($submissionData['courier_request_ids'] ?? null)
                ? $submissionData['courier_request_ids']
                : []),
            static function (int $requestId): bool {
                return $requestId > 0;
            }
        )));
        $finalizedIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($submissionData['courier_finalized_request_ids'] ?? null)
                ? $submissionData['courier_finalized_request_ids']
                : []),
            static function (int $requestId): bool {
                return $requestId > 0;
            }
        )));
        if (!$requestIds || count(array_diff($requestIds, $finalizedIds)) > 0) {
            throw new RuntimeException('Courier assets must be allocated through the linked verification approval before completion.');
        }
    }
    if ($row['status'] === 'Approved') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Submission is already approved.', ['status' => 'Approved']);
    }
    if ($row['status'] !== 'Applying' || (int) $row['reviewer_user_id'] !== $reviewerId) {
        throw new RuntimeException('This submission is not being applied by the current reviewer.');
    }
    $existingCustomerId = (int) ($row['existing_customer_id'] ?? 0);
    $linkedCustomerId = (int) ($row['linked_customer_id'] ?? 0);
    if (($existingCustomerId > 0 && $existingCustomerId !== $customerId)
        || ($linkedCustomerId > 0 && $linkedCustomerId !== $customerId)) {
        throw new RuntimeException('The customer does not match this verification submission.');
    }
    $customerStatusStmt = $conn->prepare(
        "UPDATE customers SET verification_status = 'Approved' WHERE id = ?"
    );
    if (!$customerStatusStmt) {
        throw new RuntimeException('Unable to prepare customer approval status update.');
    }
    $customerStatusStmt->bind_param('i', $customerId);
    if (!$customerStatusStmt->execute() || $customerStatusStmt->affected_rows > 1) {
        $customerStatusStmt->close();
        throw new RuntimeException('Unable to update the customer approval status.');
    }
    $customerStatusStmt->close();
    $stmt = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET status = 'Approved', linked_customer_id = ?, linked_vehicle_id = NULLIF(?, 0),
             linked_payment_id = NULLIF(?, 0), decided_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->bind_param('iiii', $customerId, $vehicleId, $paymentId, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to complete customer payment approval.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, 'Applying', 'Approved', null, $reviewerId);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Customer payment submission approved.', ['status' => 'Approved', 'customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'payment_id' => $paymentId]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
