<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'], true)) {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('device_alert.delete');

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data) || !isset($data->id)) {
    sendResponse(false, 'Alert configuration ID is required.', [], [], 400);
}

$configId = (int) $data->id;
if ($configId <= 0) {
    sendResponse(false, 'Alert configuration ID is required.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$existsStmt = $conn->prepare('SELECT id FROM stock_alert_settings WHERE id = ? LIMIT 1');
$existsStmt->bind_param('i', $configId);
$existsStmt->execute();
$exists = $existsStmt->get_result()->num_rows > 0;
$existsStmt->close();

if (!$exists) {
    $conn->close();
    sendResponse(false, 'Alert configuration not found.', [], [], 404);
}

$stmt = $conn->prepare('DELETE FROM stock_alert_settings WHERE id = ?');
$stmt->bind_param('i', $configId);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to delete alert configuration.', [], [], 500);
}

$stmt->close();
$conn->close();

sendResponse(true, 'Alert configuration deleted successfully.');
?>
