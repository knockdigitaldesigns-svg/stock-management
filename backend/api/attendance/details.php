<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$payload = authenticate();
$userId = (int) ($payload['user_id'] ?? 0);
$canViewAll = userHasPermission($userId, 'attendance.view');
$canViewSelf = userHasPermission($userId, 'attendance.self.view');
if (!$canViewAll && !$canViewSelf) {
    sendResponse(false, 'You do not have permission to view attendance details.', [], [], 403);
}
$dayId = filter_var($_GET['day_id'] ?? null, FILTER_VALIDATE_INT);
if ($dayId === false || $dayId === null || $dayId < 1) {
    sendResponse(false, 'A valid attendance record is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    expireStaleAttendanceSessions($conn, time());
    $dayStmt = $conn->prepare('SELECT * FROM attendance_days WHERE id = ? LIMIT 1');
    if (!$dayStmt) {
        throw new RuntimeException('Unable to prepare attendance detail lookup: ' . $conn->error);
    }
    $dayStmt->bind_param('i', $dayId);
    if (!$dayStmt->execute()) {
        $error = $dayStmt->error;
        $dayStmt->close();
        throw new RuntimeException('Unable to load attendance details: ' . $error);
    }
    $day = $dayStmt->get_result()->fetch_assoc();
    $dayStmt->close();
    if (!$day) {
        $conn->close();
        sendResponse(false, 'Attendance record not found.', [], [], 404);
    }
    if (!$canViewAll) {
        $roleStmt = $conn->prepare(
            'SELECT COALESCE(NULLIF(r.role_name, ""), u.role) AS role_name FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?
             LIMIT 1'
        );
        if (!$roleStmt) {
            throw new RuntimeException('Unable to prepare self attendance authorization: ' . $conn->error);
        }
        $roleStmt->bind_param('i', $userId);
        if (!$roleStmt->execute()) {
            $error = $roleStmt->error;
            $roleStmt->close();
            throw new RuntimeException('Unable to verify self attendance authorization: ' . $error);
        }
        $currentRole = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if ((int) ($day['user_id'] ?? 0) !== $userId || !$currentRole || !isAttendanceEligibleRole((string) $currentRole['role_name'])) {
            $conn->close();
            sendResponse(false, 'You may only view your own attendance details.', [], [], 403);
        }
    }
    refreshAttendanceDaySummary($conn, (int) $day['id'], time());

    $sessionsStmt = $conn->prepare(
        'SELECT login_at_utc, logout_at_utc, session_duration_seconds, logout_type
         FROM attendance_sessions
         WHERE day_id = ?
         ORDER BY login_at_utc, id'
    );
    if (!$sessionsStmt) {
        throw new RuntimeException('Unable to prepare attendance session details: ' . $conn->error);
    }
    $sessionsStmt->bind_param('i', $dayId);
    if (!$sessionsStmt->execute()) {
        $error = $sessionsStmt->error;
        $sessionsStmt->close();
        throw new RuntimeException('Unable to load attendance sessions: ' . $error);
    }
    $sessions = $sessionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sessionsStmt->close();

    $formattedSessions = [];
    foreach ($sessions as $session) {
        $formattedSessions[] = [
            'login_at' => attendanceLocalDateTime($session['login_at_utc'], (string) $day['timezone']),
            'logout_at' => attendanceLocalDateTime($session['logout_at_utc'], (string) $day['timezone']),
            'duration_seconds' => (int) $session['session_duration_seconds'],
            'logout_type' => $session['logout_type']
        ];
    }
    $result = [
        'id' => (int) $day['id'],
        'attendance_date' => (string) $day['attendance_date'],
        'employee_name' => (string) $day['employee_name_snapshot'],
        'username' => (string) $day['username_snapshot'],
        'role' => (string) $day['role_name_snapshot'],
        'status' => (string) $day['attendance_status'],
        'total_work_seconds' => (int) $day['total_work_seconds'],
        'first_login_at' => attendanceLocalDateTime($day['first_login_at_utc'], (string) $day['timezone']),
        'final_logout_at' => attendanceLocalDateTime($day['final_logout_at_utc'], (string) $day['timezone']),
        'late_seconds' => (int) $day['late_seconds'],
        'early_logout_seconds' => (int) $day['early_logout_seconds'],
        'settings' => [
            'office_start_time' => substr((string) $day['office_start_time'], 0, 5),
            'office_end_time' => substr((string) $day['office_end_time'], 0, 5),
            'full_day_hours' => (float) $day['full_day_hours'],
            'half_day_hours' => (float) $day['half_day_hours'],
            'inactivity_minutes' => (int) $day['inactivity_minutes'],
            'timezone' => (string) $day['timezone']
        ],
        'sessions' => $formattedSessions
    ];
    $conn->close();
    sendResponse(true, 'Attendance details loaded.', ['attendance' => $result]);
} catch (Throwable $error) {
    $conn->close();
    error_log('Unable to load attendance details: ' . $error->getMessage());
    sendResponse(false, 'Unable to load attendance details.', [], [], 500);
}
?>
