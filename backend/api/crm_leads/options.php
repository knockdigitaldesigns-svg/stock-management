<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission([
    'crm_leads.view',
    'crm_leads.add',
    'crm_leads.edit',
    'crm_leads.my_view'
]);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$pipelinesResult = $conn->query(
    "SELECT id, pipeline_name
     FROM crm_pipelines
     WHERE status = 'Active'
     ORDER BY display_order ASC, pipeline_name ASC, id ASC"
);
if (!$pipelinesResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active pipelines: ' . $error, [], [], 500);
}
$pipelines = [];
while ($row = $pipelinesResult->fetch_assoc()) {
    $pipelines[] = $row;
}

$campaignsResult = $conn->query(
    "SELECT id, pipeline_id, campaign_name
     FROM crm_campaigns
     WHERE status = 'Active'
     ORDER BY campaign_name ASC, id ASC"
);
if (!$campaignsResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active campaigns: ' . $error, [], [], 500);
}
$campaigns = [];
while ($row = $campaignsResult->fetch_assoc()) {
    $campaigns[] = $row;
}

$statusesResult = $conn->query(
    "SELECT id, status_name, status_type, color
     FROM crm_lead_status_master
     WHERE status = 'Active'
     ORDER BY display_order ASC, status_name ASC, id ASC"
);
if (!$statusesResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active lead statuses: ' . $error, [], [], 500);
}
$statuses = [];
while ($row = $statusesResult->fetch_assoc()) {
    $statuses[] = $row;
}

$employees = [];
$canViewAll = crmLeadsCanViewAll((int) ($currentUser['user_id'] ?? 0), $conn);
if ($canViewAll) {
    $employeeResult = $conn->query(
        "SELECT u.id,
                COALESCE(NULLIF(u.employee_name, ''), u.username) AS employee_name,
                COALESCE(NULLIF(r.role_name, ''), u.role, 'Unassigned') AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE LOWER(COALESCE(u.status, 'active')) = 'active'
         ORDER BY employee_name ASC, role_name ASC, u.id ASC"
    );
    if (!$employeeResult) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to load active assigned employees: ' . $error, [], [], 500);
    }
    while ($row = $employeeResult->fetch_assoc()) {
        $employees[] = $row;
    }
}

$conn->close();
sendResponse(true, 'Lead form options loaded successfully.', [
    'pipelines' => $pipelines,
    'campaigns' => $campaigns,
    'statuses' => $statuses,
    'employees' => $employees,
    'can_view_all_leads' => $canViewAll
]);
?>
