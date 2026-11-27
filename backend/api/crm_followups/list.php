<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_followups.view');
$userId = (int) ($currentUser['user_id'] ?? 0);
if ($userId <= 0) {
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}

$view = trim((string) ($_GET['view'] ?? 'Today'));
$validViews = ['Today', 'Overdue', 'Upcoming', 'Completed'];
if (!in_array($view, $validViews, true)) {
    sendResponse(false, 'Invalid follow-up view.', [], [], 400);
}

$dateFilters = [
    'followup_from' => trim((string) ($_GET['followup_from'] ?? '')),
    'followup_to' => trim((string) ($_GET['followup_to'] ?? '')),
    'created_from' => trim((string) ($_GET['created_from'] ?? '')),
    'created_to' => trim((string) ($_GET['created_to'] ?? ''))
];
foreach ($dateFilters as $name => $dateValue) {
    if ($dateValue === '') {
        continue;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue) ||
        !checkdate((int) substr($dateValue, 5, 2), (int) substr($dateValue, 8, 2), (int) substr($dateValue, 0, 4))) {
        sendResponse(false, "Enter a valid date for {$name}.", [], [], 400);
    }
}
if (($dateFilters['followup_from'] !== '' && $dateFilters['followup_to'] !== '' &&
        $dateFilters['followup_from'] > $dateFilters['followup_to']) ||
    ($dateFilters['created_from'] !== '' && $dateFilters['created_to'] !== '' &&
        $dateFilters['created_from'] > $dateFilters['created_to'])) {
    sendResponse(false, 'Each From Date must be on or before its To Date.', [], [], 400);
}

$search = trim((string) ($_GET['search'] ?? ''));
$campaignId = trim((string) ($_GET['campaign_id'] ?? ''));
$pipelineId = trim((string) ($_GET['pipeline_id'] ?? ''));
$statusId = trim((string) ($_GET['status_id'] ?? ''));
$assignedUserId = trim((string) ($_GET['assigned_user_id'] ?? ''));
foreach ([
    'campaign_id' => $campaignId,
    'pipeline_id' => $pipelineId,
    'status_id' => $statusId,
    'assigned_user_id' => $assignedUserId
] as $name => $value) {
    if ($value !== '' && (!ctype_digit($value) || (int) $value <= 0)) {
        sendResponse(false, "Select a valid {$name}.", [], [], 400);
    }
}

$canViewAll = crmFollowupsCanViewAllLeads($userId);
$isEmployee = crmLeadsIsEmployee($userId);
if ($assignedUserId !== '' && !$canViewAll) {
    sendResponse(false, 'You are not authorized to filter by assigned employee.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmFollowupsRequireCompletionSchema($conn);
$hasCancellationColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
$activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn) ? ' AND l.deleted_at IS NULL' : '';

$overdueScheduleCondition = static function ($alias) {
    return "({$alias}.next_followup_date < CURDATE()
        OR ({$alias}.next_followup_date = CURDATE()
            AND COALESCE({$alias}.next_followup_time, '23:59:59') < CURTIME()))";
};
$todayScheduleCondition = static function ($alias) {
    return "({$alias}.next_followup_date = CURDATE()
        AND COALESCE({$alias}.next_followup_time, '23:59:59') >= CURTIME())";
};
$dateCondition = [
    'Today' => 'f.completed_at IS NULL AND ' . $todayScheduleCondition('f'),
    'Overdue' => 'f.completed_at IS NULL AND ' . $overdueScheduleCondition('f'),
    'Upcoming' => "f.completed_at IS NULL AND (
        f.next_followup_date > CURDATE()
        OR (f.next_followup_date = CURDATE()
            AND COALESCE(f.next_followup_time, '23:59:59') > CURTIME())
    )",
    'Completed' => 'f.completed_at IS NOT NULL'
];
$where = [$dateCondition[$view]];
$where[] = '1 = 1' . $activeLeadCondition;
if ($view !== 'Completed') {
    $where[] = "COALESCE(current_s.status_type, '') <> 'Lost'
                AND f.call_outcome IS NULL
                AND f.remarks = 'Scheduled during lead assignment'";
} else {
    $where[] = "NOT (
        f.call_outcome IS NULL
        AND COALESCE(f.remarks, '') = 'Scheduled during lead assignment'
    )";
    $latestCancellationCondition = $hasCancellationColumn
        ? ' AND latest.cancelled_at IS NULL'
        : '';
    $where[] = "NOT EXISTS (
        SELECT 1
        FROM crm_lead_followups latest
        WHERE latest.lead_id = f.lead_id
          AND latest.completed_at IS NOT NULL{$latestCancellationCondition}
          AND NOT (
              latest.call_outcome IS NULL
              AND COALESCE(latest.remarks, '') = 'Scheduled during lead assignment'
          )
          AND (
              latest.completed_at > f.completed_at
              OR (latest.completed_at = f.completed_at AND latest.id > f.id)
          )
    )";
}
if ($hasCancellationColumn) {
    $where[] = 'f.cancelled_at IS NULL';
}

$types = '';
$params = [];
$addParam = static function ($type, $value) use (&$types, &$params) {
    $types .= $type;
    $params[] = $value;
};
if (!$canViewAll) {
    $where[] = 'l.assigned_user_id = ?';
    $addParam('i', $userId);
}
$followupDateExpression = $view === 'Completed' ? 'DATE(f.completed_at)' : 'f.next_followup_date';
if ($dateFilters['followup_from'] !== '') {
    $where[] = "{$followupDateExpression} >= ?";
    $addParam('s', $dateFilters['followup_from']);
}
if ($dateFilters['followup_to'] !== '') {
    $where[] = "{$followupDateExpression} <= ?";
    $addParam('s', $dateFilters['followup_to']);
}
if ($dateFilters['created_from'] !== '') {
    $where[] = 'l.created_at >= ?';
    $addParam('s', $dateFilters['created_from'] . ' 00:00:00');
}
if ($dateFilters['created_to'] !== '') {
    $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $addParam('s', $dateFilters['created_to']);
}
if ($search !== '') {
    $where[] = '(l.lead_name LIKE ? OR l.mobile_no LIKE ?)';
    $searchLike = '%' . $search . '%';
    $addParam('s', $searchLike);
    $addParam('s', $searchLike);
}
if ($campaignId !== '') {
    $where[] = 'l.campaign_id = ?';
    $addParam('i', (int) $campaignId);
}
if ($pipelineId !== '') {
    $where[] = 'l.pipeline_id = ?';
    $addParam('i', (int) $pipelineId);
}
if ($statusId !== '') {
    $where[] = 'l.lead_status_id = ?';
    $addParam('i', (int) $statusId);
}
if ($assignedUserId !== '') {
    $where[] = 'l.assigned_user_id = ?';
    $addParam('i', (int) $assignedUserId);
}

$sql =
    "SELECT f.id, f.lead_id, f.current_status_id, f.customer_need,
            CASE
                WHEN f.completed_at IS NULL
                     AND f.call_outcome IS NULL
                     AND f.remarks = 'Scheduled during lead assignment'
                THEN latest_activity.call_outcome
                WHEN f.call_outcome IS NULL AND f.remarks = 'Scheduled during lead assignment'
                THEN NULL
                ELSE f.call_outcome
            END AS call_outcome,
            CASE
                WHEN f.completed_at IS NULL
                     AND f.call_outcome IS NULL
                     AND f.remarks = 'Scheduled during lead assignment'
                     AND latest_activity.call_outcome IN (
                    'Did not pick',
                    'Busy in another call',
                    'User disconnected the call',
                    'Switch off',
                    'Out of coverage area / Network issue',
                    'Call not connected / Cannot be completed',
                    'Incorrect / Invalid number',
                    'Incoming calls not available',
                    'Number not in use / Does not exist / Out of service',
                    'Other reason'
                ) THEN 'Not Connected'
                WHEN f.completed_at IS NULL
                     AND f.call_outcome IS NULL
                     AND f.remarks = 'Scheduled during lead assignment'
                     AND latest_activity.id IS NOT NULL THEN 'Connected'
                WHEN f.call_outcome IN (
                    'Did not pick',
                    'Busy in another call',
                    'User disconnected the call',
                    'Switch off',
                    'Out of coverage area / Network issue',
                    'Call not connected / Cannot be completed',
                    'Incorrect / Invalid number',
                    'Incoming calls not available',
                    'Number not in use / Does not exist / Out of service',
                    'Other reason'
                ) THEN 'Not Connected'
                WHEN f.completed_at IS NOT NULL
                     AND NOT (
                         f.call_outcome IS NULL
                         AND f.remarks = 'Scheduled during lead assignment'
                     ) THEN 'Connected'
                ELSE NULL
            END AS connection_result,
            CASE
                WHEN f.completed_at IS NULL
                     AND f.call_outcome IS NULL
                     AND f.remarks = 'Scheduled during lead assignment'
                THEN latest_activity.remarks
                WHEN f.call_outcome IS NULL AND f.remarks = 'Scheduled during lead assignment'
                THEN NULL
                ELSE f.remarks
            END AS remarks,
            (f.call_outcome IS NULL
                AND f.remarks = 'Scheduled during lead assignment'
                AND latest_activity.id IS NULL) AS is_assignment_schedule,
            (f.completed_at IS NULL
                AND f.call_outcome IS NULL
                AND f.remarks = 'Scheduled during lead assignment') AS is_scheduled_followup,
            CASE WHEN f.completed_at IS NULL THEN f.next_followup_date ELSE NULL END AS next_followup_date,
            CASE WHEN f.completed_at IS NULL THEN f.next_followup_time ELSE NULL END AS next_followup_time,
            f.completed_at, f.updated_by_user_id,
            CASE WHEN latest_activity.id IS NOT NULL
                 THEN COALESCE(NULLIF(activity_user.employee_name, ''), activity_user.username, 'Unknown user')
                 ELSE NULL
            END AS latest_activity_updated_by,
            f.created_at, f.updated_at, l.created_at AS lead_created_at,
            l.lead_name, l.mobile_no, l.assigned_user_id, l.lead_status_id,
            p.pipeline_name, c.campaign_name,
            s.status_name AS followup_status, s.color AS followup_status_color,
            current_s.status_name AS lead_status, current_s.status_type AS lead_status_type,
            current_s.status AS lead_status_state, current_s.color AS lead_status_color,
            COALESCE(NULLIF(u.employee_name, ''), u.username, 'Unassigned') AS assigned_employee
     FROM crm_lead_followups f
     INNER JOIN crm_leads l ON l.id = f.lead_id
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = f.current_status_id
     LEFT JOIN crm_lead_status_master current_s ON current_s.id = l.lead_status_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     LEFT JOIN crm_lead_followups latest_activity
       ON latest_activity.id = (
            SELECT activity.id
            FROM crm_lead_followups activity
            WHERE f.completed_at IS NULL
              AND f.call_outcome IS NULL
              AND f.remarks = 'Scheduled during lead assignment'
              AND activity.lead_id = f.lead_id
              AND activity.completed_at IS NOT NULL
              AND (
                  activity.completed_at < f.created_at
                  OR (activity.completed_at = f.created_at AND activity.id < f.id)
              )
              AND NOT (
                  activity.call_outcome IS NULL
                  AND COALESCE(activity.remarks, '') = 'Scheduled during lead assignment'
              )
            ORDER BY activity.completed_at DESC, activity.id DESC
            LIMIT 1
       )
     LEFT JOIN users activity_user ON activity_user.id = latest_activity.updated_by_user_id
     WHERE " . implode(' AND ', $where);
if ($view === 'Completed') {
    $sql .= ' ORDER BY f.completed_at DESC, f.id DESC';
} else {
    $sql .= ' ORDER BY f.next_followup_date ASC,
              COALESCE(f.next_followup_time, TIME(f.created_at)) ASC,
              f.created_at DESC, f.id DESC';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countSql =
    "SELECT COUNT(*)
     FROM crm_lead_followups f
     INNER JOIN crm_leads l ON l.id = f.lead_id
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = f.current_status_id
     LEFT JOIN crm_lead_status_master current_s ON current_s.id = l.lead_status_id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE " . implode(' AND ', $where);
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up count: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind follow-up count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count follow-ups: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_row()[0] ?? 0);
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
    sendResponse(false, 'Unable to prepare follow-up list: ' . $error, [], [], 500);
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
        sendResponse(false, 'Unable to bind follow-up filters: ' . $error, [], [], 500);
    }
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load follow-ups: ' . $error, [], [], 500);
}

$result = $stmt->get_result();
$followups = [];
while ($row = $result->fetch_assoc()) {
    $followups[] = $row;
}
$stmt->close();

$scopeCondition = $activeLeadCondition . ($canViewAll ? '' : ' AND l.assigned_user_id = ?');
$dueCancellationCondition = $hasCancellationColumn ? ' AND due.cancelled_at IS NULL' : '';
$doneCancellationCondition = $hasCancellationColumn ? ' AND done.cancelled_at IS NULL' : '';
$lateCancellationCondition = $hasCancellationColumn ? ' AND late.cancelled_at IS NULL' : '';
$statsSql =
    "SELECT
        (SELECT COUNT(*)
         FROM crm_lead_followups due
         INNER JOIN crm_leads l ON l.id = due.lead_id
         LEFT JOIN crm_lead_status_master st ON st.id = l.lead_status_id
         WHERE due.completed_at IS NULL
           AND {$todayScheduleCondition('due')}
           AND due.call_outcome IS NULL
           AND due.remarks = 'Scheduled during lead assignment'
           AND COALESCE(st.status_type, '') <> 'Lost'{$dueCancellationCondition}{$scopeCondition}) AS due_today,
        (SELECT COUNT(*)
         FROM crm_lead_followups done
         INNER JOIN crm_leads l ON l.id = done.lead_id
         WHERE done.completed_at IS NOT NULL
           AND DATE(done.completed_at) = CURDATE()
           AND NOT (
               done.call_outcome IS NULL
               AND COALESCE(done.remarks, '') = 'Scheduled during lead assignment'
           )
           {$doneCancellationCondition}{$scopeCondition}) AS completed_today,
        (SELECT COUNT(*)
         FROM crm_lead_followups late
         INNER JOIN crm_leads l ON l.id = late.lead_id
         LEFT JOIN crm_lead_status_master st ON st.id = l.lead_status_id
         WHERE late.completed_at IS NULL
           AND {$overdueScheduleCondition('late')}
           AND late.call_outcome IS NULL
           AND late.remarks = 'Scheduled during lead assignment'
           AND COALESCE(st.status_type, '') <> 'Lost'{$lateCancellationCondition}{$scopeCondition}) AS overdue,
        CURDATE() AS business_date";
$statsStmt = $conn->prepare($statsSql);
if (!$statsStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up summary: ' . $error, [], [], 500);
}
if (!$canViewAll) {
    $statsStmt->bind_param('iii', $userId, $userId, $userId);
}
if (!$statsStmt->execute()) {
    $error = $statsStmt->error;
    $statsStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load follow-up summary: ' . $error, [], [], 500);
}
$summary = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$assignmentSql = $isEmployee
    ? 'SELECT COUNT(DISTINCT a.lead_id) AS assigned_today
       FROM crm_lead_assignments a
       INNER JOIN crm_leads l ON l.id = a.lead_id
       WHERE a.assigned_to_user_id = ? AND DATE(a.assigned_at) = CURDATE()
         AND l.assigned_user_id = ?' . $activeLeadCondition
    : 'SELECT COUNT(DISTINCT a.lead_id) AS assigned_today
       FROM crm_lead_assignments a
       INNER JOIN crm_leads l ON l.id = a.lead_id
       WHERE a.assigned_to_user_id = ? AND DATE(a.assigned_at) = CURDATE()' . $activeLeadCondition;
$assignmentStmt = $conn->prepare($assignmentSql);
if (!$assignmentStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare assignment summary: ' . $error, [], [], 500);
}
if ($isEmployee) {
    $assignmentStmt->bind_param('ii', $userId, $userId);
} else {
    $assignmentStmt->bind_param('i', $userId);
}
if (!$assignmentStmt->execute()) {
    $error = $assignmentStmt->error;
    $assignmentStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load assignment summary: ' . $error, [], [], 500);
}
$assignmentSummary = $assignmentStmt->get_result()->fetch_assoc();
$assignmentStmt->close();
$conn->close();

sendResponse(true, 'Follow-ups loaded successfully.', [
    'followups' => $followups,
    'total' => $total,
    'view' => $view,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ],
    'summary' => [
        'assigned_today' => (int) ($assignmentSummary['assigned_today'] ?? 0),
        'due_today' => (int) ($summary['due_today'] ?? 0),
        'completed_today' => (int) ($summary['completed_today'] ?? 0),
        'overdue' => (int) ($summary['overdue'] ?? 0)
    ],
    'business_date' => $summary['business_date'] ?? null
]);
?>
