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
if (!$userId || !userHasPermission($userId, 'attendance.self.view')) {
    sendResponse(false, 'You do not have permission to view attendance.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $userStmt = $conn->prepare(
        'SELECT u.id, COALESCE(NULLIF(u.employee_name, ""), u.username) AS employee_name,
                u.username, u.status, u.created_at, COALESCE(NULLIF(r.role_name, ""), u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1'
    );
    if (!$userStmt) {
        throw new RuntimeException('Unable to prepare employee attendance lookup: ' . $conn->error);
    }
    $userStmt->bind_param('i', $userId);
    if (!$userStmt->execute()) {
        $error = $userStmt->error;
        $userStmt->close();
        throw new RuntimeException('Unable to load employee attendance identity: ' . $error);
    }
    $person = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$person || !isAttendanceEligibleRole((string) $person['role_name'])) {
        $conn->close();
        sendResponse(false, 'Attendance self-view is available only to Employees and TLs.', [], [], 403);
    }

    $settings = getCurrentAttendanceSettings($conn);
    $timezone = new DateTimeZone((string) $settings['timezone']);
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $period = strtolower(trim((string) ($_GET['period'] ?? 'monthly')));
    $selected = trim((string) ($_GET['date'] ?? ''));
    [$dateFrom, $dateTo] = attendanceReportDateRange($period, $selected, $today);
    if ($dateFrom > $dateTo) {
        $conn->close();
        sendResponse(true, 'Attendance history loaded.', [
            'rows' => [],
            'summary' => [
                'present_days' => 0,
                'half_days' => 0,
                'absent_days' => 0,
                'late_arrivals' => 0,
                'early_logouts' => 0,
                'total_work_seconds' => 0
            ],
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }
    if ((int) (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->format('%a') > 365) {
        throw new InvalidArgumentException('Attendance history range cannot exceed 366 days.');
    }

    $now = time();
    expireStaleAttendanceSessions($conn, $now);
    refreshOpenAttendanceDays($conn, $dateFrom, $dateTo, $now);
    $daysStmt = $conn->prepare(
        'SELECT d.*,
                (SELECT COUNT(*) FROM attendance_sessions s WHERE s.day_id = d.id) AS session_count
         FROM attendance_days d
         WHERE d.user_id = ? AND d.attendance_date BETWEEN ? AND ?
         ORDER BY d.attendance_date DESC'
    );
    if (!$daysStmt) {
        throw new RuntimeException('Unable to prepare employee attendance history: ' . $conn->error);
    }
    $daysStmt->bind_param('iss', $userId, $dateFrom, $dateTo);
    if (!$daysStmt->execute()) {
        $error = $daysStmt->error;
        $daysStmt->close();
        throw new RuntimeException('Unable to load employee attendance history: ' . $error);
    }
    $actual = $daysStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $daysStmt->close();
    $actualByDate = [];
    foreach ($actual as $row) {
        $actualByDate[$row['attendance_date']] = $row;
    }

    $rows = [];
    $historicalSettings = [];
    $createdDate = substr((string) $person['created_at'], 0, 10);
    for ($date = new DateTimeImmutable($dateFrom); $date <= new DateTimeImmutable($dateTo); $date = $date->modify('+1 day')) {
        $dateValue = $date->format('Y-m-d');
        if ($dateValue < $createdDate) {
            continue;
        }
        $row = $actualByDate[$dateValue] ?? null;
        if (!$row) {
            $weekday = (int) (new DateTimeImmutable($dateValue . ' 12:00:00', $timezone))->format('N');
            if ($weekday === 7 || strtolower((string) $person['status']) !== 'active') {
                continue;
            }
            if (!isset($historicalSettings[$dateValue])) {
                $historicalSettings[$dateValue] = getAttendanceSettingsForDate($conn, $dateValue);
            }
            $row = [
                'id' => null,
                'attendance_date' => $dateValue,
                'employee_name_snapshot' => $person['employee_name'],
                'username_snapshot' => $person['username'],
                'role_name_snapshot' => $person['role_name'],
                'timezone' => $historicalSettings[$dateValue]['timezone'],
                'attendance_status' => 'Absent',
                'total_work_seconds' => 0,
                'first_login_at_utc' => null,
                'final_logout_at_utc' => null,
                'late_seconds' => 0,
                'early_logout_seconds' => 0,
                'session_count' => 0
            ];
        }
        $rows[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'attendance_date' => (string) $row['attendance_date'],
            'employee_name' => (string) $row['employee_name_snapshot'],
            'username' => (string) $row['username_snapshot'],
            'role' => (string) $row['role_name_snapshot'],
            'status' => (string) $row['attendance_status'],
            'total_work_seconds' => (int) $row['total_work_seconds'],
            'first_login_at' => attendanceLocalDateTime($row['first_login_at_utc'] ?? null, (string) $row['timezone']),
            'final_logout_at' => attendanceLocalDateTime($row['final_logout_at_utc'] ?? null, (string) $row['timezone']),
            'late_seconds' => (int) $row['late_seconds'],
            'early_logout_seconds' => (int) $row['early_logout_seconds'],
            'session_count' => (int) ($row['session_count'] ?? 0)
        ];
    }
    usort($rows, static function (array $left, array $right): int {
        return strcmp($right['attendance_date'], $left['attendance_date']);
    });
    $summary = [
        'present_days' => 0,
        'half_days' => 0,
        'absent_days' => 0,
        'late_arrivals' => 0,
        'early_logouts' => 0,
        'total_work_seconds' => 0
    ];
    foreach ($rows as $row) {
        if ($row['status'] === 'Present') {
            $summary['present_days']++;
        } elseif ($row['status'] === 'Half Day') {
            $summary['half_days']++;
        } else {
            $summary['absent_days']++;
        }
        $summary['late_arrivals'] += $row['late_seconds'] > 0 ? 1 : 0;
        $summary['early_logouts'] += $row['early_logout_seconds'] > 0 ? 1 : 0;
        $summary['total_work_seconds'] += $row['total_work_seconds'];
    }
    $conn->close();
    sendResponse(true, 'Attendance history loaded.', [
        'employee' => [
            'name' => (string) $person['employee_name'],
            'role' => (string) $person['role_name']
        ],
        'rows' => $rows,
        'summary' => $summary,
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ]);
} catch (InvalidArgumentException $error) {
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
} catch (Throwable $error) {
    $conn->close();
    error_log('Unable to load employee attendance history: ' . $error->getMessage());
    sendResponse(false, 'Unable to load attendance history.', [], [], 500);
}
?>
