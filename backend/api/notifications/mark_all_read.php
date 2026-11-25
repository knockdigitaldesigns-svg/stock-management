<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'UPDATE crm_notifications
     SET is_read = 1, read_at = COALESCE(read_at, NOW())
     WHERE user_id = ? AND is_read = 0'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$updated = $stmt->affected_rows;
$stmt->close();
$conn->close();
sendResponse(true, 'All notifications marked as read.', ['updated' => $updated]);
?>
