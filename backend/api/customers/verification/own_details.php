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
    'SELECT id, status, submission_data, rejection_reason, submitted_at, decided_at
     FROM customer_payment_verification_submissions
     WHERE id = ? AND submitter_user_id = ? LIMIT 1'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare submission details.', [], [], 500);
}
$stmt->bind_param('ii', $submissionId, $userId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load submission details.', [], [], 500);
}
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$row) {
    sendResponse(false, 'Verification submission not found.', [], [], 404);
}
try {
    $row['submission_data'] = decodeCustomerPaymentSubmission((string) $row['submission_data']);
} catch (Throwable $error) {
    sendResponse(false, 'Saved verification submission is invalid.', [], [], 500);
}
$row['id'] = (int) $row['id'];
sendResponse(true, 'Your verification submission was fetched.', ['submission' => $row]);
