<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/user_credential_crypto.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('users.edit');

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
$password = (string) ($data->password ?? '');
if ($id <= 0 || $password === '') {
    sendResponse(false, 'User ID and new password are required.', [], [], 400);
}
if (strlen($password) < 8) {
    sendResponse(false, 'Password must be at least 8 characters long.', [], [], 400);
}
if (!isSuperAdminUser((int) ($currentUser['user_id'] ?? 0))) {
    sendResponse(false, 'Only a Super Admin can reset another user password.', [], [], 403);
}
try {
    getUserCredentialEncryptionKey();
} catch (Throwable $error) {
    sendResponse(false, 'User credential encryption is not configured on the server.', [], [], 500);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$conn->begin_transaction();
try {
    $userStmt = $conn->prepare(
        'SELECT u.id, u.username, u.role, u.role_id, r.role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$userStmt) {
        throw new RuntimeException('Unable to prepare user lookup.');
    }
    $userStmt->bind_param('i', $id);
    if (!$userStmt->execute()) {
        throw new RuntimeException('Unable to load user.');
    }
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'User not found.', [], [], 404);
    }

    $credential = encryptUserCredential($password, $id);
    $updateStmt = $conn->prepare(
        'UPDATE users
         SET password = ?, credential_ciphertext = ?, credential_nonce = ?, credential_tag = ?
         WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare password reset.');
    }
    $updateStmt->bind_param(
        'ssssi',
        $passwordHash,
        $credential['ciphertext'],
        $credential['nonce'],
        $credential['tag'],
        $id
    );
    if (!$updateStmt->execute()) {
        throw new RuntimeException('Failed to reset user password.');
    }
    $updateStmt->close();

    writeAudit($conn, $id, 'User', 'Reset Password', 'password', null, 'Password reset by Super Admin', $currentUser);
    $conn->commit();

    $conn->close();
    sendResponse(true, 'Password reset successfully.', [
        'user' => [
            'id' => $id,
            'username' => $user['username'],
            'role' => $user['role_name'] ?? $user['role'] ?? ''
        ]
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('User password reset failed: ' . $error->getMessage());
    sendResponse(false, 'Unable to reset user password.', [], [], 500);
}
?>
