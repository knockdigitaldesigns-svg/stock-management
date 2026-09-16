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
requirePermission('permissions.assign');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$roleId = isset($data->role_id) ? (int) $data->role_id : 0;
$permissions = isset($data->permissions) && is_array($data->permissions) ? $data->permissions : [];
if (!$roleId) {
    sendResponse(false, 'Role id is required', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$roleStmt = $conn->prepare('SELECT id, role_name, is_system_role FROM roles WHERE id = ? LIMIT 1');
$roleStmt->bind_param('i', $roleId);
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
if ($roleResult->num_rows === 0) {
    $roleStmt->close();
    $conn->close();
    sendResponse(false, 'Role not found', [], [], 404);
}
$role = $roleResult->fetch_assoc();
$roleStmt->close();

if ((int) ($role['is_system_role'] ?? 0) === 1 && strtolower((string) ($role['role_name'] ?? '')) !== 'super admin') {
    $conn->close();
    sendResponse(false, 'System roles are protected', [], [], 403);
}

$permissionKeys = array_values(array_unique(array_filter(array_map('trim', $permissions))));

$conn->begin_transaction();
try {
    $oldPermissions = [];
    $oldStmt = $conn->prepare('SELECT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key');
    $oldStmt->bind_param('i', $roleId);
    $oldStmt->execute();
    $oldResult = $oldStmt->get_result();
    while ($oldRow = $oldResult->fetch_assoc()) $oldPermissions[] = $oldRow['permission_key'];
    $oldStmt->close();
    if ($oldPermissions !== $permissionKeys) {
        writeAudit($conn, $roleId, 'Role Permissions', 'Edit', 'permissions', $oldPermissions, $permissionKeys, $currentUser);
    }
    $conn->query('DELETE FROM role_permissions WHERE role_id = ' . (int) $roleId);

    if (!empty($permissionKeys)) {
        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        $ids = [];
        $query = 'SELECT id, permission_key FROM permissions WHERE permission_key IN (' . $placeholders . ')';
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($permissionKeys));
        $stmt->bind_param($types, ...$permissionKeys);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        if (!empty($ids)) {
            $insertSql = 'INSERT INTO role_permissions (role_id, permission_id) VALUES ';
            $values = [];
            foreach ($ids as $permissionId) {
                $values[] = '(' . (int) $roleId . ', ' . (int) $permissionId . ')';
            }
            $insertSql .= implode(', ', $values);
            $conn->query($insertSql);
        }
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Permissions assigned successfully', ['role_id' => $roleId, 'permissions' => $permissionKeys]);
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Failed to save permissions: ' . $e->getMessage(), [], [], 500);
}
?>
