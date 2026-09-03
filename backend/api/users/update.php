<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/user_identity.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();
requirePermission('users.edit');
$data = json_decode(file_get_contents('php://input'));
if (!$data) sendResponse(false, 'Invalid payload', [], [], 400);

$id = (int) ($data->id ?? 0);
$employeeName = trim((string) ($data->employee_name ?? ''));
$mobileNo = trim((string) ($data->mobile_no ?? ''));
$roleId = (int) ($data->role_id ?? 0);
$password = trim((string) ($data->password ?? ''));
$status = strtolower(trim((string) ($data->status ?? 'active')));
$username = normalizeEmployeeUsername($employeeName);
if ($id <= 0 || $employeeName === '' || $username === '' || $mobileNo === '' || $roleId <= 0) sendResponse(false, 'Employee name, mobile number and role are required', [], [], 400);
if ($password !== '' && strlen($password) < 8) sendResponse(false, 'Password must be at least 8 characters long', [], [], 400);
if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

$db = new Database(); $conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$stmt = $conn->prepare('SELECT id FROM users WHERE id = ?'); $stmt->bind_param('i', $id); $stmt->execute();
if ($stmt->get_result()->num_rows === 0) sendResponse(false, 'User not found', [], [], 404); $stmt->close();
$stmt = $conn->prepare('SELECT id FROM users WHERE mobile_no = ? AND id <> ? LIMIT 1'); $stmt->bind_param('si', $mobileNo, $id); $stmt->execute();
if ($stmt->get_result()->num_rows > 0) sendResponse(false, 'Mobile number already exists', [], [], 409); $stmt->close();
$stmt = $conn->prepare("SELECT id FROM users WHERE id <> ? AND (LOWER(username) = ? OR LOWER(REPLACE(REPLACE(TRIM(COALESCE(employee_name, '')), ' ', ''), CHAR(9), '')) = ?) LIMIT 1"); $stmt->bind_param('iss', $id, $username, $username); $stmt->execute();
if ($stmt->get_result()->num_rows > 0) sendResponse(false, 'Username already exists. Please use a different employee name.', [], [], 409); $stmt->close();
$stmt = $conn->prepare('SELECT role_name FROM roles WHERE id = ? AND status = "active" LIMIT 1'); $stmt->bind_param('i', $roleId); $stmt->execute(); $role = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$role) sendResponse(false, 'Selected role is invalid or inactive', [], [], 400);
if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET employee_name = ?, mobile_no = ?, username = ?, password = ?, role = ?, role_id = ?, status = ? WHERE id = ?');
    $stmt->bind_param('sssssisi', $employeeName, $mobileNo, $username, $hash, $role['role_name'], $roleId, $status, $id);
} else {
    $stmt = $conn->prepare('UPDATE users SET employee_name = ?, mobile_no = ?, username = ?, role = ?, role_id = ?, status = ? WHERE id = ?');
    $stmt->bind_param('ssssisi', $employeeName, $mobileNo, $username, $role['role_name'], $roleId, $status, $id);
}
if (!$stmt->execute()) {
    $duplicate = $stmt->errno === 1062 || $conn->errno === 1062;
    $stmt->close(); $conn->close();
    if ($duplicate) sendResponse(false, 'Username already exists. Please use a different employee name.', [], [], 409);
    sendResponse(false, 'Failed to update user', [], [], 500);
}
$stmt->close(); $conn->close(); sendResponse(true, 'User updated successfully');
?>
