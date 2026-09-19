<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
$currentUser = authenticate();
requireAnyPermission(['dealers.view']);

$db = new Database();
$conn = $db->getConnection();

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dealer_id = isset($_GET['dealer_id']) ? (int)$_GET['dealer_id'] : 0;
$sim_type = isset($_GET['sim_type']) ? trim($_GET['sim_type']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$given_date = isset($_GET['given_date']) ? trim($_GET['given_date']) : '';
$activation_date = isset($_GET['activation_date']) ? trim($_GET['activation_date']) : '';
$validity_id = isset($_GET['validity_id']) ? (int)$_GET['validity_id'] : 0;

$whereClauses = ["sa.owner_type = 'dealer'", "sa.sim_id IS NOT NULL"];
$params = [];
$types = '';

if ($search !== '') {
    $searchTerm = "%$search%";
    $whereClauses[] = "(d.dealer_name LIKE ? OR s.sim_no LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

if ($dealer_id > 0) {
    $whereClauses[] = "sa.owner_id = ?";
    $params[] = $dealer_id;
    $types .= 'i';
}

if ($sim_type !== '') {
    $whereClauses[] = "s.sim_type = ?";
    $params[] = $sim_type;
    $types .= 's';
}

if ($status !== '') {
    $whereClauses[] = "sa.sim_status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($given_date !== '') {
    $whereClauses[] = "DATE(sa.sim_given_date) = ?";
    $params[] = $given_date;
    $types .= 's';
}

if ($activation_date !== '') {
    $whereClauses[] = "DATE(sa.sim_activation_date) = ?";
    $params[] = $activation_date;
    $types .= 's';
}

if ($validity_id > 0) {
    $whereClauses[] = "sa.sim_validity_id = ?";
    $params[] = $validity_id;
    $types .= 'i';
}

$whereSql = "WHERE " . implode(' AND ', $whereClauses);

// Count total
$countQuery = "SELECT COUNT(*) as total FROM stock_allocations sa 
               JOIN sims s ON sa.sim_id = s.id 
               JOIN dealers d ON sa.owner_id = d.id 
               $whereSql";
$countStmt = $conn->prepare($countQuery);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalRecords / $limit);

// Fetch data
// We get the given_date from sim_given_date. If null, fallback to allocation_date.
$query = "SELECT 
            sa.id as allocation_id, 
            sa.sim_id,
            d.id as dealer_id,
            d.dealer_name,
            s.sim_no,
            s.sim_type,
            COALESCE(sa.sim_given_date, sa.allocation_date) as given_date,
            sa.sim_activation_date as activation_date,
            sa.sim_expiry_date as expiry_date,
            sa.sim_deactivation_date as deactivation_date,
            sa.sim_status,
            sa.sim_validity_id,
            v.months as validity_months
          FROM stock_allocations sa 
          JOIN sims s ON sa.sim_id = s.id 
          JOIN dealers d ON sa.owner_id = d.id
          LEFT JOIN sim_validities v ON sa.sim_validity_id = v.id 
          $whereSql 
          ORDER BY sa.id DESC 
          LIMIT ?, ?";

$params[] = $offset;
$params[] = $limit;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$sims = [];
while ($row = $result->fetch_assoc()) {
    $sims[] = $row;
}

$stmt->close();
$conn->close();

sendResponse(true, 'Data fetched successfully', [
    'sims' => $sims,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages
    ]
]);
?>
