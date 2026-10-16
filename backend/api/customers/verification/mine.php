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
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'SELECT id, status, submission_data, rejection_reason, submitted_at, decided_at
     FROM customer_payment_verification_submissions
     WHERE submitter_user_id = ?
       AND status <> \'Committed\'
     ORDER BY COALESCE(submitted_at, created_at) DESC, id DESC
     LIMIT 500'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$submissions = [];
while ($row = $result->fetch_assoc()) {
    try {
        $submission = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    } catch (Throwable $error) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'A saved customer draft could not be read.', [], [], 500);
    }
    $customer = $submission['customer'] ?? $submission['step1'] ?? [];
    $payment = $submission['payment_part2'] ?? [];
    $submissions[] = [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'customer_name' => (string) ($customer['username'] ?? '—'),
        'primary_mobile_no' => (string) ($customer['primary_mobile_no'] ?? ''),
        'location' => (string) ($customer['location'] ?? ''),
        'payment_status' => (string) ($payment['payment_status'] ?? ''),
        'rejection_reason' => $row['rejection_reason'],
        'submitted_at' => $row['submitted_at'],
        'decided_at' => $row['decided_at']
    ];
}
$stmt->close();
$conn->close();
sendResponse(true, 'Your customer submissions were fetched.', ['submissions' => $submissions]);
