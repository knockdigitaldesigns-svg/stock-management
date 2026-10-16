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
$submissionId = (int) ($data['submission_id'] ?? 0);
$customerId = (int) ($data['customer_id'] ?? 0);
$vehicleId = max(0, (int) ($data['vehicle_id'] ?? 0));
$paymentId = max(0, (int) ($data['payment_id'] ?? 0));
if ($submissionId <= 0 || $customerId <= 0) {
    sendResponse(false, 'Submission and customer IDs are required.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT submitter_user_id, status
         FROM customer_payment_verification_submissions
         WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int) $row['submitter_user_id'] !== $userId || $row['status'] !== 'Draft') {
        throw new RuntimeException('This customer draft cannot be finalized by the current user.');
    }
    $stmt = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET status = 'Committed', linked_customer_id = ?,
             linked_vehicle_id = NULLIF(?, 0), linked_payment_id = NULLIF(?, 0),
             decided_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->bind_param('iiii', $customerId, $vehicleId, $paymentId, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to finalize customer draft.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, 'Draft', 'Committed', null, $userId);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Customer submission saved.', ['status' => 'Committed']);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
