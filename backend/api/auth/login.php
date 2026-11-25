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
if (!isset($data->username) || !isset($data->password)) {
    sendResponse(false, 'Username and password are required', [], [], 400);
}

$username = trim((string) $data->username);
$password = trim((string) $data->password);

if ($username === '' || $password === '') {
    sendResponse(false, 'Username and password are required', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT u.id, u.username, u.employee_name, u.mobile_no, u.password, u.status, u.role, u.role_id, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Invalid username or password', [], [], 401);
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    $conn->close();
    sendResponse(false, 'Invalid username or password', [], [], 401);
}

if (strtolower((string) ($user['status'] ?? 'active')) !== 'active') {
    $conn->close();
    sendResponse(false, 'This account is inactive. Please contact the administrator.', [], [], 403);
}

$roleName = $user['role_name'] ?? $user['role'] ?? 'No Role';
$permissions = getUserPermissions((int)$user['id']);

if (!isSuperAdminUser((int)$user['id'], $roleName) && !isOtpExemptRoleName($roleName)) {
    $otp = generateSecureOtp();
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $otpEncrypted = encryptOtpValue($otp);
    $pendingToken = pendingLoginToken();
    $pendingTokenHash = hash('sha256', $pendingToken);
    $now = time();
    $expiresAt = date('Y-m-d H:i:s', $now + 300);
    $createdAt = date('Y-m-d H:i:s', $now);

    $ensureOtpTable = $conn->query(
        "CREATE TABLE IF NOT EXISTS login_otps (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            otp_encrypted TEXT NOT NULL,
            pending_token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME DEFAULT NULL,
            is_used TINYINT(1) NOT NULL DEFAULT 0,
            last_sent_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_login_otps_user (user_id),
            INDEX idx_login_otps_pending (pending_token_hash),
            INDEX idx_login_otps_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!$ensureOtpTable || $otpEncrypted === null) {
        $conn->close();
        sendResponse(false, 'Unable to start OTP verification.', [], [], 500);
    }

    $rateStmt = $conn->prepare('SELECT last_sent_at FROM login_otps WHERE user_id = ? LIMIT 1');
    $userId = (int)$user['id'];
    $rateStmt->bind_param('i', $userId);
    $rateStmt->execute();
    $existingOtp = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();
    if ($existingOtp) {
        $lastSent = strtotime((string)$existingOtp['last_sent_at']);
        if ($lastSent !== false && $lastSent + 30 > $now) {
            $wait = ($lastSent + 30) - $now;
            $conn->close();
            sendResponse(false, 'Please wait before requesting another OTP.', ['retry_in' => $wait], [], 429);
        }
    }

    $upsert = $conn->prepare(
        'INSERT INTO login_otps
            (user_id, otp_hash, otp_encrypted, pending_token_hash, expires_at, attempts, locked_until, is_used, last_sent_at, created_at, verified_at)
         VALUES (?, ?, ?, ?, ?, 0, NULL, 0, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            otp_hash = VALUES(otp_hash),
            otp_encrypted = VALUES(otp_encrypted),
            pending_token_hash = VALUES(pending_token_hash),
            expires_at = VALUES(expires_at),
            attempts = 0,
            locked_until = NULL,
            is_used = 0,
            last_sent_at = VALUES(last_sent_at),
            created_at = VALUES(created_at),
            verified_at = NULL'
    );
    if (!$upsert) {
        $conn->close();
        sendResponse(false, 'Unable to start OTP verification.', [], [], 500);
    }
    $userId = (int)$user['id'];
    $upsert->bind_param('issssss', $userId, $otpHash, $otpEncrypted, $pendingTokenHash, $expiresAt, $createdAt, $createdAt);
    if (!$upsert->execute()) {
        $upsert->close();
        $conn->close();
        sendResponse(false, 'Unable to start OTP verification.', [], [], 500);
    }
    $upsert->close();
    $conn->close();

    sendResponse(true, 'OTP verification required', [
        'otp_required' => true,
        'pending_token' => $pendingToken,
        'expires_in' => 300,
        'username' => $user['username']
    ]);
}

$secret = 'your_super_secret_key_12345';
$sessionId = bin2hex(random_bytes(32));
$tokenExpiresAt = time() + (86400 * 30);
$userId = (int) $user['id'];
try {
    $timeoutSeconds = claimAuthenticatedSession(
        $conn,
        $userId,
        $sessionId,
        $roleName,
        (string) ($user['employee_name'] ?? $user['username']),
        (string) $user['username'],
        false
    );
} catch (Throwable $error) {
    error_log('Unable to create login session: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to create login session.', [], [], 500);
}
if ($timeoutSeconds === null) {
    $conn->close();
    sendResponse(false, 'User is already logged in on another device.', [], [], 409);
}

$payload = [
    'user_id' => $userId,
    'username' => $user['username'],
    'role' => $roleName,
    'role_id' => (int)($user['role_id'] ?? 0),
    'sid' => $sessionId,
    'exp' => $tokenExpiresAt
];
$token = generateJWT($payload, $secret);

$conn->close();

sendResponse(true, 'Login successful', [
    'token' => $token,
    'user' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'employee_name' => $user['employee_name'] ?? $user['username'],
        'mobile_no' => $user['mobile_no'] ?? null,
        'status' => $user['status'] ?? 'active',
        'role' => [
            'id' => (int)($user['role_id'] ?? 0),
            'name' => $roleName
        ],
        'inactivity_timeout_seconds' => $timeoutSeconds,
        'permissions' => $permissions
    ],
    'permissions' => $permissions,
    'role' => [
        'id' => (int)($user['role_id'] ?? 0),
        'name' => $roleName
    ]
]);
?>
