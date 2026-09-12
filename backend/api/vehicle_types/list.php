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

$result = $conn->query(
    'SELECT id, vehicle_type, status, created_at, updated_at
     FROM vehicle_types
     ORDER BY vehicle_type ASC'
);

$vehicleTypes = [];

while ($result && ($row = $result->fetch_assoc())) {
    $vehicleTypes[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Vehicle types fetched successfully.',
    ['vehicle_types' => $vehicleTypes]
);
?>