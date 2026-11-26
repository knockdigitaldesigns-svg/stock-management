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
$requestData = json_decode(file_get_contents('php://input'), true);
$targetUserId = (int) (is_array($requestData) ? ($requestData['target_user_id'] ?? 0) : 0);
if ($targetUserId <= 0) {
    sendResponse(false, 'Select a valid employee account.', [], [], 400);
}
if (!empty($currentUser['impersonating']) || (int) ($currentUser['user_id'] ?? 0) !== $actingUserId
    || !isSuperAdminUser($actingUserId)) {
    sendResponse(false, 'Only a Super Admin can switch accounts.', [], [], 403);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $actor = accountImpersonationLoadUser($conn, $actingUserId);
    $target = accountImpersonationLoadUser($conn, $targetUserId);
    if (!$actor
        || strtolower(trim((string) ($actor['status'] ?? 'active'))) !== 'active'
        || strtolower(trim((string) ($actor['role_status'] ?? 'active'))) !== 'active'
        || !accountImpersonationIsSuperAdminRole((string) ($actor['role_name'] ?? ''))) {
        $conn->close();
        sendResponse(false, 'A valid active Super Admin session is required.', [], [], 403);
    }
    if (!$target || !accountImpersonationUserIsEligible($target)) {
        $conn->close();
        sendResponse(false, 'The selected account is not an eligible active employee.', [], [], 400);
    }
    $user = getCurrentUserDetails($targetUserId);
    if (!$user) {
        $conn->close();
        sendResponse(false, 'Unable to load the selected employee account.', [], [], 500);
    }

    $conn->begin_transaction();
    $lock = $conn->prepare(
        'SELECT active_session_id
         FROM users
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$lock) {
        throw new RuntimeException('Unable to prepare account-switch session lock: ' . $conn->error);
    }
    $lock->bind_param('i', $actingUserId);
    if (!$lock->execute()) {
        $error = $lock->error;
        $lock->close();
        throw new RuntimeException('Unable to validate account-switch session: ' . $error);
    }
    $activeSession = $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!hash_equals((string) ($activeSession['active_session_id'] ?? ''), $sessionId)) {
        throw new DomainException('Your Super Admin session is no longer valid.');
    }

    if (accountImpersonationFindActive($conn, $actingUserId, $sessionId)) {
        throw new DomainException('An account switch is already active.');
    }

    $sessionHash = accountImpersonationSessionHash($sessionId);
    $insert = $conn->prepare(
        'INSERT INTO user_account_switches
            (session_id_hash, acting_user_id, represented_user_id, started_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare account-switch audit record: ' . $conn->error);
    }
    $insert->bind_param('sii', $sessionHash, $actingUserId, $targetUserId);
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        throw new RuntimeException('Unable to start account switch: ' . $error);
    }
    $insert->close();
    $conn->commit();
} catch (DomainException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 409);
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Unable to start account switch: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to switch accounts.', [], [], 500);
}
$conn->close();

$actorDisplay = [
    'id' => $actingUserId,
    'username' => (string) $actor['username'],
    'employee_name' => (string) ($actor['employee_name'] ?: $actor['username'])
];
$user['impersonation'] = [
    'active' => true,
    'acting_user' => $actorDisplay
];
$permissions = $user['permissions'] ?? [];
sendResponse(true, 'Account switched successfully.', [
    'user' => $user,
    'permissions' => $permissions,
    'role' => $user['role'],
    'impersonation' => $user['impersonation']
]);
?>
