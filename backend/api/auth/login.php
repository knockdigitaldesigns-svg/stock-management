<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

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
$secret = 'your_super_secret_key_12345';
$sessionId = bin2hex(random_bytes(32));
$expiresAt = time() + (86400 * 30);
$payload = [
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'role' => $roleName,
    'role_id' => (int)($user['role_id'] ?? 0),
    'sid' => $sessionId,
    'exp' => $expiresAt
];
$token = generateJWT($payload, $secret);

$userId = (int) $user['id'];
$currentTime = time();
$updateSession = $conn->prepare('UPDATE users SET active_session_id = ?, active_session_created_at = CURRENT_TIMESTAMP, active_session_expires_at = ? WHERE id = ? AND (active_session_id IS NULL OR active_session_expires_at IS NULL OR active_session_expires_at <= ?)');
if (!$updateSession) {
    $conn->close();
    sendResponse(false, 'Unable to create login session.', [], [], 500);
}
$updateSession->bind_param('siii', $sessionId, $expiresAt, $userId, $currentTime);
if (!$updateSession->execute()) {
    $updateSession->close();
    $conn->close();
    sendResponse(false, 'Unable to create login session.', [], [], 500);
}
$sessionClaimed = $updateSession->affected_rows === 1;
$updateSession->close();

if (!$sessionClaimed) {
    $conn->close();
    sendResponse(false, 'User is already logged in on another device.', [], [], 409);
}

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
        'permissions' => $permissions
    ],
    'permissions' => $permissions,
    'role' => [
        'id' => (int)($user['role_id'] ?? 0),
        'name' => $roleName
    ]
]);
?>
