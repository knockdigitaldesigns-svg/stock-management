<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.delete');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
$leadId = filter_var(is_object($data) ? ($data->id ?? null) : null, FILTER_VALIDATE_INT);
if (!is_object($data) || $leadId === false || $leadId <= 0) {
    sendResponse(false, 'A valid lead ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

if (!crmLeadsHasSoftDeleteSchema($conn)) {
    $conn->close();
    sendResponse(
        false,
        'Lead archive is not enabled. Review and apply database/migrations/002_crm_leads_soft_delete.sql.',
        [],
        [],
        503
    );
}
$deletedByColumn = $conn->query("SHOW COLUMNS FROM crm_leads LIKE 'deleted_by_user_id'");
if (!$deletedByColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify lead archive schema: ' . $error, [], [], 500);
}
if ($deletedByColumn->num_rows === 0) {
    $conn->close();
    sendResponse(false, 'Lead archive audit schema is incomplete. Apply database/migrations/002_crm_leads_soft_delete.sql.', [], [], 503);
}

$isEmployee = crmLeadsIsEmployee($userId, $conn);
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin lead archive: ' . $error, [], [], 500);
}

$leadStmt = $conn->prepare('SELECT assigned_user_id, deleted_at FROM crm_leads WHERE id = ? LIMIT 1 FOR UPDATE');
if (!$leadStmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate lead: ' . $error, [], [], 500);
}
$leadStmt->bind_param('i', $leadId);
if (!$leadStmt->execute()) {
    $error = $leadStmt->error;
    $leadStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate lead: ' . $error, [], [], 500);
}
$lead = $leadStmt->get_result()->fetch_assoc();
$leadStmt->close();
if (!$lead || $lead['deleted_at'] !== null) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if ($isEmployee && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$isEmployee && !userHasPermission($userId, 'crm_leads.view') &&
    (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'You are only authorized to archive leads assigned to you.', [], [], 403);
}

$archive = $conn->prepare(
    'UPDATE crm_leads SET deleted_at = NOW(), deleted_by_user_id = ?, updated_at = NOW()
     WHERE id = ? AND deleted_at IS NULL'
);
if (!$archive) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to archive lead: ' . $error, [], [], 500);
}
$archive->bind_param('ii', $userId, $leadId);
if (!$archive->execute()) {
    $error = $archive->error;
    $archive->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to archive lead: ' . $error, [], [], 500);
}
$archive->close();

if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to save lead archive: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Lead archived successfully.');
?>
