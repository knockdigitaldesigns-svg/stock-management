<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('stock_transfer.edit');
$authUser = authenticate();

$data = json_decode(file_get_contents('php://input'));
$id = isset($data->id) ? (int)$data->id : 0;
$transferDate = isset($data->transfer_date) ? trim((string)$data->transfer_date) : '';
$notes = isset($data->notes) ? trim((string)$data->notes) : '';

if ($id <= 0) sendResponse(false, 'Invalid transfer ID.', [], [], 400);
if ($transferDate === '') sendResponse(false, 'Transfer date is required.', [], [], 400);

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

    $toOwnerType = isset($data->to_owner_type) ? trim((string)$data->to_owner_type) : $oldRecord['to_owner_type'];
    $toOwnerId = isset($data->to_owner_id) ? (int)$data->to_owner_id : (int)$oldRecord['to_owner_id'];
    $toOwnerName = $oldRecord['to_owner_name'];
    
    if ($toOwnerType !== $oldRecord['to_owner_type'] || $toOwnerId !== (int)$oldRecord['to_owner_id']) {
         if ($toOwnerType === 'dealer') {
             $ownerCheck = $conn->prepare("SELECT dealer_name, installation_status FROM dealers WHERE id = ?");
             $ownerCheck->bind_param('i', $toOwnerId);
             $ownerCheck->execute();
             $ownerRow = $ownerCheck->get_result()->fetch_assoc();
             $ownerCheck->close();
             if (!$ownerRow) throw new Exception('Target dealer not found.');
             if ($ownerRow['installation_status'] === 'Not Willing') throw new Exception('Cannot transfer to a Not Willing dealer.');
             $toOwnerName = $ownerRow['dealer_name'];
         } else {
             $ownerCheck = $conn->prepare("SELECT technician_name FROM technicians WHERE id = ?");
             $ownerCheck->bind_param('i', $toOwnerId);
             $ownerCheck->execute();
             $ownerRow = $ownerCheck->get_result()->fetch_assoc();
             $ownerCheck->close();
             if (!$ownerRow) throw new Exception('Target technician not found.');
             $toOwnerName = $ownerRow['technician_name'];
         }
         
         $allocCheck = $conn->prepare("SELECT id, owner_type, owner_id FROM stock_allocations WHERE id = ? FOR UPDATE");
         $allocCheck->bind_param('i', $oldRecord['allocation_id']);
         $allocCheck->execute();
         $allocRow = $allocCheck->get_result()->fetch_assoc();
         $allocCheck->close();
         
         if ($allocRow && $allocRow['owner_type'] === $oldRecord['to_owner_type'] && (int)$allocRow['owner_id'] === (int)$oldRecord['to_owner_id']) {
              $updAlloc = $conn->prepare("UPDATE stock_allocations SET owner_type = ?, owner_id = ?, allocation_type = ?, updated_at = NOW() WHERE id = ?");
              $updAlloc->bind_param('sisi', $toOwnerType, $toOwnerId, $toOwnerType, $oldRecord['allocation_id']);
              $updAlloc->execute();
              $updAlloc->close();
         } else {
              throw new Exception('Cannot change target owner because the asset has been further transferred or modified.');
         }
    }
    
    $updateStmt = $conn->prepare("UPDATE stock_transfers SET to_owner_type = ?, to_owner_id = ?, to_owner_name = ?, transfer_date = ?, notes = ? WHERE id = ?");
    $updateStmt->bind_param('sisssi', $toOwnerType, $toOwnerId, $toOwnerName, $transferDate, $notes, $id);
    if (!$updateStmt->execute()) throw new Exception("Failed to update transfer: " . $updateStmt->error);
    $updateStmt->close();
    
    $stmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $newRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    writeChangedFields($conn, $id, 'Stock Transfer', $oldRecord, $newRecord, $authUser);
    
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Stock transfer updated successfully.');
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
}
?>
