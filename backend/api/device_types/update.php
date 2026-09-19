<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('device_types.edit');

$data = json_decode(file_get_contents('php://input'));

$id = (int) ($data->id ?? 0);
$deviceType = trim((string) ($data->device_type ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if ($id <= 0) {
    sendResponse(false, 'Device type ID is required.', [], [], 400);
}

if ($deviceType === '') {
    sendResponse(false, 'Device type is required.', [], [], 400);
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid device type status.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

// Duplicate device type check
$check = $conn->prepare(
    'SELECT id
     FROM device_types
     WHERE device_type = ?
       AND id != ?
     LIMIT 1'
);

$check->bind_param('si', $deviceType, $id);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'Device type already exists.',
        [],
        [],
        409
    );
}

$check->close();

// Fetch existing record
$exists = $conn->prepare(
    'SELECT device_type, status
     FROM device_types
     WHERE id = ?
     LIMIT 1'
);

$exists->bind_param('i', $id);
$exists->execute();

$existsResult = $exists->get_result();

if ($existsResult->num_rows === 0) {
    $exists->close();
    $conn->close();

    sendResponse(
        false,
        'Device type not found.',
        [],
        [],
        404
    );
}

$oldDeviceType = $existsResult->fetch_assoc();

$exists->close();

$conn->begin_transaction();

try {

    $newValues = [
        'device_type' => $deviceType,
        'status' => $status
    ];

    // Save only changed fields
    writeChangedFields(
        $conn,
        $id,
        'Device Type',
        $oldDeviceType,
        $newValues,
        $currentUser
    );

    $stmt = $conn->prepare(
        'UPDATE device_types
         SET device_type = ?, status = ?
         WHERE id = ?'
    );

    $stmt->bind_param(
        'ssi',
        $deviceType,
        $status,
        $id
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to update device type.');
    }

    $stmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'Device type updated successfully.'
    );

} catch (Throwable $e) {

    $conn->rollback();

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $conn->close();

    sendResponse(
        false,
        'Failed to update device type.',
        [],
        [],
        500
    );
}