<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission(['crm_leads.transfer', 'crm_leads.transfer_bulk']);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    "SELECT DISTINCT u.id,
            COALESCE(NULLIF(u.employee_name, ''), u.username) AS employee_name
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE LOWER(COALESCE(u.status, 'active')) = 'active'
       AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', '')) NOT IN ('dealer', 'technician')
     ORDER BY employee_name ASC, u.id ASC"
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare eligible user list: ' . $error, [], [], 500);
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load eligible users: ' . $error, [], [], 500);
}
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
sendResponse(true, 'Eligible users loaded successfully.', ['employees' => $employees]);
?>
