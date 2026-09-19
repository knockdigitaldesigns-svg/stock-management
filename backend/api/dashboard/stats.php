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

// ---------------------------------------------------------
// 1. Calculate Available Financial Years & Default FY
// ---------------------------------------------------------
$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
$currentFyStartYear = ($currentMonth >= 4) ? $currentYear : $currentYear - 1;

$minDateSql = "
    SELECT MIN(d_min) as min_date, MAX(d_max) as max_date FROM (
        SELECT MIN(purchase_date) as d_min, MAX(purchase_date) as d_max FROM devices
        UNION ALL
        SELECT MIN(purchase_date) as d_min, MAX(purchase_date) as d_max FROM sims
        UNION ALL
        SELECT MIN(allocation_date) as d_min, MAX(allocation_date) as d_max FROM stock_allocations
        UNION ALL
        SELECT MIN(enrolled_date) as d_min, MAX(enrolled_date) as d_max FROM dealers
        UNION ALL
        SELECT MIN(enrolled_date) as d_min, MAX(enrolled_date) as d_max FROM technicians
    ) as all_dates
";
$minDateRes = $conn->query($minDateSql);
$minYear = $currentFyStartYear;
$maxYear = $currentFyStartYear;

if ($minDateRes && $row = $minDateRes->fetch_assoc()) {
    if (!empty($row['min_date'])) {
        $mDate = strtotime($row['min_date']);
        $mY = (int)date('Y', $mDate);
        $mM = (int)date('n', $mDate);
        $minFyStart = ($mM >= 4) ? $mY : $mY - 1;
        if ($minFyStart < $minYear) {
            $minYear = $minFyStart;
        }
    }
    if (!empty($row['max_date'])) {
        $mxDate = strtotime($row['max_date']);
        $mxY = (int)date('Y', $mxDate);
        $mxM = (int)date('n', $mxDate);
        $maxFyStart = ($mxM >= 4) ? $mxY : $mxY - 1;
        if ($maxFyStart > $maxYear) {
            $maxYear = $maxFyStart;
        }
    }
}

$availableFinancialYears = [];
for ($y = $maxYear; $y >= $minYear; $y--) {
    $availableFinancialYears[] = "F.Y. " . $y . "-" . ($y + 1);
}

// ---------------------------------------------------------
// 2. Parse Requested Filters
// ---------------------------------------------------------
$reqFy = isset($_GET['financial_year']) ? trim($_GET['financial_year']) : '';
$reqStartDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$reqEndDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

// Derive FY bounds
if (preg_match('/(\d{4})-(\d{4})/', $reqFy, $matches)) {
    $fyStartYear = (int)$matches[1];
    $fyEndYear = (int)$matches[2];
} else {
    $fyStartYear = $currentFyStartYear;
    $fyEndYear = $currentFyStartYear + 1;
}

$fyStartDate = sprintf("%04d-04-01", $fyStartYear);
$fyEndDate = sprintf("%04d-03-31", $fyEndYear);

// Effective date bounds
$effStart = $fyStartDate;
$effEnd = $fyEndDate;

if (!empty($reqStartDate)) {
    if (strtotime($reqStartDate) > strtotime($effStart)) {
        $effStart = $reqStartDate;
    }
}
if (!empty($reqEndDate)) {
    if (strtotime($reqEndDate) < strtotime($effEnd)) {
        $effEnd = $reqEndDate;
    }
}

$isInvalidRange = (strtotime($effStart) > strtotime($effEnd));

$effStartEsc = $conn->real_escape_string($effStart);
$effEndEsc = $conn->real_escape_string($effEnd);

if (empty($reqStartDate) && empty($reqEndDate)) {
    // Return all records if no explicit dates are supplied
    $deviceWhere = "WHERE 1=1";
    $simWhere = "WHERE 1=1";
    $dealerWhere = "WHERE 1=1";
    $techWhere = "WHERE 1=1";
    $allocWhere = "WHERE 1=1";
} else {
    $deviceWhere = $isInvalidRange ? "WHERE 1=0" : "WHERE purchase_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
    $simWhere = $isInvalidRange ? "WHERE 1=0" : "WHERE purchase_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
    $dealerWhere = $isInvalidRange ? "WHERE 1=0" : "WHERE enrolled_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
    $techWhere = $isInvalidRange ? "WHERE 1=0" : "WHERE enrolled_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
    $allocWhere = $isInvalidRange ? "WHERE 1=0" : "WHERE allocation_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
}

// ---------------------------------------------------------
// 3. Fetch Metrics & Data
// ---------------------------------------------------------
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

$deviceStatus = $conn->query("SELECT status, COUNT(*) as count FROM devices $deviceWhere GROUP BY status");
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

$simStatus = $conn->query("SELECT status, COUNT(*) as count FROM sims $simWhere GROUP BY status");
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

$dealersCount = $conn->query("SELECT COUNT(*) as count FROM dealers $dealerWhere");
if ($dealersCount && $dealersCount->num_rows > 0) {
    $summary['total_dealers'] = (int) $dealersCount->fetch_assoc()['count'];
}

$techCount = $conn->query("SELECT COUNT(*) as count FROM technicians $techWhere");
if ($techCount && $techCount->num_rows > 0) {
    $summary['total_technicians'] = (int) $techCount->fetch_assoc()['count'];
}

$pendingPaymentsWhere = "WHERE payment_status != 'Paid'";
if (!empty($reqStartDate) || !empty($reqEndDate)) {
    $pendingPaymentsWhere .= $isInvalidRange ? " AND 1=0" : " AND allocation_date BETWEEN '$effStartEsc' AND '$effEndEsc'";
}
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM stock_allocations $pendingPaymentsWhere");
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
    $allocWhere
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
    $allocWhere
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
    'monthly_allocations' => $monthlyAllocations,
    'available_financial_years' => $availableFinancialYears,
    'active_financial_year' => "F.Y. " . $fyStartYear . "-" . $fyEndYear,
    'effective_start_date' => $effStart,
    'effective_end_date' => $effEnd
]);

$conn->close();
?>
