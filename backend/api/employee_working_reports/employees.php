<?php

require_once __DIR__ . '/common.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$user = authenticate();
$access = employeeWorkingReportsAccess($user);
if (!$access['can_view_all']) {
    sendResponse(false, 'You do not have permission to view employee report filters.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
employeeWorkingReportsRequireSchema($conn);

$stmt = $conn->prepare(
    "SELECT u.id AS employee_id,
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
if (!$stmt || !$stmt->execute()) {
    $error = $stmt ? $stmt->error : $conn->error;
    if ($stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, 'Unable to load employee report filters: ' . $error, [], [], 500);
}

$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
sendResponse(true, 'Employee report filters loaded.', ['employees' => $employees]);
