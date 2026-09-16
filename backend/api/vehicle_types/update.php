<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('vehicle_types.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload.', [], [], 400);
}

$id = (int) ($data->id ?? 0);
$vehicleType = trim((string) ($data->vehicle_type ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if ($id <= 0) {
    sendResponse(false, 'Vehicle type ID is required.', [], [], 400);
}

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
// Exclude the current record
$check = $conn->prepare(
    'SELECT id
     FROM vehicle_types
     WHERE LOWER(vehicle_type) = LOWER(?)
     AND id != ?
     LIMIT 1'
);

$check->bind_param('si', $vehicleType, $id);
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

// Check whether record exists
$exists = $conn->prepare(
    'SELECT id
     FROM vehicle_types
     WHERE id = ?
     LIMIT 1'
);

$exists->bind_param('i', $id);
$exists->execute();

if ($exists->get_result()->num_rows === 0) {
    $exists->close();
    $conn->close();

    sendResponse(
        false,
        'Vehicle type not found.',
        [],
        [],
        404
    );
}

$exists->close();

// Update vehicle type + status
$stmt = $conn->prepare(
    'UPDATE vehicle_types
     SET vehicle_type = ?, status = ?
     WHERE id = ?'
);

$stmt->bind_param(
    'ssi',
    $vehicleType,
    $status,
    $id
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to update vehicle type.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Vehicle type updated successfully.'
);
?>