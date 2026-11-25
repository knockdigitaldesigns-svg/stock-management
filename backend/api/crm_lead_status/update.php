<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_lead_status.edit');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$statusName = trim((string) ($data->status_name ?? ''));
$statusType = trim((string) ($data->status_type ?? ''));
$color = trim((string) ($data->color ?? '#6c757d'));
$displayOrderValue = $data->display_order ?? 0;
$statusTypes = ['Open', 'Active', 'Resolved', 'Lost'];

if ($id === false || $id <= 0) {
    sendResponse(false, 'Lead status id is required.', [], [], 400);
}
if ($statusName === '' || $statusType === '') {
    sendResponse(false, 'Status name and status type are required.', [], [], 400);
}
if (mb_strlen($statusName) > 100) {
    sendResponse(false, 'Status name must be 100 characters or fewer.', [], [], 400);
}
if (!in_array($statusType, $statusTypes, true)) {
    sendResponse(false, 'Invalid status type.', [], [], 400);
}
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
    sendResponse(false, 'Color must be a valid 3- or 6-digit hex color.', [], [], 400);
}
$displayOrder = filter_var($displayOrderValue, FILTER_VALIDATE_INT);
if ($displayOrder === false || $displayOrder < 0) {
    sendResponse(false, 'Display order must be a whole number greater than or equal to zero.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$existing = $conn->prepare('SELECT status FROM crm_lead_status_master WHERE id = ? LIMIT 1');
if (!$existing) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate lead status: ' . $error, [], [], 500);
}
$existing->bind_param('i', $id);
if (!$existing->execute()) {
    $error = $existing->error;
    $existing->close();
    $conn->close();
    sendResponse(false, 'Unable to validate lead status: ' . $error, [], [], 500);
}
$existingStatus = $existing->get_result()->fetch_assoc();
if (!$existingStatus) {
    $existing->close();
    $conn->close();
    sendResponse(false, 'Lead status not found.', [], [], 404);
}
$existing->close();
$status = trim((string) ($data->status ?? $existingStatus['status']));
if (!in_array($status, ['Active', 'Inactive'], true)) {
    $conn->close();
    sendResponse(false, 'Invalid status value.', [], [], 400);
}
if ($status !== $existingStatus['status']) {
    requirePermission('crm_lead_status.activate');
}

$duplicate = $conn->prepare(
    'SELECT id FROM crm_lead_status_master
     WHERE LOWER(TRIM(status_name)) = LOWER(?) AND id <> ?
     LIMIT 1'
);
if (!$duplicate) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate status name: ' . $error, [], [], 500);
}
$duplicate->bind_param('si', $statusName, $id);
if (!$duplicate->execute()) {
    $error = $duplicate->error;
    $duplicate->close();
    $conn->close();
    sendResponse(false, 'Unable to validate status name: ' . $error, [], [], 500);
}
if ($duplicate->get_result()->num_rows > 0) {
    $duplicate->close();
    $conn->close();
    sendResponse(false, 'A lead status with this name already exists.', [], [], 409);
}
$duplicate->close();

$stmt = $conn->prepare(
    'UPDATE crm_lead_status_master
     SET status_name = ?, status_type = ?, color = ?, display_order = ?, status = ?, updated_at = NOW()
     WHERE id = ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
}
$stmt->bind_param('sssisi', $statusName, $statusType, $color, $displayOrder, $status, $id);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead status updated successfully.');
?>
