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
if ($submissionId <= 0 || $customerId <= 0 || $paymentId <= 0) {
    sendResponse(false, 'Submission, customer, and payment IDs are required to complete approval.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT status, reviewer_user_id FROM customer_payment_verification_submissions WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Verification submission not found.');
    }
    if ($row['status'] === 'Approved') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Submission is already approved.', ['status' => 'Approved']);
    }
    if ($row['status'] !== 'Applying' || (int) $row['reviewer_user_id'] !== $reviewerId) {
        throw new RuntimeException('This submission is not being applied by the current reviewer.');
    }
    $stmt = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET status = 'Approved', linked_customer_id = ?, linked_vehicle_id = NULLIF(?, 0),
             linked_payment_id = ?, decided_at = CURRENT_TIMESTAMP,
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
