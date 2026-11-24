<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_closures.add');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$leadId = filter_var($data->lead_id ?? null, FILTER_VALIDATE_INT);
$platformId = filter_var($data->platform_id ?? null, FILTER_VALIDATE_INT);
$customerNumber = trim((string) ($data->customer_number ?? ''));
$username = trim((string) ($data->username ?? ''));
$vehicleType = trim((string) ($data->vehicle_type ?? ''));
$vehicleNumber = strtoupper(trim((string) ($data->vehicle_number ?? '')));
$commitmentDate = trim((string) ($data->commitment_date ?? ''));
$location = trim((string) ($data->location ?? ''));
$pincode = trim((string) ($data->pincode ?? ''));
$deviceType = trim((string) ($data->device_type ?? ''));
$deviceCount = filter_var($data->device_count ?? null, FILTER_VALIDATE_INT);
$amountValue = trim((string) ($data->amount ?? ''));
$amountStatus = trim((string) ($data->amount_status ?? ''));
$appNameProvided = property_exists($data, 'app_name');
$appName = $appNameProvided ? trim((string) $data->app_name) : null;

if ($leadId === false || $leadId <= 0 || $platformId === false || $platformId <= 0) {
    sendResponse(false, 'A valid lead and platform are required.', [], [], 400);
}
if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{1,100}$/', $username)) {
    sendResponse(false, 'Enter a valid username using letters, numbers, dot, underscore, or hyphen.', [], [], 400);
}
if (mb_strlen($customerNumber) > 50) {
    sendResponse(false, 'Customer Number cannot exceed 50 characters.', [], [], 400);
}
if ($vehicleType === '' || mb_strlen($vehicleType) > 100 ||
    $vehicleNumber === '' || mb_strlen($vehicleNumber) > 30) {
    sendResponse(false, 'A valid vehicle type and vehicle number are required.', [], [], 400);
}
if ($commitmentDate === '' ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $commitmentDate) ||
    !checkdate((int) substr($commitmentDate, 5, 2), (int) substr($commitmentDate, 8, 2), (int) substr($commitmentDate, 0, 4))) {
    sendResponse(false, 'A valid commitment date is required.', [], [], 400);
}
if ($location === '' || mb_strlen($location) > 150 || !preg_match('/^\d{6}$/', $pincode)) {
    sendResponse(false, 'Location and a valid 6-digit pincode are required.', [], [], 400);
}
if ($deviceType === '' || mb_strlen($deviceType) > 100 || $deviceCount === false || $deviceCount <= 0) {
    sendResponse(false, 'A device type and positive device count are required.', [], [], 400);
}
if ($amountValue === '' || !is_numeric($amountValue) || (float) $amountValue <= 0 || (float) $amountValue > 9999999999.99) {
    sendResponse(false, 'Enter a valid positive amount.', [], [], 400);
}
$amount = number_format((float) $amountValue, 2, '.', '');
if ($amountStatus === '' || mb_strlen($amountStatus) > 50 ||
    ($appNameProvided && mb_strlen($appName) > 100)) {
    sendResponse(false, 'A valid amount status is required.', [], [], 400);
}

$canViewAll = crmClosureCanViewAllLeads($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin closure submission: ' . $error, [], [], 500);
}

try {
    $lead = crmClosureLeadForUpdate($conn, $leadId, $userId, $canViewAll);
    if (!$lead) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Lead not found.', [], [], 404);
    }
    if ($lead['status_type'] !== 'Resolved' || $lead['status_state'] !== 'Active') {
        throw new DomainException('Only leads with an active Resolved status can be converted.');
    }

    $platformStmt = $conn->prepare("SELECT id FROM platforms WHERE id = ? AND status = 'Active' LIMIT 1");
    if (!$platformStmt) {
        throw new RuntimeException('Unable to prepare platform validation: ' . $conn->error);
    }
    $platformStmt->bind_param('i', $platformId);
    if (!$platformStmt->execute()) {
        $error = $platformStmt->error;
        $platformStmt->close();
        throw new RuntimeException('Unable to validate platform: ' . $error);
    }
    if ($platformStmt->get_result()->num_rows === 0) {
        $platformStmt->close();
        throw new DomainException('Select an active platform.');
    }
    $platformStmt->close();

    if (!crmClosureIsActiveMasterValue($conn, 'vehicle_types', 'vehicle_type', $vehicleType)) {
        throw new DomainException('Select an available vehicle type.');
    }
    if (!crmClosureIsActiveMasterValue($conn, 'device_types', 'device_type', $deviceType)) {
        throw new DomainException('Select an available device type.');
    }

    $existingStmt = $conn->prepare('SELECT * FROM crm_lead_closure_forms WHERE lead_id = ? LIMIT 1 FOR UPDATE');
    if (!$existingStmt) {
        throw new RuntimeException('Unable to prepare duplicate submission check: ' . $conn->error);
    }
    $existingStmt->bind_param('i', $leadId);
    if (!$existingStmt->execute()) {
        $error = $existingStmt->error;
        $existingStmt->close();
        throw new RuntimeException('Unable to check existing closure submission: ' . $error);
    }
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if ($existing && in_array($existing['submission_status'], ['Submitted', 'Pending Approval', 'Approved'], true)) {
        throw new DomainException('A closure submission is already pending approval or approved.');
    }
    if ($existing && !empty($existing['customer_id'])) {
        throw new DomainException('This lead is already linked to a customer.');
    }
    if (!$appNameProvided && $existing) {
        $appName = $existing['app_name'];
    }

    $closure = [
        'platform_id' => $platformId,
        'customer_number' => $customerNumber,
        'username' => $username,
        'vehicle_type' => $vehicleType,
        'vehicle_number' => $vehicleNumber,
        'commitment_date' => $commitmentDate,
        'location' => $location,
        'pincode' => $pincode,
        'device_type' => $deviceType,
        'device_count' => $deviceCount,
        'amount' => $amount,
        'amount_status' => $amountStatus,
        'app_name' => $appName,
        'submission_status' => 'Pending Approval',
        'customer_id' => null,
        'rejection_reason' => null
    ];
    if ($existing) {
        $oldSnapshot = crmClosureSnapshot($existing);
        $stmt = $conn->prepare(
            "UPDATE crm_lead_closure_forms
             SET platform_id = ?, customer_number = ?, username = ?, vehicle_type = ?,
                 vehicle_number = ?, commitment_date = ?, location = ?, pincode = ?,
                 device_type = ?, device_count = ?, amount = ?, amount_status = ?,
                 app_name = ?, submission_status = 'Pending Approval',
                 submitted_by_user_id = ?, submitted_at = CURRENT_TIMESTAMP,
                 approved_by_user_id = NULL, approved_at = NULL, reviewed_at = NULL,
                 reviewed_by_user_id = NULL, rejection_reason = NULL
             WHERE id = ? AND submission_status = 'Rejected' AND customer_id IS NULL"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare closure resubmission: ' . $conn->error);
        }
        $closureId = (int) $existing['id'];
        $stmt->bind_param(
            'i' . str_repeat('s', 8) . 'idssii',
            $platformId,
            $customerNumber,
            $username,
            $vehicleType,
            $vehicleNumber,
            $commitmentDate,
            $location,
            $pincode,
            $deviceType,
            $deviceCount,
            $amount,
            $amountStatus,
            $appName,
            $userId,
            $closureId
        );
    } else {
        $oldSnapshot = null;
        $stmt = $conn->prepare(
            "INSERT INTO crm_lead_closure_forms
                (lead_id, platform_id, customer_number, username, vehicle_type, vehicle_number,
                 commitment_date, location, pincode, device_type, device_count,
                 amount, amount_status, app_name, submission_status,
                 submitted_by_user_id, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval', ?, CURRENT_TIMESTAMP)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare closure submission: ' . $conn->error);
        }
        $stmt->bind_param(
            'ii' . str_repeat('s', 8) . 'idssi',
            $leadId,
            $platformId,
            $customerNumber,
            $username,
            $vehicleType,
            $vehicleNumber,
            $commitmentDate,
            $location,
            $pincode,
            $deviceType,
            $deviceCount,
            $amount,
            $amountStatus,
            $appName,
            $userId
        );
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save closure submission: ' . $error);
    }
    if ($existing && $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new DomainException('The rejected closure could not be resubmitted. Refresh the page and try again.');
    }
    if (!$existing) {
        $closureId = $conn->insert_id;
    }
    $stmt->close();

    crmClosureWriteHistory(
        $conn,
        $leadId,
        $existing ? 'Closure Resubmitted' : 'Closure Submitted',
        $oldSnapshot,
        ['closure_id' => $closureId] + $closure,
        $currentUser
    );
    crmCreateNotification(
        $conn,
        $userId,
        'lead_closure_submitted',
        'Lead Closure Submitted',
        'Your closure for ' . $lead['lead_name'] . ' was submitted and is awaiting review.',
        'Normal',
        'lead_closure',
        $closureId,
        'lead_closure_submitted:' . $closureId,
        $leadId,
        null,
        $closureId
    );
    crmClosureNotifyApprovers($conn, $closureId, (string) $lead['lead_name']);
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit closure submission: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'Closure submitted for approval.', ['id' => $closureId]);
} catch (DomainException $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 409);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
