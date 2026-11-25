<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('crm_pipelines.view');

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
    $where[] = '(p.pipeline_name LIKE ? OR p.description LIKE ? OR p.status LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($statusFilter !== '') {
    $where[] = 'p.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM crm_pipelines p' . $whereSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare pipeline count: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind pipeline count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count pipeline records: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$query = $conn->prepare(
    'SELECT p.id, p.pipeline_name, p.description, p.status, p.display_order,
            p.created_by_user_id, p.created_at, p.updated_at,
            u.employee_name AS created_by_name
     FROM crm_pipelines p
     LEFT JOIN users u ON u.id = p.created_by_user_id
     ' . $whereSql . '
     ORDER BY p.display_order ASC, p.pipeline_name ASC, p.id ASC
     LIMIT ? OFFSET ?'
);

if (!$query) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare pipeline records: ' . $error, [], [], 500);
}
if ($params) {
    $dataParams = $params;
    $dataParams[] = $pageSize;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';
    $bindParams = [$dataTypes];
    foreach ($dataParams as $index => $value) {
        $bindParams[] = &$dataParams[$index];
    }
    $bound = call_user_func_array([$query, 'bind_param'], $bindParams);
} else {
    $bound = $query->bind_param('ii', $pageSize, $offset);
}
if (!$bound || !$query->execute()) {
    $error = $query->error;
    $query->close();
    $conn->close();
    sendResponse(false, 'Unable to load pipeline records: ' . $error, [], [], 500);
}

$result = $query->get_result();
$rows = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$query->close();
$conn->close();

sendResponse(true, 'Pipeline records loaded successfully.', [
    'pipelines' => $rows,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
