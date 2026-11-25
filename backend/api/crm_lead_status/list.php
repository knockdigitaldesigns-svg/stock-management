<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('crm_lead_status.view');

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
    $where[] = '(status_name LIKE ? OR status_type LIKE ? OR color LIKE ? OR CAST(display_order AS CHAR) LIKE ? OR status LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM crm_lead_status_master' . $whereSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead status count: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind lead status count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count lead statuses: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$stmt = $conn->prepare(
    'SELECT id, status_name, status_type, color, display_order, status,
            created_by_user_id, created_at, updated_at
     FROM crm_lead_status_master
     ' . $whereSql . '
     ORDER BY display_order ASC, status_name ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load lead statuses: ' . $error, [], [], 500);
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
    sendResponse(false, 'Unable to load lead statuses: ' . $error, [], [], 500);
}

$result = $stmt->get_result();
$statuses = [];
while ($row = $result->fetch_assoc()) {
    $statuses[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'Lead statuses loaded successfully.', [
    'statuses' => $statuses,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
