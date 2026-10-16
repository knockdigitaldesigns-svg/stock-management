<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
requireCustomerPaymentVerificationReviewer();

$status = trim((string) ($_GET['status'] ?? 'Pending Verification'));
$search = trim((string) ($_GET['search'] ?? ''));
$allowedStatuses = ['Pending Verification', 'Applying', 'Approved', 'Rejected'];
if (!in_array($status, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid verification status.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$statusSql = $status === 'Pending Verification'
    ? "s.status IN ('Pending Verification', 'Applying')"
    : 's.status = ?';
$stmt = $conn->prepare(
    'SELECT s.id, s.submitter_user_id, s.existing_customer_id, s.status, s.submission_data,
            s.submitted_at, s.created_at, s.rejection_reason,
            submitter.employee_name AS submitted_by,
            reviewer.employee_name AS reviewed_by
     FROM customer_payment_verification_submissions s
     INNER JOIN users submitter ON submitter.id = s.submitter_user_id
     LEFT JOIN users reviewer ON reviewer.id = s.reviewer_user_id
     WHERE ' . $statusSql . '
       AND (? = \'\' OR s.submission_data LIKE ? OR submitter.employee_name LIKE ? OR submitter.username LIKE ?)
     ORDER BY COALESCE(s.submitted_at, s.created_at) DESC, s.id DESC
     LIMIT 500'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare verification queue query.', [], [], 500);
}
$pattern = '%' . $search . '%';
if ($status === 'Pending Verification') {
    $stmt->bind_param('ssss', $search, $pattern, $pattern, $pattern);
} else {
    $stmt->bind_param('sssss', $status, $search, $pattern, $pattern, $pattern);
}
$stmt->execute();
$result = $stmt->get_result();
$submissions = [];
while ($row = $result->fetch_assoc()) {
    try {
        $detail = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    } catch (Throwable $error) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'A saved verification submission could not be read.', [], [], 500);
    }
    $customer = $detail['customer'] ?? [];
    $payment = $detail['payment_part2'] ?? null;
    if (!is_array($payment) && is_array($detail['vehicles'] ?? null)) {
        foreach ($detail['vehicles'] as $vehicle) {
            if (is_array($vehicle['payment_part2'] ?? null)) {
                $payment = $vehicle['payment_part2'];
                break;
            }
        }
    }
    $payment = is_array($payment) ? $payment : [];
    $submissions[] = [
        'id' => (int) $row['id'],
        'submitter_user_id' => (int) $row['submitter_user_id'],
        'existing_customer_id' => $row['existing_customer_id'] !== null ? (int) $row['existing_customer_id'] : null,
        'status' => $row['status'],
        'customer_name' => (string) ($customer['username'] ?? $customer['customer_name'] ?? '—'),
        'primary_mobile_no' => (string) ($customer['primary_mobile_no'] ?? ''),
        'platform_name' => (string) ($customer['platform_name'] ?? ''),
        'location' => (string) ($customer['location'] ?? ''),
        'payment_status' => (string) ($payment['payment_status'] ?? ''),
        'total_sale_amount' => (float) ($payment['total_sale_amount'] ?? 0),
        'submitted_by' => (string) ($row['submitted_by'] ?? ''),
        'reviewed_by' => (string) ($row['reviewed_by'] ?? ''),
        'submitted_at' => $row['submitted_at'] ?? $row['created_at'],
        'rejection_reason' => $row['rejection_reason']
    ];
}
$stmt->close();

$counts = [];
foreach ($allowedStatuses as $countStatus) {
    $countStmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM customer_payment_verification_submissions
         WHERE status = ?'
    );
    $countStmt->bind_param('s', $countStatus);
    $countStmt->execute();
    $counts[$countStatus] = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
}
$counts['Pending Verification'] += $counts['Applying'];
$conn->close();
sendResponse(true, 'Verification submissions fetched.', ['submissions' => $submissions, 'counts' => $counts]);
