<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_campaigns.delete');

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, 'Campaign id is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$count = $conn->prepare('SELECT COUNT(*) AS lead_count FROM crm_leads WHERE campaign_id = ?');
if (!$count) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to check campaign references: ' . $error, [], [], 500);
}
$count->bind_param('i', $id);
$count->execute();
$leadCount = (int) ($count->get_result()->fetch_assoc()['lead_count'] ?? 0);
$count->close();
if ($leadCount > 0) {
    $conn->close();
    sendResponse(
        false,
        'This campaign has associated leads and cannot be deleted. Deactivate it to preserve campaign attribution.',
        [],
        [],
        409
    );
}

$delete = $conn->prepare('DELETE FROM crm_campaigns WHERE id = ?');
if (!$delete) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to delete campaign: ' . $error, [], [], 500);
}
$delete->bind_param('i', $id);
if (!$delete->execute()) {
    $error = $delete->error;
    $delete->close();
    $conn->close();
    sendResponse(false, 'Unable to delete campaign: ' . $error, [], [], 500);
}
$deleted = $delete->affected_rows;
$delete->close();
$conn->close();

if ($deleted === 0) {
    sendResponse(false, 'Campaign not found.', [], [], 404);
}
sendResponse(true, 'Campaign deleted successfully.');
?>
