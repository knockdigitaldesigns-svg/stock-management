<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/device_workflow.php';
require_once __DIR__ . '/../../utils/audit.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_installations.view');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$closureId = filter_var($data->closure_id ?? null, FILTER_VALIDATE_INT);
$action = trim((string) ($data->action ?? ''));
if ($closureId === false || $closureId <= 0 || !in_array($action, ['start', 'complete'], true)) {
    sendResponse(false, 'A valid assigned installation and action are required.', [], [], 400);
}

$installationDateTime = trim((string) ($data->installation_datetime ?? ''));
$vehicleNumber = strtoupper(trim((string) ($data->vehicle_number ?? '')));
$imei = trim((string) ($data->imei_no ?? ''));
$simNumber1 = trim((string) ($data->sim_no_1 ?? ''));
$simNumber2 = trim((string) ($data->sim_no_2 ?? ''));
$validityMonths = filter_var($data->validity_months ?? null, FILTER_VALIDATE_INT);
$remarks = trim((string) ($data->installation_remarks ?? ''));
$evidence = trim((string) ($data->completion_evidence ?? ''));
$verified = filter_var($data->completion_verified ?? false, FILTER_VALIDATE_BOOLEAN);

if ($action === 'complete') {
    $dateTime = crmClosureParseInstallationDateTime($installationDateTime);
    if (!$dateTime) {
        sendResponse(false, 'Enter a valid installation date and time.', [], [], 400);
    }
    if ($dateTime->getTimestamp() > time()) {
        sendResponse(false, 'Installation date and time cannot be in the future.', [], [], 400);
    }
    if ($vehicleNumber === '' || mb_strlen($vehicleNumber) > 30) {
        sendResponse(false, 'Enter the installed vehicle number.', [], [], 400);
    }
    if (!preg_match('/^\d{15}$/', $imei)) {
        sendResponse(false, 'Enter the installed 15-digit device IMEI.', [], [], 400);
    }
    if ($simNumber2 !== '' &&
        (!preg_match('/^\d{10}$|^\d{13}$/', $simNumber2) || $simNumber2 === $simNumber1)) {
        sendResponse(false, 'Enter a valid secondary SIM number different from the primary SIM.', [], [], 400);
    }
    if ($validityMonths === false || $validityMonths <= 0) {
        sendResponse(false, 'Select a valid SIM/service validity in months.', [], [], 400);
    }
    if (mb_strlen($remarks) > 2000) {
        sendResponse(false, 'Installation remarks cannot exceed 2,000 characters.', [], [], 400);
    }
    if (mb_strlen($evidence) > 2000) {
        sendResponse(false, 'Verification/evidence note cannot exceed 2,000 characters.', [], [], 400);
    }
    if (!$verified) {
        sendResponse(false, 'Confirm that the installed vehicle and device details were verified.', [], [], 400);
    }
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
crmClosureRequireInstallationAssignmentSchema($conn);
crmClosureRequireMultiDeviceSchema($conn);

if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin installation update: ' . $error, [], [], 500);
}

try {
    $stmt = $conn->prepare(
        'SELECT cf.*, l.lead_name, l.mobile_no, l.alternate_mobile_no, l.email,
                l.created_by_user_id, l.assigned_user_id
         FROM crm_lead_closure_forms cf
         INNER JOIN crm_leads l ON l.id = cf.lead_id
         WHERE cf.id = ? AND cf.installation_person_user_id = ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare assigned installation lookup: ' . $conn->error);
    }
    $stmt->bind_param('ii', $closureId, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load assigned installation: ' . $error);
    }
    $closure = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closure) {
        throw new DomainException('Assigned installation was not found.');
    }
    crmClosureValidateInstaller(
        $conn,
        (string) $closure['installation_person_type'],
        (int) $closure['installation_person_id'],
        $userId
    );
    $deviceCount = (int) ($closure['device_count'] ?? 0);
    if ($deviceCount <= 0 || $deviceCount > 65535) {
        throw new DomainException('The assigned order has an invalid device count.');
    }

    if ($closure['submission_status'] === 'Customer Handoff Completed') {
        $result = [
            'closure_id' => (int) $closureId,
            'customer_id' => (int) $closure['customer_id'],
            'vehicle_id' => (int) $closure['customer_vehicle_id'],
            'device_id' => (int) $closure['device_id'],
            'installation_id' => (int) $closure['customer_installation_id'],
            'already_completed' => true
        ];
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Installation and Customer Management handoff were already completed.', $result);
    }
    if ($action === 'start') {
        if ($closure['submission_status'] !== 'Approved') {
            throw new DomainException('Only an assigned installation can be started.');
        }
        crmClosureEnsureDeviceRows($conn, $closure);
        $update = $conn->prepare(
            "UPDATE crm_lead_closure_forms
             SET submission_status = 'Installation In Progress'
             WHERE id = ? AND installation_person_user_id = ? AND submission_status = 'Approved'"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare installation start: ' . $conn->error);
        }
        $update->bind_param('ii', $closureId, $userId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to start assigned installation: ' . $error);
        }
        $update->close();
        $newSnapshot = crmClosureSnapshot($closure);
        $newSnapshot['submission_status'] = 'Installation In Progress';
        crmClosureWriteHistory(
            $conn,
            (int) $closure['lead_id'],
            'Installation Started',
            crmClosureSnapshot($closure),
            ['closure_id' => (int) $closureId] + $newSnapshot,
            $currentUser
        );
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to save installation start: ' . $conn->error);
        }
        $conn->close();
        sendResponse(true, 'Installation marked as In Progress.');
    }

    if ($closure['submission_status'] !== 'Installation In Progress') {
        throw new DomainException('Start the assigned installation before submitting completion.');
    }
    if ($deviceCount > 0) {
        // All completed orders, including one-device installations, require verification.
        $result = crmClosureCompleteMultiDevice(
            $conn,
            $closure,
            $currentUser,
            $userId,
            (int) $closureId,
            $data
        );
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit per-device installation completion: ' . $conn->error);
        }
        $conn->close();
        sendResponse(true, $result['message'], $result['data']);
    }

    if (!empty($closure['customer_id'])) {
        throw new DomainException('This legacy assignment already has a customer record and cannot create another handoff.');
    }

    $username = trim((string) ($data->username ?? $closure['username'] ?? ''));
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $username)) {
        throw new DomainException('Enter a valid customer username (letters, numbers, dot, underscore, or hyphen).');
    }
    $saleAmountInput = trim((string) ($data->sale_amount ?? $closure['amount'] ?? ''));
    if ($saleAmountInput === '' || !is_numeric($saleAmountInput)) {
        throw new DomainException('Enter a valid Total Sale Amount for this vehicle.');
    }
    $paymentDetails = crmClosureValidateInstallationPayment($data, (float) $saleAmountInput);
    $saleAmount = (float) $paymentDetails['total_sale_amount'];

    $platformId = (int) $closure['platform_id'];
    $platformStmt = $conn->prepare("SELECT id FROM platforms WHERE id = ? AND status = 'Active' LIMIT 1 FOR UPDATE");
    if (!$platformStmt) {
        throw new RuntimeException('Unable to prepare platform validation: ' . $conn->error);
    }
    $platformStmt->bind_param('i', $platformId);
    if (!$platformStmt->execute() || $platformStmt->get_result()->num_rows === 0) {
        $platformStmt->close();
        throw new DomainException('The selected customer platform is no longer active.');
    }
    $platformStmt->close();

    $primaryMobile = trim((string) ($data->mobile_no ?? $closure['mobile_no']));
    $secondaryMobile = trim((string) ($closure['alternate_mobile_no'] ?? ''));
    $email = trim((string) ($closure['email'] ?? ''));
    $pincode = trim((string) $closure['pincode']);
    if (!preg_match('/^\d{10}$/', $primaryMobile) ||
        ($secondaryMobile !== '' && (!preg_match('/^\d{10}$/', $secondaryMobile) || $secondaryMobile === $primaryMobile)) ||
        !preg_match('/^\d{6}$/', $pincode) ||
        ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150))) {
        throw new DomainException('Customer contact, pincode, or email details do not meet Customer Management requirements.');
    }
    if (!crmClosureIsActiveMasterValue($conn, 'vehicle_types', 'vehicle_type', (string) $closure['vehicle_type']) ||
        !crmClosureIsActiveMasterValue($conn, 'device_types', 'device_type', (string) $closure['device_type'])) {
        throw new DomainException('The requested vehicle or device type is no longer available.');
    }
    $vehicleTypeStmt = $conn->prepare("SELECT id FROM vehicle_types WHERE vehicle_type = ? AND (status = 'Active' OR status IS NULL OR status = '') LIMIT 1");
    $deviceTypeStmt = $conn->prepare('SELECT id FROM device_types WHERE device_type = ? LIMIT 1');
    if (!$vehicleTypeStmt || !$deviceTypeStmt) {
        throw new RuntimeException('Unable to prepare vehicle/device type lookup: ' . $conn->error);
    }
    $vehicleTypeName = (string) $closure['vehicle_type'];
    $deviceTypeName = (string) $closure['device_type'];
    $vehicleTypeStmt->bind_param('s', $vehicleTypeName);
    $deviceTypeStmt->bind_param('s', $deviceTypeName);
    if (!$vehicleTypeStmt->execute()) {
        $error = $vehicleTypeStmt->error;
        $vehicleTypeStmt->close();
        $deviceTypeStmt->close();
        throw new RuntimeException('Unable to load vehicle type identifier: ' . $error);
    }
    $vehicleTypeResult = $vehicleTypeStmt->get_result();
    if (!$vehicleTypeResult) {
        $error = $vehicleTypeStmt->error;
        $vehicleTypeStmt->close();
        $deviceTypeStmt->close();
        throw new RuntimeException('Unable to read vehicle type identifier: ' . $error);
    }
    $vehicleTypeId = (int) ($vehicleTypeResult->fetch_assoc()['id'] ?? 0);
    $vehicleTypeResult->free();
    $vehicleTypeStmt->close();

    if (!$deviceTypeStmt->execute()) {
        $error = $deviceTypeStmt->error;
        $deviceTypeStmt->close();
        throw new RuntimeException('Unable to load device type identifier: ' . $error);
    }
    $deviceTypeResult = $deviceTypeStmt->get_result();
    if (!$deviceTypeResult) {
        $error = $deviceTypeStmt->error;
        $deviceTypeStmt->close();
        throw new RuntimeException('Unable to read device type identifier: ' . $error);
    }
    $deviceTypeId = (int) ($deviceTypeResult->fetch_assoc()['id'] ?? 0);
    $deviceTypeResult->free();
    $deviceTypeStmt->close();
    if ($vehicleTypeId <= 0 || $deviceTypeId <= 0) {
        throw new DomainException('Unable to resolve the requested vehicle or device type.');
    }

    $validityStmt = $conn->prepare("SELECT id FROM sim_validities WHERE months = ? AND LOWER(COALESCE(status, 'active')) = 'active' LIMIT 1 FOR UPDATE");
    if (!$validityStmt) {
        throw new RuntimeException('Unable to prepare SIM validity validation: ' . $conn->error);
    }
    $validityStmt->bind_param('i', $validityMonths);
    if (!$validityStmt->execute()) {
        throw new RuntimeException('Unable to validate SIM validity: ' . $validityStmt->error);
    }
    if ($validityStmt->get_result()->num_rows === 0) {
        $validityStmt->close();
        throw new DomainException('Select an active SIM validity from Customer Management.');
    }
    $validityStmt->close();

    $duplicateStmt = $conn->prepare(
        'SELECT id FROM customers
         WHERE platform_id = ? AND LOWER(TRIM(username)) = LOWER(TRIM(?))
         LIMIT 1 FOR UPDATE'
    );
    if (!$duplicateStmt) {
        throw new RuntimeException('Unable to prepare customer duplicate check: ' . $conn->error);
    }
    $duplicateStmt->bind_param('is', $platformId, $username);
    if (!$duplicateStmt->execute()) {
        throw new RuntimeException('Unable to check for an existing customer: ' . $duplicateStmt->error);
    }
    if ($duplicateStmt->get_result()->num_rows > 0) {
        $duplicateStmt->close();
        throw new DomainException('A Customer Management record already uses this username. Resolve it before retrying the handoff.');
    }
    $duplicateStmt->close();

    $assignedPersonType = (string) $closure['installation_person_type'];
    $assignedPersonId = (int) $closure['installation_person_id'];
    $expectedOwnerType = $assignedPersonType === 'Technician' ? 'technician' : 'dealer';
    if (!crmClosureSimNumberIsOptional($deviceTypeName) &&
        !preg_match('/^\d{10}$|^\d{13}$/', $simNumber1)) {
        throw new DomainException('Enter the installed primary SIM number (10 or 13 digits).');
    }

    $assetIds = [];
    $assetInputs = [
        ['device', 'devices', 'imei_no', $imei, 'device']
    ];
    if ($simNumber1 !== '') {
        $assetInputs[] = ['sim', 'sims', 'sim_no', $simNumber1, 'sim1'];
    }
    if ($simNumber2 !== '') {
        $assetInputs[] = ['sim', 'sims', 'sim_no', $simNumber2, 'sim2'];
    }
    foreach ($assetInputs as [$assetType, $table, $column, $assetNumber, $assetKey]) {
        $assetStmt = $conn->prepare("SELECT id, status" . ($assetType === 'device' ? ', device_model_id' : '') . " FROM {$table} WHERE {$column} = ? LIMIT 1 FOR UPDATE");
        if (!$assetStmt) {
            throw new RuntimeException('Unable to prepare inventory lookup: ' . $conn->error);
        }
        $assetStmt->bind_param('s', $assetNumber);
        if (!$assetStmt->execute()) {
            throw new RuntimeException('Unable to load inventory item: ' . $assetStmt->error);
        }
        $asset = $assetStmt->get_result()->fetch_assoc();
        $assetStmt->close();
        if (!$asset) {
            throw new DomainException("The entered {$assetType} number was not found in existing stock.");
        }
        $assetId = (int) $asset['id'];
        $assetStatus = strtolower((string) $asset['status']);
        if (!in_array($assetStatus, ['available', 'allocated'], true)) {
            throw new DomainException("The entered {$assetType} is not available for installation.");
        }
        if ($assetType === 'device' && (int) $asset['device_model_id'] !== $deviceTypeId) {
            throw new DomainException('The installed IMEI device model does not match the approved device type.');
        }
        $existingLink = $assetType === 'device'
            ? $conn->prepare('SELECT id FROM customer_vehicle_details WHERE device_id = ? LIMIT 1 FOR UPDATE')
            : $conn->prepare('SELECT id FROM customer_vehicle_details WHERE sim_id_1 = ? OR sim_id_2 = ? LIMIT 1 FOR UPDATE');
        if (!$existingLink) {
            throw new RuntimeException('Unable to prepare inventory duplicate check: ' . $conn->error);
        }
        if ($assetType === 'sim') {
            $existingLink->bind_param('ii', $assetId, $assetId);
        } else {
            $existingLink->bind_param('i', $assetId);
        }
        if (!$existingLink->execute()) {
            throw new RuntimeException('Unable to check inventory usage: ' . $existingLink->error);
        }
        if ($existingLink->get_result()->num_rows > 0) {
            $existingLink->close();
            throw new DomainException("The entered {$assetType} is already linked to a customer vehicle.");
        }
        $existingLink->close();

        $allocationColumn = $assetType === 'device' ? 'device_id' : 'sim_id';
        $allocationStmt = $conn->prepare(
            "SELECT owner_type, owner_id FROM stock_allocations
             WHERE {$allocationColumn} = ?
             ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE"
        );
        if (!$allocationStmt) {
            throw new RuntimeException('Unable to prepare stock ownership check: ' . $conn->error);
        }
        $allocationStmt->bind_param('i', $assetId);
        if (!$allocationStmt->execute()) {
            throw new RuntimeException('Unable to check stock ownership: ' . $allocationStmt->error);
        }
        $allocation = $allocationStmt->get_result()->fetch_assoc();
        $allocationStmt->close();
        if ($assetStatus === 'allocated' &&
            (!$allocation || strtolower((string) $allocation['owner_type']) !== $expectedOwnerType ||
                (int) $allocation['owner_id'] !== $assignedPersonId)) {
            throw new DomainException("The allocated {$assetType} must belong to the assigned installation person.");
        }
        if ($allocation && (strtolower((string) $allocation['owner_type']) !== $expectedOwnerType ||
                (int) $allocation['owner_id'] !== $assignedPersonId)) {
            throw new DomainException('The device and SIM stock must belong to the assigned installation person.');
        }
        $transactionStmt = $conn->prepare(
            "SELECT transaction_type FROM stock_transactions
             WHERE {$allocationColumn} = ? ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        if (!$transactionStmt) {
            throw new RuntimeException('Unable to prepare stock usage status check: ' . $conn->error);
        }
        $transactionStmt->bind_param('i', $assetId);
        if (!$transactionStmt->execute()) {
            throw new RuntimeException('Unable to check previous stock usage: ' . $transactionStmt->error);
        }
        $lastTransaction = $transactionStmt->get_result()->fetch_assoc();
        $transactionStmt->close();
        if (($lastTransaction['transaction_type'] ?? '') === 'USE') {
            throw new DomainException("The entered {$assetType} already has a recorded usage transaction.");
        }
        $assetIds[$assetKey] = $assetId;
    }
    $deviceId = (int) $assetIds['device'];
    $simId1 = $assetIds['sim1'] ?? null;
    $simId2 = $assetIds['sim2'] ?? null;
    $simNumber1Value = $simNumber1 !== '' ? $simNumber1 : null;
    $simNumber2Value = $simNumber2 !== '' ? $simNumber2 : null;

    $dateTimeValue = $dateTime->format('Y-m-d H:i:s');
    $verifiedValue = 1;
    $markCompleted = $conn->prepare(
        "UPDATE crm_lead_closure_forms
         SET submission_status = 'Installation Completed',
             installation_datetime = ?, installation_remarks = ?,
             completion_evidence = ?, installation_verified = ?,
             installation_completed_by_user_id = ?,
             installation_completed_at = CURRENT_TIMESTAMP
         WHERE id = ? AND installation_person_user_id = ?
           AND submission_status = 'Installation In Progress' AND customer_id IS NULL"
    );
    if (!$markCompleted) {
        throw new RuntimeException('Unable to prepare installation completion status update: ' . $conn->error);
    }
    $markCompleted->bind_param(
        'sssiiii',
        $dateTimeValue,
        $remarks,
        $evidence,
        $verifiedValue,
        $userId,
        $closureId,
        $userId
    );
    if (!$markCompleted->execute() || $markCompleted->affected_rows !== 1) {
        throw new DomainException('The installation was already completed or reassigned. Refresh My Installations.');
    }
    $markCompleted->close();

    $customerStmt = $conn->prepare(
        "INSERT INTO customers
            (platform_id, username, primary_mobile_no, secondary_mobile_no, email, location, pincode, status)
         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, 'Active')"
    );
    if (!$customerStmt) {
        throw new RuntimeException('Unable to prepare Customer Management handoff: ' . $conn->error);
    }
    $location = (string) $closure['location'];
    $customerStmt->bind_param('issssss', $platformId, $username, $primaryMobile, $secondaryMobile, $email, $location, $pincode);
    if (!$customerStmt->execute()) {
        throw new RuntimeException('Unable to create Customer Management record: ' . $customerStmt->error);
    }
    $customerId = (int) $conn->insert_id;
    $customerStmt->close();
    writeCreatedFields($conn, $customerId, 'Customer', [
        'platform_id' => $platformId,
        'username' => $username,
        'primary_mobile_no' => $primaryMobile,
        'secondary_mobile_no' => $secondaryMobile ?: null,
        'email' => $email ?: null,
        'location' => $location,
        'pincode' => $pincode,
        'status' => 'Active',
        'crm_lead_id' => (int) $closure['lead_id']
    ], $currentUser);

    $vehicleStmt = $conn->prepare(
        'INSERT INTO customer_vehicle_details
            (customer_id, vehicle_no, vehicle_type_id, device_id, device_model_id,
             imei_no, sim_id_1, sim_no_1, sim_id_2, sim_no_2, validity_months)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$vehicleStmt) {
        throw new RuntimeException('Unable to prepare Customer Management vehicle insert: ' . $conn->error);
    }
    $vehicleStmt->bind_param(
        'isiiisisssi',
        $customerId,
        $vehicleNumber,
        $vehicleTypeId,
        $deviceId,
        $deviceTypeId,
        $imei,
        $simId1,
        $simNumber1Value,
        $simId2,
        $simNumber2Value,
        $validityMonths
    );
    if (!$vehicleStmt->execute()) {
        throw new RuntimeException('Unable to create Customer Management vehicle: ' . $vehicleStmt->error);
    }
    $vehicleDetailsId = (int) $conn->insert_id;
    $vehicleStmt->close();
    writeCreatedFields($conn, $vehicleDetailsId, 'Customer Vehicle Details', [
        'customer_id' => $customerId,
        'vehicle_no' => $vehicleNumber,
        'vehicle_type_id' => $vehicleTypeId,
        'device_id' => $deviceId,
        'device_model_id' => $deviceTypeId,
        'imei_no' => $imei,
        'sim_id_1' => $simId1,
        'sim_no_1' => $simNumber1Value,
        'sim_id_2' => $simId2,
        'sim_no_2' => $simNumber2 ?: null,
        'validity_months' => $validityMonths
    ], $currentUser);

    $installationDate = $dateTime->format('Y-m-d');
    $installationStmt = $conn->prepare(
        "INSERT INTO customer_installations
            (customer_id, vehicle_id, installation_person_type, installation_person_id,
             installation_date, installation_datetime, installation_remarks,
             completion_evidence, installation_verified, crm_closure_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$installationStmt) {
        throw new RuntimeException('Unable to prepare Customer Management installation insert: ' . $conn->error);
    }
    $persistedPersonType = $expectedOwnerType === 'technician' ? 'Technician' : 'Dealer';
    $installationStmt->bind_param(
        'iisissssii',
        $customerId,
        $vehicleDetailsId,
        $persistedPersonType,
        $assignedPersonId,
        $installationDate,
        $dateTimeValue,
        $remarks,
        $evidence,
        $verifiedValue,
        $closureId
    );
    if (!$installationStmt->execute()) {
        throw new RuntimeException('Unable to save Customer Management installation: ' . $installationStmt->error);
    }
    $installationId = (int) $conn->insert_id;
    $installationStmt->close();
    writeCreatedFields($conn, $installationId, 'Customer Installation', [
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleDetailsId,
        'installation_person_type' => $persistedPersonType,
        'installation_person_id' => $assignedPersonId,
        'installation_date' => $installationDate,
        'installation_datetime' => $dateTimeValue,
        'installation_remarks' => $remarks,
        'completion_evidence' => $evidence,
        'installation_verified' => $verifiedValue,
        'crm_closure_id' => (int) $closureId
    ], $currentUser);

    $paymentId = crmClosureCreateInstallationPayment(
        $conn,
        $customerId,
        $vehicleDetailsId,
        $paymentDetails,
        'crm-installation-' . $closureId . '-1',
        $currentUser
    );

    foreach (['device' => [$deviceId], 'sim' => array_values(array_filter([$simId1, $simId2]))] as $assetType => $ids) {
        $table = $assetType === 'device' ? 'devices' : 'sims';
        $assetColumn = $assetType === 'device' ? 'device_id' : 'sim_id';
        foreach ($ids as $assetId) {
            $allocationStmt = $conn->prepare(
                "SELECT owner_type, owner_id FROM stock_allocations
                 WHERE {$assetColumn} = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $allocationStmt->bind_param('i', $assetId);
            if (!$allocationStmt->execute()) {
                throw new RuntimeException('Unable to reload inventory allocation: ' . $allocationStmt->error);
            }
            $allocation = $allocationStmt->get_result()->fetch_assoc();
            $allocationStmt->close();
            if ($allocation) {
                $ownerType = (string) $allocation['owner_type'];
                $ownerId = (int) $allocation['owner_id'];
                $useCheck = $conn->prepare(
                    "SELECT id FROM stock_transactions
                     WHERE {$assetColumn} = ? AND from_owner_type = ? AND from_owner_id = ?
                       AND transaction_type = 'USE' AND usage_type = 'ET'
                     LIMIT 1 FOR UPDATE"
                );
                $useCheck->bind_param('isi', $assetId, $ownerType, $ownerId);
                if (!$useCheck->execute()) {
                    throw new RuntimeException('Unable to verify stock usage history: ' . $useCheck->error);
                }
                $alreadyUsed = $useCheck->get_result()->num_rows > 0;
                $useCheck->close();
                if (!$alreadyUsed) {
                    $notes = 'CRM installation completion';
                    $useStmt = $conn->prepare(
                        "INSERT INTO stock_transactions
                            ({$assetColumn}, from_owner_type, from_owner_id, transaction_type,
                             usage_type, transaction_date, notes)
                         VALUES (?, ?, ?, 'USE', 'ET', ?, ?)"
                    );
                    $useDate = $dateTime->format('Y-m-d');
                    $useStmt->bind_param('isiss', $assetId, $ownerType, $ownerId, $useDate, $notes);
                    if (!$useStmt->execute()) {
                        throw new RuntimeException('Unable to record stock usage: ' . $useStmt->error);
                    }
                    $useStmt->close();
                }
            }
            $statusStmt = $conn->prepare("UPDATE {$table} SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statusStmt->bind_param('i', $assetId);
            if (!$statusStmt->execute()) {
                throw new RuntimeException('Unable to mark installed stock as used: ' . $statusStmt->error);
            }
            $statusStmt->close();
        }
    }

    $deviceProgressUpdate = $conn->prepare(
        "UPDATE crm_closure_installation_devices
         SET customer_id = ?, installation_status = 'Completed',
             vehicle_number = ?, vehicle_type_id = ?, device_model_id = ?, sale_amount = ?,
             device_id = ?, imei_no = ?, sim_id_1 = ?, sim_no_1 = ?, sim_id_2 = ?, sim_no_2 = ?,
             validity_months = ?, installation_datetime = ?, installation_remarks = ?,
             completion_evidence = ?, installation_verified = ?,
             customer_vehicle_id = ?, customer_installation_id = ?,
             completed_by_user_id = ?, completed_at = CURRENT_TIMESTAMP,
             verification_status = 'Approved', verification_rejection_reason = NULL
         WHERE closure_id = ? AND device_sequence = ? AND installation_status = 'Pending'"
    );
    if (!$deviceProgressUpdate) {
        throw new RuntimeException('Unable to prepare completed installation progress update: ' . $conn->error);
    }
    $completedByUserId = $userId;
    $sequenceValue = 1;
    $deviceProgressUpdate->bind_param(
        'isiidisisisisssiiiiii',
        $customerId,
        $vehicleNumber,
        $vehicleTypeId,
        $deviceTypeId,
        $saleAmount,
        $deviceId,
        $imei,
        $simId1,
        $simNumber1Value,
        $simId2,
        $simNumber2Value,
        $validityMonths,
        $dateTimeValue,
        $remarks,
        $evidence,
        $verifiedValue,
        $vehicleDetailsId,
        $installationId,
        $completedByUserId,
        $closureId,
        $sequenceValue
    );
    if (!$deviceProgressUpdate->execute() || $deviceProgressUpdate->affected_rows !== 1) {
        $error = $deviceProgressUpdate->error;
        $deviceProgressUpdate->close();
        throw new DomainException($error ?: 'The device progress row could not be completed. Refresh My Installations.');
    }
    $deviceProgressUpdate->close();

    $completion = [
        'customer_id' => $customerId,
        'customer_vehicle_id' => $vehicleDetailsId,
        'customer_installation_id' => $installationId,
        'device_id' => $deviceId,
        'installation_datetime' => $dateTime->format('Y-m-d H:i:s'),
        'installation_remarks' => $remarks,
        'completion_evidence' => $evidence,
        'installation_verified' => $verifiedValue
    ];
    $update = $conn->prepare(
        "UPDATE crm_lead_closure_forms
         SET submission_status = 'Customer Handoff Completed',
             customer_id = ?, customer_vehicle_id = ?, customer_installation_id = ?,
             device_id = ?
         WHERE id = ? AND installation_person_user_id = ?
           AND submission_status = 'Installation Completed' AND customer_id IS NULL"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare completion handoff update: ' . $conn->error);
    }
    $update->bind_param(
        'iiiiii',
        $customerId,
        $vehicleDetailsId,
        $installationId,
        $deviceId,
        $closureId,
        $userId
    );
    if (!$update->execute() || $update->affected_rows !== 1) {
        throw new DomainException('The installation was already completed or reassigned. Refresh My Installations.');
    }
    $update->close();

    $oldSnapshot = crmClosureSnapshot($closure);
    $completedSnapshot = $oldSnapshot + $completion;
    $completedSnapshot['submission_status'] = 'Installation Completed';
    crmClosureWriteHistory(
        $conn,
        (int) $closure['lead_id'],
        'Installation Completed',
        $oldSnapshot,
        ['closure_id' => (int) $closureId] + $completedSnapshot,
        $currentUser
    );
    $handoffSnapshot = $completedSnapshot;
    $handoffSnapshot['submission_status'] = 'Customer Handoff Completed';
    crmClosureWriteHistory(
        $conn,
        (int) $closure['lead_id'],
        'Customer Handoff Completed',
        $completedSnapshot,
        ['closure_id' => (int) $closureId] + $handoffSnapshot,
        $currentUser
    );
    crmLeadSetLifecycleStage(
        $conn,
        (int) $closure['lead_id'],
        'Converted Lead',
        $currentUser
    );
    $responsibleUserId = (int) ($closure['assigned_user_id'] ?? 0);
    if ($responsibleUserId > 0) {
        crmCreateNotification(
            $conn,
            $responsibleUserId,
            'installation_completed',
            'Installation Completed',
            'Installation for lead ' . $closure['lead_name'] . ' was completed.',
            'Normal',
            'crm_installation',
            (int) $closureId,
            'installation_completed:' . (int) $closureId,
            (int) $closure['lead_id'],
            $customerId,
            (int) $closureId
        );
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit customer handoff: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'Installation completed and Customer Management handoff succeeded.', [
        'closure_id' => (int) $closureId,
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleDetailsId,
        'device_id' => $deviceId,
        'installation_id' => $installationId,
        'payment_id' => $paymentId
    ]);
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
