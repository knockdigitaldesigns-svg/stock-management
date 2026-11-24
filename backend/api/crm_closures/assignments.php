<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_installations.assign');
crmClosureRequireAdminRole((int) ($currentUser['user_id'] ?? 0));
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
crmClosureRequireInstallationAssignmentSchema($conn);

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countResult = $conn->query(
    "SELECT COUNT(*) AS total
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     WHERE cf.submission_status = 'Approved'
       AND cf.customer_id IS NULL
       AND cf.installation_person_user_id IS NOT NULL"
);
if (!$countResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to count installation assignments: ' . $error, [], [], 500);
}
$total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(
    "SELECT cf.id AS closure_id, cf.lead_id, cf.customer_id, cf.customer_number, cf.username,
            cf.vehicle_type, cf.vehicle_number, cf.commitment_date, cf.location,
            cf.pincode, cf.device_type, cf.device_count, cf.amount, cf.amount_status,
            cf.installation_person_type, cf.installation_person_id,
            cf.installation_person_user_id, cf.installation_assigned_at,
            l.lead_name, l.mobile_no AS lead_mobile,
            cf.username AS customer_name, l.mobile_no AS primary_mobile_no,
            cf.submission_status AS installation_status
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     WHERE cf.submission_status = 'Approved' AND cf.customer_id IS NULL
       AND cf.installation_person_user_id IS NOT NULL
     ORDER BY cf.installation_assigned_at DESC, cf.id DESC
     LIMIT ? OFFSET ?"
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare installation assignments: ' . $error, [], [], 500);
}
if (!$stmt->bind_param('ii', $pageSize, $offset) || !$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load assigned installations: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$assignments = [];
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();
$conn->close();
sendResponse(true, 'Installation assignments loaded successfully.', [
    'assignments' => $assignments,
    'total' => $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
