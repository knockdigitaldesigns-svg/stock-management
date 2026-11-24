<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
if ($userId <= 0) {
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}
requirePermission('crm_installations.view');

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireInstallationAssignmentSchema($conn);
crmClosureRequireMultiDeviceSchema($conn);

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     WHERE cf.submission_status IN ('Approved', 'Installation In Progress', 'Installation Completed', 'Customer Handoff Completed')
       AND (cf.customer_id IS NULL OR cf.submission_status IN ('Installation In Progress', 'Customer Handoff Completed'))
       AND cf.installation_person_user_id = ?"
);
if (!$countStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare assigned installation count: ' . $error, [], [], 500);
}
$countStmt->bind_param('i', $userId);
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count assigned installations: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;

$stmt = $conn->prepare(
    "SELECT cf.id AS closure_id, cf.lead_id, cf.customer_id,
            cf.customer_number, cf.username, cf.vehicle_type, cf.vehicle_number,
            cf.commitment_date, cf.location, cf.pincode, cf.device_type,
            cf.device_count, cf.amount, cf.amount_status,
            cf.installation_person_type, cf.installation_person_id,
            cf.installation_assigned_at, cf.submission_status,
            cf.installation_datetime, cf.installation_remarks,
            cf.completion_evidence, cf.customer_vehicle_id,
            cf.customer_installation_id, cf.device_id,
            l.lead_name, l.mobile_no AS lead_mobile,
            COALESCE(c.username, cf.username) AS customer_name,
            COALESCE(c.primary_mobile_no, l.mobile_no) AS primary_mobile_no,
            c.secondary_mobile_no, c.location AS customer_location,
            c.pincode AS customer_pincode
     FROM crm_lead_closure_forms cf
     INNER JOIN crm_leads l ON l.id = cf.lead_id
     LEFT JOIN customers c ON c.id = cf.customer_id
     WHERE cf.submission_status IN ('Approved', 'Installation In Progress', 'Installation Completed', 'Customer Handoff Completed')
       AND (cf.customer_id IS NULL OR cf.submission_status IN ('Installation In Progress', 'Customer Handoff Completed'))
       AND cf.installation_person_user_id = ?
     ORDER BY cf.installation_assigned_at DESC, cf.id DESC
     LIMIT ? OFFSET ?"
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare assigned installation list: ' . $error, [], [], 500);
}
$stmt->bind_param('iii', $userId, $pageSize, $offset);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load assigned installations: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$jobs = [];
while ($row = $result->fetch_assoc()) {
    $deviceStmt = $conn->prepare(
        'SELECT item.id AS installation_device_id, item.device_sequence,
                item.customer_id, customer.username AS customer_username,
                customer.primary_mobile_no AS customer_primary_mobile_no,
                item.installation_status, item.vehicle_number, item.sale_amount,
                item.imei_no, item.sim_no_1, item.sim_no_2, item.validity_months,
                item.installation_datetime, item.installation_remarks,
                item.completion_evidence, item.installation_verified,
                item.verification_submission_id, item.verification_status,
                item.verification_rejection_reason,
                item.customer_vehicle_id, item.customer_installation_id, item.device_id,
                payment.total_amount, payment.amount_paid, payment.amount_pending,
                payment.payment_status, payment.payment_mode, payment.transaction_id,
                vehicle_type.vehicle_type, device_type.device_type
         FROM crm_closure_installation_devices item
         LEFT JOIN customers customer ON customer.id = item.customer_id
         LEFT JOIN customer_payments payment ON payment.vehicle_id = item.customer_vehicle_id
         LEFT JOIN vehicle_types vehicle_type ON vehicle_type.id = item.vehicle_type_id
         LEFT JOIN device_types device_type ON device_type.id = item.device_model_id
         WHERE item.closure_id = ?
         ORDER BY item.device_sequence ASC'
    );
    if (!$deviceStmt) {
        $error = $conn->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to prepare per-device installation list: ' . $error, [], [], 500);
    }
    $closureId = (int) $row['closure_id'];
    $deviceStmt->bind_param('i', $closureId);
    if (!$deviceStmt->execute()) {
        $error = $deviceStmt->error;
        $deviceStmt->close();
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to load per-device installation progress: ' . $error, [], [], 500);
    }
    $deviceResult = $deviceStmt->get_result();
    $devices = [];
    while ($device = $deviceResult->fetch_assoc()) {
        $device['vehicle_type'] = $device['vehicle_type'] ?: $row['vehicle_type'];
        $device['device_type'] = $device['device_type'] ?: $row['device_type'];
        $devices[] = $device;
    }
    $deviceStmt->close();

    if (count($devices) === 0) {
        $deviceCount = max(1, (int) $row['device_count']);
        for ($sequence = 1; $sequence <= $deviceCount; $sequence++) {
            $legacyDeviceCompleted = $sequence === 1 &&
                !empty($row['customer_vehicle_id']) &&
                !empty($row['customer_installation_id']) &&
                !empty($row['device_id']);
            $devices[] = [
                'installation_device_id' => null,
                'device_sequence' => $sequence,
                'installation_status' => $legacyDeviceCompleted ? 'Completed' : 'Pending',
                'vehicle_number' => $sequence === 1 ? $row['vehicle_number'] : null,
                'vehicle_type' => $row['vehicle_type'],
                'device_type' => $row['device_type'],
                'sale_amount' => $legacyDeviceCompleted ? $row['amount'] : null,
                'imei_no' => null,
                'sim_no_1' => null,
                'sim_no_2' => null,
                'validity_months' => null,
                'installation_datetime' => $legacyDeviceCompleted ? $row['installation_datetime'] : null,
                'installation_remarks' => $legacyDeviceCompleted ? $row['installation_remarks'] : null,
                'completion_evidence' => $legacyDeviceCompleted ? $row['completion_evidence'] : null,
                'installation_verified' => $legacyDeviceCompleted ? 1 : 0,
                'verification_submission_id' => null,
                'verification_status' => $legacyDeviceCompleted ? 'Approved' : 'Not Submitted',
                'verification_rejection_reason' => null,
                'customer_vehicle_id' => $legacyDeviceCompleted ? $row['customer_vehicle_id'] : null,
                'customer_installation_id' => $legacyDeviceCompleted ? $row['customer_installation_id'] : null,
                'device_id' => $legacyDeviceCompleted ? $row['device_id'] : null,
                'customer_id' => $legacyDeviceCompleted ? $row['customer_id'] : null,
                'customer_username' => $legacyDeviceCompleted ? $row['customer_name'] : null,
                'customer_primary_mobile_no' => $legacyDeviceCompleted ? $row['primary_mobile_no'] : null,
                'total_amount' => null,
                'amount_paid' => null,
                'amount_pending' => null,
                'payment_status' => null,
                'payment_mode' => null,
                'transaction_id' => null
            ];
        }
    }
    $installedCount = count(array_filter(
        $devices,
        static fn (array $device): bool => $device['installation_status'] === 'Completed'
    ));
    $pendingVerificationCount = count(array_filter(
        $devices,
        static fn (array $device): bool => $device['verification_status'] === 'Pending Verification'
    ));
    $rejectedVerificationCount = count(array_filter(
        $devices,
        static fn (array $device): bool => $device['verification_status'] === 'Rejected'
    ));
    $row['devices'] = $devices;
    $row['total_devices'] = count($devices);
    $row['installed_devices'] = $installedCount;
    $row['pending_devices'] = count($devices) - $installedCount;
    $row['pending_verification_devices'] = $pendingVerificationCount;
    $row['rejected_verification_devices'] = $rejectedVerificationCount;
    $jobs[] = $row;
}
$stmt->close();

$vehicleTypes = [];
$vehicleStatusResult = $conn->query("SHOW COLUMNS FROM vehicle_types LIKE 'status'");
if (!$vehicleStatusResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify vehicle type options: ' . $error, [], [], 500);
}
$vehicleCondition = $vehicleStatusResult->num_rows > 0
    ? " WHERE status = 'Active' OR status IS NULL OR status = ''"
    : '';
$vehicleResult = $conn->query(
    'SELECT id, vehicle_type FROM vehicle_types' . $vehicleCondition . ' ORDER BY vehicle_type ASC'
);
if (!$vehicleResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load vehicle type options: ' . $error, [], [], 500);
}
while ($row = $vehicleResult->fetch_assoc()) {
    $vehicleTypes[] = $row;
}

$deviceTypes = [];
$deviceStatusResult = $conn->query("SHOW COLUMNS FROM device_types LIKE 'status'");
if (!$deviceStatusResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify device type options: ' . $error, [], [], 500);
}
$deviceCondition = $deviceStatusResult->num_rows > 0
    ? " WHERE status = 'Active' OR status IS NULL OR status = ''"
    : '';
$deviceTypeResult = $conn->query(
    'SELECT id, device_type FROM device_types' . $deviceCondition . ' ORDER BY device_type ASC'
);
if (!$deviceTypeResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load device type options: ' . $error, [], [], 500);
}
while ($row = $deviceTypeResult->fetch_assoc()) {
    $deviceTypes[] = $row;
}
$conn->close();

sendResponse(true, 'Assigned installation jobs loaded successfully.', [
    'jobs' => $jobs,
    'vehicle_types' => $vehicleTypes,
    'device_types' => $deviceTypes,
    'payment_modes' => getPaymentModes(),
    'total' => $total,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
