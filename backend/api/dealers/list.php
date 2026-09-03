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

$whereClause = "";
if (isset($_GET['for_alert']) && $_GET['for_alert'] === '1') {
    $whereClause = "WHERE LOWER(TRIM(d.installation_status)) IN ('onsite', 'offsite')";
} elseif (isset($_GET['installation_status']) && trim((string)$_GET['installation_status']) !== '') {
    $rawStatuses = explode(',', trim((string)$_GET['installation_status']));
    $cleanStatuses = [];
    foreach ($rawStatuses as $st) {
        $stClean = trim(strtolower($st));
        if ($stClean !== '') {
            $cleanStatuses[] = "'" . $conn->real_escape_string($stClean) . "'";
        }
    }
    if (!empty($cleanStatuses)) {
        $whereClause = "WHERE LOWER(TRIM(d.installation_status)) IN (" . implode(',', $cleanStatuses) . ")";
    }
}

// Calculate Device and SIM counts dynamically for dealers
$sql = "
    SELECT 
        d.id, 
        d.dealer_name, 
        d.mobile_no, 
        d.location, 
        d.enrolled_date, 
        d.installation_status,
        d.software,
        d.notes,
        COALESCE((SELECT COUNT(*) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND device_id IS NOT NULL), 0) as device_count,
        COALESCE((SELECT COUNT(*) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND sim_id IS NOT NULL), 0) as sim_count,
        COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) as total_amount,
        COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) as amount_paid,
        GREATEST(0, COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) - COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0)) as pending_amount,
        CASE
            WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) <= 0 THEN NULL
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) THEN 'Paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) > 0 THEN 'Partially Paid'
            ELSE 'Not Paid'
        END as payment_status
    FROM dealers d
    $whereClause
    ORDER BY d.created_at DESC
";
$result = $conn->query($sql);

$dealers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dealers[] = $row;
    }
}

sendResponse(true, "Dealers fetched successfully", ["dealers" => $dealers]);

$conn->close();
?>
