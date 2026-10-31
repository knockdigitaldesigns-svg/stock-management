<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requireAnyPermission([
    'device_types.view',
    'devices.view',
    'dealers.view',
    'technicians.view',
    'stock.view',
    'stock_transfer.view',
    'customers.view',
    'device_alert.view'
]);

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed',
        [],
        [],
        500
    );
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM device_types');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count device types.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare(
    'SELECT
        id,
        device_type,
        status,
        created_at,
        updated_at
     FROM device_types
     ORDER BY device_type ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare device type query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch device types.', [], [], 500);
}
$result = $stmt->get_result();

$deviceTypes = [];

while ($result && ($row = $result->fetch_assoc())) {
    $deviceTypes[] = $row;
}

$conn->close();
$stmt->close();

sendResponse(
    true,
    'Device types fetched successfully',
    [
        'device_types' => $deviceTypes,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);