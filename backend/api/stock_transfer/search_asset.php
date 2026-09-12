<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('stock_transfer.view');

$query = isset($_GET['query']) ? trim((string) $_GET['query']) : '';

if ($query === '') {
    sendResponse(false, 'Please enter an IMEI or SIM number to search.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

// 1. Try devices by IMEI
$devStmt = $conn->prepare("
    SELECT d.id, d.imei_no, d.status, dt.device_type AS device_model
    FROM devices d
    LEFT JOIN device_types dt ON dt.id = d.device_model_id
    WHERE d.imei_no = ?
    LIMIT 1
");
$devStmt->bind_param('s', $query);
$devStmt->execute();
$device = $devStmt->get_result()->fetch_assoc();
$devStmt->close();

if ($device) {
    $status = strtolower((string)($device['status'] ?? ''));

    if ($status === 'available') {
        sendResponse(false, 'This asset is available and is not currently allocated.', ['status_type' => 'available'], [], 400);
    }
    if ($status === 'used') {
        sendResponse(false, 'This asset is already used and cannot be transferred.', ['status_type' => 'used'], [], 400);
    }
    if ($status !== 'allocated') {
        sendResponse(false, 'This asset cannot be transferred because it is not currently allocated.', ['status_type' => 'not_allocated'], [], 400);
    }

    $allocStmt = $conn->prepare("
        SELECT sa.id AS allocation_id, sa.owner_type, sa.owner_id,
               d2.dealer_name, d2.installation_status AS dealer_installation_status,
               t.technician_name
        FROM stock_allocations sa
        LEFT JOIN dealers d2 ON d2.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
        WHERE sa.device_id = ?
        ORDER BY sa.id DESC
        LIMIT 1
    ");
    $allocStmt->bind_param('i', $device['id']);
    $allocStmt->execute();
    $alloc = $allocStmt->get_result()->fetch_assoc();
    $allocStmt->close();

    if (!$alloc) {
        sendResponse(false, 'This asset cannot be transferred because it is not currently allocated.', ['status_type' => 'not_allocated'], [], 400);
    }

    $ownerName = $alloc['owner_type'] === 'dealer' ? $alloc['dealer_name'] : $alloc['technician_name'];
    if (empty($ownerName)) {
        sendResponse(false, 'This asset cannot be transferred because its owner could not be found.', [], [], 400);
    }
    if ($alloc['owner_type'] === 'dealer' && ($alloc['dealer_installation_status'] ?? '') === 'Not Willing') {
        sendResponse(false, 'This asset cannot be transferred because the current owner is Not Willing.', [], [], 400);
    }

    $conn->close();
    sendResponse(true, 'Allocated device found', [
        'asset' => [
            'asset_type'   => 'device',
            'device_id'    => (int)$device['id'],
            'sim_id'       => null,
            'imei_no'      => $device['imei_no'],
            'sim_no'       => '-',
            'device_model' => $device['device_model'] ?? 'N/A',
            'owner_type'   => $alloc['owner_type'],
            'owner_type_display' => ucfirst($alloc['owner_type']),
            'owner_id'     => (int)$alloc['owner_id'],
            'owner_name'   => $ownerName,
            'status'       => 'Allocated',
            'allocation_id'=> (int)$alloc['allocation_id']
        ]
    ]);
}

// 2. Try SIMs by SIM number
$simStmt = $conn->prepare("
    SELECT s.id, s.sim_no, s.sim_type, s.status
    FROM sims s
    WHERE s.sim_no = ?
    LIMIT 1
");
$simStmt->bind_param('s', $query);
$simStmt->execute();
$sim = $simStmt->get_result()->fetch_assoc();
$simStmt->close();

if ($sim) {
    $status = strtolower((string)($sim['status'] ?? ''));

    if ($status === 'available') {
        sendResponse(false, 'This asset is available and is not currently allocated.', ['status_type' => 'available'], [], 400);
    }
    if ($status === 'used') {
        sendResponse(false, 'This asset is already used and cannot be transferred.', ['status_type' => 'used'], [], 400);
    }
    if ($status !== 'allocated') {
        sendResponse(false, 'This asset cannot be transferred because it is not currently allocated.', ['status_type' => 'not_allocated'], [], 400);
    }

    $allocStmt = $conn->prepare("
        SELECT sa.id AS allocation_id, sa.owner_type, sa.owner_id,
               d.dealer_name, d.installation_status AS dealer_installation_status,
               t.technician_name
        FROM stock_allocations sa
        LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
        WHERE sa.sim_id = ?
        ORDER BY sa.id DESC
        LIMIT 1
    ");
    $allocStmt->bind_param('i', $sim['id']);
    $allocStmt->execute();
    $alloc = $allocStmt->get_result()->fetch_assoc();
    $allocStmt->close();

    if (!$alloc) {
        sendResponse(false, 'This asset cannot be transferred because it is not currently allocated.', ['status_type' => 'not_allocated'], [], 400);
    }

    $ownerName = $alloc['owner_type'] === 'dealer' ? $alloc['dealer_name'] : $alloc['technician_name'];
    if (empty($ownerName)) {
        sendResponse(false, 'This asset cannot be transferred because its owner could not be found.', [], [], 400);
    }
    if ($alloc['owner_type'] === 'dealer' && ($alloc['dealer_installation_status'] ?? '') === 'Not Willing') {
        sendResponse(false, 'This asset cannot be transferred because the current owner is Not Willing.', [], [], 400);
    }

    $conn->close();
    sendResponse(true, 'Allocated SIM found', [
        'asset' => [
            'asset_type'   => 'sim',
            'device_id'    => null,
            'sim_id'       => (int)$sim['id'],
            'imei_no'      => '-',
            'sim_no'       => $sim['sim_no'],
            'sim_type'     => $sim['sim_type'] ?? 'N/A',
            'owner_type'   => $alloc['owner_type'],
            'owner_type_display' => ucfirst($alloc['owner_type']),
            'owner_id'     => (int)$alloc['owner_id'],
            'owner_name'   => $ownerName,
            'status'       => 'Allocated',
            'allocation_id'=> (int)$alloc['allocation_id']
        ]
    ]);
}

$conn->close();
sendResponse(false, 'Device/SIM not found.', ['status_type' => 'not_found'], [], 404);
?>
