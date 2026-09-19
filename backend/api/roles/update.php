<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('roles.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);
$roleName = trim((string) ($data->role_name ?? ''));
$description = trim((string) ($data->description ?? ''));
$status = strtolower(trim((string) ($data->status ?? 'active')));

if ($id <= 0) {
    sendResponse(false, 'Role ID is required', [], [], 400);
}

if ($roleName === '') {
    sendResponse(false, 'Role name is required', [], [], 400);
}

if (!in_array($status, ['active', 'inactive'], true)) {
    sendResponse(false, 'Invalid role status', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

/*
 * Check current role
 */
$stmt = $conn->prepare(
    'SELECT id, role_name, description, status, is_system_role
     FROM roles
     WHERE id = ?
     LIMIT 1'
);

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();

    sendResponse(false, 'Role not found', [], [], 404);
}

$oldRole = $result->fetch_assoc();
$stmt->close();

/*
 * Prevent duplicate role names
 */
$stmt = $conn->prepare(
    'SELECT id
     FROM roles
     WHERE LOWER(role_name) = LOWER(?)
       AND id != ?
     LIMIT 1'
);

$stmt->bind_param('si', $roleName, $id);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    $conn->close();

    sendResponse(false, 'Role name already exists', [], [], 409);
}

$stmt->close();

$conn->begin_transaction();

try {

    writeChangedFields(
        $conn,
        $id,
        'Role',
        $oldRole,
        [
            'role_name' => $roleName,
            'description' => $description,
            'status' => $status
        ],
        $currentUser
    );

    $stmt = $conn->prepare(
        'UPDATE roles
         SET role_name = ?,
             description = ?,
             status = ?
         WHERE id = ?'
    );

    $stmt->bind_param(
        'sssi',
        $roleName,
        $description,
        $status,
        $id
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to update role');
    }

    $stmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'Role updated successfully',
        [
            'role' => [
                'id' => $id,
                'role_name' => $roleName,
                'description' => $description,
                'status' => $status
            ]
        ]
    );

} catch (Throwable $e) {

    $conn->rollback();

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $conn->close();

    sendResponse(
        false,
        'Failed to update role',
        [],
        [],
        500
    );
}
?>