<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/technician_validation.php';
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
    requirePermission('technicians.add');
    sendResponse(false, "Invalid request payload", [], [], 400);
}

if (array_key_exists('link_existing_id', $data)) {
    requirePermission('technicians.edit');
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
            'technician',
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
            error_log('Technician login linking failed: ' . $error->getMessage());
        }
        sendResponse(
            false,
            $error instanceof DomainException ? $error->getMessage() : 'Failed to create and link technician login account.',
            [],
            [],
            $error instanceof DomainException ? 409 : 500
        );
    }
    $conn->close();
    sendResponse(true, 'Technician login account linked successfully.', [
        'id' => $personnelId,
        'user' => $user
    ]);
}

requirePermission('technicians.add');

$validation = validateTechnicianFields($data);
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
    sendResponse(false, 'Technician status must be Active or Inactive.', [], [], 400);
}
$validData['status'] = $status;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$dbErrors = checkTechnicianDuplicatesInDb($conn, $validData['technician_name'], $validData['mobile_no']);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$conn->begin_transaction();
try {
    $role = ensureInstallationPersonnelRoleInTransaction($conn, 'Technician');
    $user = createUserAccountInTransaction(
        $conn,
        $validData['technician_name'],
        $validData['mobile_no'],
        $password,
        $role['role_id'],
        $role['role_name'],
        'active',
        $currentUser
    );

    $stmt = $conn->prepare(
        "INSERT INTO technicians (
            technician_name,
            mobile_no,
            alternate_mobile_no,
            location,
            installation_service_areas,
            enrolled_date,
            status,
            notes,
            user_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare technician creation: ' . $conn->error);
    }
    $stmt->bind_param(
        'ssssssssi',
        $validData['technician_name'],
        $validData['mobile_no'],
        $validData['alternate_mobile_no'],
        $validData['location'],
        $validData['installation_service_areas'],
        $validData['enrolled_date'],
        $status,
        $validData['notes'],
        $user['user_id']
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create technician: ' . $stmt->error);
    }
    $technicianId = (int) $conn->insert_id;
    $stmt->close();

    $validData['status'] = $status;
    $validData['user_id'] = $user['user_id'];
    writeCreatedFields($conn, $technicianId, 'Technician', $validData, $currentUser);
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    if (isset($stmt) && $stmt) {
        $stmt->close();
    }
    $conn->close();
    if (!$error instanceof DomainException) {
        error_log('Technician creation failed: ' . $error->getMessage());
    }
    sendResponse(
        false,
        $error instanceof DomainException ? $error->getMessage() : 'Failed to create technician and login account.',
        [],
        [],
        $error instanceof DomainException ? 409 : 500
    );
}
$conn->close();
sendResponse(true, 'Technician created successfully.', [
    'id' => $technicianId,
    'user' => $user
]);
?>
