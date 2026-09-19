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

$result = $conn->query(
    'SELECT id, platform_name, status, created_at, updated_at
     FROM platforms
     ORDER BY platform_name ASC'
);

$platforms = [];

while ($result && ($row = $result->fetch_assoc())) {
    $platforms[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Platforms fetched successfully.',
    [
        'platforms' => $platforms
    ]
);