<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}
requirePermission('device_types.delete');
$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
if ($id <= 0) sendResponse(false, 'Device type ID is required.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$used = $conn->prepare('SELECT id FROM devices WHERE device_model_id = ? LIMIT 1');
$used->bind_param('i', $id);
$used->execute();
if ($used->get_result()->num_rows > 0) {
    $used->close();
    $conn->close();
    sendResponse(false, 'This device type is already used by existing devices and cannot be deleted.', [], [], 409);
}
$used->close();
$stmt = $conn->prepare('DELETE FROM device_types WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
if ($stmt->affected_rows === 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Device type not found.', [], [], 404);
}
$stmt->close();
$conn->close();
sendResponse(true, 'Device type deleted successfully.');
