<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('users.delete');

$data = json_decode(file_get_contents('php://input'));

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(false, 'User ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

/*
 * Check user exists
 */
$check = $conn->prepare(
    'SELECT *
     FROM users
     WHERE id = ?
     LIMIT 1'
);

$check->bind_param('i', $id);
$check->execute();

if ($check->get_result()->num_rows === 0) {
    $check->close();
    $conn->close();

    sendResponse(false, 'User not found.', [], [], 404);
}

$check->close();

$userSnapshotStmt = $conn->prepare('SELECT id, employee_name, mobile_no, username, role, role_id, status, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
$userSnapshotStmt->bind_param('i', $id);
$userSnapshotStmt->execute();
$userSnapshot = $userSnapshotStmt->get_result()->fetch_assoc() ?: [];
$userSnapshotStmt->close();
writeDeleteSnapshot($conn, $id, 'User', $userSnapshot, $currentUser);

/*
 * Delete user
 */
$stmt = $conn->prepare(
    'DELETE FROM users
     WHERE id = ?'
);

$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to delete user.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'User deleted successfully.'
);