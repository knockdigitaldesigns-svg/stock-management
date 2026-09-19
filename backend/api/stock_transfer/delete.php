<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('stock_transfer.delete');
$authUser = authenticate();

$data = json_decode(file_get_contents('php://input'));
$id = isset($data->id) ? (int)$data->id : 0;

if ($id <= 0) sendResponse(false, 'Invalid transfer ID.', [], [], 400);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $oldRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$oldRecord) throw new Exception("Transfer record not found.");

    // Check if the allocation still belongs to the to_owner. If so, revert it to from_owner.
    $allocCheck = $conn->prepare("SELECT id, owner_type, owner_id FROM stock_allocations WHERE id = ? FOR UPDATE");
    $allocCheck->bind_param('i', $oldRecord['allocation_id']);
    $allocCheck->execute();
    $allocRow = $allocCheck->get_result()->fetch_assoc();
    $allocCheck->close();

    if ($allocRow && $allocRow['owner_type'] === $oldRecord['to_owner_type'] && (int)$allocRow['owner_id'] === (int)$oldRecord['to_owner_id']) {
        // Revert allocation
        $updAlloc = $conn->prepare("UPDATE stock_allocations SET owner_type = ?, owner_id = ?, allocation_type = ?, updated_at = NOW() WHERE id = ?");
        $updAlloc->bind_param('sisi', $oldRecord['from_owner_type'], $oldRecord['from_owner_id'], $oldRecord['from_owner_type'], $oldRecord['allocation_id']);
        $updAlloc->execute();
        $updAlloc->close();
    } else {
        // Just fail if the asset was transferred again. Reversing the history without reversing ownership is bad data practice.
        throw new Exception("Cannot delete this transfer because the asset has since been transferred or modified again.");
    }

    $delStmt = $conn->prepare("DELETE FROM stock_transfers WHERE id = ?");
    $delStmt->bind_param('i', $id);
    if (!$delStmt->execute()) throw new Exception("Failed to delete transfer: " . $delStmt->error);
    $delStmt->close();

    writeDeleteSnapshot($conn, $id, 'Stock Transfer', $oldRecord, $authUser);

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Stock transfer deleted successfully.');
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
}
?>
