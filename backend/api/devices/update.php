<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

authenticate();
requirePermission('devices.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data || !isset($data->id)) {
    sendResponse(false, 'Device ID is required', [], [], 400);
}

$id = (int) $data->id;
$purchase_date = trim((string) ($data->purchase_date ?? ''));
$device_model_id = (int) ($data->device_model_id ?? 0);
$imei_no = trim((string) ($data->imei_no ?? ''));
$notes = trim((string) ($data->notes ?? ''));

if ($purchase_date === '') {
    sendResponse(false, 'Purchase date is required', [], [], 400);
}
if (isFutureDate($purchase_date)) sendResponse(false, 'Future dates are not allowed', [], [], 400);

if ($device_model_id <= 0) {
    sendResponse(false, 'Device model is required', [], [], 400);
}

if (!preg_match('/^[0-9]{15}$/', $imei_no)) {
    sendResponse(false, 'IMEI must contain exactly 15 digits', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$modelCheck = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
$modelCheck->bind_param('i', $device_model_id);
$modelCheck->execute();
if ($modelCheck->get_result()->num_rows === 0) {
    $modelCheck->close();
    sendResponse(false, 'Selected device model does not exist in Device Types.', [], [], 400);
}
$modelCheck->close();

$checkStmt = $conn->prepare('SELECT id FROM devices WHERE imei_no = ? AND id != ?');
$checkStmt->bind_param('si', $imei_no, $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    sendResponse(false, 'IMEI number already exists.', [], [], 400);
}
$checkStmt->close();

$stmt = $conn->prepare('UPDATE devices SET purchase_date = ?, device_model_id = ?, imei_no = ?, notes = ? WHERE id = ?');
$stmt->bind_param('sissi', $purchase_date, $device_model_id, $imei_no, $notes, $id);

if ($stmt->execute()) {
    sendResponse(true, 'Device updated successfully');
}

sendResponse(false, 'Failed to update device', [], [], 500);
