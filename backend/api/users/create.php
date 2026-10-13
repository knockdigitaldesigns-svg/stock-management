<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/user_identity.php';
require_once '../../utils/audit.php';
require_once '../../utils/user_credential_crypto.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('users.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$employeeName = trim((string) ($data->employee_name ?? ''));
$mobileNo = trim((string) ($data->mobile_no ?? ''));
$roleId = isset($data->role_id) ? (int) $data->role_id : 0;
$password = (string) ($data->password ?? '');
$status = isset($data->status) ? strtolower(trim((string) $data->status)) : 'active';

if ($employeeName === '' || $mobileNo === '' || $password === '' || $roleId === 0) {
    sendResponse(false, 'Employee name, mobile number, role and password are required', [], [], 400);
}
$username = normalizeEmployeeUsername($employeeName);
if ($username === '') {
    sendResponse(false, 'Employee name must contain at least one character', [], [], 400);
}
if (strlen($password) < 8) {
    sendResponse(false, 'Password must be at least 8 characters long', [], [], 400);
}
if ($status !== 'active' && $status !== 'inactive') {
    $status = 'active';
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$userCheck = $conn->prepare('SELECT id FROM users WHERE mobile_no = ? LIMIT 1');
$userCheck->bind_param('s', $mobileNo);
$userCheck->execute();
if ($userCheck->get_result()->num_rows > 0) {
    $userCheck->close();
    $conn->close();
    sendResponse(false, 'Mobile number already exists', [], [], 409);
}
$userCheck->close();

$usernameCheck = $conn->prepare("SELECT id FROM users WHERE LOWER(REPLACE(REPLACE(TRIM(COALESCE(employee_name, '')), ' ', ''), CHAR(9), '')) = ? OR LOWER(username) = ? LIMIT 1");
$usernameCheck->bind_param('ss', $username, $username);
$usernameCheck->execute();
if ($usernameCheck->get_result()->num_rows > 0) {
    $usernameCheck->close();
    $conn->close();
    sendResponse(false, 'Username already exists. Please use a different employee name.', [], [], 409);
}
$usernameCheck->close();

$roleCheck = $conn->prepare('SELECT id, role_name FROM roles WHERE id = ? AND status = "active" LIMIT 1');
$roleCheck->bind_param('i', $roleId);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleResult->num_rows === 0) {
    $roleCheck->close();
    $conn->close();
    sendResponse(false, 'Selected role is invalid or inactive', [], [], 400);
}
$role = $roleResult->fetch_assoc();
$roleCheck->close();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
try {
    getUserCredentialEncryptionKey();
} catch (Throwable $error) {
    sendResponse(false, 'User credential encryption is not configured on the server.', [], [], 500);
}
$conn->begin_transaction();
try {
    $insert = $conn->prepare('INSERT INTO users (employee_name, mobile_no, username, password, role, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$insert) {
        throw new RuntimeException('Failed to prepare user creation.');
    }
    $insert->bind_param('sssssis', $employeeName, $mobileNo, $username, $hashedPassword, $role['role_name'], $roleId, $status);
    if (!$insert->execute()) {
        $duplicate = $conn->errno === 1062 || $insert->errno === 1062;
        $insert->close();
        if ($duplicate) {
            throw new DomainException('Username already exists. Please use a different employee name.');
        }
        throw new RuntimeException('Failed to create user.');
    }
    $insert->close();
    $userId = $conn->insert_id;
    $credential = encryptUserCredential($password, $userId);
    $credentialStmt = $conn->prepare('UPDATE users SET credential_ciphertext = ?, credential_nonce = ?, credential_tag = ? WHERE id = ?');
    if (!$credentialStmt) {
        throw new RuntimeException('Failed to prepare encrypted credential storage.');
    }
    $credentialStmt->bind_param('sssi', $credential['ciphertext'], $credential['nonce'], $credential['tag'], $userId);
    if (!$credentialStmt->execute()) {
        $credentialStmt->close();
        throw new RuntimeException('Failed to store encrypted user credential.');
    }
    $credentialStmt->close();

    writeCreatedFields($conn, $userId, 'User', [
        'employee_name' => $employeeName,
        'mobile_no' => $mobileNo,
        'username' => $username,
        'role_id' => $roleId,
        'status' => $status
    ], $currentUser);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $statusCode = $error instanceof DomainException ? 409 : 500;
    if (!$error instanceof DomainException) {
        error_log('User creation failed: ' . $error->getMessage());
    }
    $message = $error instanceof DomainException
        ? $error->getMessage()
        : 'Failed to create user and securely store its display credential.';
    sendResponse(false, $message, [], [], $statusCode);
}
$conn->close();

sendResponse(true, 'User created successfully', ['user' => [
    'employee_name' => $employeeName,
    'mobile_no' => $mobileNo,
    'username' => $username,
    'role_id' => $roleId,
    'role' => $role['role_name'],
    'status' => $status
]]);
?>
