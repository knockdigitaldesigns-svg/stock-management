<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/user_credential_crypto.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('users.view');
if (!isSuperAdminUser((int) ($currentUser['user_id'] ?? 0))) {
    sendResponse(false, 'Only a Super Admin can view user credentials.', [], [], 403);
}

$data = json_decode(file_get_contents('php://input'));
$userId = (int) ($data->id ?? 0);
if ($userId <= 0) {
    sendResponse(false, 'User ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$transactionStarted = false;
try {
    $stmt = $conn->prepare(
        'SELECT credential_ciphertext, credential_nonce, credential_tag
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare credential lookup.');
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to load user credential.');
    }
    $credential = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$credential) {
        $conn->close();
        sendResponse(false, 'User not found.', [], [], 404);
    }
    if (empty($credential['credential_ciphertext'])
        || empty($credential['credential_nonce'])
        || empty($credential['credential_tag'])) {
        $conn->close();
        sendResponse(true, 'Credential is not available for this account.', [
            'available' => false,
            'message' => 'Not available — reset password to set a new password.'
        ]);
    }

    $password = decryptUserCredential(
        $credential['credential_ciphertext'],
        $credential['credential_nonce'],
        $credential['credential_tag'],
        $userId
    );

    $conn->begin_transaction();
    $transactionStarted = true;
    writeAudit(
        $conn,
        $userId,
        'User',
        'View Password',
        'credential',
        null,
        'Password viewed by Super Admin',
        $currentUser
    );
    $conn->commit();
    $transactionStarted = false;
    $conn->close();

    sendResponse(true, 'Credential retrieved successfully.', [
        'available' => true,
        'password' => $password
    ]);
} catch (Throwable $error) {
    if ($transactionStarted) $conn->rollback();
    $conn->close();
    error_log('User credential reveal failed: ' . $error->getMessage());
    sendResponse(false, 'Unable to retrieve user credential.', [], [], 500);
}
?>
