<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/otp.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$data = json_decode(file_get_contents('php://input'));
$pendingToken = trim((string) ($data->pending_token ?? ''));
if (!preg_match('/^[A-Za-z0-9]{64}$/', $pendingToken)) {
    sendResponse(false, 'Invalid pending login.', [], [], 403);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$pendingHash = hash('sha256', $pendingToken);
$stmt = $conn->prepare(
    'SELECT o.id, o.user_id, o.last_sent_at, o.is_used, u.role, r.role_name
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

if (!$record || (int)$record['is_used'] === 1) {
    $conn->close();
    sendResponse(false, 'Invalid or completed pending login.', [], [], 403);
}

$roleName = (string) ($record['role_name'] ?? $record['role'] ?? '');
if (isSuperAdminUser(null, $roleName) || isOtpExemptRoleName($roleName)) {
    $conn->close();
    sendResponse(false, 'OTP is not required for this account type. Please sign in again.', [], [], 403);
}

$now = time();
$lastSent = strtotime((string) $record['last_sent_at']);
if ($lastSent !== false && $lastSent + 30 > $now) {
    $wait = ($lastSent + 30) - $now;
    $conn->close();
    sendResponse(false, 'Please wait before requesting another OTP.', ['retry_in' => $wait], [], 429);
}

$otp = generateSecureOtp();
$otpHash = password_hash($otp, PASSWORD_DEFAULT);
$otpEncrypted = encryptOtpValue($otp);
$newPendingToken = pendingLoginToken();
$newPendingHash = hash('sha256', $newPendingToken);
$expiresAt = date('Y-m-d H:i:s', $now + 300);
$lastSentAt = date('Y-m-d H:i:s', $now);

$update = $conn->prepare(
    'UPDATE login_otps SET otp_hash = ?, otp_encrypted = ?, pending_token_hash = ?, expires_at = ?, attempts = 0, locked_until = NULL, is_used = 0, last_sent_at = ?, verified_at = NULL WHERE id = ?'
);
$update->bind_param('sssssi', $otpHash, $otpEncrypted, $newPendingHash, $expiresAt, $lastSentAt, $record['id']);
if (!$update->execute()) {
    $update->close();
    $conn->close();
    sendResponse(false, 'Unable to resend OTP.', [], [], 500);
}
$update->close();
$conn->close();

sendResponse(true, 'A new OTP has been generated.', [
    'pending_token' => $newPendingToken,
    'expires_in' => 300
]);
?>
