<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate(); requirePermission('stock.update');
$data = json_decode(file_get_contents('php://input'), true);
$allocationId = (int)($data['allocation_id'] ?? 0);
if ($allocationId <= 0) sendResponse(false, 'Valid allocation ID is required.', [], [], 400);
$db = new Database(); $conn = $db->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT sa.*, d.dealer_name, t.technician_name, dev.imei_no, dt.device_type AS device_model, s.sim_no, s.sim_type FROM stock_allocations sa LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = "dealer" LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician" LEFT JOIN devices dev ON dev.id = sa.device_id LEFT JOIN device_types dt ON dt.id = dev.device_model_id LEFT JOIN sims s ON s.id = sa.sim_id WHERE sa.id = ? FOR UPDATE'); $stmt->bind_param('i', $allocationId); $stmt->execute(); $allocation = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$allocation) throw new Exception('Stock allocation not found.');
    writeDeleteSnapshot($conn, $allocationId, 'Stock Allocation', $allocation, $currentUser);
    if (!empty($allocation['device_id'])) { $assetId = (int)$allocation['device_id']; $assetStmt = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ?"); $assetStmt->bind_param('i', $assetId); if (!$assetStmt->execute()) throw new Exception('Unable to return device to stock.'); $assetStmt->close(); }
    if (!empty($allocation['sim_id'])) { $assetId = (int)$allocation['sim_id']; $assetStmt = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ?"); $assetStmt->bind_param('i', $assetId); if (!$assetStmt->execute()) throw new Exception('Unable to return SIM to stock.'); $assetStmt->close(); }
    $stmt = $conn->prepare('DELETE FROM stock_allocations WHERE id = ?'); $stmt->bind_param('i', $allocationId); if (!$stmt->execute()) throw new Exception('Unable to delete allocated stock.'); $deleted = $stmt->affected_rows; $stmt->close();
    $conn->commit(); sendResponse(true, 'Allocated stock deleted successfully.', ['deleted' => $deleted]);
} catch (Exception $e) { $conn->rollback(); sendResponse(false, $e->getMessage(), [], [], 400); }
$conn->close();
?>
