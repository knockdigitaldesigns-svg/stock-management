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

if ($minimumDeviceCountRaw === '' || !preg_match('/^[0-9]+$/', $minimumDeviceCountRaw)) {
    sendResponse(false, 'Minimum device count must be a valid number.', [], [], 400);
}

if ($minimumSimCountRaw === '' || !preg_match('/^[0-9]+$/', $minimumSimCountRaw)) {
    sendResponse(false, 'Minimum SIM count must be a valid number.', [], [], 400);
}

$minimumDeviceCount = (int) $minimumDeviceCountRaw;
$minimumSimCount = (int) $minimumSimCountRaw;

if ($minimumDeviceCount < 0 || $minimumSimCount < 0) {
    sendResponse(false, 'Minimum counts cannot be negative.', [], [], 400);
}

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

$existingConfig = $conn->prepare('SELECT id FROM stock_alert_settings WHERE owner_type = ? AND owner_id = ? LIMIT 1');
$existingConfig->bind_param('si', $ownerType, $ownerId);
$existingConfig->execute();
$hasExisting = $existingConfig->get_result()->num_rows > 0;
$existingConfig->close();

if ($configId > 0) {
    requirePermission('device_alert.edit');
    $stmt = $conn->prepare('UPDATE stock_alert_settings SET minimum_device_count = ?, minimum_sim_count = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->bind_param('iisi', $minimumDeviceCount, $minimumSimCount, $notes, $configId);
} else {
    if ($hasExisting) {
        requirePermission('device_alert.edit');
    } else {
        requirePermission('device_alert.add');
    }

    $stmt = $conn->prepare('INSERT INTO stock_alert_settings (owner_type, owner_id, minimum_device_count, minimum_sim_count, notes) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE minimum_device_count = VALUES(minimum_device_count), minimum_sim_count = VALUES(minimum_sim_count), notes = VALUES(notes), updated_at = CURRENT_TIMESTAMP');
    $stmt->bind_param('siiis', $ownerType, $ownerId, $minimumDeviceCount, $minimumSimCount, $notes);
}

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, $configId > 0 ? 'Failed to update device alert configuration.' : 'Failed to save device alert configuration.', [], [], 500);
}
$stmt->close();
$conn->close();

sendResponse(true, $configId > 0 ? 'Alert configuration updated successfully.' : 'Alert configuration saved successfully.', []);
