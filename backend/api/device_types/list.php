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

$result = $conn->query(
    'SELECT
        id,
        device_type,
        status,
        created_at,
        updated_at
     FROM device_types
     ORDER BY device_type ASC'
);

$deviceTypes = [];

while ($result && ($row = $result->fetch_assoc())) {
    $deviceTypes[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Device types fetched successfully',
    [
        'device_types' => $deviceTypes
    ]
);