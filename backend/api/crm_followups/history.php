<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['crm_followups.view', 'crm_followups.add']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$leadId = filter_var($_GET['lead_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId <= 0 || $leadId === false || $leadId <= 0) {
    sendResponse(false, 'A valid lead is required.', [], [], 400);
}

$canViewAll = crmFollowupsCanViewAllLeads($userId);
$isEmployee = crmLeadsIsEmployee($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin follow-up history lookup: ' . $error, [], [], 500);
}
crmFollowupsRequireCompletionSchema($conn);
$closureReasonColumnResult = $conn->query("SHOW COLUMNS FROM crm_lead_followups LIKE 'closure_reason'");
if (!$closureReasonColumnResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify follow-up history schema: ' . $error, [], [], 500);
}
$closureReasonSelect = $closureReasonColumnResult->num_rows > 0
    ? 'f.closure_reason'
    : 'NULL AS closure_reason';
$hasCancellationColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
$hasCancellationReasonColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancellation_reason');
$cancellationSelect = $hasCancellationColumn
    ? 'f.cancelled_at'
    : 'NULL AS cancelled_at';
$cancellationReasonSelect = $hasCancellationReasonColumn
    ? 'f.cancellation_reason'
    : 'NULL AS cancellation_reason';
$activeScheduleCondition = $hasCancellationColumn
    ? ' AND schedule.cancelled_at IS NULL'
    : '';
$latestActivityCondition = "NOT EXISTS (
    SELECT 1
    FROM crm_lead_followups later
    WHERE later.lead_id = f.lead_id
      AND (
          later.completed_at > f.completed_at
          OR (later.completed_at = f.completed_at AND later.id > f.id)
      )
      AND NOT (
          later.call_outcome IS NULL
          AND COALESCE(later.remarks, '') = 'Scheduled during lead assignment'
      )
)";
$nextFollowupDateSelect = "CASE
    WHEN f.next_followup_date IS NOT NULL THEN f.next_followup_date
    WHEN f.completed_at IS NOT NULL AND {$latestActivityCondition} THEN (
        SELECT schedule.next_followup_date
        FROM crm_lead_followups schedule
        WHERE schedule.lead_id = f.lead_id
          AND schedule.completed_at IS NULL
          AND schedule.call_outcome IS NULL
          AND schedule.remarks = 'Scheduled during lead assignment'
          AND schedule.next_followup_date IS NOT NULL{$activeScheduleCondition}
        ORDER BY schedule.created_at DESC, schedule.id DESC
        LIMIT 1
    )
    ELSE NULL
END AS next_followup_date";
$nextFollowupTimeSelect = "CASE
    WHEN f.next_followup_time IS NOT NULL THEN f.next_followup_time
    WHEN f.completed_at IS NOT NULL AND {$latestActivityCondition} THEN (
        SELECT schedule.next_followup_time
        FROM crm_lead_followups schedule
        WHERE schedule.lead_id = f.lead_id
          AND schedule.completed_at IS NULL
          AND schedule.call_outcome IS NULL
          AND schedule.remarks = 'Scheduled during lead assignment'
          AND schedule.next_followup_date IS NOT NULL{$activeScheduleCondition}
        ORDER BY schedule.created_at DESC, schedule.id DESC
        LIMIT 1
    )
    ELSE NULL
END AS next_followup_time";

$activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn)
    ? ' AND deleted_at IS NULL'
    : '';
$leadSql = 'SELECT id, assigned_user_id FROM crm_leads WHERE id = ?' . $activeLeadCondition . ' LIMIT 1 FOR UPDATE';
$leadStmt = $conn->prepare($leadSql);
if (!$leadStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify lead access: ' . $error, [], [], 500);
}
$leadStmt->bind_param('i', $leadId);
if (!$leadStmt->execute()) {
    $error = $leadStmt->error;
    $leadStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to verify lead access: ' . $error, [], [], 500);
}
$lead = $leadStmt->get_result()->fetch_assoc();
if (!$lead) {
    $leadStmt->close();
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$canViewAll && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $leadStmt->close();
    $conn->close();
    sendResponse(false, $isEmployee ? 'Lead not found.' : 'You are not authorized to access this lead.', [], [], $isEmployee ? 404 : 403);
}
$leadStmt->close();

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM crm_lead_followups f
     WHERE f.lead_id = ?
       AND NOT (
           f.call_outcome IS NULL
           AND COALESCE(f.remarks, '') = 'Scheduled during lead assignment'
       )"
);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up history count: ' . $error, [], [], 500);
}
$countStmt->bind_param('i', $leadId);
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count follow-up history: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;
$historyStmt = $conn->prepare(
    "SELECT f.id, f.current_status_id, f.customer_need, f.call_outcome,
            CASE
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
                WHEN f.completed_at IS NOT NULL THEN 'Connected'
                ELSE NULL
            END AS connection_result,
            f.remarks,
            {$nextFollowupDateSelect},
            {$nextFollowupTimeSelect},
            f.completed_at, {$cancellationSelect}, {$cancellationReasonSelect},
            {$closureReasonSelect}, f.updated_by_user_id, f.created_at,
            s.status_name, s.color AS status_color,
            COALESCE(NULLIF(u.employee_name, ''), u.username, 'Unknown user') AS updated_by
     FROM crm_lead_followups f
     LEFT JOIN crm_lead_status_master s ON s.id = f.current_status_id
     LEFT JOIN users u ON u.id = f.updated_by_user_id
     WHERE f.lead_id = ?
       AND NOT (
           f.call_outcome IS NULL
           AND COALESCE(f.remarks, '') = 'Scheduled during lead assignment'
       )
     ORDER BY f.created_at ASC, f.id ASC
     LIMIT ? OFFSET ?"
);
if (!$historyStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up history: ' . $error, [], [], 500);
}
$historyStmt->bind_param('iii', $leadId, $pageSize, $offset);
if (!$historyStmt->execute()) {
    $error = $historyStmt->error;
    $historyStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load follow-up history: ' . $error, [], [], 500);
}

$result = $historyStmt->get_result();
$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

$historyStmt->close();
if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to finish follow-up history lookup: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Follow-up history loaded successfully.', [
    'history' => $history,
    'total' => $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
