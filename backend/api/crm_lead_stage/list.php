<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission([
    'crm_lead_stage.view',
    'crm_lead_stage.add',
    'crm_lead_stage.edit',
    'crm_lead_stage.activate'
]);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = '(stage_name LIKE ? OR description LIKE ? OR CAST(display_order AS CHAR) LIKE ? OR status LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM crm_lead_stage_master' . $whereSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead stage count: ' . $error, [], [], 500);
}
if ($params) {
    $countBindParams = [$types];
    foreach ($params as $index => $value) {
        $countBindParams[] = &$params[$index];
    }
    if (!call_user_func_array([$countStmt, 'bind_param'], $countBindParams)) {
        $error = $countStmt->error;
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to bind lead stage count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count lead stages: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$stmt = $conn->prepare(
    'SELECT id, stage_name, description, display_order, status,
            created_by_user_id, created_at, updated_at
     FROM crm_lead_stage_master
     ' . $whereSql . '
     ORDER BY display_order ASC, stage_name ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead stages: ' . $error, [], [], 500);
}
$dataParams = $params;
$dataParams[] = $pageSize;
$dataParams[] = $offset;
$dataTypes = $types . 'ii';
$bindParams = [$dataTypes];
foreach ($dataParams as $index => $value) {
   $bindParams[] = &$dataParams[$index];
}
if (!call_user_func_array([$stmt, 'bind_param'], $bindParams) || !$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load lead stages: ' . $error, [], [], 500);
}

$stages = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stages[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead stages loaded successfully.', [
    'stages' => $stages,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
