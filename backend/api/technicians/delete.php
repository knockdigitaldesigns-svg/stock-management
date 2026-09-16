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
requirePermission('technicians.delete');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    sendResponse(false, "Technician ID is required", [], [], 400);
}

$id = $data->id;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$checkStmt = $conn->prepare("SELECT COUNT(id) as allocations FROM stock_allocations WHERE owner_type='technician' AND owner_id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$result = $checkStmt->get_result()->fetch_assoc();

if ($result['allocations'] > 0) {
    sendResponse(false, "Cannot delete technician. There are existing stock allocations tied to this technician.", [], [], 400);
}
$checkStmt->close();

$oldStmt = $conn->prepare('SELECT * FROM technicians WHERE id = ? LIMIT 1');
$oldStmt->bind_param('i', $id);
$oldStmt->execute();
$oldTechnician = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();
if (!$oldTechnician) sendResponse(false, 'Technician not found', [], [], 404);

$conn->begin_transaction();
try {
writeDeleteSnapshot($conn, (int) $id, 'Technician', $oldTechnician, $currentUser);
$stmt = $conn->prepare("DELETE FROM technicians WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $stmt->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, "Technician deleted successfully");
} else {
    throw new RuntimeException("Failed to delete technician");
}
} catch (Throwable $e) {
    $conn->rollback();
    $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
