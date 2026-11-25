<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_closures.add');
$userId = (int) ($currentUser['user_id'] ?? 0);
$canViewAllLeads = crmClosureCanViewAllLeads($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'SELECT id, title, message, notification_type, related_entity_id, created_at
     FROM crm_notifications
     WHERE user_id = ? AND is_read = 0 AND related_entity_type = \'lead_closure\'
       AND (? = 1 OR EXISTS (
            SELECT 1
            FROM crm_lead_closure_forms cf
            INNER JOIN crm_leads l ON l.id = cf.lead_id
            WHERE cf.id = crm_notifications.related_entity_id
              AND l.assigned_user_id = ?
              AND l.deleted_at IS NULL
       ))
     ORDER BY created_at DESC, id DESC
     LIMIT 10'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare CRM notification lookup: ' . $error, [], [], 500);
}
$canViewAllValue = $canViewAllLeads ? 1 : 0;
$stmt->bind_param('iii', $userId, $canViewAllValue, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load CRM notifications: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'CRM notifications loaded successfully.', ['notifications' => $notifications]);
?>
