<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../utils/employee_working_reports.php';

function employeeWorkingReportsAccess(array $user): array
{
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        sendResponse(false, 'Invalid authenticated user.', [], [], 401);
    }

    $permissions = getUserPermissions($userId);
    return [
        'user_id' => $userId,
        'can_view_all' => in_array('employee_working_reports.view', $permissions, true),
        'can_view_own' => in_array('employee_working_reports.own.view', $permissions, true),
        'can_download' => in_array('employee_working_reports.download', $permissions, true)
    ];
}

function employeeWorkingReportsRequireSchema(mysqli $conn): void
{
    $result = $conn->query(
        "SELECT COUNT(*) AS table_count
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'employee_working_reports'"
    );
    if (!$result) {
        sendResponse(false, 'Unable to verify employee report schema.', [], [], 500);
    }
    $tableCount = (int) ($result->fetch_assoc()['table_count'] ?? 0);
    if ($tableCount === 0) {
        sendResponse(
            false,
            'Employee working reports are not installed. Apply backend/migrations/employee_working_reports.sql.',
            [],
            [],
            503
        );
    }
}

function employeeWorkingReportsValidDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    return checkdate(
        (int) substr($value, 5, 2),
        (int) substr($value, 8, 2),
        (int) substr($value, 0, 4)
    );
}
