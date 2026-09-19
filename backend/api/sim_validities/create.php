<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sim_validity.add');

$data = json_decode(file_get_contents('php://input'));

$monthsRaw = trim((string) ($data->months ?? ''));
$status = strtolower(trim((string) ($data->status ?? 'active')));

if (!preg_match('/^[1-9][0-9]*$/', $monthsRaw)) {
    sendResponse(
        false,
        'Number of months must be a positive integer.',
        [],
        [],
        400
    );
}

if (!in_array($status, ['active', 'inactive'], true)) {
    sendResponse(
        false,
        'Invalid SIM validity status.',
        [],
        [],
        400
    );
}

$months = (int) $monthsRaw;

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed',
        [],
        [],
        500
    );
}

/*
 * Prevent duplicate validity months
 */
$check = $conn->prepare(
    'SELECT id
     FROM sim_validities
     WHERE months = ?
     LIMIT 1'
);

$check->bind_param('i', $months);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();

    sendResponse(
        false,
        'SIM validity already exists.',
        [],
        [],
        409
    );
}

$check->close();

/*
 * Insert validity + status
 */
$stmt = $conn->prepare(
    'INSERT INTO sim_validities (months, status)
     VALUES (?, ?)'
);

$stmt->bind_param('is', $months, $status);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to add SIM validity.',
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
    'SIM validity added successfully.',
    [
        'id' => $id
    ]
);