<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();
$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$technicianId = isset($_GET['technician_id']) && $_GET['technician_id'] !== '' ? (int) $_GET['technician_id'] : null;
$sql = "SELECT t.technician_name, d.imei_no, dm.device_type AS model_name, d.purchase_date AS device_purchase_date, sa.notes, sa.allocation_date FROM stock_allocations sa JOIN devices d ON sa.device_id = d.id JOIN device_types dm ON d.device_model_id = dm.id JOIN technicians t ON sa.owner_type = 'technician' AND sa.owner_id = t.id";
$where = [];
$params = [];
$types = '';
if ($technicianId) { $where[] = 't.id = ?'; $params[] = $technicianId; $types .= 'i'; }
if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
    $where[] = '(t.technician_name LIKE ? OR d.imei_no LIKE ? OR dm.device_type LIKE ? OR sa.notes LIKE ?)';
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
if (($dateTo = trim((string) ($_GET['date_to'] ?? '')) ) !== '') {
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
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM stock_allocations sa JOIN devices d ON sa.device_id = d.id JOIN device_types dm ON d.device_model_id = dm.id JOIN technicians t ON sa.owner_type = \'technician\' AND sa.owner_id = t.id' . $whereSql);
    if (!$countStmt) { $conn->close(); sendResponse(false, 'Failed to prepare technician device report count query.', [], [], 500); }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) { $countStmt->close(); $conn->close(); sendResponse(false, 'Failed to count technician device report rows.', [], [], 500); }
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
if (!$stmt) { $conn->close(); sendResponse(false, 'Failed to prepare technician device report query.', [], [], 500); }
if ($listParams) $stmt->bind_param($listTypes, ...$listParams);
if (!$stmt->execute()) { $stmt->close(); $conn->close(); sendResponse(false, 'Failed to fetch technician device report.', [], [], 500); }
$report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$response = ['report' => $report];
if ($paginated) $response['pagination'] = $pagination;
sendResponse(true, 'Technician device report fetched', $response);
?>
