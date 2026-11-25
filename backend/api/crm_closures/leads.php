<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['crm_closures.add', 'crm_leads.view']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$canViewAll = crmClosureCanViewAllLeads($userId);

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
$activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn)
    ? ' AND l.deleted_at IS NULL'
    : '';
$requestedLeadId = trim((string) ($_GET['lead_id'] ?? ''));
if ($requestedLeadId !== '' && (!ctype_digit($requestedLeadId) || (int) $requestedLeadId <= 0)) {
    $conn->close();
    sendResponse(false, 'A valid lead ID is required.', [], [], 400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countSql =
    "SELECT COUNT(*) AS total
     FROM crm_leads l
     INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_closure_forms cf ON cf.lead_id = l.id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE s.status_type = 'Resolved' AND s.status = 'Active'
       AND cf.customer_id IS NULL
       AND (cf.id IS NULL OR cf.submission_status = 'Rejected'){$activeLeadCondition}";
if (!$canViewAll) {
    $countSql .= ' AND l.assigned_user_id = ?';
}
if ($requestedLeadId !== '') {
    $countSql .= ' AND l.id = ?';
}
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare conversion-ready lead count: ' . $error, [], [], 500);
}
$countTypes = '';
$countParams = [];
if (!$canViewAll) {
    $countTypes .= 'i';
    $countParams[] = $userId;
}
if ($requestedLeadId !== '') {
    $countTypes .= 'i';
    $countParams[] = (int) $requestedLeadId;
}
if ($countParams) {
    $countBindParams = [$countTypes];
    foreach ($countParams as $index => $value) {
        $countBindParams[] = &$countParams[$index];
    }
    if (!call_user_func_array([$countStmt, 'bind_param'], $countBindParams)) {
        $error = $countStmt->error;
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to bind conversion-ready lead count filters: ' . $error, [], [], 500);
    }
}
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count conversion-ready leads: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;

$sql =
    "SELECT l.id, l.lead_name, l.mobile_no, l.alternate_mobile_no, l.email,
            l.location, l.pincode, l.lead_source, l.campaign_id,
            l.assigned_user_id, l.lead_status_id, l.closure_reason,
            l.created_at, s.status_name AS lead_status,
            s.color AS lead_status_color, s.status_type AS lead_status_type,
            c.campaign_name,
            cf.id AS closure_id, cf.submission_status, cf.rejection_reason,
            cf.customer_id, cf.platform_id, cf.customer_number, cf.username,
            cf.vehicle_type, cf.vehicle_number, cf.commitment_date,
            cf.location AS closure_location, cf.pincode AS closure_pincode,
            cf.device_type, cf.device_count, cf.amount, cf.amount_status, cf.app_name,
            COALESCE(NULLIF(u.employee_name, ''), u.username, 'Unassigned') AS assigned_employee
     FROM crm_leads l
     INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
     LEFT JOIN crm_campaigns c ON c.id = l.campaign_id
     LEFT JOIN crm_lead_closure_forms cf ON cf.lead_id = l.id
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE s.status_type = 'Resolved' AND s.status = 'Active'
       AND cf.customer_id IS NULL
       AND (cf.id IS NULL OR cf.submission_status = 'Rejected'){$activeLeadCondition}";
if (!$canViewAll) {
    $sql .= ' AND l.assigned_user_id = ?';
}
if ($requestedLeadId !== '') {
    $sql .= ' AND l.id = ?';
}
$sql .= ' ORDER BY l.updated_at DESC, l.id DESC LIMIT ? OFFSET ?';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare conversion-ready lead list: ' . $error, [], [], 500);
}
$dataTypes = $countTypes . 'ii';
$dataParams = $countParams;
$dataParams[] = $pageSize;
$dataParams[] = $offset;
$dataBindParams = [$dataTypes];
foreach ($dataParams as $index => $value) {
    $dataBindParams[] = &$dataParams[$index];
}
if (!call_user_func_array([$stmt, 'bind_param'], $dataBindParams)) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind conversion-ready lead pagination: ' . $error, [], [], 500);
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load conversion-ready leads: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$leads = [];
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}

$stmt->close();
$conn->close();
sendResponse(true, 'Conversion-ready leads loaded successfully.', [
    'leads' => $leads,
    'total' => $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
