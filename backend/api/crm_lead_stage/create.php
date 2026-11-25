<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_lead_stage.add');

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$stageName = trim((string) ($data->stage_name ?? ''));
$description = trim((string) ($data->description ?? ''));
$displayOrder = filter_var($data->display_order ?? 0, FILTER_VALIDATE_INT);
$status = trim((string) ($data->status ?? 'Active'));

if ($stageName === '') {
    sendResponse(false, 'Stage name is required.', [], [], 400);
}
if (mb_strlen($stageName) > 100 || strlen($description) > 65535) {
    sendResponse(false, 'Stage name or description exceeds the allowed length.', [], [], 400);
}
if ($displayOrder === false || $displayOrder < 0) {
    sendResponse(false, 'Display order must be a whole number greater than or equal to zero.', [], [], 400);
}
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid stage status.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'INSERT INTO crm_lead_stage_master
        (stage_name, description, display_order, status, created_by_user_id)
     VALUES (?, NULLIF(?, \'\'), ?, ?, ?)'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead stage creation: ' . $error, [], [], 500);
}
$userId = (int) ($currentUser['user_id'] ?? 0);
$stmt->bind_param('ssisi', $stageName, $description, $displayOrder, $status, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $errorCode = $stmt->errno;
    $stmt->close();
    $conn->close();
    if ($errorCode === 1062) {
        sendResponse(false, 'A lead stage with this name already exists.', [], [], 409);
    }
    sendResponse(false, 'Unable to create lead stage: ' . $error, [], [], 500);
}

$id = $conn->insert_id;
$stmt->close();
$conn->close();
sendResponse(true, 'Lead stage created successfully.', ['id' => $id]);
?>
