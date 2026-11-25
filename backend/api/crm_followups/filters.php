<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_followups.view');
$userId = (int) ($currentUser['user_id'] ?? 0);
if ($userId <= 0) {
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$filters = [];
$queries = [
    'pipelines' => "SELECT id, pipeline_name AS name, status AS state
                    FROM crm_pipelines
                    ORDER BY display_order ASC, pipeline_name ASC, id ASC",
    'campaigns' => "SELECT id, pipeline_id, campaign_name AS name, status AS state
                    FROM crm_campaigns
                    ORDER BY campaign_name ASC, id ASC",
    'statuses' => "SELECT id, status_name AS name, status_type, status AS state
                   FROM crm_lead_status_master
                   ORDER BY display_order ASC, status_name ASC, id ASC"
];

foreach ($queries as $key => $query) {
    $result = $conn->query($query);
    if (!$result) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, "Unable to load {$key} filter options: " . $error, [], [], 500);
    }
    $filters[$key] = [];
    while ($row = $result->fetch_assoc()) {
        $filters[$key][] = $row;
    }
}

$filters['employees'] = [];
$canViewAll = crmFollowupsCanViewAllLeads($userId);
if ($canViewAll) {
    $employeeStmt = $conn->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.employee_name, ''), u.username) AS employee_name,
                COALESCE(NULLIF(r.role_name, ''), u.role, 'Unassigned') AS role_name,
                COALESCE(u.status, 'active') AS state
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         ORDER BY employee_name ASC, role_name ASC, u.id ASC"
    );
    if (!$employeeStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to prepare assigned employee filter options: ' . $error, [], [], 500);
    }
    if (!$employeeStmt->execute()) {
        $error = $employeeStmt->error;
        $employeeStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to load assigned employee filter options: ' . $error, [], [], 500);
    }
    $result = $employeeStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $filters['employees'][] = $row;
    }
    $employeeStmt->close();
}

$conn->close();
sendResponse(true, 'Follow-up filter options loaded successfully.', [
    'filters' => $filters,
    'can_filter_assigned_employee' => $canViewAll
]);
?>
