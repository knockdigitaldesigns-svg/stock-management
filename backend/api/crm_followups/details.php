<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_followups.add');
$userId = (int) ($currentUser['user_id'] ?? 0);
$leadId = filter_var($_GET['lead_id'] ?? null, FILTER_VALIDATE_INT);
$mode = trim((string) ($_GET['mode'] ?? 'record'));
$followupId = filter_var($_GET['followup_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId <= 0 || $leadId === false || $leadId <= 0 ||
    !in_array($mode, ['record', 'update'], true) ||
    ($mode === 'update' && ($followupId === false || $followupId <= 0))) {
    sendResponse(false, 'A valid lead and follow-up selection are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin lead detail lookup: ' . $error, [], [], 500);
}
$canViewAll = crmFollowupsCanViewAllLeads($userId);
$isEmployee = crmLeadsIsEmployee($userId);
$activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn)
    ? ' AND l.deleted_at IS NULL'
    : '';
$hasCancellationColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
$scheduleCancellationCondition = $hasCancellationColumn
    ? ' AND schedule.cancelled_at IS NULL'
    : '';
$leadStmt = $conn->prepare(
    "SELECT l.id, l.lead_name, l.mobile_no, l.alternate_mobile_no, l.email,
            l.lead_source, l.customer_need, l.lead_status_id, l.lead_stage_id,
            l.assigned_user_id,
            p.pipeline_name, c.campaign_name,
            s.status_name AS lead_status, s.status_type AS lead_status_type,
            st.stage_name AS lead_stage_name,
            COALESCE(NULLIF(assignee.employee_name, ''), assignee.username, 'Unassigned') AS assigned_employee,
            NOW() AS business_now,
            @@system_time_zone AS business_timezone
     FROM crm_leads l
     INNER JOIN crm_pipelines p ON p.id = l.pipeline_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_lead_stage_master st ON st.id = l.lead_stage_id
     LEFT JOIN users assignee ON assignee.id = l.assigned_user_id
     WHERE l.id = ?{$activeLeadCondition}
     LIMIT 1 FOR UPDATE"
);
if (!$leadStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead details: ' . $error, [], [], 500);
}
$leadStmt->bind_param('i', $leadId);
if (!$leadStmt->execute()) {
    $error = $leadStmt->error;
    $leadStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load lead details: ' . $error, [], [], 500);
}
$lead = $leadStmt->get_result()->fetch_assoc();
$leadStmt->close();
if (!$lead) {
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$canViewAll && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->close();
    sendResponse(false, $isEmployee ? 'Lead not found.' : 'You are not authorized to access this lead.', [], [], $isEmployee ? 404 : 403);
}

$scheduleStmt = $conn->prepare(
    "SELECT schedule.id, schedule.next_followup_date, schedule.next_followup_time
     FROM crm_lead_followups schedule
     WHERE schedule.lead_id = ?
       AND schedule.completed_at IS NULL
       AND schedule.call_outcome IS NULL
       AND schedule.remarks = 'Scheduled during lead assignment'
       AND schedule.next_followup_date IS NOT NULL{$scheduleCancellationCondition}
     ORDER BY schedule.created_at DESC, schedule.id DESC
     LIMIT 1"
);
if (!$scheduleStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare current schedule lookup: ' . $error, [], [], 500);
}
$scheduleStmt->bind_param('i', $leadId);
if (!$scheduleStmt->execute()) {
    $error = $scheduleStmt->error;
    $scheduleStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load current follow-up schedule: ' . $error, [], [], 500);
}
$lead['current_schedule'] = $scheduleStmt->get_result()->fetch_assoc();
$scheduleStmt->close();
$lead['selected_followup'] = null;
if ($mode === 'update') {
    $closureReasonSelect = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'closure_reason')
        ? ', closure_reason'
        : ', NULL AS closure_reason';
    $followupStmt = $conn->prepare(
        "SELECT id, current_status_id, customer_need, call_outcome, remarks,
                next_followup_date, next_followup_time, completed_at{$closureReasonSelect}
         FROM crm_lead_followups
         WHERE id = ? AND lead_id = ?
           AND NOT (
               call_outcome IS NULL
               AND remarks = 'Scheduled during lead assignment'
           )
         LIMIT 1"
    );
    if (!$followupStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to prepare selected follow-up lookup: ' . $error, [], [], 500);
    }
    $followupStmt->bind_param('ii', $followupId, $leadId);
    if (!$followupStmt->execute()) {
        $error = $followupStmt->error;
        $followupStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to load the selected follow-up: ' . $error, [], [], 500);
    }
    $lead['selected_followup'] = $followupStmt->get_result()->fetch_assoc();
    $followupStmt->close();
    if (!$lead['selected_followup']) {
        $conn->close();
        sendResponse(false, 'The selected follow-up does not belong to this lead.', [], [], 404);
    }
}

if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to finish lead detail lookup: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Follow-up details loaded successfully.', ['lead' => $lead]);
?>
