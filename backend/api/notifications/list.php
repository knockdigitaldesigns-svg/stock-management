<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../crm_leads/access.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$pageSize = min(50, max(1, filter_var($_GET['page_size'] ?? 10, FILTER_VALIDATE_INT) ?: 10));
$offset = ($page - 1) * $pageSize;
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$visibilityCondition = crmNotificationLeadVisibilityCondition(
    'n',
    crmLeadsCanViewAll($userId, $conn),
    crmLeadsHasSoftDeleteSchema($conn),
    userHasPermission($userId, 'crm_closures.approve')
);
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM crm_notifications n WHERE n.user_id = ?' . $visibilityCondition);
if ($visibilityCondition !== '') {
    $countStmt->bind_param('ii', $userId, $userId);
} else {
    $countStmt->bind_param('i', $userId);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$stmt = $conn->prepare(
    'SELECT n.id, n.notification_type, n.title, n.message, n.priority,
            n.is_read, n.read_at, n.created_at, n.related_entity_type, n.related_entity_id,
            n.related_lead_id, n.related_customer_id, n.related_closure_id
     FROM crm_notifications n
     WHERE n.user_id = ?' . $visibilityCondition . '
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT ? OFFSET ?'
);
if ($visibilityCondition !== '') {
    $stmt->bind_param('iiii', $userId, $userId, $pageSize, $offset);
} else {
    $stmt->bind_param('iii', $userId, $pageSize, $offset);
}
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $row['is_read'] = (bool) $row['is_read'];
    $notifications[] = $row;
}
$stmt->close();
$conn->close();
sendResponse(true, 'Notifications loaded successfully.', [
    'notifications' => $notifications,
    'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total]
]);
?>
