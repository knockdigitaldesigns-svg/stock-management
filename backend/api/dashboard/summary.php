<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$metrics = [
    'total_devices' => 0,
    'available_devices' => 0,
    'allocated_devices' => 0,
    'used_devices' => 0,
    'total_sims' => 0,
    'available_sims' => 0,
    'allocated_sims' => 0,
    'used_sims' => 0,
    'total_dealers' => 0,
    'total_technicians' => 0,
    'pending_payments' => 0
];

$deviceStatus = $conn->query("SELECT status, COUNT(*) as count FROM devices GROUP BY status");
if ($deviceStatus) {
    while ($row = $deviceStatus->fetch_assoc()) {
        $status = $row['status'];
        $count = (int) $row['count'];
        $metrics['total_devices'] += $count;

        if ($status === 'available') $metrics['available_devices'] = $count;
        if ($status === 'allocated') $metrics['allocated_devices'] = $count;
        if ($status === 'used') $metrics['used_devices'] = $count;
    }
}

$simStatus = $conn->query("SELECT status, COUNT(*) as count FROM sims GROUP BY status");
if ($simStatus) {
    while ($row = $simStatus->fetch_assoc()) {
        $status = $row['status'];
        $count = (int) $row['count'];
        $metrics['total_sims'] += $count;

        if ($status === 'available') $metrics['available_sims'] = $count;
        if ($status === 'allocated') $metrics['allocated_sims'] = $count;
        if ($status === 'used') $metrics['used_sims'] = $count;
    }
}

$dealersCount = $conn->query("SELECT COUNT(*) as count FROM dealers");
if ($dealersCount && $dealersCount->num_rows > 0) {
    $metrics['total_dealers'] = (int) $dealersCount->fetch_assoc()['count'];
}

$techCount = $conn->query("SELECT COUNT(*) as count FROM technicians");
if ($techCount && $techCount->num_rows > 0) {
    $metrics['total_technicians'] = (int) $techCount->fetch_assoc()['count'];
}

$pendingPayments = 0;

/*
|--------------------------------------------------------------------------
| Customer Pending Payments
|--------------------------------------------------------------------------
*/

$customerPendingResult = $conn->query("
    SELECT COUNT(*) AS count
    FROM customer_payments
    WHERE payment_status IN (
        'Pending',
        'Not Paid',
        'Partially Paid'
    )
");

if ($customerPendingResult && $customerPendingResult->num_rows > 0) {
    $pendingPayments += (int) $customerPendingResult
        ->fetch_assoc()['count'];
}

/*
|--------------------------------------------------------------------------
| Dealer + Technician Pending Payments
|--------------------------------------------------------------------------
*/

$allocationPendingResult = $conn->query("
    SELECT COUNT(*) AS count
    FROM stock_allocations
    WHERE payment_status <> 'Paid'
");

if ($allocationPendingResult && $allocationPendingResult->num_rows > 0) {
    $pendingPayments += (int) $allocationPendingResult
        ->fetch_assoc()['count'];
}

$metrics['pending_payments'] = $pendingPayments;

$recentAllocations = [];
$recentSql = "
    SELECT 
        sa.allocation_date as date,
        CASE 
            WHEN sa.owner_type = 'dealer' THEN d.dealer_name
            ELSE t.technician_name
        END as owner,
        CASE 
            WHEN sa.device_id IS NOT NULL THEN (SELECT imei_no FROM devices WHERE id = sa.device_id)
            ELSE (SELECT sim_no FROM sims WHERE id = sa.sim_id)
        END as item,
        sa.owner_type as status
    FROM stock_allocations sa
    LEFT JOIN dealers d ON sa.owner_type = 'dealer' AND sa.owner_id = d.id
    LEFT JOIN technicians t ON sa.owner_type = 'technician' AND sa.owner_id = t.id
    ORDER BY sa.created_at DESC
    LIMIT 5
";
$recentResult = $conn->query($recentSql);
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentAllocations[] = $row;
    }
}

$stockDistribution = [
    ['name' => 'Available Devices', 'value' => $metrics['available_devices'], 'fill' => '#10b981'],
    ['name' => 'Allocated Devices', 'value' => $metrics['allocated_devices'], 'fill' => '#f59e0b'],
    ['name' => 'Used Devices', 'value' => $metrics['used_devices'], 'fill' => '#3b82f6'],
    ['name' => 'Available SIMs', 'value' => $metrics['available_sims'], 'fill' => '#14b8a6'],
    ['name' => 'Allocated SIMs', 'value' => $metrics['allocated_sims'], 'fill' => '#f97316'],
    ['name' => 'Used SIMs', 'value' => $metrics['used_sims'], 'fill' => '#8b5cf6']
];

sendResponse(true, "Dashboard summary fetched", [
    'metrics' => $metrics,
    'recent_allocations' => $recentAllocations,
    'stock_distribution' => $stockDistribution
]);

$conn->close();
?>
