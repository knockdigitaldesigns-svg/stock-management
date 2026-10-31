<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sale_amounts.view');

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM sale_amounts');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count sale amounts.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare(
    'SELECT
        id,
        sale_amount,
        status,
        created_at
     FROM sale_amounts
     ORDER BY sale_amount ASC, id ASC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare sale amount query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch sale amounts.', [], [], 500);
}
$result = $stmt->get_result();

$saleAmounts = [];

while ($row = $result->fetch_assoc()) {
    $saleAmounts[] = $row;
}

$conn->close();
$stmt->close();

sendResponse(
    true,
    'Sale amounts fetched successfully.',
    [
        'sale_amounts' => $saleAmounts,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);
?>