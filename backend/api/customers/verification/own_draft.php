<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$currentUser = authenticate();
requireAnyPermission(['customers.add', 'customers.edit']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$submissionId = (int) ($_GET['id'] ?? 0);
if ($submissionId <= 0) {
    sendResponse(false, 'A valid submission ID is required.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'SELECT id, existing_customer_id, status, submission_data, rejection_reason
     FROM customer_payment_verification_submissions
     WHERE id = ? AND submitter_user_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $submissionId, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$row || !in_array($row['status'], ['Draft', 'Rejected'], true)) {
    sendResponse(false, 'This submission cannot be edited.', [], [], 404);
}
try {
    $row['submission_data'] = decodeCustomerPaymentSubmission((string) $row['submission_data']);
} catch (Throwable $error) {
    sendResponse(false, 'Saved customer draft is invalid.', [], [], 500);
}
$row['id'] = (int) $row['id'];
$row['existing_customer_id'] = $row['existing_customer_id'] !== null ? (int) $row['existing_customer_id'] : null;
sendResponse(true, 'Customer draft fetched.', ['submission' => $row]);
