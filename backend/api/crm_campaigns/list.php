<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../crm_leads/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_campaigns.view');
$userId = (int) ($currentUser['user_id'] ?? 0);
$isEmployee = crmLeadsIsEmployee($userId);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$hasManagerColumn = campaignManagerColumnExists($conn);
$hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);
$managerSelect = $hasManagerColumn
    ? "c.manager_user_id,
       COALESCE(NULLIF(m.employee_name, ''), m.username, 'Unassigned') AS managed_by"
    : "NULL AS manager_user_id, 'Unassigned' AS managed_by";
$managerJoin = $hasManagerColumn ? 'LEFT JOIN users m ON m.id = c.manager_user_id' : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$where = [];
$filterTypes = '';
$filterParams = [];
if ($search !== '') {
    $managedByExpression = $hasManagerColumn
        ? "COALESCE(NULLIF(m.employee_name, ''), m.username, 'Unassigned')"
        : "'Unassigned'";
    $where[] = "(c.campaign_name LIKE ? OR p.pipeline_name LIKE ? OR {$managedByExpression} LIKE ?)";
    $searchLike = '%' . $search . '%';
    array_push($filterParams, $searchLike, $searchLike, $searchLike);
    $filterTypes .= 'sss';
}
if ($statusFilter !== '') {
    $where[] = 'c.status = ?';
    $filterParams[] = $statusFilter;
    $filterTypes .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$statsQuery = $conn->prepare(
    "SELECT COUNT(*) AS total_campaigns,
            COALESCE(SUM(c.status = 'Active'), 0) AS active_campaigns,
            COALESCE(SUM(COALESCE(l.lead_count, 0)), 0) AS total_leads
     FROM crm_campaigns c
     INNER JOIN crm_pipelines p ON p.id = c.pipeline_id
     LEFT JOIN (
         SELECT campaign_id, COUNT(*) AS lead_count
         FROM crm_leads
         WHERE campaign_id IS NOT NULL" . ($hasSoftDeleteSchema ? ' AND deleted_at IS NULL' : '') . ($isEmployee ? ' AND assigned_user_id = ?' : '') . "
         GROUP BY campaign_id
     ) l ON l.campaign_id = c.id"
);
if (!$statsQuery) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare campaign summary: ' . $error, [], [], 500);
}
if ($isEmployee) {
    $statsQuery->bind_param('i', $userId);
}
if (!$statsQuery->execute()) {
    $error = $statsQuery->error;
    $statsQuery->close();
    $conn->close();
    sendResponse(false, 'Unable to load campaign summary: ' . $error, [], [], 500);
}
$stats = $statsQuery->get_result()->fetch_assoc() ?: [];
$statsQuery->close();
$stats = [
    'total_campaigns' => (int) ($stats['total_campaigns'] ?? 0),
    'active_campaigns' => (int) ($stats['active_campaigns'] ?? 0),
    'total_leads' => (int) ($stats['total_leads'] ?? 0)
];

$countSql =
    "SELECT COUNT(*) AS total
     FROM crm_campaigns c
     INNER JOIN crm_pipelines p ON p.id = c.pipeline_id
     {$managerJoin}{$whereSql}";
$countQuery = $conn->prepare($countSql);
if (!$countQuery) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare campaign count: ' . $error, [], [], 500);
}
if ($filterParams) {
    $countBindParams = [$filterTypes];
    foreach ($filterParams as $index => $value) {
        $countBindParams[] = &$filterParams[$index];
    }
    if (!call_user_func_array([$countQuery, 'bind_param'], $countBindParams)) {
        $error = $countQuery->error;
        $countQuery->close();
        $conn->close();
        sendResponse(false, 'Unable to bind campaign count filters: ' . $error, [], [], 500);
    }
}
if (!$countQuery->execute()) {
    $error = $countQuery->error;
    $countQuery->close();
    $conn->close();
    sendResponse(false, 'Unable to count campaigns: ' . $error, [], [], 500);
}
$total = (int) ($countQuery->get_result()->fetch_assoc()['total'] ?? 0);
$countQuery->close();

$query = $conn->prepare(
    "SELECT c.id, c.campaign_name, c.pipeline_id, {$managerSelect},
            p.pipeline_name, c.start_date, c.end_date,
            c.description, c.status, c.created_by_user_id,
            c.created_at, c.updated_at,
            COALESCE(l.lead_count, 0) AS total_leads
     FROM crm_campaigns c
     INNER JOIN crm_pipelines p ON p.id = c.pipeline_id
     {$managerJoin}
     LEFT JOIN (
         SELECT campaign_id, COUNT(*) AS lead_count
         FROM crm_leads
         WHERE campaign_id IS NOT NULL" . ($hasSoftDeleteSchema ? ' AND deleted_at IS NULL' : '') . ($isEmployee ? ' AND assigned_user_id = ?' : '') . "
         GROUP BY campaign_id
     ) l ON l.campaign_id = c.id
     {$whereSql}
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT ? OFFSET ?"
);

if (!$query) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load campaigns: ' . $error, [], [], 500);
}

if ($isEmployee || $filterParams) {
    $queryTypes = ($isEmployee ? 'i' : '') . $filterTypes . 'ii';
    $queryParams = [];
    if ($isEmployee) {
        $queryParams[] = $userId;
    }
    foreach ($filterParams as $value) {
        $queryParams[] = $value;
    }
    $queryParams[] = $pageSize;
    $queryParams[] = $offset;
    $queryBindParams = [$queryTypes];
    foreach ($queryParams as $index => $value) {
        $queryBindParams[] = &$queryParams[$index];
    }
    if (!call_user_func_array([$query, 'bind_param'], $queryBindParams)) {
        $error = $query->error;
        $query->close();
        $conn->close();
        sendResponse(false, 'Unable to bind campaign filters and pagination: ' . $error, [], [], 500);
    }
} else {
    $query->bind_param('ii', $pageSize, $offset);
}
if (!$query->execute()) {
    $error = $query->error;
    $query->close();
    $conn->close();
    sendResponse(false, 'Unable to load campaigns: ' . $error, [], [], 500);
}

$result = $query->get_result();
$campaigns = [];
while ($row = $result->fetch_assoc()) {
    $campaigns[] = $row;
}

$query->close();
$conn->close();

sendResponse(true, 'Campaigns loaded successfully.', [
    'campaigns' => $campaigns,
    'stats' => $stats,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
