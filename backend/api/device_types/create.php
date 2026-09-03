<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}
requirePermission('device_types.add');
$data = json_decode(file_get_contents('php://input'));
$deviceType = trim((string) ($data->device_type ?? ''));
if ($deviceType === '') {
    sendResponse(false, 'Device type is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$check = $conn->prepare('SELECT id FROM device_types WHERE device_type = ? LIMIT 1');
$check->bind_param('s', $deviceType);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();
    sendResponse(false, 'Device type already exists.', [], [], 409);
}
$check->close();
$stmt = $conn->prepare('INSERT INTO device_types (device_type) VALUES (?)');
$stmt->bind_param('s', $deviceType);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to add device type.', [], [], 500);
}
$id = $conn->insert_id;
$stmt->close();
$conn->close();
sendResponse(true, 'Device type added successfully.', ['id' => $id]);
