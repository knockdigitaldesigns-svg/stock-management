<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_lead_status.activate');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$status = trim((string) ($data->status ?? ''));
if ($id === false || $id <= 0) {
    sendResponse(false, 'Lead status id is required.', [], [], 400);
}
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid lead status value.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'UPDATE crm_lead_status_master
     SET status = ?, updated_at = NOW()
     WHERE id = ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
}
$stmt->bind_param('si', $status, $id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
}
if ($stmt->affected_rows === 0) {
    $check = $conn->prepare('SELECT id FROM crm_lead_status_master WHERE id = ? LIMIT 1');
    if (!$check) {
        $error = $conn->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to verify lead status: ' . $error, [], [], 500);
    }
    $check->bind_param('i', $id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Lead status not found.', [], [], 404);
    }
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead status updated successfully.');
?>
