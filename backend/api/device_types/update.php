<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}
$currentUser = authenticate();
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
$exists = $conn->prepare('SELECT device_type FROM device_types WHERE id = ? LIMIT 1');
$exists->bind_param('i', $id);
$exists->execute();
$existsResult = $exists->get_result();
if ($existsResult->num_rows === 0) {
    $exists->close();
    $conn->close();
    sendResponse(false, 'Device type not found.', [], [], 404);
}
$oldDeviceType = $existsResult->fetch_assoc();
$exists->close();
$conn->begin_transaction();
try {
writeChangedFields($conn, $id, 'Device Type', $oldDeviceType, ['device_type' => $deviceType], $currentUser);
$stmt = $conn->prepare('UPDATE device_types SET device_type = ? WHERE id = ?');
$stmt->bind_param('si', $deviceType, $id);
$stmt->execute();
$stmt->close();
$conn->commit();
$conn->close();
sendResponse(true, 'Device type updated successfully.');
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
