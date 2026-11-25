<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/otp.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$payload = authenticate();
$currentUserId = (int) ($payload['user_id'] ?? 0);
if (!isSuperAdminUser($currentUserId)) {
    sendResponse(false, 'Only Super Admin users can view OTP status.', [], [], 403);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$result = $conn->query(
    "SELECT o.user_id, o.otp_encrypted, o.expires_at, o.is_used, o.verified_at
     FROM login_otps o
     INNER JOIN users u ON u.id = o.user_id
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', ''))
           NOT IN ('dealer', 'technician')"
);
$statuses = [];
$now = time();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $state = '-';
        $otp = null;
        if ((int)$row['is_used'] === 1) {
            $state = 'Used';
        } elseif (strtotime((string)$row['expires_at']) <= $now) {
            $state = 'Expired';
        } else {
            $otp = decryptOtpValue((string)$row['otp_encrypted']);
            $state = $otp === null ? 'Expired' : 'Valid';
        }
        $statuses[(string)$row['user_id']] = [
            'otp' => $otp,
            'state' => $state,
            'expires_at' => $row['expires_at'],
            'remaining_seconds' => $otp !== null ? max(0, strtotime((string)$row['expires_at']) - $now) : 0
        ];
    }
}
$conn->close();

sendResponse(true, 'OTP status fetched successfully', ['statuses' => $statuses]);
?>
