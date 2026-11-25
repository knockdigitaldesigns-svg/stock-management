<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission([
    'crm_leads.view',
    'crm_leads.add',
    'crm_leads.edit',
    'crm_leads.my_view',
    'crm_leads.view'
]);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$result = $conn->query(
    "SELECT id, stage_name, description, display_order
     FROM crm_lead_stage_master
     WHERE status = 'Active'
     ORDER BY display_order ASC, stage_name ASC, id ASC"
);
if (!$result) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active lead stages: ' . $error, [], [], 500);
}

$stages = [];
while ($row = $result->fetch_assoc()) {
    $stages[] = $row;
}

$conn->close();
sendResponse(true, 'Active lead stages loaded successfully.', ['stages' => $stages]);
?>
