<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/customer_payment_verification.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid request data.', [], [], 400);
}
$verificationSubmissionId = max(0, (int) ($data->verification_submission_id ?? 0));
if ($verificationSubmissionId > 0) {
    requireAnyPermission(['customers.add', CUSTOMER_PAYMENT_VERIFICATION_PERMISSION]);
} else {
    requirePermission('customers.add');
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
$status = trim((string) ($data->status ?? 'Active'));
$mobileReuseConfirmed = filter_var($data->mobile_reuse_confirmed ?? false, FILTER_VALIDATE_BOOLEAN);


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
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Customer status must be Active or Inactive.', [], [], 400);
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
if ($verificationSubmissionId > 0) {
    $draftStmt = $conn->prepare(
        'SELECT submitter_user_id, reviewer_user_id, status, linked_customer_id,
                linked_vehicle_id, linked_payment_id
         FROM customer_payment_verification_submissions
         WHERE id = ? FOR UPDATE'
    );
    $draftStmt->bind_param('i', $verificationSubmissionId);
    $draftStmt->execute();
    $draft = $draftStmt->get_result()->fetch_assoc();
    $draftStmt->close();
    $currentUserId = (int) ($currentUser['user_id'] ?? 0);
    $isOwnerDraft = $draft && (int) $draft['submitter_user_id'] === $currentUserId && $draft['status'] === 'Draft';
    $isApplyingDraft = $draft && (int) ($draft['reviewer_user_id'] ?? 0) === $currentUserId && $draft['status'] === 'Applying';
    if (!$draft || (!$isOwnerDraft && !$isApplyingDraft)) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'This customer draft is not available for commit.', [], [], 403);
    }
    if (!empty($draft['linked_customer_id'])) {
        $linkedCustomerId = (int) $draft['linked_customer_id'];
        $customerStmt = $conn->prepare(
            'SELECT id FROM customers
             WHERE id = ? AND verification_submission_id = ?
             LIMIT 1'
        );
        $customerStmt->bind_param('ii', $linkedCustomerId, $verificationSubmissionId);
        $customerStmt->execute();
        $linkedCustomer = $customerStmt->get_result()->fetch_assoc();
        $customerStmt->close();
        if ($linkedCustomer) {
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Customer already created for this submission.', ['customer_id' => $linkedCustomerId, 'action' => 'existing']);
        }

        $customerStmt = $conn->prepare(
            'SELECT id FROM customers
             WHERE verification_submission_id = ?
             LIMIT 1 FOR UPDATE'
        );
        $customerStmt->bind_param('i', $verificationSubmissionId);
        $customerStmt->execute();
        $createdCustomer = $customerStmt->get_result()->fetch_assoc();
        $customerStmt->close();
        if ($createdCustomer) {
            $linkedCustomerId = (int) $createdCustomer['id'];
            $repairStmt = $conn->prepare(
                'UPDATE customer_payment_verification_submissions
                 SET linked_customer_id = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $repairStmt->bind_param('ii', $linkedCustomerId, $verificationSubmissionId);
            if (!$repairStmt->execute()) {
                $repairStmt->close();
                $conn->rollback();
                $conn->close();
                sendResponse(false, 'Unable to restore the customer link for this submission.', [], [], 500);
            }
            $repairStmt->close();
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Customer already created for this submission.', ['customer_id' => $linkedCustomerId, 'action' => 'existing']);
        }

        $linkedRecords = [
            [
                'id' => (int) ($draft['linked_vehicle_id'] ?? 0),
                'query' => 'SELECT id FROM customer_vehicle_details WHERE id = ? LIMIT 1'
            ],
            [
                'id' => (int) ($draft['linked_payment_id'] ?? 0),
                'query' => 'SELECT id FROM customer_payments WHERE id = ? LIMIT 1'
            ]
        ];
        foreach ($linkedRecords as $linkedRecord) {
            if ($linkedRecord['id'] <= 0) {
                continue;
            }
            $recordStmt = $conn->prepare($linkedRecord['query']);
            $recordStmt->bind_param('i', $linkedRecord['id']);
            $recordStmt->execute();
            $recordExists = $recordStmt->get_result()->num_rows > 0;
            $recordStmt->close();
            if ($recordExists) {
                $conn->rollback();
                $conn->close();
                sendResponse(
                    false,
                    'This submission has linked vehicle or payment records but its customer is missing. Manual reconciliation is required before approval can resume.',
                    [],
                    [],
                    409
                );
            }
        }

        $clearLinkStmt = $conn->prepare(
            'UPDATE customer_payment_verification_submissions
             SET linked_customer_id = NULL, linked_vehicle_id = NULL,
                 linked_payment_id = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $clearLinkStmt->bind_param('i', $verificationSubmissionId);
        if (!$clearLinkStmt->execute()) {
            $clearLinkStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to repair stale approval progress for this submission.', [], [], 500);
        }
        $clearLinkStmt->close();
    }
}
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
| Check Customer Uniqueness
| 1. Same Username + Same Mobile -> EXISTING_CUSTOMER_SAME_MOBILE
| 2. Same Username + Different Mobile -> USERNAME_EXISTS_DIFFERENT_MOBILE
| 3. Different Username + Same Mobile -> Allowed (New customer)
| 4. Different Username + Different Mobile -> Allowed (New customer)
|--------------------------------------------------------------------------
*/

// Check 1: Same Username + Same Mobile No
$stmt = $conn->prepare(
    "SELECT id, platform_id, username, primary_mobile_no, secondary_mobile_no, email, location, pincode, status
     FROM customers
     WHERE LOWER(TRIM(username)) = LOWER(?)
       AND TRIM(primary_mobile_no) = ?
     ORDER BY id ASC
     LIMIT 1"
);

$stmt->bind_param('ss', $username, $primaryMobile);
$stmt->execute();
$existingSameMobile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingSameMobile) {
    $conn->rollback();
    $conn->close();

    sendResponse(
        false,
        'Customer already exists with the same username and mobile number.',
        [
            'code' => 'EXISTING_CUSTOMER_SAME_MOBILE',
            'customer_id' => (int) $existingSameMobile['id'],
            'customer' => $existingSameMobile
        ],
        [],
        409
    );
}

// Check 2: Same Username + Different Mobile No
$stmt = $conn->prepare(
    "SELECT id
     FROM customers
     WHERE LOWER(TRIM(username)) = LOWER(?)
       AND TRIM(primary_mobile_no) != ?
     ORDER BY id ASC
     LIMIT 1"
);

$stmt->bind_param('ss', $username, $primaryMobile);
$stmt->execute();
$existingDiffMobile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingDiffMobile) {
    $conn->rollback();
    $conn->close();

    sendResponse(
        false,
        'Username already exists with a different mobile number.',
        [
            'code' => 'USERNAME_EXISTS_DIFFERENT_MOBILE'
        ],
        [],
        409
    );
}


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
        status,
        verification_submission_id
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0))"
);

$stmt->bind_param(
    'isssssssi',
    $platformId,
    $username,
    $primaryMobile,
    $secondaryValue,
    $emailValue,
    $location,
    $pincode,
    $status,
    $verificationSubmissionId
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
$linkStmt = $conn->prepare(
    'UPDATE customer_payment_verification_submissions
     SET linked_customer_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?'
);
if (!$linkStmt) {
    throw new RuntimeException('Failed to link the customer to its submission.');
}
$linkStmt->bind_param('ii', $customerId, $verificationSubmissionId);
if ($verificationSubmissionId > 0 && !$linkStmt->execute()) {
    $linkStmt->close();
    throw new RuntimeException('Failed to save the customer submission link.');
}
$linkStmt->close();
$createdCustomer = [
    'platform_id' => $platformId,
    'username' => $username,
    'primary_mobile_no' => $primaryMobile,
    'secondary_mobile_no' => $secondaryValue,
    'email' => $emailValue,
    'location' => $location,
    'pincode' => $pincode,
    'status' => $status
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