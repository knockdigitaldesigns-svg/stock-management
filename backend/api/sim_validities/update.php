<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sim_validity.edit');

$data = json_decode(file_get_contents('php://input'));

$id = (int) ($data->id ?? 0);
$monthsRaw = trim((string) ($data->months ?? ''));
$status = strtolower(trim((string) ($data->status ?? 'active')));

if ($id <= 0) {
    sendResponse(
        false,
        'SIM validity ID is required.',
        [],
        [],
        400
    );
}

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
 * Check duplicate validity months
 * Exclude the current record
 */
$check = $conn->prepare(
    'SELECT id
     FROM sim_validities
     WHERE months = ?
       AND id != ?
     LIMIT 1'
);

$check->bind_param('ii', $months, $id);
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
 * Get existing record
 */
$exists = $conn->prepare(
    'SELECT id, months, status
     FROM sim_validities
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
        'SIM validity not found.',
        [],
        [],
        404
    );
}

$oldValidity = $existsResult->fetch_assoc();

$exists->close();

/*
 * Update months + status
 */
$stmt = $conn->prepare(
    'UPDATE sim_validities
     SET months = ?, status = ?
     WHERE id = ?'
);

$stmt->bind_param(
    'isi',
    $months,
    $status,
    $id
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to update SIM validity.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'SIM validity updated successfully.'
);