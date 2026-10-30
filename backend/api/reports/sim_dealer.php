<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, "Method not allowed", [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, "Database connection failed", [], [], 500);

$dealer_id = isset($_GET['dealer_id']) && $_GET['dealer_id'] !== '' ? (int) $_GET['dealer_id'] : null;

$sql = "
    SELECT 
        d.dealer_name,
        s.sim_no,
        s.sim_type,
        v.months AS sim_validity_months,
        s.purchase_date as sim_purchase_date,
        sa.sim_given_date,
        sa.sim_activation_date,
        sa.sim_expiry_date,
        COALESCE(sa.sim_status, 'Available') AS sim_status,
        sa.notes,
        sa.allocation_date
    FROM stock_allocations sa
    JOIN sims s ON sa.sim_id = s.id
    LEFT JOIN sim_validities v ON v.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
    JOIN dealers d ON sa.owner_type = 'dealer' AND sa.owner_id = d.id
";

$where = [];
$params = [];
$types = '';
if ($dealer_id) { $where[] = 'd.id = ?'; $params[] = $dealer_id; $types .= 'i'; }
if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
    $where[] = '(d.dealer_name LIKE ? OR s.sim_no LIKE ? OR s.sim_type LIKE ? OR sa.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (($simType = trim((string) ($_GET['sim_type'] ?? ''))) !== '') {
    $where[] = 'LOWER(s.sim_type) = ?';
    $params[] = strtolower($simType);
    $types .= 's';
}
if (($validity = (int) ($_GET['sim_validity'] ?? 0)) > 0) {
    $where[] = 'v.months = ?';
    $params[] = $validity;
    $types .= 'i';
}
if (($dateFrom = trim((string) ($_GET['date_from'] ?? ''))) !== '') {
    $where[] = 'sa.allocation_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if (($dateTo = trim((string) ($_GET['date_to'] ?? ''))) !== '') {
    $where[] = 'sa.allocation_date < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;
if ($paginated) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM stock_allocations sa JOIN sims s ON sa.sim_id = s.id LEFT JOIN sim_validities v ON v.id = COALESCE(sa.sim_validity_id, s.sim_validity_id) JOIN dealers d ON sa.owner_type = \'dealer\' AND sa.owner_id = d.id' . $whereSql);
    if (!$countStmt) { $conn->close(); sendResponse(false, 'Failed to prepare dealer SIM report count query.', [], [], 500); }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) { $countStmt->close(); $conn->close(); sendResponse(false, 'Failed to count dealer SIM report rows.', [], [], 500); }
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pagination = ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'total_pages' => max(1, (int) ceil($total / $pageSize))];
    $countStmt->close();
}
$sql .= $whereSql . ' ORDER BY sa.allocation_date DESC, sa.id DESC';
$listParams = $params;
$listTypes = $types;
if ($paginated) {
    $sql .= ' LIMIT ? OFFSET ?';
    $listParams[] = $pageSize;
    $listParams[] = ($page - 1) * $pageSize;
    $listTypes .= 'ii';
}
$stmt = $conn->prepare($sql);
if (!$stmt) { $conn->close(); sendResponse(false, 'Failed to prepare dealer SIM report query.', [], [], 500); }
if ($listParams) $stmt->bind_param($listTypes, ...$listParams);
if (!$stmt->execute()) { $stmt->close(); $conn->close(); sendResponse(false, 'Failed to fetch dealer SIM report.', [], [], 500); }
$report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
 $response = ['report' => $report];
if ($paginated) $response['pagination'] = $pagination;
sendResponse(true, "Dealer SIM report fetched", $response);
?>
