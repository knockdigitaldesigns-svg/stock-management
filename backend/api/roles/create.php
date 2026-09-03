<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('roles.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$roleName = trim((string) ($data->role_name ?? ''));
$description = trim((string) ($data->description ?? ''));
$status = isset($data->status) ? strtolower(trim((string) $data->status)) : 'active';
if ($status !== 'active' && $status !== 'inactive') {
    $status = 'active';
}

if ($roleName === '') {
    sendResponse(false, 'Role name is required', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT id FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
$stmt->bind_param('s', $roleName);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Role name already exists', [], [], 409);
}
$stmt->close();

$stmt = $conn->prepare('INSERT INTO roles (role_name, description, status, is_system_role) VALUES (?, ?, ?, 0)');
$stmt->bind_param('sss', $roleName, $description, $status);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to create role', [], [], 500);
}

$roleId = $conn->insert_id;
$stmt->close();
$conn->close();

sendResponse(true, 'Role created successfully', ['role' => ['id' => $roleId, 'role_name' => $roleName, 'description' => $description, 'status' => $status]]);
?>
