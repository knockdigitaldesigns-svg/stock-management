<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission([
    'crm_campaigns.view',
    'crm_campaigns.add',
    'crm_campaigns.edit'
]);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$result = $conn->query(
    "SELECT id, pipeline_name, status
     FROM crm_pipelines
     ORDER BY status = 'Active' DESC, display_order ASC, pipeline_name ASC"
);

if (!$result) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load pipelines: ' . $error, [], [], 500);
}

$pipelines = [];
while ($row = $result->fetch_assoc()) {
    $pipelines[] = $row;
}

$conn->close();
sendResponse(true, 'Pipelines loaded successfully.', ['pipelines' => $pipelines]);
?>
