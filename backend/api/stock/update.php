<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();
requirePermission('stock.update');

$data = json_decode(file_get_contents("php://input"));
if (!$data) {
    sendResponse(false, "Invalid request body", [], [], 400);
}

$ownerType = isset($data->owner_type) ? strtolower(trim((string) $data->owner_type)) : null;
$ownerId = isset($data->owner_id) ? (int) $data->owner_id : null;
$usedFor = isset($data->used_for) ? trim((string) $data->used_for) : '';
$deviceIds = isset($data->device_ids) && is_array($data->device_ids)
    ? array_values(array_unique(array_filter(array_map('intval', $data->device_ids), fn($id) => $id > 0)))
    : [];
$simIds = isset($data->sim_ids) && is_array($data->sim_ids)
    ? array_values(array_unique(array_filter(array_map('intval', $data->sim_ids), fn($id) => $id > 0)))
    : [];
$deviceNotes = isset($data->device_notes) && is_array($data->device_notes) ? $data->device_notes : [];
$simNotes = isset($data->sim_notes) && is_array($data->sim_notes) ? $data->sim_notes : [];
$software = isset($data->software) ? trim((string) $data->software) : '';
$totalAmount = is_numeric($data->total_amount ?? null) ? (float) $data->total_amount : null;
$amountPaid = is_numeric($data->amount_paid ?? null) ? (float) $data->amount_paid : 0.0;
$paymentStatus = trim((string) ($data->payment_status ?? ''));

if (empty($usedFor)) {
    sendResponse(false, "used_for is required", [], [], 400);
}

if (!$ownerType || $ownerId === null || $ownerId <= 0) {
    sendResponse(false, "Valid owner_type and owner_id are required", [], [], 400);
}

if (!in_array($ownerType, ['dealer', 'technician'], true)) {
    sendResponse(false, "owner_type must be dealer or technician", [], [], 400);
}

$allowedSoftware = ['Tracoo', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
if ($software !== '' && !in_array($software, $allowedSoftware, true)) {
    sendResponse(false, 'Invalid software selection', [], [], 400);
}

if ($totalAmount !== null) {
    if ($amountPaid < 0) {
        sendResponse(false, 'Amount Paid cannot be negative.', [], [], 400);
    }
    if ($amountPaid > $totalAmount) {
        sendResponse(false, 'Amount Paid cannot exceed Total Amount.', [], [], 400);
    }
}

$usedForMap = [
    'used for et' => 'ET',
    'et' => 'ET',
    'used for technician' => 'TECHNICIAN',
    'technician' => 'TECHNICIAN',
    'used for dealer' => 'DEALER',
    'dealer' => 'DEALER'
];

$normalizedUsedFor = strtoupper(str_replace([' ', '-'], '', strtolower($usedFor)));
foreach ($usedForMap as $key => $value) {
    if (str_replace([' ', '-'], '', strtolower($key)) === $normalizedUsedFor) {
        $normalizedUsedFor = $value;
        break;
    }
}

if (!in_array($normalizedUsedFor, ['ET', 'TECHNICIAN', 'DEALER'], true)) {
    sendResponse(false, "Invalid used_for value", [], [], 400);
}

$ensureUsageTransaction = static function ($conn, $ownerType, $ownerId, $usageType, $deviceId = null, $simId = null, $notes = '') {
    $isDevice = $deviceId !== null;
    $lookup = $isDevice
        ? $conn->prepare("SELECT usage_type FROM stock_transactions WHERE from_owner_type = ? AND from_owner_id = ? AND device_id = ? AND sim_id IS NULL AND transaction_type = 'USE' ORDER BY id DESC LIMIT 1")
        : $conn->prepare("SELECT usage_type FROM stock_transactions WHERE from_owner_type = ? AND from_owner_id = ? AND sim_id = ? AND device_id IS NULL AND transaction_type = 'USE' ORDER BY id DESC LIMIT 1");
    $assetId = $isDevice ? $deviceId : $simId;
    $lookup->bind_param('sii', $ownerType, $ownerId, $assetId);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if ($existing) {
        if (strtoupper((string) $existing['usage_type']) !== $usageType) {
            throw new Exception('Used stock cannot be changed to another usage type.');
        }
        $table = $isDevice ? 'devices' : 'sims';
        $statusUpdate = $conn->prepare("UPDATE {$table} SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $statusUpdate->bind_param('i', $assetId);
        $statusUpdate->execute();
        $statusUpdate->close();
        return;
    }

    $transaction = $isDevice
        ? $conn->prepare("INSERT INTO stock_transactions (device_id, sim_id, from_owner_type, from_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, NULL, ?, ?, 'USE', ?, CURDATE(), ?)")
        : $conn->prepare("INSERT INTO stock_transactions (device_id, sim_id, from_owner_type, from_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (NULL, ?, ?, ?, 'USE', ?, CURDATE(), ?)");
    $transaction->bind_param('isiss', $assetId, $ownerType, $ownerId, $usageType, $notes);
    if (!$transaction->execute()) {
        throw new Exception('Unable to persist Used for ET state.');
    }
    $transaction->close();

    $table = $isDevice ? 'devices' : 'sims';
    $statusUpdate = $conn->prepare("UPDATE {$table} SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $statusUpdate->bind_param('i', $assetId);
    if (!$statusUpdate->execute()) {
        throw new Exception('Unable to synchronize used stock status.');
    }
    $statusUpdate->close();
};

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$conn->begin_transaction();

try {
    if ($ownerType === 'dealer') {
        $check = $conn->prepare("SELECT id FROM dealers WHERE id = ? AND installation_status IN ('Onsite', 'Offsite')");
        $check->bind_param("i", $ownerId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Selected dealer is not valid");
        }
        $check->close();
    } else {
        $check = $conn->prepare("SELECT id FROM technicians WHERE id = ?");
        $check->bind_param("i", $ownerId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Selected technician is not valid");
        }
        $check->close();
    }

    $seenDeviceIds = [];
    $seenSimIds = [];
    $newDeviceCount = 0;
    $newSimCount = 0;
    $currentDeviceAllocations = [];
    $currentSimAllocations = [];

    $currentAllocations = $conn->prepare("SELECT id, device_id, sim_id FROM stock_allocations WHERE owner_type = ? AND owner_id = ? FOR UPDATE");
    $currentAllocations->bind_param("si", $ownerType, $ownerId);
    $currentAllocations->execute();
    $currentResult = $currentAllocations->get_result();
    while ($allocation = $currentResult->fetch_assoc()) {
        if (!empty($allocation['device_id'])) {
            $currentDeviceAllocations[(int) $allocation['device_id']] = (int) $allocation['id'];
        }
        if (!empty($allocation['sim_id'])) {
            $currentSimAllocations[(int) $allocation['sim_id']] = (int) $allocation['id'];
        }
    }
    $currentAllocations->close();

    foreach ($deviceIds as $deviceId) {
        if (isset($seenDeviceIds[$deviceId])) {
            throw new Exception("Device ID {$deviceId} was selected more than once.");
        }
        $seenDeviceIds[$deviceId] = true;

        $deviceStmt = $conn->prepare("SELECT id, imei_no, status FROM devices WHERE id = ? FOR UPDATE");
        $deviceStmt->bind_param("i", $deviceId);
        $deviceStmt->execute();
        $deviceRow = $deviceStmt->get_result()->fetch_assoc();
        $deviceStmt->close();

        if (!$deviceRow) {
            throw new Exception("Device ID {$deviceId} not found");
        }

        $existingAllocationStmt = $conn->prepare("SELECT owner_type, owner_id FROM stock_allocations WHERE device_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE");
        $existingAllocationStmt->bind_param("i", $deviceId);
        $existingAllocationStmt->execute();
        $existingAllocation = $existingAllocationStmt->get_result()->fetch_assoc();
        $existingAllocationStmt->close();

        if ($existingAllocation) {
            if (strtolower((string) $existingAllocation['owner_type']) !== $ownerType || (int) $existingAllocation['owner_id'] !== $ownerId) {
                throw new Exception("Device {$deviceRow['imei_no']} is already allocated to another Dealer/Technician and cannot be reallocated.");
            }

            if ($normalizedUsedFor === 'ET') {
                $ensureUsageTransaction($conn, $ownerType, $ownerId, $normalizedUsedFor, $deviceId, null, trim((string) ($deviceNotes[$deviceId] ?? '')));
            }

            // Update payment fields on existing allocation
            if ($totalAmount !== null) {
                $rowTotal = (float) $totalAmount;
                $rowPaid = (float) $amountPaid;
                $rowPending = max(0, $rowTotal - $rowPaid);
                $rowStatus = $rowTotal <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));
                $existingAllocId = $conn->prepare("SELECT id FROM stock_allocations WHERE device_id = ? AND owner_type = ? AND owner_id = ? ORDER BY id DESC LIMIT 1");
                $existingAllocId->bind_param('isi', $deviceId, $ownerType, $ownerId);
                $existingAllocId->execute();
                $allocRow = $existingAllocId->get_result()->fetch_assoc();
                $existingAllocId->close();
                if ($allocRow) {
                    $updatePayment = $conn->prepare("UPDATE stock_allocations SET total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, software = NULLIF(?, '') WHERE id = ?");
                    $updatePayment->bind_param('dddssi', $rowTotal, $rowPaid, $rowPending, $rowStatus, $software, $allocRow['id']);
                    $updatePayment->execute();
                    $updatePayment->close();
                }
            }

            continue;
        }

        if (strtolower((string) $deviceRow['status']) === 'used') {
            throw new Exception("Device {$deviceRow['imei_no']} is already marked as Used for Customer and cannot be reallocated.");
        }

        if (strtolower((string) $deviceRow['status']) !== 'available') {
            throw new Exception("Device {$deviceRow['imei_no']} is not available for allocation.");
        }

        $rowTotal = $totalAmount !== null ? $totalAmount : 0.0;
        $rowPaid = $amountPaid;
        $rowPending = max(0, $rowTotal - $rowPaid);
        $rowStatus = $rowTotal <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));

        if ($paymentStatus !== '' && $paymentStatus !== $rowStatus) {
            throw new Exception("Payment status must be {$rowStatus}.");
        }

        $notes = trim((string) ($deviceNotes[$deviceId] ?? ''));

        $insertAllocation = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, device_id, allocation_type, allocation_date, device_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, software, notes) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, NULL, ?, ?)");
        $insertAllocation->bind_param("siiddddsss", $ownerType, $ownerId, $deviceId, $ownerType, $rowTotal, $rowTotal, $rowPaid, $rowPending, $rowStatus, $software, $notes);
        if (!$insertAllocation->execute()) {
            throw new Exception("Failed to allocate device {$deviceRow['imei_no']}.");
        }
        $insertAllocation->close();

        $updateDevice = $conn->prepare("UPDATE devices SET status = 'allocated', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateDevice->bind_param("i", $deviceId);
        if (!$updateDevice->execute()) {
            throw new Exception("Failed to update device {$deviceRow['imei_no']} status.");
        }
        $updateDevice->close();

        $newDeviceCount++;

        if ($normalizedUsedFor === 'ET') {
            $ensureUsageTransaction($conn, $ownerType, $ownerId, $normalizedUsedFor, $deviceId, null, $notes);
        }
    }

    foreach ($simIds as $simId) {
        if (isset($seenSimIds[$simId])) {
            throw new Exception("SIM ID {$simId} was selected more than once.");
        }
        $seenSimIds[$simId] = true;

        $simStmt = $conn->prepare("SELECT id, sim_no, status FROM sims WHERE id = ? FOR UPDATE");
        $simStmt->bind_param("i", $simId);
        $simStmt->execute();
        $simRow = $simStmt->get_result()->fetch_assoc();
        $simStmt->close();

        if (!$simRow) {
            throw new Exception("SIM ID {$simId} not found");
        }

        $existingAllocationStmt = $conn->prepare("SELECT owner_type, owner_id FROM stock_allocations WHERE sim_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE");
        $existingAllocationStmt->bind_param("i", $simId);
        $existingAllocationStmt->execute();
        $existingAllocation = $existingAllocationStmt->get_result()->fetch_assoc();
        $existingAllocationStmt->close();

        if ($existingAllocation) {
            if (strtolower((string) $existingAllocation['owner_type']) !== $ownerType || (int) $existingAllocation['owner_id'] !== $ownerId) {
                throw new Exception("SIM {$simRow['sim_no']} is already allocated to another Dealer/Technician and cannot be reallocated.");
            }

            if ($normalizedUsedFor === 'ET') {
                $ensureUsageTransaction($conn, $ownerType, $ownerId, $normalizedUsedFor, null, $simId, trim((string) ($simNotes[$simId] ?? '')));
            }

            // Update payment fields on existing allocation
            if ($totalAmount !== null) {
                $rowTotal = (float) $totalAmount;
                $rowPaid = (float) $amountPaid;
                $rowPending = max(0, $rowTotal - $rowPaid);
                $rowStatus = $rowTotal <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));
                $existingAllocId = $conn->prepare("SELECT id FROM stock_allocations WHERE sim_id = ? AND owner_type = ? AND owner_id = ? ORDER BY id DESC LIMIT 1");
                $existingAllocId->bind_param('isi', $simId, $ownerType, $ownerId);
                $existingAllocId->execute();
                $allocRow = $existingAllocId->get_result()->fetch_assoc();
                $existingAllocId->close();
                if ($allocRow) {
                    $updatePayment = $conn->prepare("UPDATE stock_allocations SET total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, software = NULLIF(?, '') WHERE id = ?");
                    $updatePayment->bind_param('dddssi', $rowTotal, $rowPaid, $rowPending, $rowStatus, $software, $allocRow['id']);
                    $updatePayment->execute();
                    $updatePayment->close();
                }
            }

            continue;
        }

        if (strtolower((string) $simRow['status']) === 'used') {
            throw new Exception("SIM {$simRow['sim_no']} is already marked as Used for Customer and cannot be reallocated.");
        }

        if (strtolower((string) $simRow['status']) !== 'available') {
            throw new Exception("SIM {$simRow['sim_no']} is not available for allocation.");
        }

        $rowTotal = $totalAmount !== null ? $totalAmount : 0.0;
        $rowPaid = $amountPaid;
        $rowPending = max(0, $rowTotal - $rowPaid);
        $rowStatus = $rowTotal <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));

        if ($paymentStatus !== '' && $paymentStatus !== $rowStatus) {
            throw new Exception("Payment status must be {$rowStatus}.");
        }

        $notes = trim((string) ($simNotes[$simId] ?? ''));

        $insertAllocation = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, sim_id, allocation_type, allocation_date, sim_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, software, notes) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, NULL, ?, ?)");
        $insertAllocation->bind_param("siiddddsss", $ownerType, $ownerId, $simId, $ownerType, $rowTotal, $rowTotal, $rowPaid, $rowPending, $rowStatus, $software, $notes);
        if (!$insertAllocation->execute()) {
            throw new Exception("Failed to allocate SIM {$simRow['sim_no']}.");
        }
        $insertAllocation->close();

        $updateSim = $conn->prepare("UPDATE sims SET status = 'allocated', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateSim->bind_param("i", $simId);
        if (!$updateSim->execute()) {
            throw new Exception("Failed to update SIM {$simRow['sim_no']} status.");
        }
        $updateSim->close();

        $newSimCount++;

        if ($normalizedUsedFor === 'ET') {
            $ensureUsageTransaction($conn, $ownerType, $ownerId, $normalizedUsedFor, null, $simId, $notes);
        }
    }

    $conn->commit();

    sendResponse(true, "Stock updated successfully", [
        'data' => [
            'updated_device_count' => $newDeviceCount,
            'updated_sim_count' => $newSimCount
        ]
    ]);
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

$conn->close();
?>
