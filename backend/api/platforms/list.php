<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requireAnyPermission([
    'platforms.view',
    'customers.view',
    'dealers.view',
    'technicians.view',
    'stock.view',
    'stock_transfer.view'
]);

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM platforms');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count platforms.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(
    'SELECT id, platform_name, status, created_at, updated_at
     FROM platforms
     ORDER BY platform_name ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare platform query.', [], [], 500);
}
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch platforms.', [], [], 500);
}
$result = $stmt->get_result();

$platforms = [];

while ($result && ($row = $result->fetch_assoc())) {
    $platforms[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Platforms fetched successfully.',
    [
        'platforms' => $platforms,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);