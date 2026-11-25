<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_followups.edit');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
$followupId = filter_var(is_object($data) ? ($data->id ?? null) : null, FILTER_VALIDATE_INT);
if ($userId <= 0 || $followupId === false || $followupId <= 0) {
    sendResponse(false, 'A valid follow-up is required.', [], [], 400);
}

$canViewAll = crmFollowupsCanViewAllLeads($userId);
$isEmployee = crmLeadsIsEmployee($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmFollowupsRequireCompletionSchema($conn);
$hasCancellationColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancelled_at');

$followupLeadStmt = $conn->prepare('SELECT lead_id FROM crm_lead_followups WHERE id = ? LIMIT 1');
if (!$followupLeadStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to locate follow-up lead: ' . $error, [], [], 500);
}
$followupLeadStmt->bind_param('i', $followupId);
if (!$followupLeadStmt->execute()) {
    $error = $followupLeadStmt->error;
    $followupLeadStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to locate follow-up lead: ' . $error, [], [], 500);
}
$followupLead = $followupLeadStmt->get_result()->fetch_assoc();
$followupLeadStmt->close();
if (!$followupLead) {
    $conn->close();
    sendResponse(false, 'Follow-up not found.', [], [], 404);
}

if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin follow-up completion: ' . $error, [], [], 500);
}
$leadId = (int) $followupLead['lead_id'];
$lead = crmFollowupsLockLead($conn, $leadId);
if (!$lead) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$canViewAll && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $isEmployee ? 'Follow-up not found.' : 'You are not authorized to update this lead.', [], [], $isEmployee ? 404 : 403);
}
if (strtolower((string) ($lead['lead_status_type'] ?? '')) === 'lost' ||
    in_array(strtolower(trim((string) ($lead['lead_stage_name'] ?? ''))), ['converted lead', 'lost lead'], true)) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Converted and Lost leads are closed and cannot receive additional follow-ups.', [], [], 409);
}

$followupSql = 'SELECT completed_at' . ($hasCancellationColumn ? ', cancelled_at' : '') .
    ' FROM crm_lead_followups WHERE id = ? AND lead_id = ? LIMIT 1 FOR UPDATE';
$followupStmt = $conn->prepare($followupSql);
if (!$followupStmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up completion check: ' . $error, [], [], 500);
}
$followupStmt->bind_param('ii', $followupId, $leadId);
if (!$followupStmt->execute()) {
    $error = $followupStmt->error;
    $followupStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to check follow-up completion: ' . $error, [], [], 500);
}
$followup = $followupStmt->get_result()->fetch_assoc();
$followupStmt->close();
if (!$followup) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Follow-up not found.', [], [], 404);
}
if ($followup['completed_at'] !== null) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'This follow-up is already completed.', [], [], 409);
}
if ($hasCancellationColumn && $followup['cancelled_at'] !== null) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'This follow-up schedule is no longer active.', [], [], 409);
}

$completedCountStmt = $conn->prepare(
    'SELECT COUNT(*) AS completed_count
     FROM crm_lead_followups f
     INNER JOIN crm_leads l ON l.id = f.lead_id
     WHERE f.lead_id = ? AND f.completed_at IS NOT NULL
       AND l.assigned_user_id IS NOT NULL
       AND (l.assigned_at IS NULL OR f.completed_at >= l.assigned_at)'
);
if (!$completedCountStmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to prepare completed follow-up lookup: ' . $error, [], [], 500);
}
$completedCountStmt->bind_param('i', $leadId);
if (!$completedCountStmt->execute()) {
    $error = $completedCountStmt->error;
    $completedCountStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to check completed follow-ups: ' . $error, [], [], 500);
}
$priorCompletedFollowupCount = (int) (
    $completedCountStmt->get_result()->fetch_assoc()['completed_count'] ?? 0
);
$completedCountStmt->close();

$sql =
    'UPDATE crm_lead_followups
     SET completed_at = NOW(), updated_by_user_id = ?
     WHERE id = ? AND lead_id = ? AND completed_at IS NULL';
if ($hasCancellationColumn) {
    $sql .= ' AND cancelled_at IS NULL';
}
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to prepare follow-up completion: ' . $error, [], [], 500);
}
$stmt->bind_param('iii', $userId, $followupId, $leadId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to complete follow-up: ' . $error, [], [], 500);
}
if ($stmt->affected_rows === 0) {
    $stmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'The follow-up changed before it could be completed. Refresh and try again.', [], [], 409);
}

$stmt->close();
if ($priorCompletedFollowupCount === 0 &&
    (int) ($lead['assigned_user_id'] ?? 0) > 0) {
    try {
        crmLeadSetLifecycleStage($conn, $leadId, 'Existing Customer', $currentUser);
    } catch (RuntimeException $error) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, $error->getMessage(), [], [], 503);
    }
}
if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to save follow-up completion: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Follow-up marked completed.');
?>
