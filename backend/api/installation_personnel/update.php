<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/installation_personnel.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'));
$type = strtolower(trim((string) ($data->type ?? '')));
$id = (int) ($data->id ?? 0);
$definition = installationPersonnelDefinition($type);
if ($definition === null || $id <= 0) {
    sendResponse(false, 'A valid installation person type and ID are required.', [], [], 400);
}
requirePermission($definition['permission_prefix'] . '.edit');

$username = strtolower((string) preg_replace('/\s+/', '', trim((string) ($data->username ?? ''))));
$mobileNo = trim((string) ($data->mobile_no ?? ''));
$status = strtolower(trim((string) ($data->status ?? '')));
if ($username === '' || strlen($username) > 50) {
    sendResponse(false, 'Username is required and must be at most 50 characters.', [], [], 400);
}
if (!preg_match('/^[6-9][0-9]{9}$/', $mobileNo)) {
    sendResponse(false, 'Mobile number must contain 10 valid digits.', [], [], 400);
}
if (!in_array($status, ['active', 'inactive'], true)) {
    sendResponse(false, 'Account status must be active or inactive.', [], [], 400);
}

$table = $definition['table'];
$roleSql = installationPersonnelRoleSqlExpression();
$roleName = strtolower($definition['role_name']);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();
try {
    $lookup = $conn->prepare(
        "SELECT p.id, p.user_id, p.mobile_no AS personnel_mobile_no,
                u.username, u.mobile_no, u.status, u.employee_name
         FROM {$table} p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE p.id = ? AND p.user_id IS NOT NULL AND {$roleSql} = ?
         LIMIT 1 FOR UPDATE"
    );
    if (!$lookup) {
        throw new RuntimeException('Unable to prepare linked account lookup.');
    }
    $lookup->bind_param('is', $id, $roleName);
    if (!$lookup->execute()) {
        throw new RuntimeException('Unable to load the linked account.');
    }
    $old = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$old) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Installation person or linked login account not found.', [], [], 404);
    }

    $userId = (int) $old['user_id'];
    $usernameCheck = $conn->prepare(
        "SELECT id FROM users
         WHERE id <> ?
           AND (LOWER(username) = ?
                OR LOWER(REPLACE(REPLACE(TRIM(COALESCE(employee_name, '')), ' ', ''), CHAR(9), '')) = ?)
         LIMIT 1"
    );
    if (!$usernameCheck) {
        throw new RuntimeException('Unable to prepare username uniqueness check.');
    }
    $usernameCheck->bind_param('iss', $userId, $username, $username);
    if (!$usernameCheck->execute()) {
        throw new RuntimeException('Unable to validate username uniqueness.');
    }
    $usernameExists = $usernameCheck->get_result()->num_rows > 0;
    $usernameCheck->close();
    if ($usernameExists) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Username is already in use.', [], [], 409);
    }

    $userMobileCheck = $conn->prepare('SELECT id FROM users WHERE mobile_no = ? AND id <> ? LIMIT 1');
    if (!$userMobileCheck) {
        throw new RuntimeException('Unable to prepare mobile number uniqueness check.');
    }
    $userMobileCheck->bind_param('si', $mobileNo, $userId);
    if (!$userMobileCheck->execute()) {
        throw new RuntimeException('Unable to validate mobile number uniqueness.');
    }
    $userMobileExists = $userMobileCheck->get_result()->num_rows > 0;
    $userMobileCheck->close();

    $personMobileCheck = $conn->prepare("SELECT id FROM {$table} WHERE mobile_no = ? AND id <> ? LIMIT 1");
    if (!$personMobileCheck) {
        throw new RuntimeException('Unable to prepare personnel mobile number uniqueness check.');
    }
    $personMobileCheck->bind_param('si', $mobileNo, $id);
    if (!$personMobileCheck->execute()) {
        throw new RuntimeException('Unable to validate personnel mobile number uniqueness.');
    }
    $personMobileExists = $personMobileCheck->get_result()->num_rows > 0;
    $personMobileCheck->close();
    if ($userMobileExists || $personMobileExists) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Mobile number is already in use.', [], [], 409);
    }

    $oldUser = [
        'username' => $old['username'],
        'mobile_no' => $old['mobile_no'],
        'status' => $old['status']
    ];
    $newUser = [
        'username' => $username,
        'mobile_no' => $mobileNo,
        'status' => $status
    ];
    writeChangedFields($conn, $userId, 'User', $oldUser, $newUser, $currentUser);
    writeChangedFields(
        $conn,
        $id,
        $definition['role_name'],
        ['mobile_no' => $old['personnel_mobile_no']],
        ['mobile_no' => $mobileNo],
        $currentUser
    );

    if ($status === 'inactive') {
        $updateUser = $conn->prepare(
            'UPDATE users
             SET username = ?, mobile_no = ?, status = ?,
                 active_session_id = NULL, active_session_created_at = NULL, active_session_expires_at = NULL
             WHERE id = ?'
        );
        if (!$updateUser) {
            throw new RuntimeException('Unable to prepare linked account update.');
        }
        $updateUser->bind_param('sssi', $username, $mobileNo, $status, $userId);
    } else {
        $updateUser = $conn->prepare('UPDATE users SET username = ?, mobile_no = ?, status = ? WHERE id = ?');
        if (!$updateUser) {
            throw new RuntimeException('Unable to prepare linked account update.');
        }
        $updateUser->bind_param('sssi', $username, $mobileNo, $status, $userId);
    }
    if (!$updateUser->execute()) {
        throw new RuntimeException('Unable to update linked login account.');
    }
    $updateUser->close();

    $updatePerson = $conn->prepare("UPDATE {$table} SET mobile_no = ? WHERE id = ? AND user_id = ?");
    if (!$updatePerson) {
        throw new RuntimeException('Unable to prepare installation person update.');
    }
    $updatePerson->bind_param('sii', $mobileNo, $id, $userId);
    if (!$updatePerson->execute()) {
        throw new RuntimeException('Unable to update installation person contact number.');
    }
    $updatePerson->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Installation person account updated successfully.', [
        'person' => [
            'id' => $id,
            'personnel_type' => $type,
            'username' => $username,
            'mobile_no' => $mobileNo,
            'status' => $status
        ]
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Installation person account update failed: ' . $error->getMessage());
    sendResponse(false, 'Unable to update installation person account.', [], [], 500);
}
?>
