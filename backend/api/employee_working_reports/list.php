<?php

require_once __DIR__ . '/common.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$user = authenticate();
$access = employeeWorkingReportsAccess($user);
if (!$access['can_view_all'] && !$access['can_view_own']) {
    sendResponse(false, 'You do not have permission to view employee working reports.', [], [], 403);
}

$employeeIdValue = trim((string) ($_GET['employee_id'] ?? ''));
$employeeId = $employeeIdValue === ''
    ? null
    : filter_var($employeeIdValue, FILTER_VALIDATE_INT);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($employeeIdValue !== '' && ($employeeId === false || $employeeId <= 0)) {
    sendResponse(false, 'Select a valid employee.', [], [], 400);
}
if (($dateFrom !== '' && !employeeWorkingReportsValidDate($dateFrom)) ||
    ($dateTo !== '' && !employeeWorkingReportsValidDate($dateTo))) {
    sendResponse(false, 'Enter a valid report date filter.', [], [], 400);
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    sendResponse(false, 'From date cannot be later than To date.', [], [], 400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 25)));
$where = [];
$params = [];
$types = '';
if (!$access['can_view_all']) {
    $where[] = 'employee_id = ?';
    $params[] = $access['user_id'];
    $types .= 'i';
} elseif ($employeeId !== null && $employeeId !== '') {
    $where[] = 'employee_id = ?';
    $params[] = (int) $employeeId;
    $types .= 'i';
}
if ($dateFrom !== '') {
    $where[] = 'period_start >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = 'period_start < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo . ' 00:00:00';
    $types .= 's';
}
$condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
employeeWorkingReportsRequireSchema($conn);

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM employee_working_reports' . $condition);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare employee report count: ' . $error, [], [], 500);
}
if ($types !== '' && !$countStmt->bind_param($types, ...$params)) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind employee report filters: ' . $error, [], [], 500);
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count employee reports: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$offset = ($page - 1) * $pageSize;
$errorSelect = $access['can_view_all'] ? 'error_details' : 'NULL AS error_details';
$sql =
    "SELECT id, employee_id, employee_code, employee_name, role_name,
            period_start, period_end, timezone_name, status, file_path,
            generated_at, attempt_count, {$errorSelect}
     FROM employee_working_reports{$condition}
     ORDER BY period_start DESC, employee_name ASC, id DESC
     LIMIT ? OFFSET ?";
$rowsStmt = $conn->prepare($sql);
if (!$rowsStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare employee report list: ' . $error, [], [], 500);
}
$rowsParams = $params;
$rowsParams[] = $pageSize;
$rowsParams[] = $offset;
$rowsTypes = $types . 'ii';
if (!$rowsStmt->bind_param($rowsTypes, ...$rowsParams)) {
    $error = $rowsStmt->error;
    $rowsStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind employee report list filters: ' . $error, [], [], 500);
}
if (!$rowsStmt->execute()) {
    $error = $rowsStmt->error;
    $rowsStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load employee reports: ' . $error, [], [], 500);
}
$reports = $rowsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rowsStmt->close();
$conn->close();

sendResponse(true, 'Employee working reports loaded.', [
    'reports' => $reports,
    'can_view_all' => $access['can_view_all'],
    'can_download' => $access['can_download'],
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
