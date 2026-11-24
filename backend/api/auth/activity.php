<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$requestBody = json_decode(file_get_contents('php://input'), true);
if (!is_array($requestBody) || ($requestBody['meaningful_activity'] ?? false) !== true) {
    sendResponse(false, 'A meaningful user activity event is required.', [], [], 400);
}

$payload = authenticate();
$userId = (int) ($payload['user_id'] ?? 0);
$sessionId = (string) ($payload['sid'] ?? '');

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$now = time();
$timeoutSeconds = attendanceSessionTimeout($conn, $sessionId, SESSION_INACTIVITY_TIMEOUT_SECONDS);
$expiresAt = $now + $timeoutSeconds;
$lastActivityCutoff = $now - $timeoutSeconds;
$conn->begin_transaction();
$stmt = $conn->prepare('UPDATE users SET active_session_created_at = CURRENT_TIMESTAMP, active_session_expires_at = ? WHERE id = ? AND active_session_id = ? AND active_session_expires_at > ? AND UNIX_TIMESTAMP(active_session_created_at) > ?');
if (!$stmt) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to update session activity.', [], [], 500);
}
$stmt->bind_param('iisii', $expiresAt, $userId, $sessionId, $now, $lastActivityCutoff);
$success = $stmt->execute();
$updated = $stmt->affected_rows === 1;
$stmt->close();
if ($success && $updated) {
    try {
        $attendanceStmt = $conn->prepare('SELECT day_id FROM attendance_sessions WHERE BINARY session_id = BINARY ? AND logout_at_utc IS NULL LIMIT 1');
        if (!$attendanceStmt) {
            throw new RuntimeException('Unable to prepare attendance activity refresh: ' . $conn->error);
        }
        $attendanceStmt->bind_param('s', $sessionId);
        if (!$attendanceStmt->execute()) {
            $error = $attendanceStmt->error;
            $attendanceStmt->close();
            throw new RuntimeException('Unable to load attendance activity record: ' . $error);
        }
        $attendance = $attendanceStmt->get_result()->fetch_assoc();
        $attendanceStmt->close();
        if ($attendance) {
            refreshAttendanceDaySummary($conn, (int) $attendance['day_id'], $now);
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        error_log('Unable to refresh attendance after activity: ' . $error->getMessage());
        $conn->close();
        sendResponse(false, 'Unable to record session activity.', [], [], 500);
    }
} else {
    $conn->rollback();
}
$conn->close();

if (!$success) {
    sendResponse(false, 'Unable to update session activity.', [], [], 500);
}
if (!$updated) {
    sendResponse(false, 'Session expired due to inactivity.', [], [], 401);
}

sendResponse(true, 'Session activity recorded');
?>
