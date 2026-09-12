<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('lead_closures.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);
$name = trim((string) ($data->lead_closure_name ?? ''));
$mobile = trim((string) ($data->mobile_no ?? ''));
$location = trim((string) ($data->location ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if ($id <= 0) {
    sendResponse(false, 'Invalid lead closure ID.', [], [], 400);
}

if ($name === '') {
    sendResponse(false, 'Lead closure name is required.', [], [], 400);
}

if (!preg_match('/^[0-9]{10}$/', $mobile)) {
    sendResponse(false, 'Mobile number must contain exactly 10 digits.', [], [], 400);
}

if ($location === '') {
    sendResponse(false, 'Location is required.', [], [], 400);
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid status.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

/* Duplicate name excluding current record */
$checkName = $conn->prepare(
    'SELECT id
     FROM lead_closures
     WHERE LOWER(lead_closure_name) = LOWER(?)
       AND id != ?
     LIMIT 1'
);

$checkName->bind_param('si', $name, $id);
$checkName->execute();

if ($checkName->get_result()->num_rows > 0) {
    $checkName->close();
    $conn->close();

    sendResponse(
        false,
        'Lead closure name already exists.',
        [],
        [],
        409
    );
}

$checkName->close();

/* Duplicate mobile excluding current record */
$checkMobile = $conn->prepare(
    'SELECT id
     FROM lead_closures
     WHERE mobile_no = ?
       AND id != ?
     LIMIT 1'
);

$checkMobile->bind_param('si', $mobile, $id);
$checkMobile->execute();

if ($checkMobile->get_result()->num_rows > 0) {
    $checkMobile->close();
    $conn->close();

    sendResponse(
        false,
        'Mobile number already exists.',
        [],
        [],
        409
    );
}

$checkMobile->close();

$stmt = $conn->prepare(
    'UPDATE lead_closures
     SET lead_closure_name = ?,
         mobile_no = ?,
         location = ?,
         status = ?
     WHERE id = ?'
);

$stmt->bind_param(
    'ssssi',
    $name,
    $mobile,
    $location,
    $status,
    $id
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to update lead closure.',
        [],
        [],
        500
    );
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    'Lead closure updated successfully.'
);
?>