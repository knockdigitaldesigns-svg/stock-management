<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate(); requirePermission('stock.update');
$data = json_decode(file_get_contents('php://input'), true);
$ownerType = strtolower(trim((string)($data['owner_type'] ?? ''))); $ownerId = (int)($data['owner_id'] ?? 0);
if (!in_array($ownerType, ['dealer', 'technician'], true) || $ownerId <= 0) sendResponse(false, 'Valid owner details are required.', [], [], 400);
$db = new Database(); $conn = $db->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT device_id, sim_id FROM stock_allocations WHERE owner_type = ? AND owner_id = ? FOR UPDATE'); $stmt->bind_param('si', $ownerType, $ownerId); $stmt->execute(); $result = $stmt->get_result(); $deviceIds = []; $simIds = [];
    while ($row = $result->fetch_assoc()) { if ($row['device_id']) $deviceIds[] = (int)$row['device_id']; if ($row['sim_id']) $simIds[] = (int)$row['sim_id']; } $stmt->close();
    if ($deviceIds) { $ids = implode(',', array_map('intval', $deviceIds)); if (!$conn->query("UPDATE devices SET status = 'available' WHERE id IN ($ids)")) throw new Exception('Unable to return devices to stock.'); }
    if ($simIds) { $ids = implode(',', array_map('intval', $simIds)); if (!$conn->query("UPDATE sims SET status = 'available' WHERE id IN ($ids)")) throw new Exception('Unable to return SIMs to stock.'); }
    $stmt = $conn->prepare('DELETE FROM stock_allocations WHERE owner_type = ? AND owner_id = ?'); $stmt->bind_param('si', $ownerType, $ownerId); if (!$stmt->execute()) throw new Exception('Unable to delete allocated stock.'); $deleted = $stmt->affected_rows; $stmt->close();
    $conn->commit(); sendResponse(true, 'Allocated stock deleted successfully.', ['deleted' => $deleted]);
} catch (Exception $e) { $conn->rollback(); sendResponse(false, $e->getMessage(), [], [], 400); }
$conn->close();
?>
