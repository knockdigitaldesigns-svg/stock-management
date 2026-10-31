<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requireAnyPermission([
    'sims.view',
    'dealers.view',
    'technicians.view',
    'customers.view',
    'stock.view'
]);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM sim_types');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count SIM types.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare('SELECT id, sim_type FROM sim_types ORDER BY id ASC LIMIT ? OFFSET ?');
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare SIM type query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch SIM types.', [], [], 500);
}
$result = $stmt->get_result();
$simTypes = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $simTypes[] = [
            'id' => (int) $row['id'],
            'sim_type' => $row['sim_type']
        ];
    }
}
$conn->close();
$stmt->close();

sendResponse(true, 'SIM types fetched successfully', [
    'sim_types' => $simTypes,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
