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
requirePermission('customers.add');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid request data.', [], [], 400);
}


/*
|--------------------------------------------------------------------------
| Get Input
|--------------------------------------------------------------------------
*/

$platformId = isset($data->platform_id)
    ? (int) $data->platform_id
    : 0;

$username = trim((string) ($data->username ?? ''));

$primaryMobile = trim(
    (string) ($data->primary_mobile_no ?? '')
);

$secondaryMobile = trim(
    (string) ($data->secondary_mobile_no ?? '')
);

$email = trim(
    (string) ($data->email ?? '')
);

$location = trim(
    (string) ($data->location ?? '')
);

$pincode = trim(
    (string) ($data->pincode ?? '')
);


/*
|--------------------------------------------------------------------------
| Required Validation
|--------------------------------------------------------------------------
*/

if ($platformId <= 0) {
    sendResponse(false, 'Platform is required.', [], [], 400);
}

if ($username === '') {
    sendResponse(false, 'Username is required.', [], [], 400);
}

if ($primaryMobile === '') {
    sendResponse(false, 'Primary mobile number is required.', [], [], 400);
}

if ($location === '') {
    sendResponse(false, 'Location is required.', [], [], 400);
}

if ($pincode === '') {
    sendResponse(false, 'Pincode is required.', [], [], 400);
}


/*
|--------------------------------------------------------------------------
| Username Validation
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
    sendResponse(
        false,
        'Username can contain only letters, numbers, dot, underscore and hyphen.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Mobile Validation
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[0-9]{10}$/', $primaryMobile)) {
    sendResponse(
        false,
        'Primary mobile number must contain exactly 10 digits.',
        [],
        [],
        400
    );
}

if ($secondaryMobile !== '') {
    if (!preg_match('/^[0-9]{10}$/', $secondaryMobile)) {
        sendResponse(
            false,
            'Secondary mobile number must contain exactly 10 digits.',
            [],
            [],
            400
        );
    }

    if ($secondaryMobile === $primaryMobile) {
        sendResponse(
            false,
            'Secondary mobile number cannot be the same as primary mobile number.',
            [],
            [],
            400
        );
    }
}


/*
|--------------------------------------------------------------------------
| Email Validation
|--------------------------------------------------------------------------
*/

if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(
            false,
            'Please enter a valid email address.',
            [],
            [],
            400
        );
    }
}


/*
|--------------------------------------------------------------------------
| Pincode Validation
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[0-9]{6}$/', $pincode)) {
    sendResponse(
        false,
        'Pincode must contain exactly 6 digits.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

$db = new Database();
$conn = $db->getConnection();

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
|--------------------------------------------------------------------------
| Check Platform
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();
$stmt = $conn->prepare(
    "SELECT id
     FROM platforms
     WHERE id = ?
       AND status = 'Active'
     LIMIT 1"
);

$stmt->bind_param('i', $platformId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Selected platform is not available or inactive.',
        [],
        [],
        400
    );
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Check Duplicate Username (per platform)
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id
     FROM customers
     WHERE platform_id = ?
       AND LOWER(username) = LOWER(?)
     LIMIT 1"
);

$stmt->bind_param('is', $platformId, $username);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Username already exists for this platform.',
        [],
        [],
        409
    );
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Check Duplicate Primary Mobile
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id
     FROM customers
     WHERE primary_mobile_no = ?
     LIMIT 1"
);

$stmt->bind_param('s', $primaryMobile);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Primary mobile number already exists.',
        [],
        [],
        409
    );
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Insert Customer
|--------------------------------------------------------------------------
*/

$secondaryValue = $secondaryMobile !== ''
    ? $secondaryMobile
    : null;

$emailValue = $email !== ''
    ? $email
    : null;


/*
|--------------------------------------------------------------------------
| Insert
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "INSERT INTO customers (
        platform_id,
        username,
        primary_mobile_no,
        secondary_mobile_no,
        email,
        location,
        pincode,
        status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
);

$stmt->bind_param(
    'issssss',
    $platformId,
    $username,
    $primaryMobile,
    $secondaryValue,
    $emailValue,
    $location,
    $pincode
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();
    $conn->rollback();
    $conn->close();

    sendResponse(
        false,
        'Failed to create customer.',
        [],
        ['error' => $error],
        500
    );
}

$customerId = $conn->insert_id;

$stmt->close();
$createdCustomer = [
    'platform_id' => $platformId,
    'username' => $username,
    'primary_mobile_no' => $primaryMobile,
    'secondary_mobile_no' => $secondaryValue,
    'email' => $emailValue,
    'location' => $location,
    'pincode' => $pincode,
    'status' => 'Active'
];
try {
    writeCreatedFields($conn, $customerId, 'Customer', $createdCustomer, $currentUser);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
$conn->close();


/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

sendResponse(
    true,
    'Customer created successfully.',
    [
        'customer_id' => $customerId
    ]
);

?>