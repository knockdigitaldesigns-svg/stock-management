<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('platforms.add');

$data = json_decode(file_get_contents('php://input'));

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

if ($platformName === '') {
    sendResponse(false, 'Platform name is required.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

/*
 * Case-insensitive duplicate check.
 */
$check = $conn->prepare(
    'SELECT id FROM platforms WHERE LOWER(platform_name) = LOWER(?) LIMIT 1'
);

$check->bind_param('s', $platformName);
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

$stmt = $conn->prepare(
    'INSERT INTO platforms (platform_name, status)
     VALUES (?, ?)'
);

$stmt->bind_param(
    'ss',
    $platformName,
    $status
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to add platform.',
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
    'Platform added successfully.',
    ['id' => $id]
);