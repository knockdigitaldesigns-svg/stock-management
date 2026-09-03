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
$deviceIds = isset($data->device_ids) && is_array($data->device_ids) ? array_map('intval', $data->device_ids) : [];
$simIds = isset($data->sim_ids) && is_array($data->sim_ids) ? array_map('intval', $data->sim_ids) : [];
$deviceNotes = isset($data->device_notes) && is_array($data->device_notes) ? $data->device_notes : [];
$simNotes = isset($data->sim_notes) && is_array($data->sim_notes) ? $data->sim_notes : [];
$software = isset($data->software) ? trim((string) $data->software) : '';
$totalAmount = is_numeric($data->total_amount ?? null) ? (float) $data->total_amount : null;
$amountPaid = is_numeric($data->amount_paid ?? null) ? (float) $data->amount_paid : 0.0;
$paymentStatus = trim((string) ($data->payment_status ?? ''));
$allowedSoftware = ['Tracoo', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
if ($software !== '' && !in_array($software, $allowedSoftware, true)) sendResponse(false, 'Invalid software selection', [], [], 400);
if ($totalAmount !== null && ($totalAmount < 0 || $amountPaid < 0 || $amountPaid > $totalAmount)) sendResponse(false, 'Invalid payment amount', [], [], 400);

if (empty($usedFor) && isset($data->type) && isset($data->number)) {
    $usedFor = (string) $data->used_for;
}

if (empty($usedFor)) {
    sendResponse(false, "used_for is required", [], [], 400);
}

if (empty($ownerType) && isset($data->number) && isset($data->type)) {
    $ownerType = 'dealer';
    $ownerId = 0;
}

if ($ownerType !== null && !in_array($ownerType, ['dealer', 'technician'], true)) {
    sendResponse(false, "owner_type must be dealer or technician", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
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

$conn->begin_transaction();

try {
    if (!empty($data->number) && !empty($data->type)) {
        $deviceType = strtolower($data->type);
        $targetNumber = trim((string) $data->number);

        if ($deviceType === 'device') {
            $stmt = $conn->prepare("SELECT id, status FROM devices WHERE imei_no = ? FOR UPDATE");
            $stmt->bind_param("s", $targetNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) throw new Exception("Device not found");
            if ($result['status'] === 'used') throw new Exception("Device is already marked as used");

            $update = $conn->prepare("UPDATE devices SET status = 'used' WHERE id = ?");
            $update->bind_param("i", $result['id']);
            if (!$update->execute()) throw new Exception("Failed to update device status");
            $update->close();

            $conn->insert_id;
        } elseif ($deviceType === 'sim') {
            $stmt = $conn->prepare("SELECT id, status FROM sims WHERE sim_no = ? FOR UPDATE");
            $stmt->bind_param("s", $targetNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) throw new Exception("SIM not found");
            if ($result['status'] === 'used') throw new Exception("SIM is already marked as used");

            $update = $conn->prepare("UPDATE sims SET status = 'used' WHERE id = ?");
            $update->bind_param("i", $result['id']);
            if (!$update->execute()) throw new Exception("Failed to update SIM status");
            $update->close();
        } else {
            throw new Exception("Invalid type");
        }
    }

    $ownerSelected = !$ownerType || $ownerId === null || $ownerId === 0;
    if (!$ownerSelected) {
        if ($ownerType === 'dealer') {
            $check = $conn->prepare("SELECT id FROM dealers WHERE id = ? AND installation_status IN ('Onsite', 'Offsite')");
            $check->bind_param("i", $ownerId);
            $check->execute();
            if ($check->get_result()->num_rows === 0) throw new Exception("Selected dealer is not valid");
            $check->close();
        } else {
            $check = $conn->prepare("SELECT id FROM technicians WHERE id = ?");
            $check->bind_param("i", $ownerId);
            $check->execute();
            if ($check->get_result()->num_rows === 0) throw new Exception("Selected technician is not valid");
            $check->close();
        }
    }

    if (!empty($deviceIds)) {
        foreach ($deviceIds as $deviceId) {
            $deviceStmt = $conn->prepare("SELECT id, status, imei_no FROM devices WHERE id = ? FOR UPDATE");
            $deviceStmt->bind_param("i", $deviceId);
            $deviceStmt->execute();
            $deviceRow = $deviceStmt->get_result()->fetch_assoc();
            $deviceStmt->close();

            if (!$deviceRow) throw new Exception("Device ID {$deviceId} not found");
            if ($deviceRow['status'] === 'used') throw new Exception("Device {$deviceRow['imei_no']} is already marked as used");

            $updateDev = $conn->prepare("UPDATE devices SET status = 'used' WHERE id = ?");
            $updateDev->bind_param("i", $deviceId);
            if (!$updateDev->execute()) throw new Exception("Failed to update device {$deviceId}");
            $updateDev->close();

            $notes = trim((string) ($deviceNotes[$deviceId] ?? ''));
            if (!$ownerSelected) {
                $rowTotal = $totalAmount !== null ? $totalAmount : null;
                $rowPaid = $amountPaid;
                $rowPending = $rowTotal !== null ? $rowTotal - $rowPaid : null;
                $expectedStatus = $rowTotal === null ? null : ($rowPaid <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : 'Partially Paid'));
                if ($expectedStatus !== null && $paymentStatus !== '' && $paymentStatus !== $expectedStatus) throw new Exception("Payment status must be $expectedStatus.");
                if ($rowTotal !== null) { $paymentUpdate = $conn->prepare('UPDATE stock_allocations SET software = COALESCE(NULLIF(?, \'\'), software), total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ? WHERE owner_type = ? AND owner_id = ? AND device_id = ?'); $paymentUpdate->bind_param('sdddssii', $software, $rowTotal, $rowPaid, $rowPending, $expectedStatus, $ownerType, $ownerId, $deviceId); }
                else { $paymentUpdate = $conn->prepare('UPDATE stock_allocations SET software = COALESCE(NULLIF(?, \'\'), software) WHERE owner_type = ? AND owner_id = ? AND device_id = ?'); $paymentUpdate->bind_param('ssii', $software, $ownerType, $ownerId, $deviceId); }
                $paymentUpdate->execute(); $paymentUpdate->close();
            }
            $history = $conn->prepare("INSERT INTO stock_transactions (device_id, from_owner_type, from_owner_id, to_owner_type, to_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, ?, ?, ?, ?, 'USE', ?, CURDATE(), ?)");
            $history->bind_param("isisiss", $deviceId, $ownerType, $ownerId, $ownerType, $ownerId, $normalizedUsedFor, $notes);
            $history->execute();
            $history->close();
        }
    }

    if (!empty($simIds)) {
        foreach ($simIds as $simId) {
            $simStmt = $conn->prepare("SELECT id, status, sim_no FROM sims WHERE id = ? FOR UPDATE");
            $simStmt->bind_param("i", $simId);
            $simStmt->execute();
            $simRow = $simStmt->get_result()->fetch_assoc();
            $simStmt->close();

            if (!$simRow) throw new Exception("SIM ID {$simId} not found");
            if ($simRow['status'] === 'used') throw new Exception("SIM {$simRow['sim_no']} is already marked as used");

            $updateSim = $conn->prepare("UPDATE sims SET status = 'used' WHERE id = ?");
            $updateSim->bind_param("i", $simId);
            if (!$updateSim->execute()) throw new Exception("Failed to update SIM {$simId}");
            $updateSim->close();

            $notes = trim((string) ($simNotes[$simId] ?? ''));
            if (!$ownerSelected) {
                $rowTotal = $totalAmount !== null ? $totalAmount : null; $rowPaid = $amountPaid; $rowPending = $rowTotal !== null ? $rowTotal - $rowPaid : null;
                $expectedStatus = $rowTotal === null ? null : ($rowPaid <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : 'Partially Paid'));
                if ($expectedStatus !== null && $paymentStatus !== '' && $paymentStatus !== $expectedStatus) throw new Exception("Payment status must be $expectedStatus.");
                if ($rowTotal !== null) { $paymentUpdate = $conn->prepare('UPDATE stock_allocations SET software = COALESCE(NULLIF(?, \'\'), software), total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ? WHERE owner_type = ? AND owner_id = ? AND sim_id = ?'); $paymentUpdate->bind_param('sdddssii', $software, $rowTotal, $rowPaid, $rowPending, $expectedStatus, $ownerType, $ownerId, $simId); }
                else { $paymentUpdate = $conn->prepare('UPDATE stock_allocations SET software = COALESCE(NULLIF(?, \'\'), software) WHERE owner_type = ? AND owner_id = ? AND sim_id = ?'); $paymentUpdate->bind_param('ssii', $software, $ownerType, $ownerId, $simId); }
                $paymentUpdate->execute(); $paymentUpdate->close();
            }
            $history = $conn->prepare("INSERT INTO stock_transactions (sim_id, from_owner_type, from_owner_id, to_owner_type, to_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, ?, ?, ?, ?, 'USE', ?, CURDATE(), ?)");
            $history->bind_param("isisiss", $simId, $ownerType, $ownerId, $ownerType, $ownerId, $normalizedUsedFor, $notes);
            $history->execute();
            $history->close();
        }
    }

    $conn->commit();

    $summary = [
        'used_device' => 0,
        'available_device' => 0,
        'used_sim' => 0,
        'available_sim' => 0
    ];

    if ($ownerType && $ownerId) {
        $deviceSummary = $conn->query("SELECT COUNT(*) AS used_count FROM stock_allocations sa JOIN devices d ON d.id = sa.device_id WHERE sa.owner_type = '{$ownerType}' AND sa.owner_id = {$ownerId} AND d.status = 'used'");
        if ($deviceSummary) {
            $summary['used_device'] = (int) $deviceSummary->fetch_assoc()['used_count'];
        }

        $totalDeviceSummary = $conn->query("SELECT COUNT(*) AS total_count FROM stock_allocations sa WHERE sa.owner_type = '{$ownerType}' AND sa.owner_id = {$ownerId} AND sa.device_id IS NOT NULL");
        if ($totalDeviceSummary) {
            $totalDevices = (int) $totalDeviceSummary->fetch_assoc()['total_count'];
            $summary['available_device'] = max(0, $totalDevices - $summary['used_device']);
        }

        $simSummary = $conn->query("SELECT COUNT(*) AS used_count FROM stock_allocations sa JOIN sims s ON s.id = sa.sim_id WHERE sa.owner_type = '{$ownerType}' AND sa.owner_id = {$ownerId} AND s.status = 'used'");
        if ($simSummary) {
            $summary['used_sim'] = (int) $simSummary->fetch_assoc()['used_count'];
        }

        $totalSimSummary = $conn->query("SELECT COUNT(*) AS total_count FROM stock_allocations sa WHERE sa.owner_type = '{$ownerType}' AND sa.owner_id = {$ownerId} AND sa.sim_id IS NOT NULL");
        if ($totalSimSummary) {
            $totalSims = (int) $totalSimSummary->fetch_assoc()['total_count'];
            $summary['available_sim'] = max(0, $totalSims - $summary['used_sim']);
        }
    }

    sendResponse(true, "Stock updated successfully", ["data" => $summary]);
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

$conn->close();
?>
