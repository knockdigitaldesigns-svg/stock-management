<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('stock_transfer.add');
$authUser = authenticate();

$data = json_decode(file_get_contents('php://input'));

// Validate required fields
$allocationId    = isset($data->allocation_id)    ? (int)$data->allocation_id    : 0;
$assetType       = isset($data->asset_type)       ? trim((string)$data->asset_type)       : '';
$deviceId        = isset($data->device_id)        ? (int)$data->device_id        : 0;
$simId           = isset($data->sim_id)           ? (int)$data->sim_id           : 0;
$fromOwnerType   = isset($data->from_owner_type)  ? trim((string)$data->from_owner_type)  : '';
$fromOwnerId     = isset($data->from_owner_id)    ? (int)$data->from_owner_id    : 0;
$toOwnerType     = isset($data->to_owner_type)    ? trim((string)$data->to_owner_type)    : '';
$toOwnerId       = isset($data->to_owner_id)      ? (int)$data->to_owner_id      : 0;
$transferDate    = isset($data->transfer_date)    ? trim((string)$data->transfer_date)    : '';
$assetChecked    = isset($data->asset_checked)    ? (bool)$data->asset_checked    : false;

// Basic validation
if ($allocationId <= 0) sendResponse(false, 'Valid allocation ID is required.', [], [], 400);
if (!in_array($assetType, ['device', 'sim'], true)) sendResponse(false, 'Invalid asset type.', [], [], 400);
if ($assetType === 'device' && $deviceId <= 0) sendResponse(false, 'Valid device ID is required.', [], [], 400);
if ($assetType === 'sim' && $simId <= 0) sendResponse(false, 'Valid SIM ID is required.', [], [], 400);
if (!in_array($fromOwnerType, ['dealer', 'technician'], true)) sendResponse(false, 'Invalid from owner type.', [], [], 400);
if ($fromOwnerId <= 0) sendResponse(false, 'Valid from owner ID is required.', [], [], 400);
if (!in_array($toOwnerType, ['dealer', 'technician'], true)) sendResponse(false, 'Invalid to owner type.', [], [], 400);
if ($toOwnerId <= 0) sendResponse(false, 'Valid to owner ID is required.', [], [], 400);
if ($transferDate === '') sendResponse(false, 'Transfer date is required.', [], [], 400);
if (!$assetChecked) sendResponse(false, 'Please select the asset checkbox to confirm the transfer.', [], [], 400);
if ($fromOwnerType === $toOwnerType && $fromOwnerId === $toOwnerId) sendResponse(false, 'New owner must be different from the current owner.', [], [], 400);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$conn->begin_transaction();

try {
    // 1. Verify the stock_allocation exists and belongs to the stated from-owner
    $allocCheck = $conn->prepare("
        SELECT sa.id, sa.owner_type, sa.owner_id, sa.device_id, sa.sim_id,
               sa.device_amount, sa.sim_amount, sa.total_amount, sa.amount_paid,
               sa.pending_amount, sa.payment_status, sa.payment_mode,
               sa.allocation_type, sa.allocation_date, sa.software, sa.notes
        FROM stock_allocations sa
        WHERE sa.id = ? FOR UPDATE
    ");
    $allocCheck->bind_param('i', $allocationId);
    $allocCheck->execute();
    $allocRow = $allocCheck->get_result()->fetch_assoc();
    $allocCheck->close();

    if (!$allocRow) throw new Exception('Allocation record not found.');
    if ($allocRow['owner_type'] !== $fromOwnerType || (int)$allocRow['owner_id'] !== $fromOwnerId) {
        throw new Exception('Allocation does not match the stated current owner.');
    }
    writeChangedFields($conn, $allocationId, 'Stock Transfer', $allocRow, [
        'owner_type' => $toOwnerType,
        'owner_id' => $toOwnerId,
        'allocation_type' => $toOwnerType
    ], $authUser);

    // 2. Verify asset status is 'allocated'
    if ($assetType === 'device') {
        $assetCheck = $conn->prepare("SELECT id, status FROM devices WHERE id = ? FOR UPDATE");
        $assetCheck->bind_param('i', $deviceId);
        $assetCheck->execute();
        $assetRow = $assetCheck->get_result()->fetch_assoc();
        $assetCheck->close();
        if (!$assetRow) throw new Exception('Device not found.');
        if (strtolower($assetRow['status']) !== 'allocated') throw new Exception('This device is not currently allocated and cannot be transferred.');
        if ((int)$allocRow['device_id'] !== $deviceId) throw new Exception('Device does not match the allocation record.');
    } else {
        $assetCheck = $conn->prepare("SELECT id, status FROM sims WHERE id = ? FOR UPDATE");
        $assetCheck->bind_param('i', $simId);
        $assetCheck->execute();
        $assetRow = $assetCheck->get_result()->fetch_assoc();
        $assetCheck->close();
        if (!$assetRow) throw new Exception('SIM not found.');
        if (strtolower($assetRow['status']) !== 'allocated') throw new Exception('This SIM is not currently allocated and cannot be transferred.');
        if ((int)$allocRow['sim_id'] !== $simId) throw new Exception('SIM does not match the allocation record.');
    }

    // 3. Verify to-owner exists and is eligible
    if ($toOwnerType === 'dealer') {
        $ownerCheck = $conn->prepare("SELECT id, dealer_name, installation_status FROM dealers WHERE id = ?");
        $ownerCheck->bind_param('i', $toOwnerId);
        $ownerCheck->execute();
        $ownerRow = $ownerCheck->get_result()->fetch_assoc();
        $ownerCheck->close();
        if (!$ownerRow) throw new Exception('Target dealer not found.');
        if ($ownerRow['installation_status'] === 'Not Willing') throw new Exception('Cannot transfer to a Not Willing dealer.');
        $toOwnerName = $ownerRow['dealer_name'];
    } else {
        $ownerCheck = $conn->prepare("SELECT id, technician_name FROM technicians WHERE id = ?");
        $ownerCheck->bind_param('i', $toOwnerId);
        $ownerCheck->execute();
        $ownerRow = $ownerCheck->get_result()->fetch_assoc();
        $ownerCheck->close();
        if (!$ownerRow) throw new Exception('Target technician not found.');
        $toOwnerName = $ownerRow['technician_name'];
    }

    // Get from-owner name for history
    if ($fromOwnerType === 'dealer') {
        $fnStmt = $conn->prepare("SELECT dealer_name FROM dealers WHERE id = ?");
    } else {
        $fnStmt = $conn->prepare("SELECT technician_name AS dealer_name FROM technicians WHERE id = ?");
    }
    $fnStmt->bind_param('i', $fromOwnerId);
    $fnStmt->execute();
    $fnRow = $fnStmt->get_result()->fetch_assoc();
    $fnStmt->close();
    $fromOwnerName = $fnRow['dealer_name'] ?? 'Unknown';

    // 4. Update stock_allocations: change owner_type and owner_id, keep everything else
    $updAlloc = $conn->prepare("
        UPDATE stock_allocations
        SET owner_type = ?, owner_id = ?, allocation_type = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updAlloc->bind_param('sisi', $toOwnerType, $toOwnerId, $toOwnerType, $allocationId);
    if (!$updAlloc->execute()) throw new Exception('Failed to update allocation owner: ' . $updAlloc->error);
    $updAlloc->close();

    // 5. Insert into stock_transfers (transfer history)
    $userId = (int)($authUser['user_id'] ?? $authUser['id'] ?? 0);
    $txDeviceId = ($assetType === 'device') ? $deviceId : null;
    $txSimId    = ($assetType === 'sim')    ? $simId    : null;

    $histStmt = $conn->prepare("
        INSERT INTO stock_transfers
            (allocation_id, device_id, sim_id,
             from_owner_type, from_owner_id, from_owner_name,
             to_owner_type, to_owner_id, to_owner_name,
             transfer_date, transferred_by_user_id,
             previous_status, new_status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Allocated', 'Allocated', NULL, NOW())
    ");
    $prevStatus = 'Allocated';
    $newStatus  = 'Allocated';
    $histStmt->bind_param(
        'iiisississi',
        $allocationId, $txDeviceId, $txSimId,
        $fromOwnerType, $fromOwnerId, $fromOwnerName,
        $toOwnerType, $toOwnerId, $toOwnerName,
        $transferDate, $userId
    );
    if (!$histStmt->execute()) throw new Exception('Failed to record transfer history: ' . $histStmt->error);
    $histStmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(true, 'Stock transferred successfully.', [
        'from_owner' => $fromOwnerName,
        'to_owner'   => $toOwnerName,
        'asset_type' => $assetType
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
}
?>
