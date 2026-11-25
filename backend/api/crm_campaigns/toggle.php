<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_campaigns.edit');

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
$status = trim((string) ($data->status ?? ''));
if ($id <= 0 || !in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'A valid campaign id and status are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'UPDATE crm_campaigns SET status = ?, updated_at = NOW() WHERE id = ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to update campaign status: ' . $error, [], [], 500);
}
$stmt->bind_param('si', $status, $id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to update campaign status: ' . $error, [], [], 500);
}
$updated = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($updated === 0) {
    sendResponse(false, 'Campaign not found or status is unchanged.', [], [], 404);
}
sendResponse(true, 'Campaign status updated successfully.');
?>
