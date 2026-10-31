<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('vehicle_types.view');

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM vehicle_types');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count vehicle types.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare(
    'SELECT id, vehicle_type, status, created_at, updated_at
     FROM vehicle_types
     ORDER BY vehicle_type ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare vehicle type query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch vehicle types.', [], [], 500);
}
$result = $stmt->get_result();

$vehicleTypes = [];

while ($result && ($row = $result->fetch_assoc())) {
    $vehicleTypes[] = $row;
}

$conn->close();
$stmt->close();

sendResponse(
    true,
    'Vehicle types fetched successfully.',
    [
        'vehicle_types' => $vehicleTypes,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);
?>