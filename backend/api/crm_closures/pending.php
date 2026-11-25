<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_closures.approve');
$userId = (int) ($currentUser['user_id'] ?? 0);
crmClosureRequireAdminRole($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
crmClosureRequireInstallationAssignmentSchema($conn);

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$locationFilter = trim((string) ($_GET['location'] ?? ''));
$locationSql = $locationFilter === '' ? '' : ' AND cf.location LIKE ?';
$locationLike = '%' . $locationFilter . '%';
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN platforms p ON p.id = cf.platform_id
     LEFT JOIN users submitter ON submitter.id = cf.submitted_by_user_id
     LEFT JOIN users assignee ON assignee.id = l.assigned_user_id
     WHERE cf.submission_status = 'Pending Approval'{$locationSql}"
);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare closure approval count: ' . $error, [], [], 500);
}
if ($locationFilter !== '') {
    $countStmt->bind_param('s', $locationLike);
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count pending closure submissions: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;
$locationResult = $conn->query(
    "SELECT DISTINCT cf.location
     FROM crm_lead_closure_forms cf
     WHERE cf.submission_status = 'Pending Approval'
       AND TRIM(COALESCE(cf.location, '')) <> ''
     ORDER BY cf.location ASC"
);
if (!$locationResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load pending customer locations: ' . $error, [], [], 500);
}
$locations = [];
while ($locationRow = $locationResult->fetch_assoc()) {
    $locations[] = $locationRow['location'];
}

$sql =
    "SELECT cf.id, cf.lead_id, cf.platform_id, cf.customer_number, cf.username,
            cf.vehicle_type, cf.vehicle_number, cf.commitment_date,
            cf.location, cf.pincode, cf.device_type, cf.device_count,
            cf.amount, cf.amount_status,
            cf.installation_person_type, cf.installation_person_id,
            cf.installation_person_user_id, cf.installation_assigned_at,
            cf.submission_status, cf.submitted_by_user_id, cf.submitted_at,
            l.lead_name, l.mobile_no, l.email, l.location AS lead_location,
            l.pincode AS lead_pincode, l.campaign_id, l.assigned_user_id,
            s.status_name AS lead_status,
            c.campaign_name, p.platform_name,
            COALESCE(NULLIF(submitter.employee_name, ''), submitter.username, 'Unknown user') AS submitted_by,
            COALESCE(NULLIF(assignee.employee_name, ''), assignee.username, 'Unassigned') AS assigned_employee
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN platforms p ON p.id = cf.platform_id
     LEFT JOIN users submitter ON submitter.id = cf.submitted_by_user_id
     LEFT JOIN users assignee ON assignee.id = l.assigned_user_id
     WHERE cf.submission_status = 'Pending Approval'{$locationSql}
     ORDER BY cf.submitted_at ASC, cf.id ASC
     LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load approval queue: ' . $error, [], [], 500);
}
$bindOk = $locationFilter !== ''
    ? $stmt->bind_param('sii', $locationLike, $pageSize, $offset)
    : $stmt->bind_param('ii', $pageSize, $offset);
if (!$bindOk) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind closure approval pagination: ' . $error, [], [], 500);
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load approval queue: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$submissions = [];
while ($row = $result->fetch_assoc()) {
    $submissions[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'Pending closure submissions loaded successfully.', [
    'submissions' => $submissions,
    'locations' => $locations,
    'total' => $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
