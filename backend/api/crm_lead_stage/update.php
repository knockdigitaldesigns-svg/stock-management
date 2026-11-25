<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_lead_stage.edit');

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$stageName = trim((string) ($data->stage_name ?? ''));
$description = trim((string) ($data->description ?? ''));
$displayOrder = filter_var($data->display_order ?? null, FILTER_VALIDATE_INT);
$status = trim((string) ($data->status ?? ''));

if ($id === false || $id <= 0) {
    sendResponse(false, 'Lead stage id is required.', [], [], 400);
}
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

$existing = $conn->prepare('SELECT id, stage_name, status FROM crm_lead_stage_master WHERE id = ? LIMIT 1');
if (!$existing) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate lead stage: ' . $error, [], [], 500);
}
$existing->bind_param('i', $id);
if (!$existing->execute()) {
    $error = $existing->error;
    $existing->close();
    $conn->close();
    sendResponse(false, 'Unable to validate lead stage: ' . $error, [], [], 500);
}
$existingStage = $existing->get_result()->fetch_assoc();
$existing->close();
if (!$existingStage) {
    $conn->close();
    sendResponse(false, 'Lead stage not found.', [], [], 404);
}
if (in_array(
    strtolower(trim((string) $existingStage['stage_name'])),
    ['new lead', 'existing customer', 'converted lead', 'lost lead'],
    true
) && (
    strtolower($stageName) !== strtolower(trim((string) $existingStage['stage_name'])) ||
    $status !== 'Active'
)) {
    $conn->close();
    sendResponse(false, 'Required lifecycle stages must keep their names and remain active.', [], [], 409);
}
if ($status !== $existingStage['status']) {
    requirePermission('crm_lead_stage.activate');
}

$stmt = $conn->prepare(
    'UPDATE crm_lead_stage_master
     SET stage_name = ?, description = NULLIF(?, \'\'),
         display_order = ?, status = ?, updated_at = NOW()
     WHERE id = ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead stage update: ' . $error, [], [], 500);
}
$stmt->bind_param('ssisi', $stageName, $description, $displayOrder, $status, $id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $errorCode = $stmt->errno;
    $stmt->close();
    $conn->close();
    if ($errorCode === 1062) {
        sendResponse(false, 'A lead stage with this name already exists.', [], [], 409);
    }
    sendResponse(false, 'Unable to update lead stage: ' . $error, [], [], 500);
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead stage updated successfully.');
?>
