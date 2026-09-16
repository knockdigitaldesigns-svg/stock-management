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
$conn->begin_transaction();
try {
writeDeleteSnapshot($conn, $id, 'Platform', $platform, $currentUser);
$stmt = $conn->prepare(
    'DELETE FROM platforms WHERE id = ?'
);

if (!$stmt) {
    $error = $conn->error;
    throw new RuntimeException('Delete prepare failed: ' . $error);
}

$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    $error = $stmt->error;
    throw new RuntimeException('Delete failed: ' . $error);
}

if ($stmt->affected_rows === 0) {
    throw new RuntimeException('Platform could not be deleted.');
}

$stmt->close();
$conn->commit();
$conn->close();

sendResponse(
    true,
    'Platform "' . $platform['platform_name'] . '" deleted successfully.'
);
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}