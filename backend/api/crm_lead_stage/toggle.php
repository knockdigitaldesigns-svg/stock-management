<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_lead_stage.activate');

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$status = trim((string) ($data->status ?? ''));
if ($id === false || $id <= 0) {
    sendResponse(false, 'Lead stage id is required.', [], [], 400);
}
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid lead stage status.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stageStmt = $conn->prepare(
    'SELECT stage_name FROM crm_lead_stage_master WHERE id = ? LIMIT 1'
);
if (!$stageStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate lead stage: ' . $error, [], [], 500);
}
$stageStmt->bind_param('i', $id);
if (!$stageStmt->execute()) {
    $error = $stageStmt->error;
    $stageStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to validate lead stage: ' . $error, [], [], 500);
}
$stageRecord = $stageStmt->get_result()->fetch_assoc();
$stageStmt->close();
if (!$stageRecord) {
    $conn->close();
    sendResponse(false, 'Lead stage not found.', [], [], 404);
}
if ($status === 'Inactive' && in_array(
    strtolower(trim((string) $stageRecord['stage_name'])),
    ['new lead', 'existing customer', 'converted lead', 'lost lead'],
    true
)) {
    $conn->close();
    sendResponse(false, 'Required lifecycle stages cannot be deactivated.', [], [], 409);
}

$stmt = $conn->prepare(
    'UPDATE crm_lead_stage_master
     SET status = ?, updated_at = NOW()
     WHERE id = ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead stage update: ' . $error, [], [], 500);
}
$stmt->bind_param('si', $status, $id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to update lead stage: ' . $error, [], [], 500);
}
if ($stmt->affected_rows === 0) {
    $check = $conn->prepare('SELECT id FROM crm_lead_stage_master WHERE id = ? LIMIT 1');
    if (!$check) {
        $error = $conn->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to verify lead stage: ' . $error, [], [], 500);
    }
    $check->bind_param('i', $id);
    if (!$check->execute()) {
        $error = $check->error;
        $check->close();
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to verify lead stage: ' . $error, [], [], 500);
    }
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Lead stage not found.', [], [], 404);
    }
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead stage updated successfully.');
?>
