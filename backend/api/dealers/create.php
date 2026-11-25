<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/dealer_validation.php';
require_once '../../utils/audit.php';
require_once '../../utils/user_creation.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    requirePermission('dealers.add');
    sendResponse(false, "Invalid request payload", [], [], 400);
}

if (array_key_exists('link_existing_id', $data)) {
    requirePermission('dealers.edit');
    $personnelId = (int) $data['link_existing_id'];
    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        sendResponse(false, 'Database connection failed', [], [], 500);
    }

    $conn->begin_transaction();
    try {
        $user = createAndLinkPersonnelLoginInTransaction(
            $conn,
            'dealer',
            $personnelId,
            (string) ($data['password'] ?? ''),
            (string) ($data['confirm_password'] ?? ''),
            $currentUser
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        $conn->close();
        if (!$error instanceof DomainException) {
            error_log('Dealer login linking failed: ' . $error->getMessage());
        }
        sendResponse(
            false,
            $error instanceof DomainException ? $error->getMessage() : 'Failed to create and link dealer login account.',
            [],
            [],
            $error instanceof DomainException ? 409 : 500
        );
    }
    $conn->close();
    sendResponse(true, 'Dealer login account linked successfully.', [
        'id' => $personnelId,
        'user' => $user
    ]);
}

requirePermission('dealers.add');

$validation = validateDealerFields($data);
if (!empty($validation['errors'])) {
    sendResponse(false, $validation['errors'][0], ['errors' => $validation['errors']], [], 400);
}

$password = (string) ($data['password'] ?? '');
$confirmPassword = (string) ($data['confirm_password'] ?? '');
if (strlen($password) < 8) {
    sendResponse(false, 'Password must be at least 8 characters long.', [], [], 400);
}
if (!hash_equals($password, $confirmPassword)) {
    sendResponse(false, 'Passwords do not match.', [], [], 400);
}

$validData = $validation['data'];
if (!preg_match('/^[6-9][0-9]{9}$/', $validData['mobile_no'])) {
    sendResponse(false, 'Enter a valid 10-digit mobile number.', [], [], 400);
}
$status = strtolower(trim((string) ($data['status'] ?? 'active')));
if (!in_array($status, ['active', 'inactive'], true)) {
    sendResponse(false, 'Dealer status must be Active or Inactive.', [], [], 400);
}
$validData['status'] = $status;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$dbErrors = checkDealerDuplicatesInDb($conn, $validData['dealer_name'], $validData['mobile_no']);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$conn->begin_transaction();
try {
    $role = ensureInstallationPersonnelRoleInTransaction($conn, 'Dealer');
    $user = createUserAccountInTransaction(
        $conn,
        $validData['dealer_name'],
        $validData['mobile_no'],
        $password,
        $role['role_id'],
        $role['role_name'],
        'active',
        $currentUser
    );

    $stmt = $conn->prepare(
        'INSERT INTO dealers (
            dealer_name, mobile_no, alternate_mobile_no, location,
            installation_service_areas, enrolled_date, installation_status,
            status, software, threshold_amount, notes, user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare dealer creation: ' . $conn->error);
    }
    $stmt->bind_param(
        'sssssssssdsi',
        $validData['dealer_name'],
        $validData['mobile_no'],
        $validData['alternate_mobile_no'],
        $validData['location'],
        $validData['installation_service_areas'],
        $validData['enrolled_date'],
        $validData['installation_status'],
        $status,
        $validData['software'],
        $validData['threshold_amount'],
        $validData['notes'],
        $user['user_id']
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create dealer: ' . $stmt->error);
    }
    $dealerId = (int) $conn->insert_id;
    $stmt->close();

    if (!empty($validData['software_list'])) {
        $swStmt = $conn->prepare('INSERT IGNORE INTO dealer_software (dealer_id, software) VALUES (?, ?)');
        if (!$swStmt) {
            throw new RuntimeException('Failed to prepare dealer software assignment: ' . $conn->error);
        }
        foreach ($validData['software_list'] as $software) {
            $swStmt->bind_param('is', $dealerId, $software);
            if (!$swStmt->execute()) {
                $error = $swStmt->error;
                $swStmt->close();
                throw new RuntimeException('Failed to assign dealer software: ' . $error);
            }
        }
        $swStmt->close();
    }

    $validData['status'] = $status;
    $validData['user_id'] = $user['user_id'];
    writeCreatedFields($conn, $dealerId, 'Dealer', $validData, $currentUser);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    if (isset($stmt) && $stmt) {
        $stmt->close();
    }
    $conn->close();
    if (!$error instanceof DomainException) {
        error_log('Dealer creation failed: ' . $error->getMessage());
    }
    sendResponse(
        false,
        $error instanceof DomainException ? $error->getMessage() : 'Failed to create dealer and login account.',
        [],
        [],
        $error instanceof DomainException ? 409 : 500
    );
}
$conn->close();
sendResponse(true, 'Dealer created successfully.', [
    'id' => $dealerId,
    'user' => $user
]);
?>
