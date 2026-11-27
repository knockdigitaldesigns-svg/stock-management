<?php

require_once __DIR__ . '/common.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$user = authenticate();
$access = employeeWorkingReportsAccess($user);
if ((!$access['can_view_all'] && !$access['can_view_own']) || !$access['can_download']) {
    sendResponse(false, 'You do not have permission to download this employee report.', [], [], 403);
}

$reportId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($reportId === false || $reportId <= 0) {
    sendResponse(false, 'A valid report is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
employeeWorkingReportsRequireSchema($conn);
$stmt = $conn->prepare(
    'SELECT employee_id, status, file_path
     FROM employee_working_reports
     WHERE id = ?
     LIMIT 1'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare employee report download: ' . $error, [], [], 500);
}
$stmt->bind_param('i', $reportId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load employee report: ' . $error, [], [], 500);
}
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$report || (!$access['can_view_all'] &&
        (int) $report['employee_id'] !== $access['user_id'])) {
    sendResponse(false, 'Employee report not found.', [], [], 404);
}
if ($report['status'] !== 'generated' || !is_string($report['file_path'])) {
    sendResponse(false, 'This employee report is not available for download.', [], [], 409);
}

$filename = basename($report['file_path']);
if ($filename !== $report['file_path'] ||
    !preg_match('/^Employee_Working_Report_EMP\d+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.pdf$/', $filename)) {
    sendResponse(false, 'The stored employee report path is invalid.', [], [], 500);
}

try {
    $storageDirectory = realpath(employeeWorkingReportStorageDirectory());
} catch (RuntimeException $error) {
    sendResponse(false, 'Employee report storage is unavailable.', [], [], 500);
}
$filePath = $storageDirectory . DIRECTORY_SEPARATOR . $filename;
$realFilePath = realpath($filePath);
if ($realFilePath === false ||
    dirname($realFilePath) !== $storageDirectory ||
    !is_file($realFilePath) ||
    !is_readable($realFilePath)) {
    sendResponse(false, 'The employee report file is missing or unreadable.', [], [], 404);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realFilePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($realFilePath);
exit;
