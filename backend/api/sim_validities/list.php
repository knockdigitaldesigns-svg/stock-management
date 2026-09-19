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
$result = $conn->query(
    "SELECT
        id,
        months,
        status,
        created_at,
        updated_at
     FROM sim_validities
     ORDER BY months ASC"
);

$validities = [];

while ($result && ($row = $result->fetch_assoc())) {
    $row['id'] = (int) $row['id'];
    $row['months'] = (int) $row['months'];
    $row['status'] = strtolower((string) ($row['status'] ?? 'active'));

    $validities[] = $row;
}

$conn->close();

sendResponse(
    true,
    'SIM validities fetched successfully',
    [
        'validities' => $validities
    ]
);