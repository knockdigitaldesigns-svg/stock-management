<?php

function attendanceNormalizeRole(string $roleName): string
{
    return strtolower(trim($roleName));
}

function isAttendanceEligibleRole(string $roleName): bool
{
    return in_array(attendanceNormalizeRole($roleName), ['employee', 'tl'], true);
}

function attendanceUtcDateTime(int $timestamp): string
{
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function attendanceParseUtcDateTime(string $value): int
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$date) {
        throw new RuntimeException('Invalid UTC attendance timestamp.');
    }
    return $date->getTimestamp();
}

function attendanceLocalDateTime(?string $value, string $timezone): ?string
{
    if (!$value) {
        return null;
    }
    return (new DateTimeImmutable('@' . attendanceParseUtcDateTime($value)))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('Y-m-d H:i:s');
}

function getCurrentAttendanceSettings(mysqli $conn): array
{
    $result = $conn->query('SELECT s.*, h.id AS settings_history_id FROM attendance_settings s LEFT JOIN attendance_settings_history h ON h.id = (SELECT MAX(id) FROM attendance_settings_history) WHERE s.id = 1 LIMIT 1');
    if (!$result) {
        throw new RuntimeException('Unable to load attendance settings: ' . $conn->error);
    }
    $settings = $result->fetch_assoc();
    if (!$settings) {
        throw new RuntimeException('Attendance settings have not been initialized.');
    }
    return $settings;
}

function getAttendanceSettingsForDate(mysqli $conn, string $date): array
{
    static $historyCache = [];
    $connectionKey = spl_object_id($conn);
    if (!isset($historyCache[$connectionKey])) {
        $fallback = getCurrentAttendanceSettings($conn);
        $result = $conn->query('SELECT * FROM attendance_settings_history ORDER BY effective_at_utc DESC, id DESC');
        if (!$result) {
            throw new RuntimeException('Unable to load historical attendance settings: ' . $conn->error);
        }
        $historyCache[$connectionKey] = [
            'fallback' => $fallback,
            'history' => $result->fetch_all(MYSQLI_ASSOC)
        ];
    }
    $fallback = $historyCache[$connectionKey]['fallback'];
    $history = $historyCache[$connectionKey]['history'];
    foreach ($history as $settings) {
        try {
            $localMidnight = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone((string) $settings['timezone']));
        } catch (Throwable $error) {
            throw new RuntimeException('Attendance settings contain an invalid timezone.', 0, $error);
        }
        $utcMidnight = $localMidnight->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ((string) $settings['effective_at_utc'] <= $utcMidnight) {
            return $settings;
        }
    }
    return $history ? $history[count($history) - 1] : $fallback;
}

function attendanceReportDateRange(string $period, string $selected, string $today): array
{
    if ($selected === '') {
        $selected = $period === 'monthly'
            ? substr($today, 0, 7)
            : ($period === 'yearly' ? substr($today, 0, 4) : $today);
    }

    if ($period === 'daily' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $selected);
        if (!$date || $date->format('Y-m-d') !== $selected) {
            throw new InvalidArgumentException('Choose a valid attendance date.');
        }
        $dateFrom = $selected;
        $dateTo = $selected;
    } elseif ($period === 'monthly' && preg_match('/^\d{4}-\d{2}$/', $selected)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $selected . '-01');
        if (!$date || $date->format('Y-m') !== $selected) {
            throw new InvalidArgumentException('Choose a valid attendance month.');
        }
        $dateFrom = $date->format('Y-m-01');
        $dateTo = $date->modify('last day of this month')->format('Y-m-d');
    } elseif ($period === 'yearly' && preg_match('/^\d{4}$/', $selected)) {
        $year = (int) $selected;
        if ($year < 2000 || $year > (int) substr($today, 0, 4) + 1) {
            throw new InvalidArgumentException('Choose a valid attendance year.');
        }
        $dateFrom = $selected . '-01-01';
        $dateTo = $selected . '-12-31';
    } else {
        throw new InvalidArgumentException('Choose a valid daily, monthly, or yearly period.');
    }

    if ($dateTo > $today) {
        $dateTo = $today;
    }
    return [$dateFrom, $dateTo];
}

function attendanceSessionTimeout(mysqli $conn, string $sessionId, int $defaultTimeout = 900): int
{
    $stmt = $conn->prepare('SELECT inactivity_timeout_seconds FROM attendance_sessions WHERE BINARY session_id = BINARY ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare attendance timeout lookup: ' . $conn->error);
    }
    $stmt->bind_param('s', $sessionId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load attendance timeout: ' . $error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $timeout = (int) ($row['inactivity_timeout_seconds'] ?? 0);
    return $timeout > 0 ? $timeout : $defaultTimeout;
}

function claimAuthenticatedSession(
    mysqli $conn,
    int $userId,
    string $sessionId,
    string $roleName,
    string $employeeName,
    string $username,
    bool $startAttendance,
    bool $manageTransaction = true
): ?int {
    $now = time();
    $eligibleForAttendance = $startAttendance && isAttendanceEligibleRole($roleName);
    if ($manageTransaction) {
        $conn->begin_transaction();
    }
    try {
        $settings = $eligibleForAttendance ? getCurrentAttendanceSettings($conn) : null;
        $timeoutSeconds = $eligibleForAttendance
            ? max(1, (int) $settings['inactivity_minutes'] * 60)
            : (defined('SESSION_INACTIVITY_TIMEOUT_SECONDS') ? SESSION_INACTIVITY_TIMEOUT_SECONDS : 900);
        $stmt = $conn->prepare(
            'SELECT active_session_id,
                    UNIX_TIMESTAMP(active_session_created_at) AS last_activity_at,
                    active_session_expires_at
             FROM users
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare login session claim: ' . $conn->error);
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to inspect current login session: ' . $error);
        }
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) {
            if ($manageTransaction) {
                $conn->rollback();
            }
            return null;
        }

        $oldSessionId = (string) ($current['active_session_id'] ?? '');
        if ($oldSessionId !== '') {
            $lastActivityAt = (int) ($current['last_activity_at'] ?? 0);
            $oldExpiresAt = (int) ($current['active_session_expires_at'] ?? 0);
            $oldTimeout = attendanceSessionTimeout(
                $conn,
                $oldSessionId,
                defined('SESSION_INACTIVITY_TIMEOUT_SECONDS') ? SESSION_INACTIVITY_TIMEOUT_SECONDS : 900
            );
            $expired = $oldExpiresAt <= $now || $lastActivityAt <= $now - $oldTimeout;
            if (!$expired) {
                if ($manageTransaction) {
                    $conn->rollback();
                }
                return null;
            }
            $autoLogoutAt = $lastActivityAt > 0
                ? min($now, $lastActivityAt + $oldTimeout)
                : min($now, $oldExpiresAt > 0 ? $oldExpiresAt : $now);
            closeAttendanceSession($conn, $oldSessionId, $autoLogoutAt, 'Auto');
        }

        $expiresAt = $now + $timeoutSeconds;
        $update = $conn->prepare(
            'UPDATE users
             SET active_session_id = ?, active_session_created_at = CURRENT_TIMESTAMP, active_session_expires_at = ?
             WHERE id = ?'
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare login session update: ' . $conn->error);
        }
        $update->bind_param('sii', $sessionId, $expiresAt, $userId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to claim login session: ' . $error);
        }
        $update->close();

        if ($eligibleForAttendance) {
            startAttendanceSession(
                $conn,
                $userId,
                $sessionId,
                $now,
                $timeoutSeconds,
                $employeeName,
                $username,
                $roleName
            );
        }
        if ($manageTransaction) {
            $conn->commit();
        }
        return $timeoutSeconds;
    } catch (Throwable $error) {
        if ($manageTransaction) {
            $conn->rollback();
        }
        throw $error;
    }
}

function startAttendanceSession(
    mysqli $conn,
    int $userId,
    string $sessionId,
    int $loginTimestamp,
    int $timeoutSeconds,
    string $employeeName,
    string $username,
    string $roleName
): int {
    $settings = getCurrentAttendanceSettings($conn);
    $timezone = new DateTimeZone((string) $settings['timezone']);
    $localDate = (new DateTimeImmutable('@' . $loginTimestamp))
        ->setTimezone($timezone)
        ->format('Y-m-d');
    $historyId = (int) ($settings['settings_history_id'] ?? 0);
    $historyIdValue = $historyId > 0 ? $historyId : null;
    $officeStart = (string) $settings['office_start_time'];
    $officeEnd = (string) $settings['office_end_time'];
    $fullDayHours = (float) $settings['full_day_hours'];
    $halfDayHours = (float) $settings['half_day_hours'];
    $inactivityMinutes = (int) $settings['inactivity_minutes'];
    $timezoneName = (string) $settings['timezone'];

    $stmt = $conn->prepare(
        'INSERT INTO attendance_days
            (user_id, attendance_date, employee_name_snapshot, username_snapshot, role_name_snapshot, settings_history_id,
             office_start_time, office_end_time, full_day_hours, half_day_hours, inactivity_minutes, timezone)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare attendance day creation: ' . $conn->error);
    }
    $stmt->bind_param(
        'issssissddis',
        $userId,
        $localDate,
        $employeeName,
        $username,
        $roleName,
        $historyIdValue,
        $officeStart,
        $officeEnd,
        $fullDayHours,
        $halfDayHours,
        $inactivityMinutes,
        $timezoneName
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create attendance day: ' . $error);
    }
    $dayId = (int) $conn->insert_id;
    $stmt->close();
    if ($dayId <= 0) {
        throw new RuntimeException('Attendance day creation did not return a valid record.');
    }

    $loginAt = attendanceUtcDateTime($loginTimestamp);
    $stmt = $conn->prepare(
        'INSERT INTO attendance_sessions
            (day_id, user_id, session_id, login_at_utc, inactivity_timeout_seconds)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare attendance session creation: ' . $conn->error);
    }
    $stmt->bind_param('iissi', $dayId, $userId, $sessionId, $loginAt, $timeoutSeconds);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create attendance session: ' . $error);
    }
    $stmt->close();
    refreshAttendanceDaySummary($conn, $dayId, $loginTimestamp);
    return $dayId;
}

function refreshAttendanceDaySummary(mysqli $conn, int $dayId, ?int $referenceTimestamp = null): void
{
    $dayStmt = $conn->prepare('SELECT * FROM attendance_days WHERE id = ? LIMIT 1');
    if (!$dayStmt) {
        throw new RuntimeException('Unable to prepare attendance day lookup: ' . $conn->error);
    }
    $dayStmt->bind_param('i', $dayId);
    if (!$dayStmt->execute()) {
        $error = $dayStmt->error;
        $dayStmt->close();
        throw new RuntimeException('Unable to load attendance day: ' . $error);
    }
    $day = $dayStmt->get_result()->fetch_assoc();
    $dayStmt->close();
    if (!$day) {
        throw new RuntimeException('Attendance day was not found.');
    }

    $sessionsStmt = $conn->prepare(
        'SELECT id, login_at_utc, logout_at_utc FROM attendance_sessions WHERE day_id = ? ORDER BY login_at_utc, id'
    );
    if (!$sessionsStmt) {
        throw new RuntimeException('Unable to prepare attendance session summary: ' . $conn->error);
    }
    $sessionsStmt->bind_param('i', $dayId);
    if (!$sessionsStmt->execute()) {
        $error = $sessionsStmt->error;
        $sessionsStmt->close();
        throw new RuntimeException('Unable to load attendance sessions: ' . $error);
    }
    $sessions = $sessionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sessionsStmt->close();

    $now = $referenceTimestamp ?? time();
    $intervals = [];
    $firstLogin = null;
    $finalLogout = null;
    $allClosed = count($sessions) > 0;
    foreach ($sessions as $session) {
        $start = attendanceParseUtcDateTime((string) $session['login_at_utc']);
        $logoutValue = $session['logout_at_utc'] ?? null;
        $end = $logoutValue ? attendanceParseUtcDateTime((string) $logoutValue) : $now;
        if ($end < $start) {
            $end = $start;
        }
        $firstLogin = $firstLogin === null ? $start : min($firstLogin, $start);
        $intervals[] = [$start, $end];
        if ($logoutValue) {
            $finalLogout = $finalLogout === null ? $end : max($finalLogout, $end);
        } else {
            $allClosed = false;
            $duration = $end - $start;
            $sessionRowId = (int) $session['id'];
            $durationUpdate = $conn->prepare(
                'UPDATE attendance_sessions
                 SET session_duration_seconds = ?
                 WHERE id = ? AND logout_at_utc IS NULL'
            );
            if (!$durationUpdate) {
                throw new RuntimeException('Unable to prepare live attendance duration update: ' . $conn->error);
            }
            $durationUpdate->bind_param('ii', $duration, $sessionRowId);
            if (!$durationUpdate->execute()) {
                $error = $durationUpdate->error;
                $durationUpdate->close();
                throw new RuntimeException('Unable to update live attendance duration: ' . $error);
            }
            $durationUpdate->close();
        }
    }

    usort($intervals, static function (array $left, array $right): int {
        return $left[0] <=> $right[0];
    });
    $totalSeconds = 0;
    $currentStart = null;
    $currentEnd = null;
    foreach ($intervals as [$start, $end]) {
        if ($currentStart === null) {
            $currentStart = $start;
            $currentEnd = $end;
            continue;
        }
        if ($start <= $currentEnd) {
            $currentEnd = max($currentEnd, $end);
            continue;
        }
        $totalSeconds += $currentEnd - $currentStart;
        $currentStart = $start;
        $currentEnd = $end;
    }
    if ($currentStart !== null) {
        $totalSeconds += $currentEnd - $currentStart;
    }

    $timezone = new DateTimeZone((string) $day['timezone']);
    $attendanceDate = (string) $day['attendance_date'];
    $officeStart = new DateTimeImmutable($attendanceDate . ' ' . $day['office_start_time'], $timezone);
    $officeEnd = new DateTimeImmutable($attendanceDate . ' ' . $day['office_end_time'], $timezone);
    $lateSeconds = 0;
    $earlySeconds = 0;
    if ($firstLogin !== null) {
        $firstLocal = (new DateTimeImmutable('@' . $firstLogin))->setTimezone($timezone);
        $lateSeconds = max(0, $firstLocal->getTimestamp() - $officeStart->getTimestamp());
    }
    if ($allClosed && $finalLogout !== null) {
        $lastLocal = (new DateTimeImmutable('@' . $finalLogout))->setTimezone($timezone);
        $earlySeconds = max(0, $officeEnd->getTimestamp() - $lastLocal->getTimestamp());
    }

    $status = $firstLogin !== null ? 'Present' : 'Absent';
    $firstLoginUtc = $firstLogin === null ? null : attendanceUtcDateTime($firstLogin);
    $finalLogoutUtc = (!$allClosed || $finalLogout === null) ? null : attendanceUtcDateTime($finalLogout);

    $update = $conn->prepare(
        'UPDATE attendance_days
         SET total_work_seconds = ?, attendance_status = ?, first_login_at_utc = ?,
             final_logout_at_utc = ?, late_seconds = ?, early_logout_seconds = ?
         WHERE id = ?'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare attendance summary update: ' . $conn->error);
    }
    $update->bind_param(
        'isssiii',
        $totalSeconds,
        $status,
        $firstLoginUtc,
        $finalLogoutUtc,
        $lateSeconds,
        $earlySeconds,
        $dayId
    );
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to update attendance summary: ' . $error);
    }
    $update->close();
}

function closeAttendanceSession(
    mysqli $conn,
    string $sessionId,
    int $logoutTimestamp,
    string $logoutType
): bool {
    if (!in_array($logoutType, ['Manual', 'Auto', 'System'], true)) {
        throw new InvalidArgumentException('Invalid attendance logout type.');
    }
    $stmt = $conn->prepare(
        'SELECT id, day_id, login_at_utc FROM attendance_sessions
         WHERE BINARY session_id = BINARY ? AND logout_at_utc IS NULL
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare attendance session closure: ' . $conn->error);
    }
    $stmt->bind_param('s', $sessionId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load attendance session for closure: ' . $error);
    }
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        return false;
    }

    $loginTimestamp = attendanceParseUtcDateTime((string) $session['login_at_utc']);
    $logoutTimestamp = max($loginTimestamp, $logoutTimestamp);
    $duration = $logoutTimestamp - $loginTimestamp;
    $logoutAt = attendanceUtcDateTime($logoutTimestamp);
    $update = $conn->prepare(
        'UPDATE attendance_sessions
         SET logout_at_utc = ?, session_duration_seconds = ?, logout_type = ?
         WHERE id = ? AND logout_at_utc IS NULL'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare attendance session update: ' . $conn->error);
    }
    $sessionIdValue = (int) $session['id'];
    $update->bind_param('sisi', $logoutAt, $duration, $logoutType, $sessionIdValue);
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to close attendance session: ' . $error);
    }
    $closed = $update->affected_rows === 1;
    $update->close();
    if ($closed) {
        refreshAttendanceDaySummary($conn, (int) $session['day_id'], $logoutTimestamp);
    }
    return $closed;
}

function refreshOpenAttendanceDays(mysqli $conn, string $dateFrom, string $dateTo, int $now): void
{
    $stmt = $conn->prepare(
        'SELECT DISTINCT s.day_id
         FROM attendance_sessions s
         JOIN attendance_days d ON d.id = s.day_id
         WHERE s.logout_at_utc IS NULL AND d.attendance_date BETWEEN ? AND ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare open attendance lookup: ' . $conn->error);
    }
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load open attendance days: ' . $error);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        refreshAttendanceDaySummary($conn, (int) $row['day_id'], $now);
    }
}

function expireStaleAttendanceSessions(mysqli $conn, int $now): void
{
    $result = $conn->query(
        'SELECT s.session_id, s.user_id, s.inactivity_timeout_seconds,
                UNIX_TIMESTAMP(u.active_session_created_at) AS last_activity_at,
                u.active_session_expires_at
         FROM attendance_sessions s
         JOIN users u ON u.id = s.user_id AND BINARY u.active_session_id = BINARY s.session_id
         WHERE s.logout_at_utc IS NULL'
    );
    if (!$result) {
        throw new RuntimeException('Unable to find expired attendance sessions: ' . $conn->error);
    }
    $sessions = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($sessions as $session) {
        $timeout = max(1, (int) $session['inactivity_timeout_seconds']);
        $lastActivityAt = (int) ($session['last_activity_at'] ?? 0);
        $expiresAt = (int) ($session['active_session_expires_at'] ?? 0);
        if ($expiresAt > $now && $lastActivityAt > $now - $timeout) {
            continue;
        }
        $sessionId = (string) $session['session_id'];
        $userId = (int) $session['user_id'];
        $logoutAt = $lastActivityAt > 0
            ? min($now, $lastActivityAt + $timeout)
            : min($now, $expiresAt > 0 ? $expiresAt : $now);
        $cutoff = $now - $timeout;
        $conn->begin_transaction();
        try {
            $lockUser = $conn->prepare('SELECT active_session_id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$lockUser) {
                throw new RuntimeException('Unable to prepare stale attendance user lock: ' . $conn->error);
            }
            $lockUser->bind_param('i', $userId);
            if (!$lockUser->execute()) {
                $error = $lockUser->error;
                $lockUser->close();
                throw new RuntimeException('Unable to lock stale attendance user: ' . $error);
            }
            $lockedUser = $lockUser->get_result()->fetch_assoc();
            $lockUser->close();
            if (!hash_equals((string) ($lockedUser['active_session_id'] ?? ''), $sessionId)) {
                $conn->rollback();
                continue;
            }
            closeAttendanceSession($conn, $sessionId, $logoutAt, 'Auto');
            $clear = $conn->prepare(
                'UPDATE users
                 SET active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL
                 WHERE id = ? AND active_session_id = ?
                   AND (active_session_expires_at IS NULL OR active_session_expires_at <= ?
                        OR active_session_created_at IS NULL
                        OR UNIX_TIMESTAMP(active_session_created_at) <= ?)'
            );
            if (!$clear) {
                throw new RuntimeException('Unable to prepare stale attendance session cleanup: ' . $conn->error);
            }
            $clear->bind_param('isii', $userId, $sessionId, $now, $cutoff);
            if (!$clear->execute()) {
                $error = $clear->error;
                $clear->close();
                throw new RuntimeException('Unable to clear stale attendance session: ' . $error);
            }
            $clear->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
}
