<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['crm_closures.add', 'crm_leads.view']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'SELECT s.id, s.submission_data, s.rejection_reason, s.submitted_at,
            cf.id AS closure_id, cf.lead_id, l.lead_name
     FROM customer_payment_verification_submissions s
     INNER JOIN crm_closure_installation_devices item
        ON item.verification_submission_id = s.id
     INNER JOIN crm_lead_closure_forms cf ON cf.id = item.closure_id
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     WHERE s.status = \'Rejected\'
       AND s.submission_data LIKE \'%"source":"crm_installation"%\'
       AND COALESCE(NULLIF(l.assigned_user_id, 0), NULLIF(l.created_by_user_id, 0),
                    cf.submitted_by_user_id) = ?
     ORDER BY s.decided_at DESC, s.id DESC'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare returned installation lookup: ' . $error, [], [], 500);
}
$stmt->bind_param('i', $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load returned installations: ' . $error, [], [], 500);
}

$corrections = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    try {
        $submission = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    } catch (Throwable $error) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'A returned installation submission could not be read.', [], [], 500);
    }
    $corrections[] = [
        'submission_id' => (int) $row['id'],
        'closure_id' => (int) $row['closure_id'],
        'lead_id' => (int) $row['lead_id'],
        'lead_name' => (string) $row['lead_name'],
        'device_sequence' => (int) ($submission['device_sequence'] ?? 0),
        'customer' => $submission['customer'] ?? [],
        'vehicle' => $submission['vehicle'] ?? [],
        'payment_part1' => $submission['payment_part1'] ?? [],
        'rejection_reason' => (string) ($row['rejection_reason'] ?? ''),
        'submitted_at' => $row['submitted_at']
    ];
}
$stmt->close();
$conn->close();
sendResponse(true, 'Returned installation submissions loaded.', ['corrections' => $corrections]);
?>
