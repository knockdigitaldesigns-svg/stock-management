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
$payload = [
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'role' => $roleName,
    'role_id' => (int)($user['role_id'] ?? 0),
    'exp' => time() + (86400 * 30)
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
        'permissions' => $permissions
    ],
    'permissions' => $permissions,
    'role' => [
        'id' => (int)($user['role_id'] ?? 0),
        'name' => $roleName
    ]
]);
?>
