<?php

require_once __DIR__ . '/user_identity.php';
require_once __DIR__ . '/user_credential_crypto.php';
require_once __DIR__ . '/audit.php';

function createUserAccountInTransaction(
    mysqli $conn,
    string $employeeName,
    string $mobileNo,
    string $password,
    int $roleId,
    string $roleName,
    string $status,
    array $actor
): array {
    $username = normalizeEmployeeUsername($employeeName);
    if ($username === '' || strlen($username) > 50) {
        throw new DomainException('Employee name must generate a username of at most 50 characters.');
    }

    $mobileCheck = $conn->prepare('SELECT id FROM users WHERE mobile_no = ? LIMIT 1');
    if (!$mobileCheck) {
        throw new RuntimeException('Unable to prepare duplicate mobile validation: ' . $conn->error);
    }
    $mobileCheck->bind_param('s', $mobileNo);
    if (!$mobileCheck->execute()) {
        $error = $mobileCheck->error;
        $mobileCheck->close();
        throw new RuntimeException('Unable to validate user mobile number: ' . $error);
    }
    $mobileExists = $mobileCheck->get_result()->num_rows > 0;
    $mobileCheck->close();
    if ($mobileExists) {
        throw new DomainException('Mobile number already exists.');
    }

    $usernameCheck = $conn->prepare(
        "SELECT id FROM users
         WHERE LOWER(REPLACE(REPLACE(TRIM(COALESCE(employee_name, '')), ' ', ''), CHAR(9), '')) = ?
            OR LOWER(username) = ?
         LIMIT 1"
    );
    if (!$usernameCheck) {
        throw new RuntimeException('Unable to prepare duplicate username validation: ' . $conn->error);
    }
    $usernameCheck->bind_param('ss', $username, $username);
    if (!$usernameCheck->execute()) {
        $error = $usernameCheck->error;
        $usernameCheck->close();
        throw new RuntimeException('Unable to validate generated username: ' . $error);
    }
    $usernameExists = $usernameCheck->get_result()->num_rows > 0;
    $usernameCheck->close();
    if ($usernameExists) {
        throw new DomainException('Username already exists. Please use a different employee name.');
    }

    if (strlen($password) < 8) {
        throw new DomainException('Password must be at least 8 characters long.');
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        throw new RuntimeException('Unable to securely hash the user password.');
    }
    $insert = $conn->prepare(
        'INSERT INTO users (employee_name, mobile_no, username, password, role, role_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare user account creation: ' . $conn->error);
    }
    $insert->bind_param(
        'sssssis',
        $employeeName,
        $mobileNo,
        $username,
        $hashedPassword,
        $roleName,
        $roleId,
        $status
    );
    if (!$insert->execute()) {
        $duplicate = $insert->errno === 1062 || $conn->errno === 1062;
        $error = $insert->error;
        $insert->close();
        if ($duplicate) {
            throw new DomainException('Username already exists. Please use a different employee name.');
        }
        throw new RuntimeException('Unable to create user account: ' . $error);
    }
    $insert->close();
    $userId = (int) $conn->insert_id;

    $credential = encryptUserCredential($password, $userId);
    $credentialStmt = $conn->prepare(
        'UPDATE users
         SET credential_ciphertext = ?, credential_nonce = ?, credential_tag = ?
         WHERE id = ?'
    );
    if (!$credentialStmt) {
        throw new RuntimeException('Unable to prepare encrypted credential storage: ' . $conn->error);
    }
    $credentialStmt->bind_param(
        'sssi',
        $credential['ciphertext'],
        $credential['nonce'],
        $credential['tag'],
        $userId
    );
    if (!$credentialStmt->execute()) {
        $error = $credentialStmt->error;
        $credentialStmt->close();
        throw new RuntimeException('Unable to store encrypted user credential: ' . $error);
    }
    $credentialStmt->close();

    writeCreatedFields($conn, $userId, 'User', [
        'employee_name' => $employeeName,
        'mobile_no' => $mobileNo,
        'username' => $username,
        'role_id' => $roleId,
        'status' => $status
    ], $actor);

    return [
        'user_id' => $userId,
        'employee_name' => $employeeName,
        'mobile_no' => $mobileNo,
        'username' => $username,
        'role_id' => $roleId,
        'role' => $roleName,
        'status' => $status
    ];
}

function ensureInstallationPersonnelRoleInTransaction(mysqli $conn, string $roleName): array
{
    $roleDefinitions = [
        'Technician' => 'Installation login role for technician personnel.',
        'Dealer' => 'Installation login role for dealer personnel.'
    ];
    if (!isset($roleDefinitions[$roleName])) {
        throw new InvalidArgumentException('Invalid installation personnel role.');
    }

    $roleInsert = $conn->prepare(
        "INSERT INTO roles (role_name, description, status, is_system_role)
         VALUES (?, ?, 'active', 0)
         ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), status = 'active'"
    );
    if (!$roleInsert) {
        throw new RuntimeException('Unable to prepare installation role creation: ' . $conn->error);
    }
    $description = $roleDefinitions[$roleName];
    $roleInsert->bind_param('ss', $roleName, $description);
    if (!$roleInsert->execute()) {
        $error = $roleInsert->error;
        $roleInsert->close();
        throw new RuntimeException('Unable to create installation personnel role: ' . $error);
    }
    $roleInsert->close();

    $roleStmt = $conn->prepare(
        'SELECT id, role_name FROM roles
         WHERE LOWER(role_name) = LOWER(?) AND LOWER(status) = \'active\'
         LIMIT 1'
    );
    if (!$roleStmt) {
        throw new RuntimeException('Unable to prepare installation role lookup: ' . $conn->error);
    }
    $roleStmt->bind_param('s', $roleName);
    if (!$roleStmt->execute()) {
        $error = $roleStmt->error;
        $roleStmt->close();
        throw new RuntimeException('Unable to load installation personnel role: ' . $error);
    }
    $role = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
    if (!$role) {
        throw new RuntimeException('The installation personnel role is unavailable.');
    }

    $permissionStmt = $conn->prepare(
        'SELECT id FROM permissions WHERE permission_key = ? LIMIT 1'
    );
    if (!$permissionStmt) {
        throw new RuntimeException('Unable to prepare installation permission lookup: ' . $conn->error);
    }
    $viewPermission = 'crm_installations.view';
    $permissionStmt->bind_param('s', $viewPermission);
    if (!$permissionStmt->execute()) {
        $error = $permissionStmt->error;
        $permissionStmt->close();
        throw new RuntimeException('Unable to load installation view permission: ' . $error);
    }
    $permissionId = (int) ($permissionStmt->get_result()->fetch_assoc()['id'] ?? 0);
    $permissionStmt->close();
    if ($permissionId <= 0) {
        throw new RuntimeException('The crm_installations.view permission is missing.');
    }

    $roleId = (int) $role['id'];
    $restrictPermissions = $conn->prepare(
        'DELETE FROM role_permissions
         WHERE role_id = ? AND permission_id <> ?'
    );
    if (!$restrictPermissions) {
        throw new RuntimeException('Unable to prepare installation role permission check: ' . $conn->error);
    }
    $restrictPermissions->bind_param('ii', $roleId, $permissionId);
    if (!$restrictPermissions->execute()) {
        $error = $restrictPermissions->error;
        $restrictPermissions->close();
        throw new RuntimeException('Unable to restrict installation role permissions: ' . $error);
    }
    $restrictPermissions->close();

    $mappingStmt = $conn->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
    );
    if (!$mappingStmt) {
        throw new RuntimeException('Unable to prepare installation permission mapping: ' . $conn->error);
    }
    $mappingStmt->bind_param('ii', $roleId, $permissionId);
    if (!$mappingStmt->execute()) {
        $error = $mappingStmt->error;
        $mappingStmt->close();
        throw new RuntimeException('Unable to assign installation view permission: ' . $error);
    }
    $mappingStmt->close();

    return [
        'role_id' => $roleId,
        'role_name' => (string) $role['role_name']
    ];
}

function createAndLinkPersonnelLoginInTransaction(
    mysqli $conn,
    string $personnelType,
    int $personnelId,
    string $password,
    string $confirmPassword,
    array $actor
): array {
    $personnelDefinitions = [
        'technician' => [
            'table' => 'technicians',
            'name_column' => 'technician_name',
            'role' => 'Technician',
            'module' => 'Technician'
        ],
        'dealer' => [
            'table' => 'dealers',
            'name_column' => 'dealer_name',
            'role' => 'Dealer',
            'module' => 'Dealer'
        ]
    ];
    if (!isset($personnelDefinitions[$personnelType]) || $personnelId <= 0) {
        throw new DomainException('Select a valid installation personnel record.');
    }
    if (strlen($password) < 8) {
        throw new DomainException('Password must be at least 8 characters long.');
    }
    if (!hash_equals($password, $confirmPassword)) {
        throw new DomainException('Passwords do not match.');
    }

    $personnel = $personnelDefinitions[$personnelType];
    $table = $personnel['table'];
    $nameColumn = $personnel['name_column'];
    $personStmt = $conn->prepare(
        "SELECT id, {$nameColumn} AS employee_name, mobile_no, user_id, status
         FROM {$table} WHERE id = ? LIMIT 1 FOR UPDATE"
    );
    if (!$personStmt) {
        throw new RuntimeException('Unable to prepare personnel account lookup: ' . $conn->error);
    }
    $personStmt->bind_param('i', $personnelId);
    if (!$personStmt->execute()) {
        $error = $personStmt->error;
        $personStmt->close();
        throw new RuntimeException('Unable to load personnel record: ' . $error);
    }
    $person = $personStmt->get_result()->fetch_assoc();
    $personStmt->close();
    if (!$person) {
        throw new DomainException('Personnel record was not found.');
    }
    if (strtolower((string) ($person['status'] ?? 'active')) !== 'active') {
        throw new DomainException('Only active personnel can receive an installation login account.');
    }
    if (!empty($person['user_id'])) {
        throw new DomainException('This personnel record already has a linked login account.');
    }
    $mobileNo = trim((string) $person['mobile_no']);
    if (!preg_match('/^[6-9][0-9]{9}$/', $mobileNo)) {
        throw new DomainException('Personnel mobile number must be a valid 10-digit mobile number before creating a login.');
    }

    $role = ensureInstallationPersonnelRoleInTransaction($conn, $personnel['role']);
    $user = createUserAccountInTransaction(
        $conn,
        trim((string) $person['employee_name']),
        $mobileNo,
        $password,
        $role['role_id'],
        $role['role_name'],
        'active',
        $actor
    );

    $update = $conn->prepare(
        "UPDATE {$table} SET user_id = ? WHERE id = ? AND user_id IS NULL"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare personnel account link: ' . $conn->error);
    }
    $update->bind_param('ii', $user['user_id'], $personnelId);
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to link login account to personnel: ' . $error);
    }
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new DomainException('This personnel record already has a linked login account.');
    }
    $update->close();

    writeChangedFields(
        $conn,
        $personnelId,
        $personnel['module'],
        ['user_id' => null],
        ['user_id' => $user['user_id']],
        $actor
    );

    return $user;
}

?>
