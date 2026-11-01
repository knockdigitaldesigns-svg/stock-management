<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requireAnyPermission([
    'sim_validity.view',
    'sims.view',
    'dealers.view',
    'technicians.view',
    'customers.view',
    'stock.view',
    'stock_transfer.view',
    'customer_renewals.view'
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

/*
 * Master Settings needs BOTH Active and Inactive records.
 */
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query('SELECT COUNT(*) AS total FROM sim_validities');
if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count SIM validities.', [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare(
    "SELECT
        id,
        months,
        status,
        created_at,
        updated_at
     FROM sim_validities
     ORDER BY months ASC, id ASC
     LIMIT ? OFFSET ?"
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare SIM validity query.', [], [], 500);
}
$offset = ($page - 1) * $pageSize;
$stmt->bind_param('ii', $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch SIM validities.', [], [], 500);
}
$result = $stmt->get_result();

$validities = [];

while ($result && ($row = $result->fetch_assoc())) {
    $row['id'] = (int) $row['id'];
    $row['months'] = (int) $row['months'];
    $row['status'] = strtolower((string) ($row['status'] ?? 'active'));

    $validities[] = $row;
}

$conn->close();
$stmt->close();

sendResponse(
    true,
    'SIM validities fetched successfully',
    [
        'validities' => $validities,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);