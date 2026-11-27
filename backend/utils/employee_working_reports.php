<?php

function employeeWorkingReportFetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare employee report query: ' . $conn->error);
    }

    if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to bind employee report query: ' . $error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to execute employee report query: ' . $error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to read employee report query: ' . $error);
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function employeeWorkingReportColumnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if (!$result) {
        throw new RuntimeException('Unable to verify report source schema: ' . $conn->error);
    }
    return $result->num_rows > 0;
}

function employeeWorkingReportNotConnectedOutcomes(): array
{
    return [
        'Did not pick',
        'Busy in another call',
        'User disconnected the call',
        'Switch off',
        'Out of coverage area / Network issue',
        'Call not connected / Cannot be completed',
        'Incorrect / Invalid number',
        'Incoming calls not available',
        'Number not in use / Does not exist / Out of service',
        'Other reason'
    ];
}

function employeeWorkingReportBuild(
    mysqli $conn,
    array $employee,
    string $periodStart,
    string $periodEnd,
    string $generatedAt,
    string $timezoneName
): array {
    $employeeId = (int) $employee['id'];
    $employeeCode = 'EMP' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT);
    $employeeName = trim((string) ($employee['employee_name'] ?? ''));
    if ($employeeName === '') {
        $employeeName = 'User ' . $employeeId;
    }
    $roleName = trim((string) ($employee['role_name'] ?? ''));
    if ($roleName === '') {
        $roleName = 'Unknown role';
    }

    $notConnectedOutcomes = employeeWorkingReportNotConnectedOutcomes();

    $callRows = employeeWorkingReportFetchRows(
        $conn,
        "SELECT f.id, f.lead_id,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', f.lead_id)) AS lead_name,
                f.completed_at AS occurred_at, f.call_outcome, f.remarks
         FROM crm_lead_followups f
         LEFT JOIN crm_leads l ON l.id = f.lead_id
         WHERE f.updated_by_user_id = ?
           AND f.completed_at >= ? AND f.completed_at < ?
           AND (f.call_outcome IS NOT NULL OR NULLIF(TRIM(f.remarks), '') IS NOT NULL)
           AND NOT (
               f.call_outcome IS NULL
               AND COALESCE(f.remarks, '') = 'Scheduled during lead assignment'
           )
         ORDER BY f.completed_at ASC, f.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $connectedCalls = 0;
    $notConnectedCalls = 0;
    $unansweredCalls = 0;
    $contactedLeadIds = [];
    foreach ($callRows as &$call) {
        $outcome = (string) ($call['call_outcome'] ?? '');
        $isNotConnected = in_array($outcome, $notConnectedOutcomes, true);
        $call['connection_result'] = $isNotConnected ? 'Not connected' : 'Connected';
        $call['duration'] = 'Not recorded';
        if ($isNotConnected) {
            $notConnectedCalls++;
            if ($outcome === 'Did not pick') {
                $unansweredCalls++;
            }
        } else {
            $connectedCalls++;
        }
        $contactedLeadIds[(int) $call['lead_id']] = true;
    }
    unset($call);

    $hasCancellationColumn = employeeWorkingReportColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
    $cancellationSelect = $hasCancellationColumn ? 'f.cancelled_at' : 'NULL AS cancelled_at';
    $followupRows = employeeWorkingReportFetchRows(
        $conn,
        "SELECT f.id, f.lead_id, f.created_at, f.completed_at,
                {$cancellationSelect}, f.next_followup_date, f.next_followup_time,
                f.call_outcome, f.remarks,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', f.lead_id)) AS lead_name
         FROM crm_lead_followups f
         LEFT JOIN crm_leads l ON l.id = f.lead_id
         WHERE f.updated_by_user_id = ?
           AND f.created_at >= ? AND f.created_at < ?
           AND NOT (
               f.call_outcome IS NULL
               AND COALESCE(f.remarks, '') = 'Scheduled during lead assignment'
           )
         ORDER BY f.created_at ASC, f.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );
    $notCompletedCreatedRows = [];
    foreach ($followupRows as $followup) {
        $completedByPeriodEnd = $followup['completed_at'] !== null &&
            (string) $followup['completed_at'] < $periodEnd;
        if (!$completedByPeriodEnd) {
            $followup['period_status'] = $followup['cancelled_at'] !== null &&
                (string) $followup['cancelled_at'] < $periodEnd
                ? 'Cancelled'
                : 'Not completed at period end';
            $notCompletedCreatedRows[] = $followup;
        }
    }

    $completedFollowupRows = employeeWorkingReportFetchRows(
        $conn,
        "SELECT f.id, f.lead_id, f.completed_at,
                f.next_followup_date, f.next_followup_time, f.call_outcome, f.remarks,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', f.lead_id)) AS lead_name
         FROM crm_lead_followups f
         LEFT JOIN crm_leads l ON l.id = f.lead_id
         WHERE f.updated_by_user_id = ?
           AND f.completed_at >= ? AND f.completed_at < ?
         ORDER BY f.completed_at ASC, f.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $hasSoftDeleteColumn = employeeWorkingReportColumnExists($conn, 'crm_leads', 'deleted_at');
    $activeLeadCondition = $hasSoftDeleteColumn ? ' AND l.deleted_at IS NULL' : '';
    $newLeads = employeeWorkingReportFetchRows(
        $conn,
        "SELECT l.id, l.lead_name, l.created_at
         FROM crm_leads l
         WHERE l.created_by_user_id = ?
           AND l.created_at >= ? AND l.created_at < ?
         ORDER BY l.created_at ASC, l.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $assignmentsReceived = employeeWorkingReportFetchRows(
        $conn,
        "SELECT a.id, a.lead_id, a.assignment_type, a.assigned_at,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', a.lead_id)) AS lead_name,
                COALESCE(NULLIF(actor.employee_name, ''), actor.username, 'Unknown user') AS assigned_by
         FROM crm_lead_assignments a
         LEFT JOIN crm_leads l ON l.id = a.lead_id
         LEFT JOIN users actor ON actor.id = a.assigned_by_user_id
         WHERE a.assigned_to_user_id = ?
           AND a.assigned_at >= ? AND a.assigned_at < ?
         ORDER BY a.assigned_at ASC, a.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $assignmentsMade = employeeWorkingReportFetchRows(
        $conn,
        "SELECT a.id, a.lead_id, a.assignment_type, a.assigned_at,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', a.lead_id)) AS lead_name,
                COALESCE(NULLIF(target.employee_name, ''), target.username, 'Unknown user') AS assigned_to
         FROM crm_lead_assignments a
         LEFT JOIN crm_leads l ON l.id = a.lead_id
         LEFT JOIN users target ON target.id = a.assigned_to_user_id
         WHERE a.assigned_by_user_id = ?
           AND a.assigned_at >= ? AND a.assigned_at < ?
         ORDER BY a.assigned_at ASC, a.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $transfers = employeeWorkingReportFetchRows(
        $conn,
        "SELECT t.id, t.lead_id, t.transferred_at, t.transfer_type,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', t.lead_id)) AS lead_name,
                t.previous_employee_id, t.new_employee_id,
                COALESCE(NULLIF(previous_user.employee_name, ''), previous_user.username, 'Unknown user') AS previous_employee,
                COALESCE(NULLIF(new_user.employee_name, ''), new_user.username, 'Unknown user') AS new_employee
         FROM crm_lead_transfer_history t
         LEFT JOIN crm_leads l ON l.id = t.lead_id
         LEFT JOIN users previous_user ON previous_user.id = t.previous_employee_id
         LEFT JOIN users new_user ON new_user.id = t.new_employee_id
         WHERE t.transferred_by_user_id = ?
           AND t.transferred_at >= ? AND t.transferred_at < ?
         ORDER BY t.transferred_at ASC, t.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );

    $workflowHistory = employeeWorkingReportFetchRows(
        $conn,
        "SELECT h.id, h.changed_at, h.field_changed, h.new_value
         FROM history h
         WHERE h.changed_by_user_id = ?
           AND h.module = 'CRM Lead Workflow'
           AND h.field_changed IN ('lead_status_id', 'lead_stage_id')
           AND h.changed_at >= ? AND h.changed_at < ?
         ORDER BY h.changed_at ASC, h.id ASC",
        'iss',
        [$employeeId, $periodStart, $periodEnd]
    );
    $statusUpdates = [];
    foreach ($workflowHistory as $history) {
        $newValue = json_decode((string) ($history['new_value'] ?? ''), true);
        $statusUpdates[] = [
            'changed_at' => $history['changed_at'],
            'field' => (string) $history['field_changed'],
            'lead_id' => is_array($newValue) && isset($newValue['lead_id'])
                ? (int) $newValue['lead_id']
                : null
        ];
    }

    $currentSchedules = employeeWorkingReportFetchRows(
        $conn,
        "SELECT f.id, f.lead_id, f.next_followup_date, f.next_followup_time,
                f.remarks, f.customer_need,
                COALESCE(NULLIF(l.lead_name, ''), CONCAT('Lead #', f.lead_id)) AS lead_name,
                CONCAT(f.next_followup_date, ' ',
                    COALESCE(f.next_followup_time, '23:59:59')) AS due_at
         FROM crm_lead_followups f
         INNER JOIN crm_leads l ON l.id = f.lead_id
         WHERE l.assigned_user_id = ?{$activeLeadCondition}
           AND f.completed_at IS NULL
           AND f.next_followup_date IS NOT NULL" .
            ($hasCancellationColumn ? ' AND f.cancelled_at IS NULL' : '') . "
           AND NOT EXISTS (
               SELECT 1
               FROM crm_lead_followups newer
               WHERE newer.lead_id = f.lead_id
                 AND newer.completed_at IS NULL
                 AND newer.next_followup_date IS NOT NULL" .
                    ($hasCancellationColumn ? ' AND newer.cancelled_at IS NULL' : '') . "
                 AND (
                     newer.created_at > f.created_at
                     OR (newer.created_at = f.created_at AND newer.id > f.id)
                 )
           )
         ORDER BY f.next_followup_date ASC,
                  COALESCE(f.next_followup_time, '23:59:59') ASC,
                  f.id ASC",
        'i',
        [$employeeId]
    );
    $pendingRows = [];
    $overdueRows = [];
    foreach ($currentSchedules as $schedule) {
        if ((string) $schedule['due_at'] < $generatedAt) {
            $schedule['snapshot_status'] = 'Overdue';
            $overdueRows[] = $schedule;
        } else {
            $schedule['snapshot_status'] = 'Pending';
            $pendingRows[] = $schedule;
        }
    }

    return [
        'employee' => [
            'id' => $employeeId,
            'employee_code' => $employeeCode,
            'name' => $employeeName,
            'role' => $roleName
        ],
        'period' => [
            'start' => $periodStart,
            'end' => $periodEnd,
            'reporting_date' => substr($periodStart, 0, 10),
            'timezone' => $timezoneName
        ],
        'generated_at' => $generatedAt,
        'calls' => [
            'total' => count($callRows),
            'connected' => $connectedCalls,
            'unanswered' => $unansweredCalls,
            'not_connected' => $notConnectedCalls,
            'other_not_connected' => max(0, $notConnectedCalls - $unansweredCalls),
            'duration_available' => false,
            'rows' => $callRows
        ],
        'followups' => [
            'created_in_period' => count($followupRows),
            'not_completed_at_period_end' => count($notCompletedCreatedRows),
            'completed_in_period' => count($completedFollowupRows),
            'pending_snapshot' => count($pendingRows),
            'overdue_snapshot' => count($overdueRows),
            'created_rows' => $followupRows,
            'not_completed_rows' => $notCompletedCreatedRows,
            'completed_rows' => $completedFollowupRows,
            'pending_rows' => $pendingRows,
            'overdue_rows' => $overdueRows
        ],
        'leads' => [
            'contacted' => count($contactedLeadIds),
            'created' => count($newLeads),
            'assigned_to_employee' => count($assignmentsReceived),
            'assigned_by_employee' => count($assignmentsMade),
            'transfers_made' => count($transfers),
            'transfers_received' => count(array_filter(
                $transfers,
                static fn(array $transfer): bool => (int) $transfer['new_employee_id'] === $employeeId
            )),
            'transfers_sent' => count(array_filter(
                $transfers,
                static fn(array $transfer): bool => (int) $transfer['previous_employee_id'] === $employeeId
            )),
            'status_updates_recorded' => count($statusUpdates),
            'requiring_followup' => count(array_unique(array_merge(
                array_column($pendingRows, 'lead_id'),
                array_column($overdueRows, 'lead_id')
            ))),
            'new_rows' => $newLeads,
            'assigned_to_rows' => $assignmentsReceived,
            'assigned_by_rows' => $assignmentsMade,
            'transfer_rows' => $transfers,
            'status_update_rows' => $statusUpdates
        ],
        'data_notes' => [
            'Calls are represented by completed CRM follow-up interaction records; there is no separate telephony log or call-duration field.',
            'Connected/unconnected classification follows the call-outcome choices used by the CRM follow-up history.',
            'The follow-up table stores updated_by_user_id, not an immutable creator. Follow-up attribution therefore uses the stored actor on each record.',
            'Pending and overdue are the latest active schedules assigned to this employee, as of PDF generation. A missing due time uses the CRM end-of-day convention.',
            'Lead status/stage updates include CRM Lead Workflow history entries only; direct changes without history cannot be reconstructed.'
        ]
    ];
}

function employeeWorkingReportFilename(int $employeeId, string $periodStart): string
{
    $periodStamp = str_replace([' ', ':'], ['_', '-'], substr($periodStart, 0, 16));
    return 'Employee_Working_Report_EMP' .
        str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT) .
        '_' . $periodStamp . '.pdf';
}

function employeeWorkingReportStorageDirectory(): string
{
    $configuredPath = getenv('EMPLOYEE_WORKING_REPORTS_DIR');
    $path = $configuredPath !== false && trim($configuredPath) !== ''
        ? trim($configuredPath)
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'employee-working-reports';

    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create the employee report storage directory.');
    }
    $realPath = realpath($path);
    if ($realPath === false || !is_writable($realPath)) {
        throw new RuntimeException('The employee report storage directory is not writable.');
    }
    return $realPath;
}

function employeeWorkingReportRenderPdf(array $report, string $filename): string
{
    $storageDirectory = employeeWorkingReportStorageDirectory();
    $finalPath = $storageDirectory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $storageDirectory . DIRECTORY_SEPARATOR .
        '.' . $filename . '.tmp-' . bin2hex(random_bytes(6));
    $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'frontend' .
        DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'employeeWorkingReportPdf.mjs';
    if (!is_file($scriptPath)) {
        throw new RuntimeException('The employee report PDF renderer is missing.');
    }

    $nodeBinary = trim((string) getenv('NODE_BINARY'));
    if ($nodeBinary === '') {
        $nodeBinary = 'node';
    }
    $command = escapeshellarg($nodeBinary) . ' ' .
        escapeshellarg($scriptPath) . ' ' . escapeshellarg($temporaryPath);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname($scriptPath));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the Node.js PDF renderer.');
    }

    try {
        $json = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $written = fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $process = null;

        if ($written !== strlen($json) || $exitCode !== 0) {
            $detail = trim((string) $stderr . ' ' . (string) $stdout);
            throw new RuntimeException(
                'PDF generation failed' . ($detail !== '' ? ': ' . $detail : '.')
            );
        }
        if (!is_file($temporaryPath) || filesize($temporaryPath) < 100) {
            throw new RuntimeException('The PDF renderer returned an empty report file.');
        }
        if (!chmod($temporaryPath, 0640)) {
            throw new RuntimeException('Unable to set safe permissions on the generated report.');
        }
        if (is_file($finalPath) && !unlink($finalPath)) {
            throw new RuntimeException('Unable to replace an incomplete prior report file.');
        }
        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('Unable to move the generated report into storage.');
        }
        return $filename;
    } catch (Throwable $error) {
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
        throw $error;
    }
}
