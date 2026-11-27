<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/account_impersonation.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
$actingUserId = (int) ($currentUser['auth_user_id'] ?? $currentUser['user_id'] ?? 0);
$sessionId = (string) ($currentUser['sid'] ?? '');
if ($actingUserId <= 0 || !isSuperAdminUser($actingUserId)) {
    sendResponse(false, 'Only the original Super Admin can restore this account.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $actor = accountImpersonationLoadUser($conn, $actingUserId);
    if (!$actor
        || strtolower(trim((string) ($actor['status'] ?? 'active'))) !== 'active'
        || strtolower(trim((string) ($actor['role_status'] ?? 'active'))) !== 'active'
        || !accountImpersonationIsSuperAdminRole((string) ($actor['role_name'] ?? ''))) {
        $conn->close();
        sendResponse(false, 'A valid active Super Admin session is required.', [], [], 403);
    }

    if (!empty($currentUser['impersonating'])) {
        $conn->begin_transaction();
        $lock = $conn->prepare(
            'SELECT active_session_id
             FROM users
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        if (!$lock) {
            throw new RuntimeException('Unable to prepare account-return session lock: ' . $conn->error);
        }
        $lock->bind_param('i', $actingUserId);
        if (!$lock->execute()) {
            $error = $lock->error;
            $lock->close();
            throw new RuntimeException('Unable to validate account-return session: ' . $error);
        }
        $activeSession = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!hash_equals((string) ($activeSession['active_session_id'] ?? ''), $sessionId)) {
            throw new DomainException('Your Super Admin session is no longer valid.');
        }
        accountImpersonationEnd($conn, $actingUserId, $sessionId, 'returned_to_super_admin');
        $conn->commit();
    }
} catch (DomainException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 401);
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Unable to restore Super Admin account: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to restore your Super Admin account.', [], [], 500);
}
$conn->close();

$user = getCurrentUserDetails($actingUserId);
if (!$user) {
    sendResponse(false, 'Unable to load your Super Admin account.', [], [], 500);
}
$user['impersonation'] = null;
$permissions = $user['permissions'] ?? [];
sendResponse(true, 'Super Admin account restored.', [
    'user' => $user,
    'permissions' => $permissions,
    'role' => $user['role'],
    'impersonation' => null
]);
?>
