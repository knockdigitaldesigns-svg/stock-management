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

$now = time();
$expiresAt = $now + SESSION_INACTIVITY_TIMEOUT_SECONDS;
$lastActivityCutoff = $now - SESSION_INACTIVITY_TIMEOUT_SECONDS;
$stmt = $conn->prepare('UPDATE users SET active_session_created_at = CURRENT_TIMESTAMP, active_session_expires_at = ? WHERE id = ? AND active_session_id = ? AND active_session_expires_at > ? AND UNIX_TIMESTAMP(active_session_created_at) > ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to update session activity.', [], [], 500);
}
$stmt->bind_param('iisii', $expiresAt, $userId, $sessionId, $now, $lastActivityCutoff);
$success = $stmt->execute();
$updated = $stmt->affected_rows === 1;
$stmt->close();
$conn->close();

if (!$success) {
    sendResponse(false, 'Unable to update session activity.', [], [], 500);
}
if (!$updated) {
    sendResponse(false, 'Session expired due to inactivity.', [], [], 401);
}

sendResponse(true, 'Session activity recorded');
?>
