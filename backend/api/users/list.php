<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('users.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT u.id, u.employee_name, u.mobile_no, u.username, u.role, u.role_id, u.status, u.created_at, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC');
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Users fetched successfully', ['users' => $users]);
?>
