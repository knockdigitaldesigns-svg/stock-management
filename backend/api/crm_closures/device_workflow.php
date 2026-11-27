<?php

require_once __DIR__ . '/../../utils/customer_payment_verification.php';

function crmClosureDeviceMasterId($conn, string $table, string $column, string $value): ?int {
    $allowed = [
        'vehicle_types' => 'vehicle_type',
        'device_types' => 'device_type'
    ];
    if (($allowed[$table] ?? null) !== $column) {
        throw new InvalidArgumentException('Invalid installation device master lookup.');
    }
    $statusResult = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
    if (!$statusResult) {
        throw new RuntimeException('Unable to verify installation device type: ' . $conn->error);
    }
    $statusCondition = $statusResult->num_rows > 0
        ? " AND (status = 'Active' OR status IS NULL OR status = '')"
        : '';
    $stmt = $conn->prepare(
        "SELECT id FROM `{$table}` WHERE `{$column}` = ?{$statusCondition} LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare installation device type lookup: ' . $conn->error);
    }
    $stmt->bind_param('s', $value);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load installation device type: ' . $error);
    }
    $id = $stmt->get_result()->fetch_assoc()['id'] ?? null;
    $stmt->close();
    return $id === null ? null : (int) $id;
}

function crmClosureEnsureDeviceRows($conn, array $closure): void {
    $closureId = (int) ($closure['id'] ?? 0);
    $deviceCount = (int) ($closure['device_count'] ?? 0);
    if ($closureId <= 0 || $deviceCount <= 0 || $deviceCount > 65535) {
        throw new DomainException('The assigned order has an invalid device count.');
    }

    $vehicleTypeId = crmClosureDeviceMasterId(
        $conn,
        'vehicle_types',
        'vehicle_type',
        trim((string) ($closure['vehicle_type'] ?? ''))
    );
    $deviceTypeId = crmClosureDeviceMasterId(
        $conn,
        'device_types',
        'device_type',
        trim((string) ($closure['device_type'] ?? ''))
    );

    $insert = $conn->prepare(
        "INSERT IGNORE INTO crm_closure_installation_devices
            (closure_id, device_sequence, vehicle_number, vehicle_type_id, device_model_id)
         VALUES (?, ?, NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, 0))"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare per-device installation rows: ' . $conn->error);
    }
    $vehicleNumber = '';
    $vehicleTypeValue = $vehicleTypeId ?? 0;
    $deviceTypeValue = $deviceTypeId ?? 0;
    for ($sequence = 1; $sequence <= $deviceCount; $sequence++) {
        $vehicleNumber = $sequence === 1 ? trim((string) ($closure['vehicle_number'] ?? '')) : '';
        if (mb_strlen($vehicleNumber) > 30) {
            $vehicleNumber = '';
        }
        $insert->bind_param(
            'iisii',
            $closureId,
            $sequence,
            $vehicleNumber,
            $vehicleTypeValue,
            $deviceTypeValue
        );
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException('Unable to initialize per-device installation progress: ' . $error);
        }
    }
    $insert->close();
}

function crmClosureCompleteMultiDevice(
    $conn,
    array $closure,
    array $currentUser,
    int $userId,
    int $closureId,
    object $data
): array {
    $sequence = filter_var($data->device_sequence ?? 1, FILTER_VALIDATE_INT);
    $deviceCount = (int) ($closure['device_count'] ?? 0);
    if ($sequence === false || $sequence <= 0 || $sequence > $deviceCount) {
        throw new DomainException('Select a valid device in this installation order.');
    }

    $vehicleNumber = strtoupper(trim((string) ($data->vehicle_number ?? '')));
    $vehicleType = trim((string) ($data->vehicle_type ?? $closure['vehicle_type'] ?? ''));
    $deviceType = trim((string) ($data->device_type ?? $closure['device_type'] ?? ''));
    $saleAmountInput = trim((string) ($data->sale_amount ?? $closure['amount'] ?? ''));
    $imei = trim((string) ($data->imei_no ?? ''));
    $simNumber1 = trim((string) ($data->sim_no_1 ?? ''));
    $simNumber2 = trim((string) ($data->sim_no_2 ?? ''));
    $validityMonths = filter_var($data->validity_months ?? null, FILTER_VALIDATE_INT);
    $installationDateTime = trim((string) ($data->installation_datetime ?? ''));
    $remarks = trim((string) ($data->installation_remarks ?? ''));
    $evidence = trim((string) ($data->completion_evidence ?? ''));
    $verified = filter_var($data->completion_verified ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($vehicleNumber === '' || mb_strlen($vehicleNumber) > 30) {
        throw new DomainException('Enter the installed vehicle number for this device.');
    }
    if ($vehicleType === '' || mb_strlen($vehicleType) > 100 ||
        $deviceType === '' || mb_strlen($deviceType) > 100) {
        throw new DomainException('Select a valid vehicle type and device type for this device.');
    }
    if ($saleAmountInput === '' || !is_numeric($saleAmountInput) ||
        (float) $saleAmountInput <= 0 || (float) $saleAmountInput > 9999999999.99) {
        throw new DomainException('Enter a valid sale amount for this device.');
    }
    $saleAmount = number_format((float) $saleAmountInput, 2, '.', '');
    $paymentDetails = crmClosureValidateInstallationPayment($data, (float) $saleAmount);
    $username = trim((string) ($data->username ?? ''));
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $username)) {
        throw new DomainException('Enter a valid customer username (letters, numbers, dot, underscore, or hyphen).');
    }
    $dateTime = crmClosureParseInstallationDateTime($installationDateTime);
    if (!$dateTime ||
        $dateTime->getTimestamp() > time()) {
        throw new DomainException('Enter a valid installation date and time that is not in the future.');
    }
    if (!preg_match('/^\d{15}$/', $imei)) {
        throw new DomainException('Enter the installed 15-digit device IMEI.');
    }
    if (!crmClosureSimNumberIsOptional($deviceType) &&
        !preg_match('/^\d{10}$|^\d{13}$/', $simNumber1)) {
        throw new DomainException('Enter the installed primary SIM number (10 or 13 digits).');
    }
    if ($simNumber2 !== '' &&
        (!preg_match('/^\d{10}$|^\d{13}$/', $simNumber2) || $simNumber2 === $simNumber1)) {
        throw new DomainException('Enter a valid secondary SIM number different from the primary SIM.');
    }
    if ($validityMonths === false || $validityMonths <= 0) {
        throw new DomainException('Select a valid SIM/service validity in months.');
    }
    if (mb_strlen($remarks) > 2000) {
        throw new DomainException('Installation remarks cannot exceed 2,000 characters.');
    }
    if (mb_strlen($evidence) > 2000) {
        throw new DomainException('Verification/evidence note cannot exceed 2,000 characters.');
    }
    if (!$verified) {
        throw new DomainException('Confirm that the installed vehicle and device details were verified.');
    }

    crmClosureEnsureDeviceRows($conn, $closure);

    $itemStmt = $conn->prepare(
        'SELECT * FROM crm_closure_installation_devices
         WHERE closure_id = ? AND device_sequence = ? LIMIT 1 FOR UPDATE'
    );
    if (!$itemStmt) {
        throw new RuntimeException('Unable to prepare per-device installation lookup: ' . $conn->error);
    }
    $itemStmt->bind_param('ii', $closureId, $sequence);
    if (!$itemStmt->execute()) {
        $error = $itemStmt->error;
        $itemStmt->close();
        throw new RuntimeException('Unable to load per-device installation: ' . $error);
    }
    $item = $itemStmt->get_result()->fetch_assoc();
    $itemStmt->close();
    if (!$item) {
        throw new RuntimeException('The selected device progress record could not be initialized.');
    }
    if ($item['installation_status'] === 'Completed') {
        throw new DomainException('This device is already installed. Refresh My Installations.');
    }
    $itemId = (int) $item['id'];

    $vehicleTypeId = crmClosureDeviceMasterId($conn, 'vehicle_types', 'vehicle_type', $vehicleType);
    $deviceTypeId = crmClosureDeviceMasterId($conn, 'device_types', 'device_type', $deviceType);
    if (!$vehicleTypeId || !$deviceTypeId) {
        throw new DomainException('The selected vehicle or device type is no longer active.');
    }

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

    $validityStmt = $conn->prepare("SELECT id FROM sim_validities WHERE months = ? AND LOWER(COALESCE(status, 'active')) = 'active' LIMIT 1 FOR UPDATE");
    if (!$validityStmt) {
        throw new RuntimeException('Unable to prepare SIM validity validation: ' . $conn->error);
    }
    $validityStmt->bind_param('i', $validityMonths);
    if (!$validityStmt->execute() || $validityStmt->get_result()->num_rows === 0) {
        $validityStmt->close();
        throw new DomainException('Select an active SIM validity from Customer Management.');
    }
    $validityStmt->close();

    $vehicleNumberCheck = $conn->prepare(
        'SELECT id FROM customer_vehicle_details WHERE vehicle_no = ? LIMIT 1 FOR UPDATE'
    );
    if (!$vehicleNumberCheck) {
        throw new RuntimeException('Unable to prepare vehicle duplicate check: ' . $conn->error);
    }
    $vehicleNumberCheck->bind_param('s', $vehicleNumber);
    if (!$vehicleNumberCheck->execute()) {
        $error = $vehicleNumberCheck->error;
        $vehicleNumberCheck->close();
        throw new RuntimeException('Unable to verify vehicle registration: ' . $error);
    }
    if ($vehicleNumberCheck->get_result()->num_rows > 0) {
        $vehicleNumberCheck->close();
        throw new DomainException('This vehicle registration number is already linked to a customer.');
    }
    $vehicleNumberCheck->close();

    $stagedVehicleCheck = $conn->prepare(
        'SELECT id FROM crm_closure_installation_devices
         WHERE closure_id = ? AND vehicle_number = ? AND device_sequence <> ? LIMIT 1 FOR UPDATE'
    );
    if (!$stagedVehicleCheck) {
        throw new RuntimeException('Unable to prepare per-order vehicle duplicate check: ' . $conn->error);
    }
    $stagedVehicleCheck->bind_param('isi', $closureId, $vehicleNumber, $sequence);
    if (!$stagedVehicleCheck->execute()) {
        $error = $stagedVehicleCheck->error;
        $stagedVehicleCheck->close();
        throw new RuntimeException('Unable to verify per-order vehicle registration: ' . $error);
    }
    if ($stagedVehicleCheck->get_result()->num_rows > 0) {
        $stagedVehicleCheck->close();
        throw new DomainException('This vehicle registration number is already entered for another device in this order.');
    }
    $stagedVehicleCheck->close();

    $assetIds = [];
    $assetInputs = [
        ['device', 'devices', 'imei_no', $imei, 'device'],
    ];
    if ($simNumber1 !== '') {
        $assetInputs[] = ['sim', 'sims', 'sim_no', $simNumber1, 'sim1'];
    }
    if ($simNumber2 !== '') {
        $assetInputs[] = ['sim', 'sims', 'sim_no', $simNumber2, 'sim2'];
    }
    foreach ($assetInputs as [$assetType, $table, $column, $assetNumber, $assetKey]) {
        $assetStmt = $conn->prepare(
            "SELECT id, status" . ($assetType === 'device' ? ', device_model_id' : '') .
            " FROM {$table} WHERE {$column} = ? LIMIT 1 FOR UPDATE"
        );
        if (!$assetStmt) {
            throw new RuntimeException('Unable to prepare inventory lookup: ' . $conn->error);
        }
        $assetStmt->bind_param('s', $assetNumber);
        if (!$assetStmt->execute()) {
            $error = $assetStmt->error;
            $assetStmt->close();
            throw new RuntimeException('Unable to load inventory item: ' . $error);
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
            throw new DomainException('The installed IMEI device model does not match the selected device type.');
        }

        if ($assetType === 'device') {
            $existingLink = $conn->prepare(
                'SELECT id FROM customer_vehicle_details
                 WHERE device_id = ? OR BINARY imei_no = BINARY ? LIMIT 1 FOR UPDATE'
            );
            $stagedLink = $conn->prepare(
                'SELECT id FROM crm_closure_installation_devices
                 WHERE (device_id = ? OR BINARY imei_no = BINARY ?)
                   AND id <> ? LIMIT 1 FOR UPDATE'
            );
            if (!$existingLink || !$stagedLink) {
                throw new RuntimeException('Unable to prepare device duplicate validation: ' . $conn->error);
            }
            $existingLink->bind_param('is', $assetId, $assetNumber);
            $stagedLink->bind_param('isi', $assetId, $assetNumber, $itemId);
        } else {
            $existingLink = $conn->prepare(
                'SELECT id FROM customer_vehicle_details
                 WHERE sim_id_1 = ? OR sim_id_2 = ? OR BINARY sim_no_1 = BINARY ?
                    OR BINARY sim_no_2 = BINARY ? LIMIT 1 FOR UPDATE'
            );
            $stagedLink = $conn->prepare(
                'SELECT id FROM crm_closure_installation_devices
                 WHERE (sim_id_1 = ? OR sim_id_2 = ? OR BINARY sim_no_1 = BINARY ?
                    OR BINARY sim_no_2 = BINARY ?) AND id <> ? LIMIT 1 FOR UPDATE'
            );
            if (!$existingLink || !$stagedLink) {
                throw new RuntimeException('Unable to prepare SIM duplicate validation: ' . $conn->error);
            }
            $existingLink->bind_param('iiss', $assetId, $assetId, $assetNumber, $assetNumber);
            $stagedLink->bind_param('iissi', $assetId, $assetId, $assetNumber, $assetNumber, $itemId);
        }
        if (!$existingLink->execute()) {
            $error = $existingLink->error;
            $existingLink->close();
            $stagedLink->close();
            throw new RuntimeException('Unable to verify inventory item usage: ' . $error);
        }
        $existingResult = $existingLink->get_result();
        if (!$existingResult) {
            $error = $existingLink->error;
            $existingLink->close();
            $stagedLink->close();
            throw new RuntimeException('Unable to read existing inventory links: ' . $error);
        }
        $alreadyLinked = $existingResult->num_rows > 0;
        $existingResult->free();

        if (!$stagedLink->execute()) {
            $error = $stagedLink->error;
            $existingLink->close();
            $stagedLink->close();
            throw new RuntimeException('Unable to verify staged inventory usage: ' . $error);
        }
        $stagedResult = $stagedLink->get_result();
        if (!$stagedResult) {
            $error = $stagedLink->error;
            $existingLink->close();
            $stagedLink->close();
            throw new RuntimeException('Unable to read staged inventory links: ' . $error);
        }
        $alreadyLinked = $alreadyLinked || $stagedResult->num_rows > 0;
        $stagedResult->free();
        $existingLink->close();
        $stagedLink->close();
        if ($alreadyLinked) {
            throw new DomainException("The entered {$assetType} is already linked to another device.");
        }

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
            $error = $allocationStmt->error;
            $allocationStmt->close();
            throw new RuntimeException('Unable to check stock ownership: ' . $error);
        }
        $allocation = $allocationStmt->get_result()->fetch_assoc();
        $allocationStmt->close();
        $expectedOwnerType = (string) $closure['installation_person_type'] === 'Technician'
            ? 'technician'
            : 'dealer';
        $assignedPersonId = (int) $closure['installation_person_id'];
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
            $error = $transactionStmt->error;
            $transactionStmt->close();
            throw new RuntimeException('Unable to check previous stock usage: ' . $error);
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

    $customerId = (int) ($item['customer_id'] ?? 0);
    if ($customerId <= 0) {
        $sameOrderCustomer = $conn->prepare(
            'SELECT c.id
             FROM crm_closure_installation_devices installed
             INNER JOIN customers c ON c.id = installed.customer_id
             WHERE installed.closure_id = ? AND installed.id <> ?
               AND c.platform_id = ?
               AND LOWER(TRIM(c.username)) = LOWER(TRIM(?))
             ORDER BY installed.device_sequence
             LIMIT 1 FOR UPDATE'
        );
        if (!$sameOrderCustomer) {
            throw new RuntimeException('Unable to prepare shared installation customer lookup: ' . $conn->error);
        }
        $sameOrderCustomer->bind_param('iiis', $closureId, $itemId, $platformId, $username);
        if (!$sameOrderCustomer->execute()) {
            $error = $sameOrderCustomer->error;
            $sameOrderCustomer->close();
            throw new RuntimeException('Unable to find a shared installation customer: ' . $error);
        }
        $customerId = (int) ($sameOrderCustomer->get_result()->fetch_assoc()['id'] ?? 0);
        $sameOrderCustomer->close();
    }
    $creatorUserId = (int) ($closure['created_by_user_id'] ?? 0);
    if ($creatorUserId <= 0) {
        $creatorUserId = (int) ($closure['submitted_by_user_id'] ?? 0);
    }
    if ($creatorUserId <= 0) {
        throw new DomainException('The original lead creator could not be resolved for Customer Verification.');
    }
    $dateTimeValue = $dateTime->format('Y-m-d H:i:s');
    $installationDate = $dateTime->format('Y-m-d');
    $persistedPersonType = (string) $closure['installation_person_type'] === 'Technician'
        ? 'Technician'
        : 'Dealer';
    $verificationData = [
        'source' => 'crm_installation',
        'legacy_customer_review' => true,
        'crm_closure_id' => $closureId,
        'installation_device_id' => $itemId,
        'device_sequence' => $sequence,
        'lead_creator_user_id' => $creatorUserId,
        'existing_customer_id' => $customerId > 0 ? $customerId : null,
        'customer' => [
            'platform_id' => $platformId,
            'username' => $username,
            'primary_mobile_no' => $primaryMobile,
            'secondary_mobile_no' => $secondaryMobile,
            'email' => $email,
            'location' => (string) $closure['location'],
            'pincode' => $pincode,
            'status' => 'Active'
        ],
        'vehicle' => [
            'vehicle_no' => $vehicleNumber,
            'vehicle_type_id' => $vehicleTypeId,
            'vehicle_type' => $vehicleType,
            'device_model_id' => $deviceTypeId,
            'device_model' => $deviceType,
            'device_id' => $deviceId,
            'imei_no' => $imei,
            'sim_id_1' => $simId1,
            'sim_no_1' => $simNumber1Value,
            'sim_id_2' => $simId2,
            'sim_no_2' => $simNumber2 !== '' ? $simNumber2 : null,
            'validity_months' => $validityMonths,
            'installation' => [
                'installation_person_type' => $persistedPersonType,
                'installation_person_id' => $assignedPersonId,
                'lead_closure_id' => 0,
                'installation_date' => $installationDate,
                'installation_datetime' => $dateTimeValue,
                'installation_remarks' => $remarks,
                'completion_evidence' => $evidence
            ]
        ],
        'payment_part1' => [
            'total_sale_amount' => (float) $saleAmount,
            'total_amount' => $paymentDetails['total_amount'],
            'amount_paid' => $paymentDetails['amount_paid'],
            'amount_pending' => $paymentDetails['amount_pending'],
            'payment_status' => $paymentDetails['payment_status'],
            'payment_mode' => $paymentDetails['payment_mode'],
            'transaction_id' => $paymentDetails['transaction_id']
        ]
    ];
    $verificationJson = json_encode($verificationData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($verificationJson === false) {
        throw new RuntimeException('Unable to prepare the Customer Verification submission.');
    }
    $verificationStmt = $conn->prepare(
        "INSERT INTO customer_payment_verification_submissions
            (submitter_user_id, existing_customer_id, status, submission_data, submitted_at)
         VALUES (?, NULLIF(?, 0), 'Pending Verification', ?, CURRENT_TIMESTAMP)"
    );
    if (!$verificationStmt) {
        throw new RuntimeException('Unable to prepare Customer Verification submission: ' . $conn->error);
    }
    $verificationStmt->bind_param('iis', $creatorUserId, $customerId, $verificationJson);
    if (!$verificationStmt->execute()) {
        $error = $verificationStmt->error;
        $verificationStmt->close();
        throw new RuntimeException('Unable to submit the installed device for Customer Verification: ' . $error);
    }
    $verificationSubmissionId = (int) $conn->insert_id;
    $verificationStmt->close();
    insertCustomerPaymentVerificationHistory(
        $conn,
        $verificationSubmissionId,
        null,
        'Pending Verification',
        'CRM installation submitted for Customer Management verification.',
        $userId
    );

    if ($customerId > 0) {
        $customerCheck = $conn->prepare(
            'SELECT id, primary_mobile_no FROM customers
             WHERE id = ? AND platform_id = ? AND username = ? AND status = \'Active\'
             LIMIT 1 FOR UPDATE'
        );
        if (!$customerCheck) {
            throw new RuntimeException('Unable to prepare customer reuse validation: ' . $conn->error);
        }
        $customerCheck->bind_param('iis', $customerId, $platformId, $username);
        if (!$customerCheck->execute()) {
            $error = $customerCheck->error;
            $customerCheck->close();
            throw new RuntimeException('Unable to verify the linked Customer Management record: ' . $error);
        }
        $existingCustomer = $customerCheck->get_result()->fetch_assoc();
        $customerCheck->close();
        if (!$existingCustomer) {
            throw new DomainException('The linked Customer Management record is missing or no longer active.');
        }
        if ((string) $existingCustomer['primary_mobile_no'] !== $primaryMobile) {
            throw new DomainException('Vehicles using the same username must use the same mobile number.');
        }
    } else {
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
            $error = $duplicateStmt->error;
            $duplicateStmt->close();
            throw new RuntimeException('Unable to check for an existing customer: ' . $error);
        }
        if ($duplicateStmt->get_result()->num_rows > 0) {
            $duplicateStmt->close();
            throw new DomainException('A Customer Management record already uses this username. Resolve it before retrying the handoff.');
        }
        $duplicateStmt->close();

        $customerStmt = $conn->prepare(
            "INSERT INTO customers
                (platform_id, username, primary_mobile_no, secondary_mobile_no, email, location, pincode,
                 status, verification_status, verification_submission_id)
             VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, 'Active', 'Pending', ?)"
        );
        if (!$customerStmt) {
            throw new RuntimeException('Unable to prepare Customer Management handoff: ' . $conn->error);
        }
        $location = (string) $closure['location'];
        $customerStmt->bind_param(
            'issssssi',
            $platformId,
            $username,
            $primaryMobile,
            $secondaryMobile,
            $email,
            $location,
            $pincode,
            $verificationSubmissionId
        );
        if (!$customerStmt->execute()) {
            $error = $customerStmt->error;
            $customerStmt->close();
            throw new RuntimeException('Unable to create Customer Management record: ' . $error);
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
            'verification_status' => 'Pending',
            'verification_submission_id' => $verificationSubmissionId,
            'crm_lead_id' => (int) $closure['lead_id']
        ], $currentUser);
    }

    $customerVerificationUpdate = $conn->prepare(
        "UPDATE customers
         SET verification_status = 'Pending', verification_submission_id = ?
         WHERE id = ?"
    );
    if (!$customerVerificationUpdate) {
        throw new RuntimeException('Unable to prepare shared customer verification status update: ' . $conn->error);
    }
    $customerVerificationUpdate->bind_param('ii', $verificationSubmissionId, $customerId);
    if (!$customerVerificationUpdate->execute()) {
        $error = $customerVerificationUpdate->error;
        $customerVerificationUpdate->close();
        throw new RuntimeException('Unable to reset shared customer verification status: ' . $error);
    }
    $customerVerificationUpdate->close();

    $vehicleStmt = $conn->prepare(
        'INSERT INTO customer_vehicle_details
            (customer_id, vehicle_no, vehicle_type_id, device_id, device_model_id,
             imei_no, sim_id_1, sim_no_1, sim_id_2, sim_no_2, validity_months,
             verification_submission_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$vehicleStmt) {
        throw new RuntimeException('Unable to prepare Customer Management vehicle insert: ' . $conn->error);
    }
    $simNumber2Value = $simNumber2 !== '' ? $simNumber2 : null;
    $vehicleStmt->bind_param(
        'isiiisisssii',
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
        $validityMonths,
        $verificationSubmissionId
    );
    if (!$vehicleStmt->execute()) {
        $error = $vehicleStmt->error;
        $vehicleStmt->close();
        throw new RuntimeException('Unable to create Customer Management vehicle: ' . $error);
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

    $crmClosureId = $sequence === 1 ? $closureId : null;
    $installationStmt = $conn->prepare(
        "INSERT INTO customer_installations
            (customer_id, vehicle_id, installation_person_type, installation_person_id,
             installation_date, installation_datetime, installation_remarks,
             completion_evidence, installation_verified, crm_closure_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
    );
    if (!$installationStmt) {
        throw new RuntimeException('Unable to prepare Customer Management installation insert: ' . $conn->error);
    }
    $installationStmt->bind_param(
        'iisissssi',
        $customerId,
        $vehicleDetailsId,
        $persistedPersonType,
        $assignedPersonId,
        $installationDate,
        $dateTimeValue,
        $remarks,
        $evidence,
        $crmClosureId
    );
    if (!$installationStmt->execute()) {
        $error = $installationStmt->error;
        $installationStmt->close();
        throw new RuntimeException('Unable to save Customer Management installation: ' . $error);
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
        'installation_verified' => 1,
        'crm_closure_id' => $crmClosureId
    ], $currentUser);

    $paymentId = crmClosureCreateInstallationPayment(
        $conn,
        $customerId,
        $vehicleDetailsId,
        $paymentDetails,
        'crm-installation-' . $closureId . '-' . $sequence,
        $currentUser
    );

    $linkVerificationStmt = $conn->prepare(
        'UPDATE customer_payment_verification_submissions
         SET linked_customer_id = ?, linked_vehicle_id = ?, linked_payment_id = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = \'Pending Verification\''
    );
    if (!$linkVerificationStmt) {
        throw new RuntimeException('Unable to prepare verification record links: ' . $conn->error);
    }
    $linkVerificationStmt->bind_param(
        'iiii',
        $customerId,
        $vehicleDetailsId,
        $paymentId,
        $verificationSubmissionId
    );
    if (!$linkVerificationStmt->execute() || $linkVerificationStmt->affected_rows !== 1) {
        $error = $linkVerificationStmt->error;
        $linkVerificationStmt->close();
        throw new RuntimeException('Unable to link installed device records to Customer Verification: ' . $error);
    }
    $linkVerificationStmt->close();

    foreach (['device' => [$deviceId], 'sim' => array_values(array_filter([$simId1, $simId2]))] as $assetType => $ids) {
        $table = $assetType === 'device' ? 'devices' : 'sims';
        $assetColumn = $assetType === 'device' ? 'device_id' : 'sim_id';
        foreach ($ids as $assetId) {
            $allocationStmt = $conn->prepare(
                "SELECT owner_type, owner_id FROM stock_allocations
                 WHERE {$assetColumn} = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            if (!$allocationStmt) {
                throw new RuntimeException('Unable to prepare inventory allocation reload: ' . $conn->error);
            }
            $allocationStmt->bind_param('i', $assetId);
            if (!$allocationStmt->execute()) {
                $error = $allocationStmt->error;
                $allocationStmt->close();
                throw new RuntimeException('Unable to reload inventory allocation: ' . $error);
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
                if (!$useCheck) {
                    throw new RuntimeException('Unable to prepare stock usage history check: ' . $conn->error);
                }
                $useCheck->bind_param('isi', $assetId, $ownerType, $ownerId);
                if (!$useCheck->execute()) {
                    $error = $useCheck->error;
                    $useCheck->close();
                    throw new RuntimeException('Unable to verify stock usage history: ' . $error);
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
                    if (!$useStmt) {
                        throw new RuntimeException('Unable to prepare stock usage record: ' . $conn->error);
                    }
                    $useDate = $dateTime->format('Y-m-d');
                    $useStmt->bind_param('isiss', $assetId, $ownerType, $ownerId, $useDate, $notes);
                    if (!$useStmt->execute()) {
                        $error = $useStmt->error;
                        $useStmt->close();
                        throw new RuntimeException('Unable to record stock usage: ' . $error);
                    }
                    $useStmt->close();
                }
            }
            $statusStmt = $conn->prepare(
                "UPDATE {$table} SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            if (!$statusStmt) {
                throw new RuntimeException('Unable to prepare installed stock status update: ' . $conn->error);
            }
            $statusStmt->bind_param('i', $assetId);
            if (!$statusStmt->execute()) {
                $error = $statusStmt->error;
                $statusStmt->close();
                throw new RuntimeException('Unable to mark installed stock as used: ' . $error);
            }
            $statusStmt->close();
        }
    }

    $simNumber2ForStorage = $simNumber2 !== '' ? $simNumber2 : null;
    $verifiedValue = 1;
    $completedByUserId = $userId;
    $itemUpdate = $conn->prepare(
        "UPDATE crm_closure_installation_devices
         SET customer_id = ?, installation_status = 'Completed',
             vehicle_number = ?, vehicle_type_id = ?, device_model_id = ?, sale_amount = ?,
             device_id = ?, imei_no = ?, sim_id_1 = ?, sim_no_1 = ?, sim_id_2 = ?, sim_no_2 = ?,
             validity_months = ?, installation_datetime = ?, installation_remarks = ?,
             completion_evidence = ?, installation_verified = ?,
             customer_vehicle_id = ?, customer_installation_id = ?,
             completed_by_user_id = ?, completed_at = CURRENT_TIMESTAMP,
             verification_submission_id = ?, verification_status = 'Pending Verification',
             verification_rejection_reason = NULL
         WHERE id = ? AND closure_id = ? AND installation_status = 'Pending'"
    );
    if (!$itemUpdate) {
        throw new RuntimeException('Unable to prepare per-device completion update: ' . $conn->error);
    }
    $itemUpdate->bind_param(
        'isiidisisisisssiiiiiii',
        $customerId,
        $vehicleNumber,
        $vehicleTypeId,
        $deviceTypeId,
        $saleAmount,
        $deviceId,
        $imei,
        $simId1,
        $simNumber1,
        $simId2,
        $simNumber2ForStorage,
        $validityMonths,
        $dateTimeValue,
        $remarks,
        $evidence,
        $verifiedValue,
        $vehicleDetailsId,
        $installationId,
        $completedByUserId,
        $verificationSubmissionId,
        $itemId,
        $closureId
    );
    if (!$itemUpdate->execute() || $itemUpdate->affected_rows !== 1) {
        $error = $itemUpdate->error;
        $itemUpdate->close();
        throw new DomainException($error ?: 'This device was already completed or changed. Refresh My Installations.');
    }
    $itemUpdate->close();

    $progressStmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(installation_status = 'Completed') AS installed,
                SUM(verification_status = 'Approved') AS verified
         FROM crm_closure_installation_devices WHERE closure_id = ?"
    );
    if (!$progressStmt) {
        throw new RuntimeException('Unable to prepare installation progress count: ' . $conn->error);
    }
    $progressStmt->bind_param('i', $closureId);
    if (!$progressStmt->execute()) {
        $error = $progressStmt->error;
        $progressStmt->close();
        throw new RuntimeException('Unable to load installation progress: ' . $error);
    }
    $progress = $progressStmt->get_result()->fetch_assoc();
    $progressStmt->close();
    $totalDevices = (int) ($progress['total'] ?? 0);
    $installedDevices = (int) ($progress['installed'] ?? 0);
    $verifiedDevices = (int) ($progress['verified'] ?? 0);
    if ($totalDevices !== $deviceCount) {
        throw new RuntimeException('The per-device installation count does not match the approved order.');
    }
    $allInstalled = $installedDevices === $totalDevices;
    $allVerified = $verifiedDevices === $totalDevices;
    $newStatus = $allVerified ? 'Customer Handoff Completed' : 'Installation In Progress';

    if ($sequence === 1) {
        $parentSql = $allInstalled
            ? "UPDATE crm_lead_closure_forms
               SET customer_id = ?, customer_vehicle_id = ?, customer_installation_id = ?, device_id = ?,
                   installation_datetime = ?, installation_remarks = ?, completion_evidence = ?,
                   installation_verified = 1, submission_status = ?,
                   installation_completed_by_user_id = ?, installation_completed_at = CURRENT_TIMESTAMP
               WHERE id = ? AND installation_person_user_id = ? AND submission_status = 'Installation In Progress'"
            : "UPDATE crm_lead_closure_forms
               SET customer_id = ?, customer_vehicle_id = ?, customer_installation_id = ?, device_id = ?,
                   installation_datetime = ?, installation_remarks = ?, completion_evidence = ?,
                   installation_verified = 1, submission_status = 'Installation In Progress'
               WHERE id = ? AND installation_person_user_id = ? AND submission_status = 'Installation In Progress'";
        $parentUpdate = $conn->prepare($parentSql);
        if (!$parentUpdate) {
            throw new RuntimeException('Unable to prepare primary installation link update: ' . $conn->error);
        }
        if ($allInstalled) {
            $parentUpdate->bind_param(
                'iiiissssiii',
                $customerId,
                $vehicleDetailsId,
                $installationId,
                $deviceId,
                $dateTimeValue,
                $remarks,
                $evidence,
                $newStatus,
                $completedByUserId,
                $closureId,
                $userId
            );
        } else {
            $parentUpdate->bind_param(
                'iiiisssii',
                $customerId,
                $vehicleDetailsId,
                $installationId,
                $deviceId,
                $dateTimeValue,
                $remarks,
                $evidence,
                $closureId,
                $userId
            );
        }
    } else {
        $parentSql = $allInstalled
            ? "UPDATE crm_lead_closure_forms
               SET submission_status = ?, installation_completed_by_user_id = ?,
                   installation_completed_at = CURRENT_TIMESTAMP
               WHERE id = ? AND installation_person_user_id = ? AND submission_status = 'Installation In Progress'"
            : "UPDATE crm_lead_closure_forms
               SET submission_status = 'Installation In Progress'
               WHERE id = ? AND installation_person_user_id = ? AND submission_status = 'Installation In Progress'";
        $parentUpdate = $conn->prepare($parentSql);
        if (!$parentUpdate) {
            throw new RuntimeException('Unable to prepare installation order progress update: ' . $conn->error);
        }
        if ($allInstalled) {
            $parentUpdate->bind_param(
                'siii',
                $newStatus,
                $completedByUserId,
                $closureId,
                $userId
            );
        } else {
            $parentUpdate->bind_param('ii', $closureId, $userId);
        }
    }
    if (!$parentUpdate->execute()) {
        $error = $parentUpdate->error;
        $parentUpdate->close();
        throw new RuntimeException('Unable to update installation order progress: ' . $error);
    }
    if ($parentUpdate->affected_rows !== 1 && ($sequence === 1 || $allInstalled)) {
        $parentUpdate->close();
        throw new DomainException('The installation order changed while this device was being completed. Refresh My Installations.');
    }
    $parentUpdate->close();
    if ($newStatus === 'Customer Handoff Completed') {
        crmLeadSetLifecycleStage(
            $conn,
            (int) $closure['lead_id'],
            'Converted Lead',
            $currentUser
        );
    }

    $oldSnapshot = crmClosureSnapshot($closure);
    $deviceSnapshot = $oldSnapshot + [
        'device_sequence' => $sequence,
        'vehicle_number' => $vehicleNumber,
        'vehicle_type_id' => $vehicleTypeId,
        'device_type_id' => $deviceTypeId,
        'sale_amount' => (float) $saleAmount,
        'device_id' => $deviceId,
        'customer_vehicle_id' => $vehicleDetailsId,
        'customer_installation_id' => $installationId,
        'installation_datetime' => $dateTimeValue,
        'installation_remarks' => $remarks,
        'completion_evidence' => $evidence,
        'installation_verified' => 1
    ];
    crmClosureWriteHistory(
        $conn,
        (int) $closure['lead_id'],
        'Installation Device Completed',
        $oldSnapshot,
        ['closure_id' => $closureId] + $deviceSnapshot,
        $currentUser
    );
    if ($allInstalled) {
        $completedSnapshot = $oldSnapshot;
        $completedSnapshot['customer_id'] = $customerId;
        $completedSnapshot['submission_status'] = $newStatus;
        crmClosureWriteHistory(
            $conn,
            (int) $closure['lead_id'],
            'All Installation Devices Completed',
            $oldSnapshot,
            ['closure_id' => $closureId] + $completedSnapshot,
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
    }

    return [
        'message' => $allInstalled
            ? 'All devices are installed and submitted for Customer Management verification.'
            : 'Device ' . $sequence . ' of ' . $deviceCount . ' installed. ' .
                ($deviceCount - $installedDevices) . ' device(s) remain.',
        'data' => [
            'closure_id' => $closureId,
            'customer_id' => $customerId,
            'customer_vehicle_id' => $vehicleDetailsId,
            'customer_installation_id' => $installationId,
            'device_id' => $deviceId,
            'payment_id' => $paymentId,
            'device_sequence' => $sequence,
            'total_devices' => $totalDevices,
            'installed_devices' => $installedDevices,
            'pending_devices' => $totalDevices - $installedDevices,
            'verification_submission_id' => $verificationSubmissionId,
            'handoff_completed' => $allVerified
        ]
    ];
}
?>
