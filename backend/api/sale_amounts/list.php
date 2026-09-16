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

$result = $conn->query(
    'SELECT
        id,
        sale_amount,
        status
     FROM sale_amounts
     ORDER BY sale_amount ASC'
);

$saleAmounts = [];

while ($row = $result->fetch_assoc()) {
    $saleAmounts[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Sale amounts fetched successfully.',
    [
        'sale_amounts' => $saleAmounts
    ]
);
?>