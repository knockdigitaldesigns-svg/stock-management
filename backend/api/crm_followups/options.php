<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('crm_followups.add');
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$result = $conn->query(
    "SELECT id, status_name, status_type, color
     FROM crm_lead_status_master
     WHERE status = 'Active'
     ORDER BY display_order ASC, status_name ASC, id ASC"
);
if (!$result) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active lead statuses: ' . $error, [], [], 500);
}

$statuses = [];
while ($row = $result->fetch_assoc()) {
    $statuses[] = $row;
}

$conn->close();
sendResponse(true, 'Follow-up options loaded successfully.', ['statuses' => $statuses]);
?>
