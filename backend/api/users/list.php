<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$campaignUsersOnly = ($_GET['scope'] ?? '') === 'campaign_users';
if ($campaignUsersOnly) {
    requireAnyPermission([
        'crm_campaigns.view',
        'crm_campaigns.add',
        'crm_campaigns.edit'
    ]);
} else {
    requirePermission('users.view');
}


$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$normalAccountRoleCondition = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', '')) NOT IN ('dealer', 'technician')
    AND NOT EXISTS (SELECT 1 FROM dealers d WHERE d.user_id = u.id)
    AND NOT EXISTS (SELECT 1 FROM technicians t WHERE t.user_id = u.id)";

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
