<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_pipelines.edit');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = isset($data->id) ? (int) $data->id : 0;
if ($id <= 0) {
    sendResponse(false, 'Pipeline id is required.', [], [], 400);
}

$status = trim((string) ($data->status ?? ''));
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid pipeline status.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'SELECT id FROM crm_pipelines WHERE id = ? LIMIT 1'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Pipeline record not found.', [], [], 404);
}

$stmt->bind_param('i', $id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Pipeline record not found.', [], [], 404);
}
$stmt->close();

$update = $conn->prepare(
    'UPDATE crm_pipelines
     SET status = ?, updated_at = NOW()
     WHERE id = ?'
);

if (!$update) {
    $conn->close();
    sendResponse(false, 'Unable to update pipeline status.', [], [], 500);
}

$update->bind_param('si', $status, $id);
if (!$update->execute()) {
    $update->close();
    $conn->close();
    sendResponse(false, 'Failed to update pipeline status.', [], [], 500);
}

$update->close();
$conn->close();

sendResponse(true, 'Pipeline status updated successfully.', []);
?>
