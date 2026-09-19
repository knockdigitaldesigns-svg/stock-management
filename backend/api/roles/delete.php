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
requirePermission('roles.delete');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(false, 'Role ID is required', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

/*
 * Check role
 */
$stmt = $conn->prepare(
    'SELECT id, role_name, is_system_role
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

$role = $result->fetch_assoc();
$stmt->close();

$permissionSnapshotStmt = $conn->prepare('SELECT p.permission_key, p.permission_name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key');
$permissionSnapshotStmt->bind_param('i', $id);
$permissionSnapshotStmt->execute();
$role['permissions'] = $permissionSnapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$permissionSnapshotStmt->close();

/*
 * System roles cannot be deleted
 */
if ((int) $role['is_system_role'] === 1) {
    $conn->close();

    sendResponse(
        false,
        'System roles cannot be deleted.',
        [],
        [],
        422
    );
}

/*
 * Check whether users are using this role
 */
$userCheck = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM users
     WHERE role_id = ?'
);

$userCheck->bind_param('i', $id);
$userCheck->execute();

$userCount = (int) $userCheck->get_result()->fetch_assoc()['total'];

$userCheck->close();

if ($userCount > 0) {
    $conn->close();

    sendResponse(
        false,
        'This role is assigned to users and cannot be deleted.',
        [],
        [],
        409
    );
}

$conn->begin_transaction();

try {

    writeDeleteSnapshot($conn, $id, 'Role', $role, $currentUser);

    /*
     * Remove role permission mappings first
     */
    $permissionStmt = $conn->prepare(
        'DELETE FROM role_permissions
         WHERE role_id = ?'
    );

    $permissionStmt->bind_param('i', $id);
    $permissionStmt->execute();
    $permissionStmt->close();

    /*
     * Delete role
     */
    $stmt = $conn->prepare(
        'DELETE FROM roles
         WHERE id = ?'
    );

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        throw new Exception('Failed to delete role');
    }

    if ($stmt->affected_rows === 0) {
        throw new Exception('Role not found');
    }

    $stmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'Role deleted successfully.'
    );

} catch (Throwable $e) {

    $conn->rollback();

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $conn->close();

    sendResponse(
        false,
        'Failed to delete role.',
        [],
        [],
        500
    );
}
?>