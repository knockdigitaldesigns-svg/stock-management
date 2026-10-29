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
requireAnyPermission(['customers.add', 'customers.edit', 'courier.add']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$countStmt = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM customer_payment_verification_submissions
     WHERE submitter_user_id = ? AND status <> \'Committed\''
);
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare your submission count query.', [], [], 500);
}
$countStmt->bind_param('i', $userId);
if (!$countStmt->execute()) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count your submissions.', [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$stmt = $conn->prepare(
    'SELECT id, status, submission_data, rejection_reason, submitted_at, decided_at
     FROM customer_payment_verification_submissions
     WHERE submitter_user_id = ?
       AND status <> \'Committed\'
     ORDER BY COALESCE(submitted_at, created_at) DESC, id DESC
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('iii', $userId, $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch your submissions.', [], [], 500);
}
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
        'submission_type' => ($submission['source'] ?? '') === 'courier' ? 'Courier Request' : 'Customer Payment',
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
sendResponse(true, 'Your customer submissions were fetched.', [
    'submissions' => $submissions,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
