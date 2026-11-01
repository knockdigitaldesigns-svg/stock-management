<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('permissions.view');
ensurePermissionDefinitions([
    'courier.view', 'courier.add', 'courier.edit', 'courier.delete', 'courier.approve',
    'stock_management.edit', 'stock_management.delete',
    'stock_transfer.edit', 'stock_transfer.delete',
    'reports.export', 'renewals.delete',
    'dealer_sim_activation.view', 'dealer_sim_activation.edit', 'dealer_sim_activation.delete',
    'dealer_sim_activation.import', 'dealers.import', 'technicians.import',
    'support.view', 'support.add', 'support.edit', 'support.delete',
    'support.assign', 'support.close', 'support.qa.view', 'support.qa.manage'
]);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM permissions');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count permissions.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare('SELECT id, permission_key, permission_name, module, action FROM permissions ORDER BY module ASC, permission_name ASC, id ASC LIMIT ? OFFSET ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare permission query.', [], [], 500);
}
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch permissions.', [], [], 500);
}
$result = $stmt->get_result();
$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Permissions fetched successfully', [
    'permissions' => $permissions,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
