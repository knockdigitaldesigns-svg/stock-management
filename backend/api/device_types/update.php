<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}
requirePermission('device_types.edit');
$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
$deviceType = trim((string) ($data->device_type ?? ''));
if ($id <= 0) sendResponse(false, 'Device type ID is required.', [], [], 400);
if ($deviceType === '') sendResponse(false, 'Device type is required.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$check = $conn->prepare('SELECT id FROM device_types WHERE device_type = ? AND id != ? LIMIT 1');
$check->bind_param('si', $deviceType, $id);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();
    sendResponse(false, 'Device type already exists.', [], [], 409);
}
$check->close();
$exists = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
$exists->bind_param('i', $id);
$exists->execute();
if ($exists->get_result()->num_rows === 0) {
    $exists->close();
    $conn->close();
    sendResponse(false, 'Device type not found.', [], [], 404);
}
$exists->close();
$stmt = $conn->prepare('UPDATE device_types SET device_type = ? WHERE id = ?');
$stmt->bind_param('si', $deviceType, $id);
$stmt->execute();
$stmt->close();
$conn->close();
sendResponse(true, 'Device type updated successfully.');
