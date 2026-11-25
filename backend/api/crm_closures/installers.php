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

crmClosureRequireInstallationAssignmentSchema($conn);

$linksResult = $conn->query(
    'SELECT user_id FROM technicians WHERE user_id IS NOT NULL
     UNION ALL
     SELECT user_id FROM dealers WHERE user_id IS NOT NULL'
);
if (!$linksResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify installation personnel account links: ' . $error, [], [], 500);
}

$linkedAccountCounts = [];
while ($link = $linksResult->fetch_assoc()) {
    $userId = (int) $link['user_id'];
    $linkedAccountCounts[$userId] = ($linkedAccountCounts[$userId] ?? 0) + 1;
}
$linksResult->free();

$installersResult = $conn->query(
    "SELECT
        'Technician' AS person_type,
        t.id AS person_id,
        t.technician_name AS person_name,
        t.mobile_no,
        t.alternate_mobile_no,
        t.location,
        t.user_id,
        u.employee_name AS account_employee_name,
        u.username AS account_username,
        u.status AS account_status
     FROM technicians t
     INNER JOIN users u ON u.id = t.user_id
     WHERE LOWER(COALESCE(t.status, 'active')) = 'active'
       AND LOWER(COALESCE(u.status, 'active')) = 'active'

     UNION ALL

     SELECT
        CONCAT(d.installation_status, ' Dealer') AS person_type,
        d.id AS person_id,
        d.dealer_name AS person_name,
        d.mobile_no,
        d.alternate_mobile_no,
        d.location,
        d.user_id,
        u.employee_name AS account_employee_name,
        u.username AS account_username,
        u.status AS account_status
     FROM dealers d
     INNER JOIN users u ON u.id = d.user_id
     WHERE d.installation_status IN ('Onsite', 'Offsite')
       AND LOWER(COALESCE(d.status, 'active')) = 'active'
       AND LOWER(COALESCE(u.status, 'active')) = 'active'

     ORDER BY person_name ASC, person_type ASC, person_id ASC"
);
if (!$installersResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load installation personnel: ' . $error, [], [], 500);
}

$installers = [];
while ($row = $installersResult->fetch_assoc()) {
    $userId = (int) $row['user_id'];
    if (($linkedAccountCounts[$userId] ?? 0) !== 1) {
        continue;
    }
    if (!userHasPermission($userId, 'crm_installations.view')) {
        continue;
    }

    $installers[] = [
        'person_type' => $row['person_type'],
        'person_id' => (int) $row['person_id'],
        'person_name' => $row['person_name'],
        'mobile_no' => $row['mobile_no'],
        'alternate_mobile_no' => $row['alternate_mobile_no'],
        'location' => $row['location'],
        'user_id' => $userId,
        'account_name' => $row['account_employee_name'] ?: $row['account_username'],
        'account_status' => 'active',
        'account_eligible' => true
    ];
}
$installersResult->free();
$conn->close();

sendResponse(
    true,
    'Installation personnel loaded successfully.',
    ['installers' => $installers]
);
?>
