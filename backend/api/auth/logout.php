<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$payload = authenticate();
$userId = (int) ($payload['user_id'] ?? 0);
$sessionId = (string) ($payload['sid'] ?? '');

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('UPDATE users SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL WHERE id = ? AND active_session_id = ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to end login session.', [], [], 500);
}
$stmt->bind_param('is', $userId, $sessionId);
$success = $stmt->execute();
$stmt->close();
$conn->close();

if (!$success) {
    sendResponse(false, 'Unable to end login session.', [], [], 500);
}

sendResponse(true, 'Logout successful');
?>