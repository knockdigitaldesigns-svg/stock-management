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
        dl.dealer_name,
        d.imei_no,
        dm.device_type AS model_name,
        d.purchase_date as device_purchase_date,
        sa.notes,
        sa.allocation_date
    FROM stock_allocations sa
    JOIN devices d ON sa.device_id = d.id
    JOIN device_types dm ON d.device_model_id = dm.id
    JOIN dealers dl ON sa.owner_type = 'dealer' AND sa.owner_id = dl.id
";

$where = [];
$params = [];
$types = '';
if ($dealer_id) { $where[] = 'dl.id = ?'; $params[] = $dealer_id; $types .= 'i'; }
if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
    $where[] = '(dl.dealer_name LIKE ? OR d.imei_no LIKE ? OR dm.device_type LIKE ? OR sa.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (($deviceModel = trim((string) ($_GET['device_model'] ?? ''))) !== '') {
    $where[] = 'LOWER(dm.device_type) = ?';
    $params[] = strtolower($deviceModel);
    $types .= 's';
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
$paginated = true;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;
if ($paginated) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM stock_allocations sa JOIN devices d ON sa.device_id = d.id JOIN device_types dm ON d.device_model_id = dm.id JOIN dealers dl ON sa.owner_type = \'dealer\' AND sa.owner_id = dl.id' . $whereSql);
    if (!$countStmt) { $conn->close(); sendResponse(false, 'Failed to prepare dealer device report count query.', [], [], 500); }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) { $countStmt->close(); $conn->close(); sendResponse(false, 'Failed to count dealer device report rows.', [], [], 500); }
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
if (!$stmt) { $conn->close(); sendResponse(false, 'Failed to prepare dealer device report query.', [], [], 500); }
if ($listParams) $stmt->bind_param($listTypes, ...$listParams);
if (!$stmt->execute()) { $stmt->close(); $conn->close(); sendResponse(false, 'Failed to fetch dealer device report.', [], [], 500); }
$report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
 $response = ['report' => $report];
if ($paginated) $response['pagination'] = $pagination;
sendResponse(true, "Dealer device report fetched", $response);
?>
