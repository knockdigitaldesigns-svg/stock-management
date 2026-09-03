<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/user_identity.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('users.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$employeeName = trim((string) ($data->employee_name ?? ''));
$mobileNo = trim((string) ($data->mobile_no ?? ''));
$roleId = isset($data->role_id) ? (int) $data->role_id : 0;
$password = trim((string) ($data->password ?? ''));
$status = isset($data->status) ? strtolower(trim((string) $data->status)) : 'active';

if ($employeeName === '' || $mobileNo === '' || $password === '' || $roleId === 0) {
    sendResponse(false, 'Employee name, mobile number, role and password are required', [], [], 400);
}
$username = normalizeEmployeeUsername($employeeName);
if ($username === '') {
    sendResponse(false, 'Employee name must contain at least one character', [], [], 400);
}
if (strlen($password) < 8) {
    sendResponse(false, 'Password must be at least 8 characters long', [], [], 400);
}
if ($status !== 'active' && $status !== 'inactive') {
    $status = 'active';
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$userCheck = $conn->prepare('SELECT id FROM users WHERE mobile_no = ? LIMIT 1');
$userCheck->bind_param('s', $mobileNo);
$userCheck->execute();
if ($userCheck->get_result()->num_rows > 0) {
    $userCheck->close();
    $conn->close();
    sendResponse(false, 'Mobile number already exists', [], [], 409);
}
$userCheck->close();

$usernameCheck = $conn->prepare("SELECT id FROM users WHERE LOWER(REPLACE(REPLACE(TRIM(COALESCE(employee_name, '')), ' ', ''), CHAR(9), '')) = ? OR LOWER(username) = ? LIMIT 1");
$usernameCheck->bind_param('ss', $username, $username);
$usernameCheck->execute();
if ($usernameCheck->get_result()->num_rows > 0) {
    $usernameCheck->close();
    $conn->close();
    sendResponse(false, 'Username already exists. Please use a different employee name.', [], [], 409);
}
$usernameCheck->close();

$roleCheck = $conn->prepare('SELECT id, role_name FROM roles WHERE id = ? AND status = "active" LIMIT 1');
$roleCheck->bind_param('i', $roleId);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleResult->num_rows === 0) {
    $roleCheck->close();
    $conn->close();
    sendResponse(false, 'Selected role is invalid or inactive', [], [], 400);
}
$role = $roleResult->fetch_assoc();
$roleCheck->close();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$insert = $conn->prepare('INSERT INTO users (employee_name, mobile_no, username, password, role, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('sssssis', $employeeName, $mobileNo, $username, $hashedPassword, $role['role_name'], $roleId, $status);
if (!$insert->execute()) {
    if ($conn->errno === 1062 || $insert->errno === 1062) {
        $insert->close();
        $conn->close();
        sendResponse(false, 'Username already exists. Please use a different employee name.', [], [], 409);
    }
    $insert->close();
    $conn->close();
    sendResponse(false, 'Failed to create user', [], [], 500);
}
$insert->close();
$conn->close();

sendResponse(true, 'User created successfully', ['user' => ['employee_name' => $employeeName, 'mobile_no' => $mobileNo, 'role_id' => $roleId, 'status' => $status]]);
?>
