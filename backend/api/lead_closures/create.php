<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('lead_closures.add');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$name = trim((string) ($data->lead_closure_name ?? ''));
$mobile = trim((string) ($data->mobile_no ?? ''));
$location = trim((string) ($data->location ?? ''));
$status = trim((string) ($data->status ?? 'Active'));

if ($name === '') {
    sendResponse(false, 'Lead closure name is required.', [], [], 400);
}

if ($mobile === '') {
    sendResponse(false, 'Mobile number is required.', [], [], 400);
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

/* Duplicate name */
$checkName = $conn->prepare(
    'SELECT id FROM lead_closures
     WHERE LOWER(lead_closure_name) = LOWER(?)
     LIMIT 1'
);

$checkName->bind_param('s', $name);
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

/* Duplicate mobile */
$checkMobile = $conn->prepare(
    'SELECT id FROM lead_closures
     WHERE mobile_no = ?
     LIMIT 1'
);

$checkMobile->bind_param('s', $mobile);
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
    'INSERT INTO lead_closures
     (lead_closure_name, mobile_no, location, status)
     VALUES (?, ?, ?, ?)'
);

$stmt->bind_param(
    'ssss',
    $name,
    $mobile,
    $location,
    $status
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to add lead closure.',
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
    'Lead closure added successfully.',
    ['id' => $id]
);
?>