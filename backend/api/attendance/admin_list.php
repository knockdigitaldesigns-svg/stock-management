<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$payload = authenticate();
if (!userHasPermission((int) ($payload['user_id'] ?? 0), 'attendance.view')) {
    sendResponse(false, 'You do not have permission to view attendance reports.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$period = strtolower(trim((string) ($_GET['period'] ?? 'daily')));
$selected = trim((string) ($_GET['date'] ?? ''));
try {
    $currentSettings = getCurrentAttendanceSettings($conn);
    $timezone = new DateTimeZone((string) $currentSettings['timezone']);
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    [$dateFrom, $dateTo] = attendanceReportDateRange($period, $selected, $today);
    if ($dateFrom > $dateTo) {
        $conn->close();
        sendResponse(true, 'Attendance report loaded.', [
            'rows' => [],
            'summary' => [
                'present_days' => 0,
                'half_days' => 0,
                'absent_days' => 0,
                'late_arrivals' => 0,
                'early_logouts' => 0,
                'total_work_seconds' => 0
            ],
            'pagination' => ['page' => 1, 'page_size' => 25, 'total' => 0, 'total_pages' => 0],
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }
    $fromDate = new DateTimeImmutable($dateFrom);
    $toDate = new DateTimeImmutable($dateTo);
    if ((int) $fromDate->diff($toDate)->format('%a') > 365) {
        throw new InvalidArgumentException('Attendance report range cannot exceed 366 days.');
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    if ($statusFilter !== '' && !in_array($statusFilter, ['Present', 'Half Day', 'Absent'], true)) {
        throw new InvalidArgumentException('Choose a valid attendance status.');
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 25)));
    $now = time();
    expireStaleAttendanceSessions($conn, $now);
    refreshOpenAttendanceDays($conn, $dateFrom, $dateTo, $now);

    $staffResult = $conn->query(
        'SELECT u.id, COALESCE(NULLIF(u.employee_name, ""), u.username) AS employee_name,
                u.username, u.status, u.created_at,
                COALESCE(NULLIF(r.role_name, ""), u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         ORDER BY employee_name, u.id'
    );
    if (!$staffResult) {
        throw new RuntimeException('Unable to load attendance employees: ' . $conn->error);
    }
    $staff = [];
    while ($person = $staffResult->fetch_assoc()) {
        if (!isAttendanceEligibleRole((string) $person['role_name'])) {
            continue;
        }
        $person['id'] = (int) $person['id'];
        $staff[$person['id']] = $person;
    }

    $daysStmt = $conn->prepare(
        'SELECT d.*,
                (SELECT COUNT(*) FROM attendance_sessions s WHERE s.day_id = d.id) AS session_count
         FROM attendance_days d
         WHERE d.attendance_date BETWEEN ? AND ?
         ORDER BY d.attendance_date DESC, d.employee_name_snapshot'
    );
    if (!$daysStmt) {
        throw new RuntimeException('Unable to prepare attendance report query: ' . $conn->error);
    }
    $daysStmt->bind_param('ss', $dateFrom, $dateTo);
    if (!$daysStmt->execute()) {
        $error = $daysStmt->error;
        $daysStmt->close();
        throw new RuntimeException('Unable to load attendance records: ' . $error);
    }
    $actualRows = $daysStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $daysStmt->close();
    $actualByKey = [];
    $orphanRows = [];
    foreach ($actualRows as $row) {
        if (empty($row['user_id']) || !isset($staff[(int) $row['user_id']])) {
            $orphanRows[] = $row;
            continue;
        }
        $actualByKey[(int) $row['user_id'] . ':' . $row['attendance_date']] = $row;
    }

    $rows = [];
    $settingsByDate = [];
    for ($date = $fromDate; $date <= $toDate; $date = $date->modify('+1 day')) {
        $dateValue = $date->format('Y-m-d');
        $weekday = (int) (new DateTimeImmutable($dateValue . ' 12:00:00', $timezone))->format('N');
        foreach ($staff as $person) {
            $createdDate = substr((string) $person['created_at'], 0, 10);
            if ($createdDate > $dateValue) {
                continue;
            }
            $key = $person['id'] . ':' . $dateValue;
            $row = $actualByKey[$key] ?? null;
            if (!$row) {
                if ($weekday === 7) {
                    continue;
                }
                if (strtolower((string) $person['status']) !== 'active') {
                    continue;
                }
                if (!isset($settingsByDate[$dateValue])) {
                    $settingsByDate[$dateValue] = getAttendanceSettingsForDate($conn, $dateValue);
                }
                $row = [
                    'id' => null,
                    'user_id' => $person['id'],
                    'attendance_date' => $dateValue,
                    'employee_name_snapshot' => $person['employee_name'],
                    'username_snapshot' => $person['username'],
                    'role_name_snapshot' => $person['role_name'],
                    'timezone' => $settingsByDate[$dateValue]['timezone'],
                    'attendance_status' => 'Absent',
                    'total_work_seconds' => 0,
                    'first_login_at_utc' => null,
                    'final_logout_at_utc' => null,
                    'late_seconds' => 0,
                    'early_logout_seconds' => 0,
                    'session_count' => 0
                ];
            }
            $row = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'user_id' => (int) ($row['user_id'] ?? $person['id']),
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
            if ($search !== '' && stripos(
                $row['employee_name'] . ' ' . $row['username'] . ' ' . $row['role'],
                $search
            ) === false) {
                continue;
            }
            if ($statusFilter !== '' && $row['status'] !== $statusFilter) {
                continue;
            }
            $rows[] = $row;
        }
    }

    foreach ($orphanRows as $orphan) {
        $orphanRow = [
            'id' => (int) $orphan['id'],
            'user_id' => null,
            'attendance_date' => (string) $orphan['attendance_date'],
            'employee_name' => (string) $orphan['employee_name_snapshot'],
            'username' => (string) $orphan['username_snapshot'],
            'role' => (string) $orphan['role_name_snapshot'],
            'status' => (string) $orphan['attendance_status'],
            'total_work_seconds' => (int) $orphan['total_work_seconds'],
            'first_login_at' => attendanceLocalDateTime($orphan['first_login_at_utc'] ?? null, (string) $orphan['timezone']),
            'final_logout_at' => attendanceLocalDateTime($orphan['final_logout_at_utc'] ?? null, (string) $orphan['timezone']),
            'late_seconds' => (int) $orphan['late_seconds'],
            'early_logout_seconds' => (int) $orphan['early_logout_seconds'],
            'session_count' => (int) $orphan['session_count']
        ];
        if ($search !== '' && stripos(
            $orphanRow['employee_name'] . ' ' . $orphanRow['username'] . ' ' . $orphanRow['role'],
            $search
        ) === false) {
            continue;
        }
        if ($statusFilter !== '' && $orphanRow['status'] !== $statusFilter) {
            continue;
        }
        $rows[] = $orphanRow;
    }

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
        if ($row['late_seconds'] > 0) {
            $summary['late_arrivals']++;
        }
        if ($row['early_logout_seconds'] > 0) {
            $summary['early_logouts']++;
        }
        $summary['total_work_seconds'] += $row['total_work_seconds'];
    }
    usort($rows, static function (array $left, array $right): int {
        $dateOrder = strcmp($right['attendance_date'], $left['attendance_date']);
        return $dateOrder !== 0 ? $dateOrder : strcasecmp($left['employee_name'], $right['employee_name']);
    });
    $total = count($rows);
    $pageRows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);
    $conn->close();
    sendResponse(true, 'Attendance report loaded.', [
        'rows' => $pageRows,
        'summary' => $summary,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => (int) ceil($total / $pageSize)
        ],
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ]);
} catch (InvalidArgumentException $error) {
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
} catch (Throwable $error) {
    $conn->close();
    error_log('Unable to build attendance report: ' . $error->getMessage());
    sendResponse(false, 'Unable to load attendance report.', [], [], 500);
}
?>
