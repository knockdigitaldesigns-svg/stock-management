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

$summary = [
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
        $summary['total_devices'] += $count;

        if ($status === 'available') $summary['available_devices'] = $count;
        if ($status === 'allocated') $summary['allocated_devices'] = $count;
        if ($status === 'used') $summary['used_devices'] = $count;
    }
}

$simStatus = $conn->query("SELECT status, COUNT(*) as count FROM sims GROUP BY status");
if ($simStatus) {
    while ($row = $simStatus->fetch_assoc()) {
        $status = $row['status'];
        $count = (int) $row['count'];
        $summary['total_sims'] += $count;

        if ($status === 'available') $summary['available_sims'] = $count;
        if ($status === 'allocated') $summary['allocated_sims'] = $count;
        if ($status === 'used') $summary['used_sims'] = $count;
    }
}

$dealersCount = $conn->query("SELECT COUNT(*) as count FROM dealers");
if ($dealersCount && $dealersCount->num_rows > 0) {
    $summary['total_dealers'] = (int) $dealersCount->fetch_assoc()['count'];
}

$techCount = $conn->query("SELECT COUNT(*) as count FROM technicians");
if ($techCount && $techCount->num_rows > 0) {
    $summary['total_technicians'] = (int) $techCount->fetch_assoc()['count'];
}

$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM stock_allocations WHERE payment_status != 'Paid'");
if ($pendingPayments && $pendingPayments->num_rows > 0) {
    $summary['pending_payments'] = (int) $pendingPayments->fetch_assoc()['count'];
}

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
    ['name' => 'Available Devices', 'value' => $summary['available_devices'], 'fill' => '#10b981'],
    ['name' => 'Allocated Devices', 'value' => $summary['allocated_devices'], 'fill' => '#f59e0b'],
    ['name' => 'Used Devices', 'value' => $summary['used_devices'], 'fill' => '#3b82f6'],
    ['name' => 'Available SIMs', 'value' => $summary['available_sims'], 'fill' => '#14b8a6'],
    ['name' => 'Allocated SIMs', 'value' => $summary['allocated_sims'], 'fill' => '#f97316'],
    ['name' => 'Used SIMs', 'value' => $summary['used_sims'], 'fill' => '#8b5cf6']
];
$stockDistribution = array_values(array_filter($stockDistribution, fn($item) => $item['value'] > 0));

$monthlyAllocations = [];
$monthlySql = "
    SELECT
        DATE_FORMAT(allocation_date, '%b %Y') AS name,
        SUM(CASE WHEN device_id IS NOT NULL THEN 1 ELSE 0 END) AS devices,
        SUM(CASE WHEN sim_id IS NOT NULL THEN 1 ELSE 0 END) AS sims
    FROM stock_allocations
    GROUP BY YEAR(allocation_date), MONTH(allocation_date), DATE_FORMAT(allocation_date, '%b %Y')
    ORDER BY YEAR(allocation_date) DESC, MONTH(allocation_date) DESC
    LIMIT 12
";
$monthlyResult = $conn->query($monthlySql);
if ($monthlyResult) {
    while ($row = $monthlyResult->fetch_assoc()) {
        $monthlyAllocations[] = [
            'name' => $row['name'],
            'devices' => (int) $row['devices'],
            'sims' => (int) $row['sims']
        ];
    }
    $monthlyAllocations = array_reverse($monthlyAllocations);
}

sendResponse(true, "Dashboard stats fetched", [
    'metrics' => $summary,
    'recent_allocations' => $recentAllocations,
    'stock_distribution' => $stockDistribution,
    'monthly_allocations' => $monthlyAllocations
]);

$conn->close();
?>
