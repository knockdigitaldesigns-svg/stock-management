<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('lead_closures.view');

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM lead_closures');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count lead closures.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare(
    'SELECT
        id,
        lead_closure_name,
        mobile_no,
        location,
        status,
        created_at
     FROM lead_closures
     ORDER BY lead_closure_name ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare lead closure query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch lead closures.', [], [], 500);
}
$result = $stmt->get_result();

$leadClosures = [];

while ($row = $result->fetch_assoc()) {
    $leadClosures[] = $row;
}

$conn->close();
$stmt->close();

sendResponse(
    true,
    'Lead closures fetched successfully.',
    [
        'lead_closures' => $leadClosures,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);
?>