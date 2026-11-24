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

$campaignId = (int) ($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    sendResponse(false, 'Campaign id is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$canViewAllLeads = crmLeadsCanViewAll($userId, $conn);
$restrictLeadScope = !$canViewAllLeads;
$closureReasonColumnResult = $conn->query("SHOW COLUMNS FROM crm_leads LIKE 'closure_reason'");
if (!$closureReasonColumnResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify lead outcome schema: ' . $error, [], [], 500);
}
$closureReasonSelect = $closureReasonColumnResult->num_rows > 0
    ? 'l.closure_reason'
    : 'NULL AS closure_reason';
$hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);
$hasManagerColumn = campaignManagerColumnExists($conn);
$managerSelect = $hasManagerColumn
    ? "c.manager_user_id,
       COALESCE(NULLIF(m.employee_name, ''), m.username, 'Unassigned') AS managed_by"
    : "NULL AS manager_user_id, 'Unassigned' AS managed_by";
$managerJoin = $hasManagerColumn ? 'LEFT JOIN users m ON m.id = c.manager_user_id' : '';
$managerGroupBy = $hasManagerColumn ? ', c.manager_user_id, m.employee_name, m.username' : '';

$summary = $conn->prepare(
    "SELECT c.id, c.campaign_name, c.pipeline_id,
            {$managerSelect},
            p.pipeline_name, c.start_date, c.end_date,
            c.description, c.status, c.created_at,
            COUNT(l.id) AS total_leads,
            COALESCE(SUM(CASE WHEN l.assigned_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned_leads,
            COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND l.assigned_user_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_leads
     FROM crm_campaigns c
     INNER JOIN crm_pipelines p ON p.id = c.pipeline_id
     {$managerJoin}
     LEFT JOIN crm_leads l ON l.campaign_id = c.id" . ($hasSoftDeleteSchema ? ' AND l.deleted_at IS NULL' : '') . ($restrictLeadScope ? ' AND l.assigned_user_id = ?' : '') . "
     WHERE c.id = ?
     GROUP BY c.id, c.campaign_name, c.pipeline_id,
              p.pipeline_name, c.start_date, c.end_date,
              c.description, c.status, c.created_at{$managerGroupBy}"
);
if (!$summary) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load campaign report: ' . $error, [], [], 500);
}
if ($restrictLeadScope) {
    $summary->bind_param('ii', $userId, $campaignId);
} else {
    $summary->bind_param('i', $campaignId);
}
if (!$summary->execute()) {
    $error = $summary->error;
    $summary->close();
    $conn->close();
    sendResponse(false, 'Unable to load campaign report: ' . $error, [], [], 500);
}
$campaign = $summary->get_result()->fetch_assoc();
$summary->close();
if (!$campaign) {
    $conn->close();
    sendResponse(false, 'Campaign not found.', [], [], 404);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$totalLeads = (int) ($campaign['total_leads'] ?? 0);
$leadsQuery = $conn->prepare(
    "SELECT l.id, l.lead_name, l.mobile_no, l.email, l.location,
            l.lead_source, {$closureReasonSelect}, c.campaign_name,
            COALESCE(u.employee_name, u.username) AS assigned_employee,
            s.status_name AS lead_status, st.stage_name AS lead_stage_name, l.created_at
     FROM crm_leads l
     INNER JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_lead_stage_master st ON st.id = l.lead_stage_id
     WHERE l.campaign_id = ?" . ($hasSoftDeleteSchema ? ' AND l.deleted_at IS NULL' : '') . ($restrictLeadScope ? ' AND l.assigned_user_id = ?' : '') . "
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT ? OFFSET ?"
);
if (!$leadsQuery) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load campaign leads: ' . $error, [], [], 500);
}
if ($restrictLeadScope) {
    $leadsQuery->bind_param('iiii', $campaignId, $userId, $pageSize, $offset);
} else {
    $leadsQuery->bind_param('iii', $campaignId, $pageSize, $offset);
}
if (!$leadsQuery->execute()) {
    $error = $leadsQuery->error;
    $leadsQuery->close();
    $conn->close();
    sendResponse(false, 'Unable to load campaign leads: ' . $error, [], [], 500);
}
$result = $leadsQuery->get_result();
$leads = [];
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}

$leadsQuery->close();
$conn->close();
sendResponse(true, 'Campaign report loaded successfully.', [
    'campaign' => $campaign,
    'leads' => $leads,
    'pagination' => [
        'total' => $totalLeads,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($totalLeads / $pageSize))
    ]
]);
?>
