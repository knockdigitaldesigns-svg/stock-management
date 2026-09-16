<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sale_amounts.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);

$saleAmount = $data->sale_amount ?? null;

$status = trim(
    (string) ($data->status ?? 'Active')
);

if ($id <= 0) {
    sendResponse(
        false,
        'Invalid sale amount ID.',
        [],
        [],
        400
    );
}

if ($saleAmount === null || $saleAmount === '') {
    sendResponse(
        false,
        'Sale amount is required.',
        [],
        [],
        400
    );
}

if (!is_numeric($saleAmount)) {
    sendResponse(
        false,
        'Sale amount must be a valid number.',
        [],
        [],
        400
    );
}

$saleAmount = round((float) $saleAmount, 2);

if ($saleAmount <= 0) {
    sendResponse(
        false,
        'Sale amount must be greater than 0.',
        [],
        [],
        400
    );
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(
        false,
        'Invalid status.',
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

/*
 * Duplicate amount check
 * Exclude current record
 */
$check = $conn->prepare(
    'SELECT id
     FROM sale_amounts
     WHERE sale_amount = ?
       AND id != ?
     LIMIT 1'
);

$check->bind_param(
    'di',
    $saleAmount,
    $id
);

$check->execute();

if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'Sale amount already exists.',
        [],
        [],
        409
    );
}

$check->close();

/*
 * Check record exists
 */
$exists = $conn->prepare(
    'SELECT id
     FROM sale_amounts
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
        'Sale amount not found.',
        [],
        [],
        404
    );
}

$exists->close();

/*
 * Update
 */
$stmt = $conn->prepare(
    'UPDATE sale_amounts
     SET sale_amount = ?,
         status = ?
     WHERE id = ?'
);

$stmt->bind_param(
    'dsi',
    $saleAmount,
    $status,
    $id
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to update sale amount.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Sale amount updated successfully.'
);
?>