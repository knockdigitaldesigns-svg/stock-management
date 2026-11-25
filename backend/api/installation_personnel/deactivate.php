<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/installation_personnel.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'));
$type = strtolower(trim((string) ($data->type ?? '')));
$id = (int) ($data->id ?? 0);
$definition = installationPersonnelDefinition($type);
if ($definition === null || $id <= 0) {
    sendResponse(false, 'A valid installation person type and ID are required.', [], [], 400);
}
requirePermission($definition['permission_prefix'] . '.delete');
requirePermission('users.delete');

$table = $definition['table'];
$roleSql = installationPersonnelRoleSqlExpression();
$roleName = strtolower($definition['role_name']);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();
try {
    $lookup = $conn->prepare(
        "SELECT p.id, p.user_id, u.status
         FROM {$table} p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE p.id = ? AND p.user_id IS NOT NULL AND {$roleSql} = ?
         LIMIT 1 FOR UPDATE"
    );
    if (!$lookup) {
        throw new RuntimeException('Unable to prepare linked account lookup.');
    }
    $lookup->bind_param('is', $id, $roleName);
    if (!$lookup->execute()) {
        throw new RuntimeException('Unable to verify linked login account.');
    }
    $account = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$account) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Installation person or linked login account not found.', [], [], 404);
    }

    $userId = (int) $account['user_id'];
    $update = $conn->prepare(
        "UPDATE users
         SET status = 'inactive', active_session_id = NULL,
             active_session_created_at = NULL, active_session_expires_at = NULL
         WHERE id = ?"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare login deactivation.');
    }
    $update->bind_param('i', $userId);
    if (!$update->execute()) {
        throw new RuntimeException('Unable to deactivate linked login account.');
    }
    $update->close();

    writeAudit(
        $conn,
        $userId,
        'User',
        'Deactivate Login',
        'status',
        $account['status'],
        'inactive',
        $currentUser
    );
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Login account deactivated. The profile, assignments, and transaction history were preserved.');
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Installation person login deactivation failed: ' . $error->getMessage());
    sendResponse(false, 'Unable to deactivate installation person login.', [], [], 500);
}
?>
