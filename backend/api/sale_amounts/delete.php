<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sale_amounts.delete');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(
        false,
        'Invalid sale amount ID.',
        [],
        [],
        400
    );
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}

$stmt = $conn->prepare(
    'DELETE FROM sale_amounts
     WHERE id = ?'
);

$stmt->bind_param('i', $id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Sale amount not found.',
        [],
        [],
        404
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Sale amount deleted successfully.'
);
?>