<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
$method = $_SERVER['REQUEST_METHOD'];
$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$listSql = "SELECT st.id, st.ticket_id, st.customer_id, st.vehicle_id, st.assigned_to_user_id, st.created_by_user_id, st.closed_by_user_id, c.username, c.primary_mobile_no AS mobile_no, cv.imei_no, cv.vehicle_no, p.platform_name, st.issue, st.priority, st.status, st.resolution_notes, st.created_at, st.updated_at, st.closed_at, COALESCE(au.employee_name, au.username) AS assigned_to, au.username AS assigned_username, COALESCE(cu.employee_name, cu.username) AS created_by, COALESCE(clo.employee_name, clo.username) AS closed_by FROM support_tickets st INNER JOIN customers c ON c.id = st.customer_id LEFT JOIN customer_vehicle_details cv ON cv.id = st.vehicle_id LEFT JOIN platforms p ON p.id = c.platform_id LEFT JOIN users au ON au.id = st.assigned_to_user_id LEFT JOIN users cu ON cu.id = st.created_by_user_id LEFT JOIN users clo ON clo.id = st.closed_by_user_id";

if ($method === 'GET') {
    requirePermission('support.view');
    $result = $conn->query($listSql . ' ORDER BY st.updated_at DESC, st.id DESC');
    $tickets = [];
    while ($result && ($row = $result->fetch_assoc())) $tickets[] = $row;
    $conn->close();
    sendResponse(true, 'Support tickets fetched successfully.', ['tickets' => $tickets]);
}

$data = json_decode(file_get_contents('php://input'));
if ($method === 'POST') {
    requirePermission('support.add');
    $customerId = (int) ($data->customer_id ?? 0);
    $vehicleId = (int) ($data->vehicle_id ?? 0);
    $issue = trim((string) ($data->issue ?? ''));
    $priority = trim((string) ($data->priority ?? 'Medium'));
    $assignedTo = (int) ($data->assigned_to_user_id ?? 0);
    if ($customerId <= 0 || $issue === '' || !in_array($priority, ['Low', 'Medium', 'High', 'Urgent'], true)) sendResponse(false, 'Customer, issue and valid priority are required.', [], [], 400);
    if ($assignedTo > 0 && !userHasPermission($userId, 'support.assign')) sendResponse(false, 'You do not have permission to assign tickets.', [], [], 403);
    $conn->begin_transaction();
    try {
        $customerStmt = $conn->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1 FOR UPDATE');
        $customerStmt->bind_param('i', $customerId); $customerStmt->execute();
        if ($customerStmt->get_result()->num_rows === 0) throw new Exception('Verified customer was not found.');
        $customerStmt->close();
        if ($assignedTo > 0) {
            $userStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' LIMIT 1");
            $userStmt->bind_param('i', $assignedTo); $userStmt->execute();
            if ($userStmt->get_result()->num_rows === 0) throw new Exception('Employee not found.');
            $userStmt->close();
        }
        $status = $assignedTo > 0 ? 'Assigned' : 'Open';
        $insert = $conn->prepare('INSERT INTO support_tickets (customer_id, vehicle_id, issue, priority, assigned_to_user_id, status, created_by_user_id) VALUES (?, NULLIF(?, 0), ?, ?, NULLIF(?, 0), ?, ?)');
        $insert->bind_param('iissisi', $customerId, $vehicleId, $issue, $priority, $assignedTo, $status, $userId);
        if (!$insert->execute()) throw new Exception('Ticket creation failed.');
        $id = $insert->insert_id; $insert->close();
        $ticketId = 'SUP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $update = $conn->prepare('UPDATE support_tickets SET ticket_id = ? WHERE id = ?');
        $update->bind_param('si', $ticketId, $id); if (!$update->execute()) throw new Exception('Ticket number generation failed.'); $update->close();
        writeCreatedFields($conn, $id, 'Support Ticket', ['ticket_id' => $ticketId, 'customer_id' => $customerId, 'issue' => $issue, 'priority' => $priority, 'assigned_to_user_id' => $assignedTo ?: null, 'status' => $status], $currentUser);
        $conn->commit(); $conn->close();
        sendResponse(true, 'Support ticket created successfully.', ['ticket_id' => $ticketId]);
    } catch (Throwable $error) { $conn->rollback(); $conn->close(); sendResponse(false, 'Ticket creation failed.', [], [], 400); }
}

if ($method !== 'PUT') sendResponse(false, 'Method not allowed', [], [], 405);
$id = (int) ($data->id ?? 0);
if ($id <= 0) sendResponse(false, 'Ticket ID is required.', [], [], 400);
$conn->begin_transaction();
$select = $conn->prepare('SELECT * FROM support_tickets WHERE id = ? FOR UPDATE');
$select->bind_param('i', $id); $select->execute(); $old = $select->get_result()->fetch_assoc(); $select->close();
if (!$old) { $conn->rollback(); $conn->close(); sendResponse(false, 'Ticket not found.', [], [], 404); }
$newStatus = array_key_exists('status', (array) $data) ? trim((string) $data->status) : $old['status'];
$allowedStatuses = ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed'];
if (!in_array($newStatus, $allowedStatuses, true)) sendResponse(false, 'Invalid status transition.', [], [], 400);
$statusOrder = ['Open' => 0, 'Assigned' => 1, 'In Progress' => 2, 'Resolved' => 3, 'Closed' => 4];
if ($newStatus !== $old['status'] && !userHasPermission($userId, 'support.edit') && $newStatus !== 'Closed') { $conn->rollback(); $conn->close(); sendResponse(false, 'You do not have permission to edit support tickets.', [], [], 403); }
if ($newStatus !== $old['status'] && $statusOrder[$newStatus] < $statusOrder[$old['status']] && !userHasPermission($userId, 'support.edit')) { $conn->rollback(); $conn->close(); sendResponse(false, 'Invalid status transition.', [], [], 400); }
$assignedTo = array_key_exists('assigned_to_user_id', (array) $data) ? (int) $data->assigned_to_user_id : (int) ($old['assigned_to_user_id'] ?? 0);
$resolution = array_key_exists('resolution_notes', (array) $data) ? trim((string) $data->resolution_notes) : (string) ($old['resolution_notes'] ?? '');
if (($newStatus === $old['status'] && $resolution !== (string) ($old['resolution_notes'] ?? '')) && !userHasPermission($userId, 'support.edit')) { $conn->rollback(); $conn->close(); sendResponse(false, 'You do not have permission to edit support tickets.', [], [], 403); }
if ($assignedTo !== (int) ($old['assigned_to_user_id'] ?? 0) && !userHasPermission($userId, 'support.assign')) { $conn->rollback(); $conn->close(); sendResponse(false, 'You do not have permission to assign tickets.', [], [], 403); }
if ($newStatus === 'Closed') {
    if (!userHasPermission($userId, 'support.close')) { $conn->rollback(); $conn->close(); sendResponse(false, 'You do not have permission to close tickets.', [], [], 403); }
    if ($resolution === '') { $conn->rollback(); $conn->close(); sendResponse(false, 'Resolution / Closing Notes are required to close a ticket.', [], [], 400); }
}
if ($assignedTo > 0) {
    $userStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' LIMIT 1"); $userStmt->bind_param('i', $assignedTo); $userStmt->execute(); if ($userStmt->get_result()->num_rows === 0) { $userStmt->close(); $conn->rollback(); $conn->close(); sendResponse(false, 'Employee not found.', [], [], 404); } $userStmt->close();
}
$closedBy = $newStatus === 'Closed' ? ((string) $old['status'] === 'Closed' ? ($old['closed_by_user_id'] ?? $userId) : $userId) : null;
$closedAt = $newStatus === 'Closed' ? ((string) $old['status'] === 'Closed' ? ($old['closed_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s')) : null;
$update = $conn->prepare('UPDATE support_tickets SET assigned_to_user_id = NULLIF(?, 0), status = ?, resolution_notes = NULLIF(?, \'\'), closed_by_user_id = ?, closed_at = ? WHERE id = ?');
$update->bind_param('issisi', $assignedTo, $newStatus, $resolution, $closedBy, $closedAt, $id);
if (!$update->execute()) { $update->close(); $conn->rollback(); $conn->close(); sendResponse(false, 'Unable to update ticket.', [], [], 400); }
$update->close();
$changes = ['assigned_to_user_id' => $assignedTo ?: null, 'status' => $newStatus, 'resolution_notes' => $resolution ?: null, 'closed_by_user_id' => $closedBy, 'closed_at' => $closedAt];
try {
    writeChangedFields($conn, $id, 'Support Ticket', $old, $changes, $currentUser);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Support ticket updated successfully.');
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to update ticket.', [], [], 500);
}
?>
