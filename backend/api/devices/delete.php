<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
requirePermission('devices.delete');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    sendResponse(false, "Device ID is required", [], [], 400);
}

$id = $data->id;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Check status first
$checkStmt = $conn->prepare("SELECT * FROM devices WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    sendResponse(false, "Device not found", [], [], 404);
}

$device = $result->fetch_assoc();

if ($device['status'] === 'allocated' || $device['status'] === 'used') {
    sendResponse(false, "This device is currently " . $device['status'] . " and cannot be deleted.", [], [], 400);
}

$checkStmt->close();

$conn->begin_transaction();
try {
writeDeleteSnapshot($conn, (int) $id, 'Device', $device, $currentUser);
$stmt = $conn->prepare("DELETE FROM devices WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, "Device deleted successfully");
} else {
    throw new RuntimeException("Failed to delete device");
}
} catch (Throwable $e) {
    $conn->rollback();
    $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
