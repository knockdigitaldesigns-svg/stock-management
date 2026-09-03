<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$payload = authenticate();
$userId = (int) ($payload['user_id'] ?? 0);
if (!$userId) {
    sendResponse(false, 'Unauthorized', [], [], 401);
}

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$currentPassword = trim((string) ($data->current_password ?? ''));
$newPassword = trim((string) ($data->new_password ?? ''));
$confirmPassword = trim((string) ($data->confirm_password ?? ''));

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    sendResponse(false, 'All password fields are required', [], [], 400);
}
if (strlen($newPassword) < 8) {
    sendResponse(false, 'New password must be at least 8 characters long', [], [], 400);
}
if ($newPassword !== $confirmPassword) {
    sendResponse(false, 'New password and confirm password do not match', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'User not found', [], [], 404);
}
$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($currentPassword, $user['password'])) {
    $conn->close();
    sendResponse(false, 'Current password is incorrect', [], [], 400);
}

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$update = $conn->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
$update->bind_param('si', $hashedPassword, $userId);
$ok = $update->execute();
$update->close();
$conn->close();

if (!$ok) {
    sendResponse(false, 'Failed to update password', [], [], 500);
}

sendResponse(true, 'Password changed successfully', []);
