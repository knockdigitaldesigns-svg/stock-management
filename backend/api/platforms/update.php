<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('platforms.edit');

$data = json_decode(file_get_contents('php://input'));

$id = (int) ($data->id ?? 0);
$platformName = trim((string) ($data->platform_name ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(
        false,
        'Invalid platform status.',
        [],
        [],
        400
    );
}

if ($id <= 0) {
    sendResponse(false, 'Platform ID is required.', [], [], 400);
}

if ($platformName === '') {
    sendResponse(false, 'Platform name is required.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

/*
 * Duplicate check excluding current record.
 */
$check = $conn->prepare(
    'SELECT id
     FROM platforms
     WHERE LOWER(platform_name) = LOWER(?)
     AND id != ?
     LIMIT 1'
);

$check->bind_param('si', $platformName, $id);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'Platform already exists.',
        [],
        [],
        409
    );
}

$check->close();

/*
 * Check whether platform exists.
 */
$exists = $conn->prepare(
    'SELECT id FROM platforms WHERE id = ? LIMIT 1'
);

$exists->bind_param('i', $id);
$exists->execute();

if ($exists->get_result()->num_rows === 0) {
    $exists->close();
    $conn->close();

    sendResponse(
        false,
        'Platform not found.',
        [],
        [],
        404
    );
}

$exists->close();

$stmt = $conn->prepare(
    'UPDATE platforms
     SET platform_name = ?, status = ?
     WHERE id = ?'
);

$stmt->bind_param(
    'ssi',
    $platformName,
    $status,
    $id
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to update platform.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Platform updated successfully.'
);