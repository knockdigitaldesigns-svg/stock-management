<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_lead_status.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$statusName = trim((string) ($data->status_name ?? ''));
$statusType = trim((string) ($data->status_type ?? ''));
$color = trim((string) ($data->color ?? '#6c757d'));
$displayOrderValue = $data->display_order ?? 0;
$status = trim((string) ($data->status ?? 'Active'));
$statusTypes = ['Open', 'Active', 'Resolved', 'Lost'];

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
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid status value.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$duplicate = $conn->prepare(
    'SELECT id FROM crm_lead_status_master
     WHERE LOWER(TRIM(status_name)) = LOWER(?)
     LIMIT 1'
);
if (!$duplicate) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate status name: ' . $error, [], [], 500);
}
$duplicate->bind_param('s', $statusName);
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

$userId = (int) ($currentUser['user_id'] ?? 0);
$stmt = $conn->prepare(
    'INSERT INTO crm_lead_status_master
        (status_name, status_type, color, display_order, status, created_by_user_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to create lead status: ' . $error, [], [], 500);
}
$stmt->bind_param('sssisi', $statusName, $statusType, $color, $displayOrder, $status, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to create lead status: ' . $error, [], [], 500);
}

$id = $conn->insert_id;
$stmt->close();
$conn->close();
sendResponse(true, 'Lead status created successfully.', ['id' => $id]);
?>
