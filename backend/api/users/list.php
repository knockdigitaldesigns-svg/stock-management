<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$campaignUsersOnly = ($_GET['scope'] ?? '') === 'campaign_users';
$switchAccountsOnly = ($_GET['scope'] ?? '') === 'switch_accounts';
$leadClosureUsersOnly = ($_GET['scope'] ?? '') === 'lead_closure_by_users';
if ($switchAccountsOnly) {
    $currentUser = authenticate();
    $actingUserId = (int) ($currentUser['auth_user_id'] ?? $currentUser['user_id'] ?? 0);
    if (!empty($currentUser['impersonating'])
        || (int) ($currentUser['user_id'] ?? 0) !== $actingUserId
        || !isSuperAdminUser($actingUserId)) {
        sendResponse(false, 'Only a Super Admin can load switchable accounts.', [], [], 403);
    }
} elseif ($campaignUsersOnly) {
    requireAnyPermission([
        'crm_campaigns.view',
        'crm_campaigns.add',
        'crm_campaigns.edit'
    ]);
} elseif ($leadClosureUsersOnly) {
    requireAnyPermission([
        'customers.view',
        'customers.add',
        'customers.edit',
        'courier.view',
        'courier.add',
        'courier.edit'
    ]);
} else {
    requirePermission('users.view');
}


$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

if ($switchAccountsOnly) {
    try {
        $actor = accountImpersonationLoadUser($conn, $actingUserId);
    } catch (Throwable $error) {
        error_log('Unable to verify switch-account list access: ' . $error->getMessage());
        $conn->close();
        sendResponse(false, 'Unable to verify Super Admin account.', [], [], 500);
    }
    if (!$actor
        || strtolower(trim((string) ($actor['status'] ?? 'active'))) !== 'active'
        || strtolower(trim((string) ($actor['role_status'] ?? 'active'))) !== 'active'
        || !accountImpersonationIsSuperAdminRole((string) ($actor['role_name'] ?? ''))) {
        $conn->close();
        sendResponse(false, 'An active Super Admin account is required.', [], [], 403);
    }
}

$normalAccountRoleCondition = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', '')) NOT IN ('dealer', 'technician')
    AND NOT EXISTS (SELECT 1 FROM dealers d WHERE d.user_id = u.id)
    AND NOT EXISTS (SELECT 1 FROM technicians t WHERE t.user_id = u.id)";

if ($leadClosureUsersOnly) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.employee_name, u.username,
                COALESCE(NULLIF(r.role_name, ''), u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE {$normalAccountRoleCondition}
           AND LOWER(COALESCE(u.status, 'active')) = 'active'
           AND LOWER(COALESCE(r.status, 'active')) = 'active'
         ORDER BY COALESCE(NULLIF(u.employee_name, ''), u.username) ASC, u.id ASC"
    );
    if (!$stmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare Lead Closure By user query.', [], [], 500);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Failed to fetch Lead Closure By users.', [], [], 500);
    }
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    sendResponse(true, 'Lead Closure By users fetched successfully.', ['users' => $users]);
}

if ($switchAccountsOnly) {
    $eligibleAccountCondition = $normalAccountRoleCondition . "
        AND LOWER(COALESCE(u.status, 'active')) = 'active'
        AND LOWER(COALESCE(r.status, 'active')) = 'active'
        AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', '')) <> 'superadmin'";
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 100)));
    $offset = ($page - 1) * $pageSize;
    $countResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE {$eligibleAccountCondition}"
    );
    if (!$countResult) {
        $conn->close();
        sendResponse(false, 'Failed to count switchable accounts.', [], [], 500);
    }
    $total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT u.id, u.employee_name, u.mobile_no, u.username, u.role, u.role_id,
                u.status, r.role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE {$eligibleAccountCondition}
         ORDER BY COALESCE(NULLIF(u.employee_name, ''), u.username) ASC, u.id ASC
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare switchable account query.', [], [], 500);
    }
    $stmt->bind_param('ii', $pageSize, $offset);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Failed to fetch switchable accounts.', [], [], 500);
    }
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    $conn->close();
    sendResponse(true, 'Switchable accounts fetched successfully.', [
        'users' => $users,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]);
}

// Campaign users - separate response
if ($campaignUsersOnly) {
    $stmt = $conn->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.employee_name, ''), u.username) AS manager_name,
                COALESCE(NULLIF(r.role_name, ''), u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE LOWER(COALESCE(r.status, 'active')) = 'active'
           AND LOWER(COALESCE(u.status, 'active')) = 'active'
           AND {$normalAccountRoleCondition}
         ORDER BY manager_name ASC, role_name ASC, u.id ASC"
    );

    if (!$stmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to load campaign users: ' . $error, [], [], 500);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to load campaign users: ' . $error, [], [], 500);
    }

    $result = $stmt->get_result();
    $campaignUsers = [];

    while ($row = $result->fetch_assoc()) {
        $campaignUsers[] = $row;
    }

    $stmt->close();
    $conn->close();

    sendResponse(true, 'Campaign users fetched successfully', [
        'users' => $campaignUsers
    ]);
}

// Normal users pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));

$countResult = $conn->query(
    "SELECT COUNT(*) AS total
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE {$normalAccountRoleCondition}"
);

if (!$countResult) {
    $conn->close();
    sendResponse(false, 'Failed to count users.', [], [], 500);
}

$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;

$stmt = $conn->prepare(
    "SELECT u.id, u.employee_name, u.mobile_no, u.username,
            u.role, u.role_id, u.status, u.created_at, r.role_name
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE {$normalAccountRoleCondition}
     ORDER BY u.created_at DESC, u.id DESC
     LIMIT ? OFFSET ?"
);

if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare user query.', [], [], 500);
}

$stmt->bind_param('ii', $pageSize, $offset);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch users.', [], [], 500);
}
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Users fetched successfully', [
    'users' => $users,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
