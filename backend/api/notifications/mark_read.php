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
$data = json_decode(file_get_contents('php://input'));
$notificationId = filter_var(is_object($data) ? ($data->id ?? null) : null, FILTER_VALIDATE_INT);
if ($notificationId === false || $notificationId <= 0) {
    sendResponse(false, 'A valid notification ID is required.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'UPDATE crm_notifications
     SET is_read = 1, read_at = COALESCE(read_at, NOW())
     WHERE id = ? AND user_id = ?'
);
$stmt->bind_param('ii', $notificationId, $userId);
$stmt->execute();
if ($stmt->affected_rows !== 1) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Notification not found or already read.', [], [], 404);
}
$stmt->close();
$conn->close();
sendResponse(true, 'Notification marked as read.');
?>
