<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('permissions.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare('SELECT id, permission_key, permission_name, module, action FROM permissions ORDER BY module ASC, permission_name ASC');
$stmt->execute();
$result = $stmt->get_result();
$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Permissions fetched successfully', ['permissions' => $permissions]);
?>
