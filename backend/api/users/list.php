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

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM users');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count users.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare('SELECT u.id, u.employee_name, u.mobile_no, u.username, u.role, u.role_id, u.status, u.created_at, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare user query.', [], [], 500);
}
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch users.', [], [], 500);
}
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Users fetched successfully', [
    'users' => $users,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
