<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/otp.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$data = json_decode(file_get_contents('php://input'));
$pendingToken = trim((string) ($data->pending_token ?? ''));
$otp = trim((string) ($data->otp ?? ''));

if (!preg_match('/^[A-Za-z0-9]{64}$/', $pendingToken) || !preg_match('/^[0-9]{6}$/', $otp)) {
    sendResponse(false, 'Enter a valid 6-digit OTP.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$pendingHash = hash('sha256', $pendingToken);
$stmt = $conn->prepare(
    'SELECT o.*, u.username, u.employee_name, u.mobile_no, u.status, u.role_id,
            u.role, COALESCE(r.role_name, u.role, "No Role") AS role_name
     FROM login_otps o
     JOIN users u ON u.id = o.user_id
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE o.pending_token_hash = ?
     LIMIT 1'
);
$stmt->bind_param('s', $pendingHash);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    $conn->close();
    sendResponse(false, 'Invalid or expired pending login.', [], [], 403);
}

if (strtolower((string) ($record['status'] ?? 'active')) !== 'active') {
    $conn->close();
    sendResponse(false, 'This account is inactive. Please contact the administrator.', [], [], 403);
}

if (
    isSuperAdminUser(null, $record['role_name'] ?? '') ||
    isOtpExemptRoleName($record['role_name'] ?? '')
) {
    $conn->close();
    sendResponse(false, 'OTP verification is not used for this account type. Please sign in again.', [], [], 403);
}

if ((int) $record['is_used'] === 1) {
    $conn->close();
    sendResponse(false, 'This OTP has already been used.', [], [], 410);
}

$now = time();
$expiresTimestamp = strtotime((string) $record['expires_at']);
$lockedUntil = (string) ($record['locked_until'] ?? '');
if ($lockedUntil !== '' && strtotime($lockedUntil) > $now) {
    $conn->close();
    sendResponse(false, 'Too many incorrect attempts. Please resend OTP or try again later.', [], [], 423);
}

if ($expiresTimestamp === false || $expiresTimestamp <= $now) {
    $conn->close();
    sendResponse(false, 'OTP expired. Please resend OTP.', [], [], 410);
}

if (!password_verify($otp, (string) $record['otp_hash'])) {
    $attempts = (int) $record['attempts'] + 1;
    $lockedUntilValue = null;
    $lockedUntilSql = null;
    if ($attempts >= 5) {
        $lockedUntilSql = date('Y-m-d H:i:s', $now + 900);
        $lockedUntilValue = $lockedUntilSql;
        $attempts = 5;
    }

    $update = $conn->prepare('UPDATE login_otps SET attempts = ?, locked_until = ? WHERE id = ?');
    $update->bind_param('isi', $attempts, $lockedUntilValue, $record['id']);
    $update->execute();
    $update->close();
    $conn->close();

    sendResponse(
        false,
        $lockedUntilValue
            ? 'Too many incorrect attempts. OTP verification is temporarily locked.'
            : 'Invalid OTP.',
        [],
        [],
        $lockedUntilValue ? 423 : 401
    );
}

$userId = (int) $record['user_id'];
$roleName = (string) $record['role_name'];
$secret = 'your_super_secret_key_12345';
$sessionId = bin2hex(random_bytes(32));
$conn->begin_transaction();
try {
    $markUsed = $conn->prepare(
        'UPDATE login_otps SET is_used = 1, verified_at = ?, locked_until = NULL WHERE id = ? AND is_used = 0 AND expires_at > ?'
    );
    if (!$markUsed) {
        throw new RuntimeException('Unable to prepare OTP verification update: ' . $conn->error);
    }
    $verifiedAt = date('Y-m-d H:i:s', $now);
    $sqlNow = date('Y-m-d H:i:s', $now);
    $markUsed->bind_param('sis', $verifiedAt, $record['id'], $sqlNow);
    if (!$markUsed->execute() || $markUsed->affected_rows !== 1) {
        $markUsed->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'OTP expired or has already been used.', [], [], 410);
    }
    $markUsed->close();

    $timeoutSeconds = claimAuthenticatedSession(
        $conn,
        $userId,
        $sessionId,
        $roleName,
        (string) ($record['employee_name'] ?? $record['username']),
        (string) $record['username'],
        true,
        false
    );
    if ($timeoutSeconds === null) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'User is already logged in on another device.', [], [], 409);
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Unable to complete OTP login session: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to create login session.', [], [], 500);
}

$permissions = getUserPermissions($userId);
$tokenExpiresAt = time() + (86400 * 30);
$payload = [
    'user_id' => $userId,
    'username' => $record['username'],
    'role' => $roleName,
    'role_id' => (int) ($record['role_id'] ?? 0),
    'sid' => $sessionId,
    'exp' => $tokenExpiresAt
];
$token = generateJWT($payload, $secret);
$conn->close();

sendResponse(true, 'Login successful', [
    'token' => $token,
    'user' => [
        'id' => $userId,
        'username' => $record['username'],
        'employee_name' => $record['employee_name'] ?? $record['username'],
        'mobile_no' => $record['mobile_no'] ?? null,
        'status' => $record['status'] ?? 'active',
        'role' => ['id' => (int)($record['role_id'] ?? 0), 'name' => $roleName],
        'inactivity_timeout_seconds' => $timeoutSeconds,
        'permissions' => $permissions
    ],
    'permissions' => $permissions,
    'role' => ['id' => (int)($record['role_id'] ?? 0), 'name' => $roleName]
]);
?>
