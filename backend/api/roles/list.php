<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('roles.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT id, role_name, description, status, is_system_role, created_at, updated_at FROM roles ORDER BY created_at DESC');
$stmt->execute();
$result = $stmt->get_result();
$roles = [];
while ($row = $result->fetch_assoc()) {
    $roles[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Roles fetched successfully', ['roles' => $roles]);
?>
