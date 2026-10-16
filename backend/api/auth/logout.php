<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$requestBody = json_decode(file_get_contents('php://input'), true);
$isInactivityLogout = is_array($requestBody)
    && ($requestBody['reason'] ?? '') === 'inactivity';
$payload = $isInactivityLogout ? getCurrentUserFromToken() : authenticate();
if (!$payload) {
    sendResponse(false, 'Invalid or expired token', [], [], 401);
}
$userId = (int) ($payload['user_id'] ?? 0);
$sessionId = (string) ($payload['sid'] ?? '');
if (!$userId || $sessionId === '') {
    sendResponse(false, 'Your session is no longer valid.', [], [], 401);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

if ($isInactivityLogout) {
    $now = time();
    $lastActivityCutoff = $now - SESSION_INACTIVITY_TIMEOUT_SECONDS;
    $stmt = $conn->prepare('UPDATE users SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL WHERE id = ? AND active_session_id = ? AND (active_session_expires_at IS NULL OR active_session_expires_at <= ? OR active_session_created_at IS NULL OR UNIX_TIMESTAMP(active_session_created_at) <= ?)');
} else {
    $stmt = $conn->prepare('UPDATE users SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL WHERE id = ? AND active_session_id = ?');
}
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to end login session.', [], [], 500);
}
if ($isInactivityLogout) {
    $stmt->bind_param('isii', $userId, $sessionId, $now, $lastActivityCutoff);
} else {
    $stmt->bind_param('is', $userId, $sessionId);
}
$success = $stmt->execute();
$sessionEnded = $stmt->affected_rows === 1;
$stmt->close();

if ($success && $isInactivityLogout && !$sessionEnded) {
    $statusStmt = $conn->prepare('SELECT active_session_id, UNIX_TIMESTAMP(active_session_created_at) AS last_activity_at FROM users WHERE id = ? LIMIT 1');
    if (!$statusStmt) {
        $conn->close();
        sendResponse(false, 'Unable to verify login session status.', [], [], 500);
    }
    $statusStmt->bind_param('i', $userId);
    $statusStmt->execute();
    $activeSession = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();
    $sameSession = hash_equals((string) ($activeSession['active_session_id'] ?? ''), $sessionId);
    $lastActivityAt = (int) ($activeSession['last_activity_at'] ?? 0);
    $conn->close();
    if ($sameSession) {
        sendResponse(true, 'Session remains active.', [
            'status' => 'active',
            'last_activity_at' => $lastActivityAt
        ]);
    }
    sendResponse(true, 'Session is no longer current.', ['status' => 'replaced']);
}
$conn->close();

if (!$success) {
    sendResponse(false, 'Unable to end login session.', [], [], 500);
}

sendResponse(true, 'Logout successful', $isInactivityLogout ? ['status' => 'ended'] : []);
?>