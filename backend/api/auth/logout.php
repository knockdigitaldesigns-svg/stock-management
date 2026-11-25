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

$conn->begin_transaction();
$legacyTimeout = SESSION_INACTIVITY_TIMEOUT_SECONDS;
$stmt = $conn->prepare(
    'SELECT u.active_session_id,
            UNIX_TIMESTAMP(u.active_session_created_at) AS last_activity_at,
            u.active_session_expires_at,
            COALESCE(a.inactivity_timeout_seconds, ?) AS inactivity_timeout_seconds
     FROM users u
     LEFT JOIN attendance_sessions a ON BINARY a.session_id = BINARY u.active_session_id
     WHERE u.id = ?
     LIMIT 1
     FOR UPDATE'
);
if (!$stmt) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to verify login session status.', [], [], 500);
}
$stmt->bind_param('ii', $legacyTimeout, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->rollback();
    $conn->close();
    error_log('Unable to load logout session: ' . $error);
    sendResponse(false, 'Unable to verify login session status.', [], [], 500);
}
$activeSession = $stmt->get_result()->fetch_assoc();
$stmt->close();
$activeSessionId = (string) ($activeSession['active_session_id'] ?? '');
$sameSession = $activeSessionId !== '' && hash_equals($activeSessionId, $sessionId);
if (!$activeSession || !$sameSession) {
    $conn->rollback();
    $conn->close();
    if ($isInactivityLogout) {
        sendResponse(true, 'Session is no longer current.', ['status' => 'replaced']);
    }
    sendResponse(false, 'Your session is no longer valid.', [], [], 401);
}

$now = time();
$lastActivityAt = (int) ($activeSession['last_activity_at'] ?? 0);
$timeoutSeconds = max(1, (int) ($activeSession['inactivity_timeout_seconds'] ?? $legacyTimeout));
$expiresAt = (int) ($activeSession['active_session_expires_at'] ?? 0);
if ($isInactivityLogout) {
    $expired = $expiresAt <= $now || $lastActivityAt <= $now - $timeoutSeconds;
    if (!$expired) {
        $conn->rollback();
        $conn->close();
        sendResponse(true, 'Session remains active.', [
            'status' => 'active',
            'last_activity_at' => $lastActivityAt
        ]);
    }
    $logoutAt = $lastActivityAt > 0
        ? min($now, $lastActivityAt + $timeoutSeconds)
        : min($now, $expiresAt > 0 ? $expiresAt : $now);
    $lastActivityCutoff = $now - $timeoutSeconds;
    $logoutType = 'Auto';
} else {
    $logoutAt = $now;
    $lastActivityCutoff = 0;
    $logoutType = 'Manual';
}

try {
    closeAttendanceSession($conn, $sessionId, $logoutAt, $logoutType);
    if ($isInactivityLogout) {
        $clearSession = $conn->prepare(
            'UPDATE users
             SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL
             WHERE id = ? AND active_session_id = ?
               AND (active_session_expires_at IS NULL OR active_session_expires_at <= ?
                    OR active_session_created_at IS NULL
                    OR UNIX_TIMESTAMP(active_session_created_at) <= ?)'
        );
        if (!$clearSession) {
            throw new RuntimeException('Unable to prepare expired session cleanup: ' . $conn->error);
        }
        $clearSession->bind_param('isii', $userId, $sessionId, $now, $lastActivityCutoff);
    } else {
        $clearSession = $conn->prepare(
            'UPDATE users
             SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL
             WHERE id = ? AND active_session_id = ?'
        );
        if (!$clearSession) {
            throw new RuntimeException('Unable to prepare session cleanup: ' . $conn->error);
        }
        $clearSession->bind_param('is', $userId, $sessionId);
    }
    if (!$clearSession->execute()) {
        $error = $clearSession->error;
        $clearSession->close();
        throw new RuntimeException('Unable to end login session: ' . $error);
    }
    $sessionEnded = $clearSession->affected_rows === 1;
    $clearSession->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Unable to close attendance login session: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to end login session.', [], [], 500);
}
$conn->close();

if ($isInactivityLogout && !$sessionEnded) {
    sendResponse(true, 'Session is no longer current.', ['status' => 'replaced']);
}

sendResponse(true, 'Logout successful', $isInactivityLogout ? ['status' => 'ended'] : []);
?>
