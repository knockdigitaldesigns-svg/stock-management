<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sale_amounts.add');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$saleAmount = $data->sale_amount ?? null;
$status = trim((string) ($data->status ?? 'Active'));

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
 */
$check = $conn->prepare(
    'SELECT id
     FROM sale_amounts
     WHERE sale_amount = ?
     LIMIT 1'
);

$check->bind_param('d', $saleAmount);
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
 * Insert
 */
$stmt = $conn->prepare(
    'INSERT INTO sale_amounts
     (sale_amount, status)
     VALUES (?, ?)'
);

$stmt->bind_param(
    'ds',
    $saleAmount,
    $status
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to add sale amount.',
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
    'Sale amount added successfully.',
    [
        'id' => $id
    ]
);
?>