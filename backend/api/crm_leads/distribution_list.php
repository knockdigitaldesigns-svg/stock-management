<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once 'distribution_common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
requireLeadDistributionAccess($conn);

$assignmentStatus = trim((string) ($_GET['assignment_status'] ?? 'unassigned'));
if (!in_array($assignmentStatus, ['unassigned', 'assigned', 'all'], true)) {
    $conn->close();
    sendResponse(false, 'Invalid assignment status filter.', [], [], 400);
}
$search = trim((string) ($_GET['search'] ?? ''));
$pipelineId = trim((string) ($_GET['pipeline_id'] ?? ''));
$campaignId = trim((string) ($_GET['campaign_id'] ?? ''));
$leadSource = trim((string) ($_GET['lead_source'] ?? ''));
foreach (['pipeline_id' => $pipelineId, 'campaign_id' => $campaignId] as $field => $value) {
    if ($value !== '' && (!ctype_digit($value) || (int) $value <= 0)) {
        $conn->close();
        sendResponse(false, "Select a valid {$field}.", [], [], 400);
    }
}
$hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);

$fromSql =
    " FROM crm_leads l
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN users u ON u.id = l.assigned_user_id";
$where = [];
$types = '';
$params = [];
$addParam = static function ($type, $value) use (&$types, &$params) {
    $types .= $type;
    $params[] = $value;
};
if ($hasSoftDeleteSchema) {
    $where[] = 'l.deleted_at IS NULL';
}
if ($assignmentStatus === 'unassigned') {
    $where[] = 'l.assigned_user_id IS NULL';
} elseif ($assignmentStatus === 'assigned') {
    $where[] = 'l.assigned_user_id IS NOT NULL';
}
if ($search !== '') {
    $where[] = '(l.lead_name LIKE ? OR l.mobile_no LIKE ?)';
    $searchLike = '%' . $search . '%';
    $addParam('s', $searchLike);
    $addParam('s', $searchLike);
}
if ($pipelineId !== '') {
    $where[] = 'l.pipeline_id = ?';
    $addParam('i', (int) $pipelineId);
}
if ($campaignId !== '') {
    $where[] = 'l.campaign_id = ?';
    $addParam('i', (int) $campaignId);
}
if ($leadSource !== '') {
    $where[] = 'l.lead_source = ?';
    $addParam('s', $leadSource);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $conn->prepare('SELECT COUNT(*) AS total' . $fromSql . $whereSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead distribution count: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind lead distribution count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count leads for distribution: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$sql =
    "SELECT l.id, l.pipeline_id, l.campaign_id, l.lead_name, l.mobile_no,
            l.lead_source, l.priority, l.lead_status_id, l.assigned_user_id,
            l.created_at, p.pipeline_name, c.campaign_name,
            s.status_name AS lead_status, s.color AS lead_status_color,
            COALESCE(NULLIF(u.employee_name, ''), u.username, 'Unassigned') AS assigned_employee"
    . $fromSql . $whereSql . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare leads for distribution: ' . $error, [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$dataParams = $params;
$dataParams[] = $pageSize;
$dataParams[] = $offset;
$dataTypes = $types . 'ii';
$bindParams = [$dataTypes];
foreach ($dataParams as $index => $value) {
    $bindParams[] = &$dataParams[$index];
}
if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind lead distribution pagination: ' . $error, [], [], 500);
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load leads for distribution: ' . $error, [], [], 500);
}

$leads = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Leads for distribution loaded successfully.', [
    'leads' => $leads,
    'total' => $total,
    'assignment_status' => $assignmentStatus,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
