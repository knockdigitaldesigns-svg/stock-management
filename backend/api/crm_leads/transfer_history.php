<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.transfer_history');
$userId = (int) ($currentUser['user_id'] ?? 0);
$leadId = filter_var($_GET['lead_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId <= 0 || $leadId === false || $leadId <= 0) {
    sendResponse(false, 'A valid lead is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn) ? ' AND deleted_at IS NULL' : '';
$leadStmt = $conn->prepare(
    'SELECT assigned_user_id FROM crm_leads WHERE id = ?' . $activeLeadCondition . ' LIMIT 1'
);
if (!$leadStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare transfer history access check: ' . $error, [], [], 500);
}
$leadStmt->bind_param('i', $leadId);
if (!$leadStmt->execute()) {
    $error = $leadStmt->error;
    $leadStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to verify transfer history access: ' . $error, [], [], 500);
}
$lead = $leadStmt->get_result()->fetch_assoc();
$leadStmt->close();
if (!$lead) {
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!crmLeadsCanViewAll($userId, $conn) && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(50, max(1, (int) ($_GET['page_size'] ?? 10)));
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM crm_lead_transfer_history WHERE lead_id = ?');
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare transfer history count: ' . $error, [], [], 500);
}
$countStmt->bind_param('i', $leadId);
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count lead transfers: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;
$historyStmt = $conn->prepare(
    "SELECT h.id, h.lead_id, h.previous_employee_id, h.new_employee_id,
            h.transferred_by_user_id, h.transfer_reason, h.transfer_type, h.transferred_at,
            COALESCE(NULLIF(previous_user.employee_name, ''), previous_user.username, 'Deleted user') AS previous_employee,
            COALESCE(NULLIF(new_user.employee_name, ''), new_user.username, 'Deleted user') AS new_employee,
            COALESCE(NULLIF(actor.employee_name, ''), actor.username, 'Deleted user') AS transferred_by
     FROM crm_lead_transfer_history h
     LEFT JOIN users previous_user ON previous_user.id = h.previous_employee_id
     LEFT JOIN users new_user ON new_user.id = h.new_employee_id
     LEFT JOIN users actor ON actor.id = h.transferred_by_user_id
     WHERE h.lead_id = ?
     ORDER BY h.transferred_at DESC, h.id DESC
     LIMIT ? OFFSET ?"
);
if (!$historyStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare lead transfer history: ' . $error, [], [], 500);
}
$historyStmt->bind_param('iii', $leadId, $pageSize, $offset);
if (!$historyStmt->execute()) {
    $error = $historyStmt->error;
    $historyStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load lead transfer history: ' . $error, [], [], 500);
}
$history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
$conn->close();
sendResponse(true, 'Lead transfer history loaded successfully.', [
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
