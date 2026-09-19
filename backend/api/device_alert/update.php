<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$configId = isset($data->id) ? (int) $data->id : 0;
$ownerType = isset($data->owner_type) ? strtolower(trim((string) $data->owner_type)) : '';
$ownerId = isset($data->owner_id) ? (int) $data->owner_id : 0;
$assetType = isset($data->asset_type) ? strtolower(trim((string) $data->asset_type)) : '';
$deviceModelId = isset($data->device_model_id) && $data->device_model_id !== '' ? (int) $data->device_model_id : null;
$simTypeId = isset($data->sim_type_id) && $data->sim_type_id !== '' ? (int) $data->sim_type_id : null;
$minimumDeviceCountRaw = isset($data->minimum_device_count) ? trim((string) $data->minimum_device_count) : '';
$minimumSimCountRaw = isset($data->minimum_sim_count) ? trim((string) $data->minimum_sim_count) : '';
$notes = trim((string) ($data->notes ?? ''));

if ($configId > 0) {
    requirePermission('device_alert.edit');

    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        sendResponse(false, 'Database connection failed', [], [], 500);
    }

    $existingConfigStmt = $conn->prepare('SELECT owner_type, owner_id FROM stock_alert_settings WHERE id = ? LIMIT 1');
    $existingConfigStmt->bind_param('i', $configId);
    $existingConfigStmt->execute();
    $configRow = $existingConfigStmt->get_result()->fetch_assoc();
    $existingConfigStmt->close();

    if (!$configRow) {
        $conn->close();
        sendResponse(false, 'Alert configuration not found.', [], [], 404);
    }

    $ownerType = strtolower((string) ($configRow['owner_type'] ?? ''));
    $ownerId = (int) ($configRow['owner_id'] ?? 0);
    $conn->close();

} else {
    if (!in_array($ownerType, ['dealer', 'technician'], true)) {
        sendResponse(false, 'Owner type must be dealer or technician.', [], [], 400);
    }

    if ($ownerId <= 0) {
        sendResponse(false, 'Owner is required.', [], [], 400);
    }
}

if (!in_array($assetType, ['device', 'sim', 'both'], true)) {
    sendResponse(false, 'Asset type must be device, sim, or both.', [], [], 400);
}

if (in_array($assetType, ['device', 'both'], true) && $deviceModelId === null) {
    sendResponse(false, 'Device model is required.', [], [], 400);
}

if (in_array($assetType, ['sim', 'both'], true) && $simTypeId === null) {
    sendResponse(false, 'SIM type is required.', [], [], 400);
}

if (in_array($assetType, ['device', 'both'], true) && ($minimumDeviceCountRaw === '' || !preg_match('/^[0-9]+$/', $minimumDeviceCountRaw) || (int) $minimumDeviceCountRaw <= 0)) {
    sendResponse(false, 'Minimum device count must be a positive number.', [], [], 400);
}
if (in_array($assetType, ['sim', 'both'], true) && ($minimumSimCountRaw === '' || !preg_match('/^[0-9]+$/', $minimumSimCountRaw) || (int) $minimumSimCountRaw <= 0)) {
    sendResponse(false, 'Minimum SIM count must be a positive number.', [], [], 400);
}
$minimumDeviceCount = in_array($assetType, ['device', 'both'], true) ? (int) $minimumDeviceCountRaw : 0;
$minimumSimCount = in_array($assetType, ['sim', 'both'], true) ? (int) $minimumSimCountRaw : 0;
$minCount = $assetType === 'sim' ? $minimumSimCount : $minimumDeviceCount;

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

if ($ownerType === 'dealer') {
    $ownerCheck = $conn->prepare('SELECT id, installation_status FROM dealers WHERE id = ? LIMIT 1');
    $ownerCheck->bind_param('i', $ownerId);
    $ownerCheck->execute();
    $dealerData = $ownerCheck->get_result()->fetch_assoc();
    $ownerExists = !empty($dealerData);
    $ownerCheck->close();

    if ($ownerExists && $configId <= 0) {
        $statusNorm = strtolower(trim((string)($dealerData['installation_status'] ?? '')));
        if (!in_array($statusNorm, ['onsite', 'offsite'], true)) {
            $conn->close();
            sendResponse(false, 'Device alerts can only be configured for dealers with Onsite or Offsite installation status.', [], [], 400);
        }
    }
} else {
    $ownerCheck = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
    $ownerCheck->bind_param('i', $ownerId);
    $ownerCheck->execute();
    $ownerExists = $ownerCheck->get_result()->num_rows > 0;
    $ownerCheck->close();
}

if (!$ownerExists) {
    $conn->close();
    sendResponse(false, 'Selected owner was not found.', [], [], 400);
}

// Validate model/type exists
if (in_array($assetType, ['device', 'both'], true)) {
    $typeCheck = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
    $typeCheck->bind_param('i', $deviceModelId);
    $typeCheck->execute();
    $typeExists = $typeCheck->get_result()->num_rows > 0;
    $typeCheck->close();
    if (!$typeExists) {
        $conn->close();
        sendResponse(false, 'Selected device model was not found.', [], [], 400);
    }
    if ($assetType === 'device') {
        $simTypeId = null;
    }
}
if (in_array($assetType, ['sim', 'both'], true)) {
    $typeCheck = $conn->prepare('SELECT id FROM sim_types WHERE id = ? LIMIT 1');
    $typeCheck->bind_param('i', $simTypeId);
    $typeCheck->execute();
    $typeExists = $typeCheck->get_result()->num_rows > 0;
    $typeCheck->close();
    if (!$typeExists) {
        $conn->close();
        sendResponse(false, 'Selected SIM type was not found.', [], [], 400);
    }
    if ($assetType === 'sim') {
        $deviceModelId = null;
    }
}

// Check for duplicates
if ($configId > 0) {
    $dupCheck = $conn->prepare('SELECT id FROM stock_alert_settings WHERE owner_type = ? AND owner_id = ? AND asset_type = ? AND (device_model_id = ? OR (device_model_id IS NULL AND ? IS NULL)) AND (sim_type_id = ? OR (sim_type_id IS NULL AND ? IS NULL)) AND id != ?');
    $dupCheck->bind_param('sssiiiii', $ownerType, $ownerId, $assetType, $deviceModelId, $deviceModelId, $simTypeId, $simTypeId, $configId);
} else {
    $dupCheck = $conn->prepare('SELECT id FROM stock_alert_settings WHERE owner_type = ? AND owner_id = ? AND asset_type = ? AND (device_model_id = ? OR (device_model_id IS NULL AND ? IS NULL)) AND (sim_type_id = ? OR (sim_type_id IS NULL AND ? IS NULL))');
    $dupCheck->bind_param('sssiiii', $ownerType, $ownerId, $assetType, $deviceModelId, $deviceModelId, $simTypeId, $simTypeId);
}
$dupCheck->execute();
$isDuplicate = $dupCheck->get_result()->num_rows > 0;
$dupCheck->close();

if ($isDuplicate) {
    $conn->close();
    sendResponse(false, 'This configuration already exists.', [], [], 400);
}

if ($configId > 0) {
    requirePermission('device_alert.edit');
    $stmt = $conn->prepare('UPDATE stock_alert_settings SET asset_type = ?, device_model_id = ?, sim_type_id = ?, min_count = ?, minimum_device_count = ?, minimum_sim_count = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->bind_param('siiiiisi', $assetType, $deviceModelId, $simTypeId, $minCount, $minimumDeviceCount, $minimumSimCount, $notes, $configId);
} else {
    requirePermission('device_alert.add');
    $stmt = $conn->prepare('INSERT INTO stock_alert_settings (owner_type, owner_id, asset_type, device_model_id, sim_type_id, min_count, minimum_device_count, minimum_sim_count, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sisiiiiis', $ownerType, $ownerId, $assetType, $deviceModelId, $simTypeId, $minCount, $minimumDeviceCount, $minimumSimCount, $notes);
}

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, $configId > 0 ? 'Failed to update device alert configuration.' : 'Failed to save device alert configuration.', [], [], 500);
}
$stmt->close();
$conn->close();

sendResponse(true, $configId > 0 ? 'Alert configuration updated successfully.' : 'Alert configuration saved successfully.', []);
