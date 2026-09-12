<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('vehicle_types.add');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload.', [], [], 400);
}

$vehicleType = trim((string) ($data->vehicle_type ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if ($vehicleType === '') {
    sendResponse(false, 'Vehicle type is required.', [], [], 400);
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid status.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

// Check duplicate vehicle type
$check = $conn->prepare(
    'SELECT id
     FROM vehicle_types
     WHERE LOWER(vehicle_type) = LOWER(?)
     LIMIT 1'
);

$check->bind_param('s', $vehicleType);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'Vehicle type already exists.',
        [],
        [],
        409
    );
}

$check->close();

// Insert vehicle type
$stmt = $conn->prepare(
    'INSERT INTO vehicle_types (vehicle_type, status)
     VALUES (?, ?)'
);

$stmt->bind_param('ss', $vehicleType, $status);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to add vehicle type.',
        [],
        [],
        500
    );
}

$id = $conn->insert_id;

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Vehicle type added successfully.',
    ['id' => $id]
);
?>