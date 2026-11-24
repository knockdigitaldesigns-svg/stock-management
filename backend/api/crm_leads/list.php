<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once 'access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();

$scope = trim((string) ($_GET['scope'] ?? 'all'));
if (!in_array($scope, ['all', 'my'], true)) {
    sendResponse(false, 'Invalid lead list scope.', [], [], 400);
}
if ($scope === 'my') {
    requireAnyPermission(['crm_leads.my_view', 'crm_leads.view']);
} else {
    requirePermission('crm_leads.view');
}
$userId = (int) ($currentUser['user_id'] ?? 0);
if ($scope === 'my' && $userId <= 0) {
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}
$isEmployee = crmLeadsIsEmployee($userId);
if ($isEmployee && $scope !== 'my') {
    sendResponse(false, 'Employees may only view their assigned leads.', [], [], 403);
}

$canViewAll = !$isEmployee && userHasPermission($userId, 'crm_leads.view');
$search = trim((string) ($_GET['search'] ?? ''));
$pipelineId = trim((string) ($_GET['pipeline_id'] ?? ''));
$campaignId = trim((string) ($_GET['campaign_id'] ?? ''));
$leadSource = trim((string) ($_GET['lead_source'] ?? ''));
$leadStageId = trim((string) ($_GET['lead_stage_id'] ?? ''));
$leadStatusId = trim((string) ($_GET['lead_status_id'] ?? ''));
$includeClosedValue = trim((string) ($_GET['include_closed'] ?? '0'));
$includeClosed = $includeClosedValue === '1';
$followupFilter = trim((string) ($_GET['followup_filter'] ?? 'all'));
$followupFrom = trim((string) ($_GET['followup_from'] ?? ''));
$followupTo = trim((string) ($_GET['followup_to'] ?? ''));
$assignmentFilter = trim((string) ($_GET['assignment_filter'] ?? ($scope === 'my' ? 'me' : 'all')));
$assignedUserId = trim((string) ($_GET['assigned_user_id'] ?? ''));
$validFollowupFilters = [
    'all', 'none', 'overdue', 'today', 'tomorrow',
    'next_7_days', 'next_30_days', 'upcoming', 'any', 'custom'
];
$validAssignmentFilters = ['all', 'me', 'unassigned', 'other', 'user'];
if (!in_array($followupFilter, $validFollowupFilters, true) ||
    !in_array($assignmentFilter, $validAssignmentFilters, true) ||
    !in_array($includeClosedValue, ['0', '1'], true)) {
    sendResponse(false, 'Invalid lead filter selection.', [], [], 400);
}
foreach ([
    'pipeline_id' => $pipelineId,
    'campaign_id' => $campaignId,
    'lead_stage_id' => $leadStageId,
    'lead_status_id' => $leadStatusId
] as $field => $value) {
    if ($value !== '' && (!ctype_digit($value) || (int) $value <= 0)) {
        sendResponse(false, "Select a valid {$field}.", [], [], 400);
    }
}
if ($assignedUserId !== '' && (!ctype_digit($assignedUserId) || (int) $assignedUserId <= 0)) {
    sendResponse(false, 'Select a valid assigned employee.', [], [], 400);
}
if ($followupFilter === 'custom') {
    foreach (['From' => $followupFrom, 'To' => $followupTo] as $label => $dateValue) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue) ||
            !checkdate((int) substr($dateValue, 5, 2), (int) substr($dateValue, 8, 2), (int) substr($dateValue, 0, 4))) {
            sendResponse(false, "Enter a valid custom follow-up {$label} Date.", [], [], 400);
        }
    }
    if ($followupFrom > $followupTo) {
        sendResponse(false, 'Follow-up From Date must be on or before To Date.', [], [], 400);
    }
} elseif ($followupFrom !== '' || $followupTo !== '') {
    sendResponse(false, 'Custom follow-up dates require the Custom Date Range filter.', [], [], 400);
}

$broadAssignmentFilter = in_array($assignmentFilter, ['all', 'unassigned', 'other', 'user'], true);
if ($scope === 'my' && $broadAssignmentFilter && !$canViewAll) {
    sendResponse(false, 'You are not authorized to view leads outside your assigned leads.', [], [], 403);
}
if ($assignmentFilter === 'user' && $assignedUserId === '') {
    sendResponse(false, 'Select an assigned employee.', [], [], 400);
}
if ($assignmentFilter !== 'user' && $assignedUserId !== '') {
    sendResponse(false, 'An employee selection requires the Assigned Employee filter.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
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

$followupCompletionColumnResult = $conn->query("SHOW COLUMNS FROM crm_lead_followups LIKE 'completed_at'");
if (!$followupCompletionColumnResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify follow-up schedule schema: ' . $error, [], [], 500);
}
$nextFollowupSelect = 'NULL AS next_followup_date';
$nextFollowupIdSelect = 'NULL AS followup_id';
if ($followupCompletionColumnResult->num_rows > 0) {
    $followupCancellationColumnResult = $conn->query("SHOW COLUMNS FROM crm_lead_followups LIKE 'cancelled_at'");
    if (!$followupCancellationColumnResult) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to verify follow-up cancellation schema: ' . $error, [], [], 500);
    }
    $followupCancellationCondition = $followupCancellationColumnResult->num_rows > 0
        ? ' AND f.cancelled_at IS NULL'
        : '';
    $nextFollowupSelect =
        "(SELECT f.next_followup_date
          FROM crm_lead_followups f
          WHERE f.lead_id = l.id
            AND f.completed_at IS NULL{$followupCancellationCondition}
            AND f.call_outcome IS NULL
            AND f.remarks = 'Scheduled during lead assignment'
            AND f.next_followup_date IS NOT NULL
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1) AS next_followup_date";
    $nextFollowupIdSelect =
        "(SELECT f.id
          FROM crm_lead_followups f
          WHERE f.lead_id = l.id
            AND f.completed_at IS NULL{$followupCancellationCondition}
            AND f.call_outcome IS NULL
            AND f.remarks = 'Scheduled during lead assignment'
            AND f.next_followup_date IS NOT NULL
          ORDER BY f.created_at DESC, f.id DESC
          LIMIT 1) AS followup_id";
}

$where = [];
$where[] = $hasSoftDeleteSchema ? 'l.deleted_at IS NULL' : '1 = 1';
$types = '';
$params = [];
$addParam = static function ($type, $value) use (&$types, &$params) {
    $types .= $type;
    $params[] = $value;
};

if ($scope === 'my' && !$broadAssignmentFilter) {
    $where[] = 'l.assigned_user_id = ?';
    $addParam('i', $userId);
} elseif ($scope === 'all' && $assignmentFilter === 'me') {
    $where[] = 'l.assigned_user_id = ?';
    $addParam('i', $userId);
} elseif ($assignmentFilter === 'unassigned') {
    $where[] = 'l.assigned_user_id IS NULL';
} elseif ($assignmentFilter === 'other') {
    $where[] = 'l.assigned_user_id IS NOT NULL AND l.assigned_user_id <> ?';
    $addParam('i', $userId);
} elseif ($assignmentFilter === 'user') {
    $activeUserStmt = $conn->prepare(
        "SELECT id FROM users
         WHERE id = ? AND LOWER(COALESCE(status, 'active')) = 'active'
         LIMIT 1"
    );
    if (!$activeUserStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to validate assigned employee: ' . $error, [], [], 500);
    }
    $selectedUserId = (int) $assignedUserId;
    $activeUserStmt->bind_param('i', $selectedUserId);
    if (!$activeUserStmt->execute()) {
        $error = $activeUserStmt->error;
        $activeUserStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to validate assigned employee: ' . $error, [], [], 500);
    }
    $activeUser = $activeUserStmt->get_result()->fetch_assoc();
    $activeUserStmt->close();
    if (!$activeUser) {
        $conn->close();
        sendResponse(false, 'Select an active employee.', [], [], 400);
    }
    $where[] = 'l.assigned_user_id = ?';
    $addParam('i', $selectedUserId);
}
if ($search !== '') {
    $where[] = '(l.lead_name LIKE ? OR l.mobile_no LIKE ? OR l.alternate_mobile_no LIKE ? OR l.email LIKE ?)';
    $searchLike = '%' . $search . '%';
    for ($index = 0; $index < 4; $index++) {
        $addParam('s', $searchLike);
    }
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
if ($leadStageId !== '') {
    $where[] = 'l.lead_stage_id = ?';
    $addParam('i', (int) $leadStageId);
}
if ($leadStatusId !== '') {
    $where[] = 'l.lead_status_id = ?';
    $addParam('i', (int) $leadStatusId);
}
if ($scope === 'my' && !$includeClosed && $leadStatusId === '') {
    $where[] = "COALESCE(s.status_type, '') NOT IN ('Lost', 'Resolved')";
}

$hasCancellationColumn = $followupCompletionColumnResult->num_rows > 0 &&
    isset($followupCancellationColumnResult) &&
    $followupCancellationColumnResult->num_rows > 0;
if ($followupFilter !== 'all' && $followupCompletionColumnResult->num_rows === 0) {
    $conn->close();
    sendResponse(
        false,
        'Follow-up filters require crm_lead_followups.completed_at. Review backend/migrations/crm_followups_completion.sql.',
        [],
        [],
        503
    );
}
$pendingScheduleWhere = "f.lead_id = l.id
    AND f.completed_at IS NULL
    AND f.call_outcome IS NULL
    AND f.remarks = 'Scheduled during lead assignment'
    AND f.next_followup_date IS NOT NULL" .
    ($hasCancellationColumn ? ' AND f.cancelled_at IS NULL' : '');
switch ($followupFilter) {
    case 'none':
        $where[] = "NOT EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere})";
        break;
    case 'overdue':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date < CURDATE())";
        break;
    case 'today':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date = CURDATE())";
        break;
    case 'tomorrow':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY))";
        break;
    case 'next_7_days':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date > CURDATE() AND f.next_followup_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
        break;
    case 'next_30_days':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date > CURDATE() AND f.next_followup_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
        break;
    case 'upcoming':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date > CURDATE())";
        break;
    case 'any':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere})";
        break;
    case 'custom':
        $where[] = "EXISTS (SELECT 1 FROM crm_lead_followups f WHERE {$pendingScheduleWhere} AND f.next_followup_date >= ? AND f.next_followup_date <= ?)";
        $addParam('s', $followupFrom);
        $addParam('s', $followupTo);
        break;
}

$sql =
    "SELECT l.id, l.pipeline_id, l.campaign_id, l.lead_name, l.mobile_no,
            l.alternate_mobile_no, l.email, l.location, l.pincode,
            l.lead_source, l.customer_need, l.priority, l.lead_status_id, l.lead_stage_id,
            l.remarks, {$closureReasonSelect}, l.assigned_user_id, l.assigned_by_user_id, l.assigned_at,
            l.created_by_user_id, l.created_at, l.updated_at,
            {$nextFollowupSelect}, {$nextFollowupIdSelect},
            p.pipeline_name, p.status AS pipeline_state,
            c.campaign_name, c.status AS campaign_state,
            s.status_name AS lead_status, s.status_type AS lead_status_type,
            s.color AS lead_status_color, s.status AS lead_status_state,
            st.stage_name AS lead_stage_name, st.status AS lead_stage_state,
            COALESCE(NULLIF(u.employee_name, ''), u.username, 'Unassigned') AS assigned_employee
     FROM crm_leads l
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_lead_stage_master st ON st.id = l.lead_stage_id
     LEFT JOIN users u ON u.id = l.assigned_user_id";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY l.created_at DESC, l.id DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countSql =
    "SELECT COUNT(*) AS total
     FROM crm_leads l
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_lead_stage_master st ON st.id = l.lead_stage_id
     LEFT JOIN users u ON u.id = l.assigned_user_id";
if ($where) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead count: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind lead count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count leads: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql .= ' LIMIT ? OFFSET ?';
$dataTypes = $types . 'ii';
$dataParams = $params;
$dataParams[] = $pageSize;
$dataParams[] = ($page - 1) * $pageSize;
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead list: ' . $error, [], [], 500);
}
{
    $bindParams = [$dataTypes];
    foreach ($dataParams as $index => $value) {
        $bindParams[] = &$dataParams[$index];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to bind lead filters: ' . $error, [], [], 500);
    }
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load leads: ' . $error, [], [], 500);
}

$result = $stmt->get_result();
$leads = [];
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'Leads loaded successfully.', [
    'leads' => $leads,
    'total' => $total,
    'scope' => $scope,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
