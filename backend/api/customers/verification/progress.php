<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireSuperAdminCustomerPaymentVerificationReviewer();
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
$customerId = max(0, (int) ($data['customer_id'] ?? 0));
$vehicleId = max(0, (int) ($data['vehicle_id'] ?? 0));
$paymentId = max(0, (int) ($data['payment_id'] ?? 0));
if ($submissionId <= 0) {
    sendResponse(false, 'A valid submission ID is required.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    "UPDATE customer_payment_verification_submissions
     SET linked_customer_id = IF(? > 0, ?, linked_customer_id),
         linked_vehicle_id = IF(? > 0, ?, linked_vehicle_id),
         linked_payment_id = IF(? > 0, ?, linked_payment_id),
         updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND status = 'Applying' AND reviewer_user_id = ?"
);
$reviewerId = (int) ($reviewer['user_id'] ?? 0);
$stmt->bind_param(
    'iiiiiiii',
    $customerId,
    $customerId,
    $vehicleId,
    $vehicleId,
    $paymentId,
    $paymentId,
    $submissionId,
    $reviewerId
);
if (!$stmt->execute() || $stmt->affected_rows < 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to save approval progress.', [], [], 500);
}
$stmt->close();
$conn->close();
sendResponse(true, 'Approval progress saved.');
