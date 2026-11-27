<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/employee_working_reports.php';

function employeeWorkingReportLog(string $message): void
{
    $line = '[employee-working-reports] ' . $message . PHP_EOL;
    fwrite(STDERR, $line);
    error_log(trim($line));
}

function employeeWorkingReportPeriodDate(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
        throw new RuntimeException('The database returned an invalid report period timestamp.');
    }
    return $date;
}

function employeeWorkingReportEligibleEmployees(mysqli $conn): array
{
    return employeeWorkingReportFetchRows(
        $conn,
        "SELECT u.id,
                COALESCE(NULLIF(u.employee_name, ''), NULLIF(u.username, ''), CONCAT('User ', u.id)) AS employee_name,
               COALESCE(NULLIF(TRIM(r.role_name), ''), NULLIF(TRIM(u.role), ''), 'Unknown role') AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE LOWER(COALESCE(u.status, 'active')) = 'active'
           AND (r.status IS NULL OR LOWER(r.status) = 'active')
          AND LOWER(TRIM(COALESCE(NULLIF(TRIM(r.role_name), ''), NULLIF(TRIM(u.role), ''), '')))
               NOT IN ('dealer', 'technician')
         ORDER BY employee_name ASC, u.id ASC"
    );
}

function employeeWorkingReportEnsureRow(
    mysqli $conn,
    array $employee,
    string $periodStart,
    string $periodEnd,
    string $timezoneName
): array {
    $employeeId = (int) $employee['id'];
    $employeeCode = 'EMP' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT);
    $insert = $conn->prepare(
        "INSERT IGNORE INTO employee_working_reports
            (employee_id, employee_code, employee_name, role_name,
             period_start, period_end, timezone_name, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'queued')"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare report metadata insert: ' . $conn->error);
    }
    $employeeName = (string) $employee['employee_name'];
    $roleName = (string) $employee['role_name'];
    $insert->bind_param(
        'issssss',
        $employeeId,
        $employeeCode,
        $employeeName,
        $roleName,
        $periodStart,
        $periodEnd,
        $timezoneName
    );
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        throw new RuntimeException('Unable to create report metadata: ' . $error);
    }
    $insert->close();

    $rows = employeeWorkingReportFetchRows(
        $conn,
        'SELECT id, employee_id, employee_code, employee_name, role_name,
                period_start, period_end, timezone_name, status
         FROM employee_working_reports
         WHERE employee_id = ? AND period_start = ? AND period_end = ?
         LIMIT 1',
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );
    if (!$rows) {
        throw new RuntimeException('Report metadata could not be loaded after insert.');
    }
    return $rows[0];
}

function employeeWorkingReportMarkFailed(mysqli $conn, int $reportId, string $message): void
{
    $stmt = $conn->prepare(
        "UPDATE employee_working_reports
         SET status = 'failed', file_path = NULL, generated_at = NULL,
             error_details = ?
         WHERE id = ? AND status <> 'generated'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare report failure status: ' . $conn->error);
    }
    $errorDetails = mb_substr($message, 0, 4000);
    $stmt->bind_param('si', $errorDetails, $reportId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to record report failure: ' . $error);
    }
    $stmt->close();
}

function employeeWorkingReportGenerate(
    mysqli $conn,
    array $reportRow,
    string $timezoneName
): bool {
    if ((string) $reportRow['status'] === 'generated') {
        return true;
    }

    $reportId = (int) $reportRow['id'];
    $processing = $conn->prepare(
        "UPDATE employee_working_reports
         SET status = 'processing', attempt_count = attempt_count + 1,
             error_details = NULL, file_path = NULL
         WHERE id = ? AND status <> 'generated'"
    );
    if (!$processing) {
        throw new RuntimeException('Unable to prepare report generation status: ' . $conn->error);
    }
    $processing->bind_param('i', $reportId);
    if (!$processing->execute()) {
        $error = $processing->error;
        $processing->close();
        throw new RuntimeException('Unable to set report generation status: ' . $error);
    }
    $processing->close();

    try {
        $nowRows = employeeWorkingReportFetchRows(
            $conn,
            'SELECT NOW() AS generated_at'
        );
        $generatedAt = (string) ($nowRows[0]['generated_at'] ?? '');
        if ($generatedAt === '') {
            throw new RuntimeException('The database did not return a report generation timestamp.');
        }

        $employee = [
            'id' => (int) $reportRow['employee_id'],
            'employee_name' => (string) $reportRow['employee_name'],
            'role_name' => (string) $reportRow['role_name']
        ];
        $report = employeeWorkingReportBuild(
            $conn,
            $employee,
            (string) $reportRow['period_start'],
            (string) $reportRow['period_end'],
            $generatedAt,
            $timezoneName
        );
        $filename = employeeWorkingReportFilename(
            (int) $reportRow['employee_id'],
            (string) $reportRow['period_start']
        );
        $storedFilename = employeeWorkingReportRenderPdf($report, $filename);

        $success = $conn->prepare(
            "UPDATE employee_working_reports
             SET status = 'generated', file_path = ?, generated_at = NOW(),
                 timezone_name = ?, error_details = NULL
             WHERE id = ?"
        );
        if (!$success) {
            throw new RuntimeException('Unable to prepare report success status: ' . $conn->error);
        }
        $success->bind_param('ssi', $storedFilename, $timezoneName, $reportId);
        if (!$success->execute()) {
            $error = $success->error;
            $success->close();
            throw new RuntimeException('Unable to record report success: ' . $error);
        }
        $success->close();
        return true;
    } catch (Throwable $error) {
        employeeWorkingReportMarkFailed($conn, $reportId, $error->getMessage());
        employeeWorkingReportLog(
            'Report ' . $reportId . ' for employee ' . (int) $reportRow['employee_id'] .
            ' failed: ' . $error->getMessage()
        );
        return false;
    }
}

function employeeWorkingReportAttempt(
    mysqli $conn,
    array $reportRow,
    string $timezoneName
): bool {
    try {
        return employeeWorkingReportGenerate($conn, $reportRow, $timezoneName);
    } catch (Throwable $error) {
        $reportId = (int) ($reportRow['id'] ?? 0);
        if ($reportId > 0) {
            try {
                employeeWorkingReportMarkFailed($conn, $reportId, $error->getMessage());
            } catch (Throwable $statusError) {
                employeeWorkingReportLog(
                    'Unable to record failure for report ' . $reportId . ': ' . $statusError->getMessage()
                );
            }
        }
        employeeWorkingReportLog(
            'Report ' . $reportId . ' for employee ' . (int) ($reportRow['employee_id'] ?? 0) .
            ' failed before rendering completed: ' . $error->getMessage()
        );
        return false;
    }
}

$conn = (new Database())->getConnection();
if (!$conn) {
    employeeWorkingReportLog('Database connection failed.');
    exit(1);
}

$lockResult = $conn->query("SELECT GET_LOCK('employee_working_report_scheduler', 0) AS acquired");
if (!$lockResult) {
    employeeWorkingReportLog('Unable to acquire the scheduler lock: ' . $conn->error);
    $conn->close();
    exit(1);
}
$lockAcquired = (int) ($lockResult->fetch_assoc()['acquired'] ?? 0) === 1;
if (!$lockAcquired) {
    employeeWorkingReportLog('Another report generation run is active; this invocation is deferred.');
    $conn->close();
    exit(0);
}

$failedReports = 0;
$attemptedReportIds = [];
$exitCode = 0;
try {
    $clockRows = employeeWorkingReportFetchRows(
        $conn,
        'SELECT NOW() AS database_now, @@session.time_zone AS session_timezone,
                @@system_time_zone AS system_timezone'
    );
    $databaseNow = (string) ($clockRows[0]['database_now'] ?? '');
    $timezoneName = (string) ($clockRows[0]['session_timezone'] ?? '');
    if ($timezoneName === '' || $timezoneName === 'SYSTEM') {
        $timezoneName = (string) ($clockRows[0]['system_timezone'] ?? 'Server local time');
    }
    $latestEnd = employeeWorkingReportPeriodDate($databaseNow);
    $hour = (int) $latestEnd->format('G');
    $latestEnd = $latestEnd->setTime($hour - ($hour % 2), 0, 0);

    if (!$conn->query('INSERT IGNORE INTO employee_working_report_schedule (id) VALUES (1)')) {
        throw new RuntimeException('Unable to initialize report schedule state: ' . $conn->error);
    }
    $stateRows = employeeWorkingReportFetchRows(
        $conn,
        'SELECT last_period_end
         FROM employee_working_report_schedule
         WHERE id = 1
         LIMIT 1'
    );
    if (!$stateRows) {
        throw new RuntimeException('The report schedule state row is missing.');
    }
    $cursorValue = $stateRows[0]['last_period_end'];
    if ($cursorValue === null) {
        $cursor = $latestEnd->modify('-2 hours');
        $seed = $conn->prepare(
            'UPDATE employee_working_report_schedule SET last_period_end = ? WHERE id = 1'
        );
        if (!$seed) {
            throw new RuntimeException('Unable to initialize report schedule cursor: ' . $conn->error);
        }
        $cursorValue = $cursor->format('Y-m-d H:i:s');
        $seed->bind_param('s', $cursorValue);
        if (!$seed->execute()) {
            $error = $seed->error;
            $seed->close();
            throw new RuntimeException('Unable to initialize report schedule cursor: ' . $error);
        }
        $seed->close();
    } else {
        $cursor = employeeWorkingReportPeriodDate((string) $cursorValue);
    }

    if (!$conn->query(
        "UPDATE employee_working_reports
         SET status = 'failed',
             error_details = 'Previous report generation process ended before status update.'
         WHERE status = 'processing'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    )) {
        throw new RuntimeException('Unable to recover stale report attempts: ' . $conn->error);
    }

    $retryRows = employeeWorkingReportFetchRows(
        $conn,
        "SELECT id, employee_id, employee_code, employee_name, role_name,
                period_start, period_end, timezone_name, status
         FROM employee_working_reports
         WHERE status IN ('queued', 'failed')
         ORDER BY period_start ASC, employee_id ASC"
    );
    foreach ($retryRows as $retryRow) {
        $reportId = (int) $retryRow['id'];
        $attemptedReportIds[$reportId] = true;
        if (!employeeWorkingReportAttempt($conn, $retryRow, (string) $retryRow['timezone_name'])) {
            $failedReports++;
        }
    }

    $employees = employeeWorkingReportEligibleEmployees($conn);
    while ($cursor < $latestEnd) {
        $periodStart = $cursor->format('Y-m-d H:i:s');
        $periodEnd = $cursor->modify('+2 hours');
        $periodEndValue = $periodEnd->format('Y-m-d H:i:s');

        foreach ($employees as $employee) {
            try {
                $reportRow = employeeWorkingReportEnsureRow(
                    $conn,
                    $employee,
                    $periodStart,
                    $periodEndValue,
                    $timezoneName
                );
                $reportId = (int) $reportRow['id'];
                if ($reportRow['status'] === 'generated' || isset($attemptedReportIds[$reportId])) {
                    continue;
                }
                $attemptedReportIds[$reportId] = true;
                if (!employeeWorkingReportAttempt($conn, $reportRow, $timezoneName)) {
                    $failedReports++;
                }
            } catch (Throwable $error) {
                $failedReports++;
                employeeWorkingReportLog(
                    'Unable to generate report for employee ' . (int) $employee['id'] .
                    ' in period ' . $periodStart . '–' . $periodEndValue . ': ' . $error->getMessage()
                );
            }
        }

        $advance = $conn->prepare(
            'UPDATE employee_working_report_schedule SET last_period_end = ? WHERE id = 1'
        );
        if (!$advance) {
            throw new RuntimeException('Unable to advance report schedule cursor: ' . $conn->error);
        }
        $advance->bind_param('s', $periodEndValue);
        if (!$advance->execute()) {
            $error = $advance->error;
            $advance->close();
            throw new RuntimeException('Unable to advance report schedule cursor: ' . $error);
        }
        $advance->close();
        $cursor = $periodEnd;
    }

    $summary = [
        'generated_or_retried' => count($attemptedReportIds),
        'failed' => $failedReports,
        'latest_completed_period_end' => $latestEnd->format('Y-m-d H:i:s'),
        'timezone' => $timezoneName
    ];
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if ($failedReports > 0) {
        $exitCode = 1;
    }
} catch (Throwable $error) {
    employeeWorkingReportLog('Scheduler run failed: ' . $error->getMessage());
    $exitCode = 1;
} finally {
    $conn->query("SELECT RELEASE_LOCK('employee_working_report_scheduler')");
    $conn->close();
}

exit($exitCode);
