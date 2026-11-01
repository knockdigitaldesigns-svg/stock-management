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

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM roles');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count roles.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare('SELECT id, role_name, description, status, is_system_role, created_at, updated_at FROM roles ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare role query.', [], [], 500);
}
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch roles.', [], [], 500);
}
$result = $stmt->get_result();
$roles = [];
while ($row = $result->fetch_assoc()) {
    $roles[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Roles fetched successfully', [
    'roles' => $roles,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
