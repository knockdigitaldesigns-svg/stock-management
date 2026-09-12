<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('platforms.delete');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(false, 'Platform ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

/* Check platform exists */
$check = $conn->prepare(
    'SELECT id, platform_name
     FROM platforms
     WHERE id = ?
     LIMIT 1'
);

if (!$check) {
    $conn->close();

    sendResponse(
        false,
        'Failed to prepare platform check: ' . $conn->error,
        [],
        [],
        500
    );
}

$check->bind_param('i', $id);
$check->execute();

$result = $check->get_result();

if ($result->num_rows === 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'Platform not found.',
        [],
        [],
        404
    );
}

$platform = $result->fetch_assoc();

$check->close();

/* Delete */
$stmt = $conn->prepare(
    'DELETE FROM platforms WHERE id = ?'
);

if (!$stmt) {
    $error = $conn->error;
    $conn->close();

    sendResponse(
        false,
        'Delete prepare failed: ' . $error,
        [],
        [],
        500
    );
}

$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    $error = $stmt->error;

    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Delete failed: ' . $error,
        [],
        [],
        500
    );
}

if ($stmt->affected_rows === 0) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Platform could not be deleted.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Platform "' . $platform['platform_name'] . '" deleted successfully.'
);