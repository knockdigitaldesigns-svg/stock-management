<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('permissions.view');

$roleId = isset($_GET['role_id']) ? (int) $_GET['role_id'] : 0;
if (!$roleId) {
    sendResponse(false, 'Role id is required', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key ASC');
$stmt->bind_param('i', $roleId);
$stmt->execute();
$result = $stmt->get_result();
$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row['permission_key'];
}
$stmt->close();
$conn->close();

sendResponse(true, 'Role permissions fetched successfully', ['permissions' => $permissions]);
?>
