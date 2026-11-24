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
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$visibilityCondition = crmNotificationLeadVisibilityCondition(
    'n',
    crmLeadsCanViewAll($userId, $conn),
    crmLeadsHasSoftDeleteSchema($conn)
);
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS unread_count FROM crm_notifications n
     WHERE n.user_id = ? AND n.is_read = 0' . $visibilityCondition
);
if ($visibilityCondition !== '') {
    $stmt->bind_param('ii', $userId, $userId);
} else {
    $stmt->bind_param('i', $userId);
}
$stmt->execute();
$count = (int) ($stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
$stmt->close();
$conn->close();
sendResponse(true, 'Unread count loaded successfully.', ['unread_count' => $count]);
?>
