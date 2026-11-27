<?php

require_once __DIR__ . '/../../utils/notifications.php';

require_once __DIR__ . '/../crm_followups/common.php';
require_once __DIR__ . '/../../utils/payment_modes.php';
require_once __DIR__ . '/../../utils/transaction_ids.php';

function crmClosureSimNumberIsOptional(string $deviceType): bool {
    return strtolower(trim($deviceType)) === 'bharat101 - ais 140';
}

function crmClosureValidateInstallationPayment(object $data, float $saleAmount): array {
    if ($saleAmount <= 0 || $saleAmount > 9999999999.99) {
        throw new DomainException('Enter a valid Total Sale Amount for this vehicle.');
    }

    $totalAmountInput = trim((string) ($data->total_amount ?? ''));
    if ($totalAmountInput === '' || !is_numeric($totalAmountInput) ||
        (float) $totalAmountInput <= 0 || (float) $totalAmountInput > 9999999999.99) {
        throw new DomainException('Enter a valid Total Amount for this vehicle.');
    }
    $totalAmount = round((float) $totalAmountInput, 2);
    $saleAmount = round($saleAmount, 2);
    if (abs($totalAmount - $saleAmount) > 0.005) {
        throw new DomainException('Total Sale Amount and Total Amount must be equal.');
    }

    $amountPaidInput = trim((string) ($data->amount_paid ?? '0'));
    if ($amountPaidInput === '') {
        $amountPaidInput = '0';
    }
    if (!is_numeric($amountPaidInput) || (float) $amountPaidInput < 0 ||
        (float) $amountPaidInput > $totalAmount) {
        throw new DomainException('Amount Paid must be between zero and Total Amount.');
    }
    $amountPaid = round((float) $amountPaidInput, 2);
    $paymentStatus = $amountPaid <= 0
        ? 'Not Paid'
        : ($amountPaid >= $totalAmount ? 'Paid' : 'Partially Paid');

    $paymentMode = trim((string) ($data->payment_mode ?? ''));
    if ($paymentMode !== '' && !in_array($paymentMode, getPaymentModes(), true)) {
        throw new DomainException('Select a valid Payment Method.');
    }
    if ($amountPaid > 0 && $paymentMode === '') {
        throw new DomainException('Payment Method is required when Amount Paid is greater than zero.');
    }
    try {
        $transactionId = normalizeTransactionId($data->transaction_id ?? '');
    } catch (InvalidArgumentException $error) {
        throw new DomainException($error->getMessage());
    }
    if ($amountPaid > 0 && $paymentMode !== 'Cash' && $transactionId === '') {
        throw new DomainException('Transaction ID is required for the selected Payment Method.');
    }
    if ($paymentMode === 'Cash') {
        $transactionId = '';
    }

    return [
        'total_sale_amount' => $saleAmount,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_pending' => round($totalAmount - $amountPaid, 2),
        'payment_status' => $paymentStatus,
        'payment_mode' => $paymentMode,
        'transaction_id' => $transactionId
    ];
}

function crmClosureCreateInstallationPayment(
    mysqli $conn,
    int $customerId,
    int $vehicleId,
    array $payment,
    string $requestKey,
    array $currentUser
): int {
    $stmt = $conn->prepare(
        "INSERT INTO customer_payments
            (customer_id, vehicle_id, total_sale_amount, transaction_id, payment_mode,
             device_charge, software_charge, technician_charge, sim_charge, courier_charge,
             total_amount, amount_paid, amount_pending, payment_status)
         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), 0, 0, 0, 0, 0, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Customer Management payment record: ' . $conn->error);
    }
    $totalSaleAmount = (float) $payment['total_sale_amount'];
    $transactionId = (string) $payment['transaction_id'];
    $paymentMode = (string) $payment['payment_mode'];
    $totalAmount = (float) $payment['total_amount'];
    $amountPaid = (float) $payment['amount_paid'];
    $amountPending = (float) $payment['amount_pending'];
    $paymentStatus = (string) $payment['payment_status'];
    $stmt->bind_param(
        'iidssddds',
        $customerId,
        $vehicleId,
        $totalSaleAmount,
        $transactionId,
        $paymentMode,
        $totalAmount,
        $amountPaid,
        $amountPending,
        $paymentStatus
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create Customer Management payment record: ' . $error);
    }
    $paymentId = (int) $conn->insert_id;
    $stmt->close();

    writeCreatedFields($conn, $paymentId, 'Customer Payment', [
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'total_sale_amount' => $totalSaleAmount,
        'transaction_id' => $transactionId ?: null,
        'payment_mode' => $paymentMode ?: null,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_pending' => $amountPending,
        'payment_status' => $paymentStatus
    ], $currentUser);
    if ($transactionId !== '') {
        try {
            reserveTransactionId($conn, $transactionId, 'customer_payments', (string) $paymentId);
        } catch (InvalidArgumentException $error) {
            throw new DomainException($error->getMessage());
        }
    }

    if ($amountPaid > 0) {
        $history = $conn->prepare(
            "INSERT INTO customer_payment_transactions
                (payment_id, customer_id, request_key, amount, payment_mode, transaction_id,
                 payment_date, payment_status, notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), CURDATE(), ?, 'Payment captured during CRM installation', ?)"
        );
        if (!$history) {
            throw new RuntimeException('Unable to prepare installation payment history: ' . $conn->error);
        }
        $createdByUserId = (int) ($currentUser['user_id'] ?? 0);
        $history->bind_param(
            'iisdsssi',
            $paymentId,
            $customerId,
            $requestKey,
            $amountPaid,
            $paymentMode,
            $transactionId,
            $paymentStatus,
            $createdByUserId
        );
        if (!$history->execute()) {
            $error = $history->error;
            $history->close();
            throw new RuntimeException('Unable to save installation payment history: ' . $error);
        }
        $history->close();
    }

    return $paymentId;
}

function crmClosureParseInstallationDateTime(string $value): ?DateTimeImmutable {
    $dateTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value,
        new DateTimeZone('Asia/Kolkata')
    );
    if (!$dateTime || $dateTime->format('Y-m-d\TH:i') !== $value) {
        return null;
    }

    return $dateTime;
}

function crmClosureLeadForUpdate($conn, $leadId, $userId, $canViewAll) {
    $activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn)
        ? ' AND l.deleted_at IS NULL'
        : '';
    $sql =
        "SELECT l.id, l.lead_name, l.mobile_no, l.alternate_mobile_no, l.email,
                l.location, l.pincode, l.campaign_id, l.assigned_user_id,
                l.lead_status_id, s.status_name, s.status_type, s.status AS status_state
         FROM crm_leads l
         INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
         WHERE l.id = ?{$activeLeadCondition}";
    if (!$canViewAll) {
        $sql .= ' AND l.assigned_user_id = ?';
    }
    $sql .= ' LIMIT 1 FOR UPDATE';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare lead validation: ' . $conn->error);
    }
    if ($canViewAll) {
        $stmt->bind_param('i', $leadId);
    } else {
        $stmt->bind_param('ii', $leadId, $userId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to validate lead access: ' . $error);
    }
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $lead;
}

function crmClosureSnapshot(array $data): array {
    return [
        'platform_id' => (int) ($data['platform_id'] ?? 0),
        'customer_number' => $data['customer_number'] ?? null,
        'username' => $data['username'] ?? null,
        'vehicle_type' => $data['vehicle_type'] ?? null,
        'vehicle_number' => $data['vehicle_number'] ?? null,
        'commitment_date' => $data['commitment_date'] ?? null,
        'location' => $data['location'] ?? null,
        'pincode' => $data['pincode'] ?? null,
        'device_type' => $data['device_type'] ?? null,
        'device_count' => (int) ($data['device_count'] ?? 0),
        'amount' => $data['amount'] ?? null,
        'amount_status' => $data['amount_status'] ?? null,
        'app_name' => $data['app_name'] ?? null,
        'submission_status' => $data['submission_status'] ?? null,
        'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
        'rejection_reason' => $data['rejection_reason'] ?? null,
        'installation_person_type' => $data['installation_person_type'] ?? null,
        'installation_person_id' => isset($data['installation_person_id']) ? (int) $data['installation_person_id'] : null,
        'installation_person_user_id' => isset($data['installation_person_user_id']) ? (int) $data['installation_person_user_id'] : null,
        'installation_assigned_by_user_id' => isset($data['installation_assigned_by_user_id']) ? (int) $data['installation_assigned_by_user_id'] : null,
        'installation_assigned_at' => $data['installation_assigned_at'] ?? null,
        'customer_vehicle_id' => isset($data['customer_vehicle_id']) ? (int) $data['customer_vehicle_id'] : null,
        'customer_installation_id' => isset($data['customer_installation_id']) ? (int) $data['customer_installation_id'] : null,
        'device_id' => isset($data['device_id']) ? (int) $data['device_id'] : null,
        'installation_datetime' => $data['installation_datetime'] ?? null,
        'installation_remarks' => $data['installation_remarks'] ?? null,
        'completion_evidence' => $data['completion_evidence'] ?? null,
        'installation_verified' => isset($data['installation_verified']) ? (int) $data['installation_verified'] : null
    ];
}

function crmClosureRequireInstallationAssignmentSchema($conn): void {
    $requirements = [
        'technicians' => ['user_id', 'location'],
        'dealers' => ['user_id', 'location'],
        'crm_lead_closure_forms' => [
            'username',
            'installation_person_type',
            'installation_person_id',
            'installation_person_user_id',
            'installation_assigned_by_user_id',
            'installation_assigned_at',
            'customer_vehicle_id',
            'customer_installation_id',
            'device_id',
            'installation_datetime',
            'installation_remarks',
            'completion_evidence',
            'installation_verified',
            'installation_completed_by_user_id',
            'installation_completed_at'
        ],
        'customer_installations' => [
            'vehicle_id',
            'installation_datetime',
            'installation_remarks',
            'completion_evidence',
            'installation_verified',
            'crm_closure_id'
        ]
    ];
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            $result = $conn->query(
                "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
            );
            if (!$result) {
                $error = $conn->error;
                $conn->close();
                sendResponse(false, 'Unable to verify installer assignment schema: ' . $error, [], [], 500);
            }
            if ($result->num_rows === 0) {
                $conn->close();
                $migration = $table === 'crm_lead_closure_forms' && $column === 'username'
                    ? 'backend/migrations/crm_lead_converter_customer_number.sql'
                    : (in_array($column, [
                        'customer_vehicle_id',
                        'customer_installation_id',
                        'device_id',
                        'installation_datetime',
                        'installation_remarks',
                        'completion_evidence',
                        'installation_verified',
                        'installation_completed_by_user_id',
                        'installation_completed_at',
                        'crm_closure_id'
                    ], true)
                        ? 'backend/migrations/crm_installation_location_handoff.sql'
                        : 'backend/migrations/crm_installation_assignment_workflow.sql');
                sendResponse(
                    false,
                    "Installation workflow requires {$table}.{$column}. Review and apply {$migration}.",
                    [],
                    [],
                    503
                );
            }
        }
        $statusResult = $conn->query("SHOW COLUMNS FROM crm_lead_closure_forms LIKE 'submission_status'");
        if (!$statusResult) {
            $error = $conn->error;
            $conn->close();
            sendResponse(false, 'Unable to verify installation status schema: ' . $error, [], [], 500);
        }
        $statusColumn = $statusResult->fetch_assoc();
        if (!$statusColumn || strpos((string) $statusColumn['Type'], 'Customer Handoff Completed') === false) {
            $conn->close();
            sendResponse(
                false,
                'Installation workflow status values are missing. Review and apply backend/migrations/crm_installation_location_handoff.sql.',
                [],
                [],
                503
            );
        }
    }
}

function crmClosureRequireMultiDeviceSchema($conn): void {
    $table = 'crm_closure_installation_devices';
    $tableResult = $conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$tableResult) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to verify multi-device installation schema: ' . $error, [], [], 500);
    }
    if ($tableResult->num_rows === 0) {
        $conn->close();
        sendResponse(
            false,
            "Multi-device installations require {$table}. Review and apply backend/migrations/crm_multi_device_installations.sql.",
            [],
            [],
            503
        );
    }

    $requiredColumns = [
        'id',
        'closure_id',
        'customer_id',
        'device_sequence',
        'installation_status',
        'vehicle_number',
        'vehicle_type_id',
        'device_model_id',
        'sale_amount',
        'device_id',
        'imei_no',
        'sim_id_1',
        'sim_no_1',
        'sim_id_2',
        'sim_no_2',
        'validity_months',
        'installation_datetime',
        'installation_remarks',
        'completion_evidence',
        'installation_verified',
        'customer_vehicle_id',
        'customer_installation_id',
        'completed_by_user_id',
        'completed_at',
        'verification_submission_id',
        'verification_status',
        'verification_rejection_reason'
    ];
    foreach ($requiredColumns as $column) {
        $result = $conn->query(
            "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
        );
        if (!$result) {
            $error = $conn->error;
            $conn->close();
            sendResponse(false, 'Unable to verify multi-device installation schema: ' . $error, [], [], 500);
        }
        if ($result->num_rows === 0) {
            $conn->close();
            $migration = in_array(
                $column,
                ['verification_submission_id', 'verification_status', 'verification_rejection_reason'],
                true
            )
                ? 'backend/migrations/crm_installation_customer_verification.sql'
                : 'backend/migrations/crm_multi_device_installations.sql';
            sendResponse(
                false,
                "Multi-device installations require {$table}.{$column}. Review and apply {$migration}.",
                [],
                [],
                503
            );
        }
    }

    $verificationResult = $conn->query(
        "SHOW TABLES LIKE 'customer_payment_verification_submissions'"
    );
    if (!$verificationResult) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to verify Customer Management verification schema: ' . $error, [], [], 500);
    }
    if ($verificationResult->num_rows === 0) {
        $conn->close();
        sendResponse(
            false,
            'CRM installation verification requires backend/migrations/customer_payment_verification.sql.',
            [],
            [],
            503
        );
    }
    foreach ([
        'customers' => ['verification_status', 'verification_submission_id'],
        'customer_vehicle_details' => ['verification_submission_id']
    ] as $tableName => $columns) {
        foreach ($columns as $columnName) {
            $columnResult = $conn->query(
                "SHOW COLUMNS FROM `{$tableName}` LIKE '" . $conn->real_escape_string($columnName) . "'"
            );
            if (!$columnResult) {
                $error = $conn->error;
                $conn->close();
                sendResponse(false, 'Unable to verify Customer Management verification columns: ' . $error, [], [], 500);
            }
            if ($columnResult->num_rows === 0) {
                $conn->close();
                sendResponse(
                    false,
                    "CRM installation verification requires {$tableName}.{$columnName}. Review and apply backend/migrations/customer_payment_verification.sql.",
                    [],
                    [],
                    503
                );
            }
        }
    }
}

function crmClosureRequireAdminRole(int $userId): void {
    $conn = (new Database())->getConnection();
    if (!$conn) {
        sendResponse(false, 'Database connection failed.', [], [], 500);
    }
    $stmt = $conn->prepare(
        'SELECT COALESCE(NULLIF(r.role_name, \'\'), u.role, \'\') AS role_name
         FROM users u LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? LIMIT 1'
    );
    if (!$stmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to verify installation administration role: ' . $error, [], [], 500);
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        sendResponse(false, 'Unable to verify installation administration role: ' . $error, [], [], 500);
    }
    $roleName = strtolower(trim((string) ($stmt->get_result()->fetch_assoc()['role_name'] ?? '')));
    $stmt->close();
    $conn->close();
    if (!in_array($roleName, ['admin', 'super admin', 'super_admin'], true)) {
        sendResponse(false, 'Only Admin or Super Admin users can approve or assign installations.', [], [], 403);
    }
}

function crmClosureValidateInstaller($conn, string $personType, int $personId, int $userId): array {
    $people = [
        'Technician' => [
            'table' => 'technicians',
            'name' => 'technician_name',
            'installation_status' => null
        ],
        'Onsite Dealer' => [
            'table' => 'dealers',
            'name' => 'dealer_name',
            'installation_status' => 'Onsite'
        ],
        'Offsite Dealer' => [
            'table' => 'dealers',
            'name' => 'dealer_name',
            'installation_status' => 'Offsite'
        ]
    ];
    if (!isset($people[$personType]) || $personId <= 0 || $userId <= 0) {
        throw new DomainException('Select a valid installation person and login account.');
    }

    $accountStmt = $conn->prepare(
        "SELECT u.id
         FROM users u
         WHERE u.id = ? AND LOWER(COALESCE(u.status, 'active')) = 'active'
         LIMIT 1 FOR UPDATE"
    );
    if (!$accountStmt) {
        throw new RuntimeException('Unable to prepare installer account validation: ' . $conn->error);
    }
    $accountStmt->bind_param('i', $userId);
    if (!$accountStmt->execute()) {
        $error = $accountStmt->error;
        $accountStmt->close();
        throw new RuntimeException('Unable to validate installer account: ' . $error);
    }
    $account = $accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();
    if (!$account) {
        throw new DomainException('The linked installation login account is inactive or missing.');
    }
    if (!userHasPermission($userId, 'crm_installations.view')) {
        throw new DomainException('The linked login is not authorized to view assigned installations.');
    }

    $person = $people[$personType];
    $personSql = "SELECT id, user_id, {$person['name']}
                  FROM {$person['table']}
                  WHERE id = ?
                    AND LOWER(COALESCE(status, 'active')) = 'active'";
    if ($person['installation_status'] !== null) {
        $personSql .= ' AND installation_status = ?';
    }
    $personSql .= ' LIMIT 1 FOR UPDATE';
    $personStmt = $conn->prepare($personSql);
    if (!$personStmt) {
        throw new RuntimeException('Unable to prepare installation person validation: ' . $conn->error);
    }
    if ($person['installation_status'] !== null) {
        $personStmt->bind_param('is', $personId, $person['installation_status']);
    } else {
        $personStmt->bind_param('i', $personId);
    }
    if (!$personStmt->execute()) {
        $error = $personStmt->error;
        $personStmt->close();
        throw new RuntimeException('Unable to validate installation person: ' . $error);
    }
    $personRow = $personStmt->get_result()->fetch_assoc();
    $personStmt->close();
    if (!$personRow) {
        throw new DomainException('The selected installation person is inactive or no longer available.');
    }
    if (empty($personRow['user_id'])) {
        throw new DomainException('This installation person has no linked login account.');
    }
    if ((int) $personRow['user_id'] !== $userId) {
        throw new DomainException('The selected login account does not match this installation person\'s linked account.');
    }

    $linkCountStmt = $conn->prepare(
        'SELECT
            (SELECT COUNT(*) FROM technicians WHERE user_id = ?) +
            (SELECT COUNT(*) FROM dealers WHERE user_id = ?) AS linked_count'
    );
    if (!$linkCountStmt) {
        throw new RuntimeException('Unable to prepare installer account uniqueness check: ' . $conn->error);
    }
    $linkCountStmt->bind_param('ii', $userId, $userId);
    if (!$linkCountStmt->execute()) {
        $error = $linkCountStmt->error;
        $linkCountStmt->close();
        throw new RuntimeException('Unable to validate installer account uniqueness: ' . $error);
    }
    $linkedCount = (int) ($linkCountStmt->get_result()->fetch_assoc()['linked_count'] ?? 0);
    $linkCountStmt->close();
    if ($linkedCount !== 1) {
        throw new DomainException('This login account is linked to multiple installation personnel records.');
    }

    return [
        'table' => $person['table'],
        'id' => $personId,
        'user_id' => $userId,
        'name' => $personRow[$person['name']] ?? null
    ];
}

function crmClosureNotifyApprovers($conn, int $closureId, string $leadName): void {
            $result = $conn->query(
                "SELECT id
                 FROM users
                 WHERE LOWER(COALESCE(status, 'active')) = 'active'
                 ORDER BY id"
            );
            if (!$result) {
                throw new RuntimeException('Unable to load active approval recipients: ' . $conn->error);
            }
            $notified = 0;
            while ($row = $result->fetch_assoc()) {
                $approverId = (int) $row['id'];
                if (!userHasPermission($approverId, 'crm_closures.approve')) {
                    continue;
                }
                crmClosureNotify(
                    $conn,
                    $approverId,
                    'Lead conversion awaiting approval',
                    'A conversion request for ' . $leadName . ' is waiting for review.',
                    'lead_closure',
                    $closureId
                );
                $notified++;
            }
            if ($notified === 0) {
                throw new RuntimeException('No active user is authorized to review lead conversion submissions.');
            }
}

function crmClosureIsActiveMasterValue($conn, $table, $column, $value) {
    $allowedColumns = [
        'vehicle_types' => 'vehicle_type',
        'device_types' => 'device_type'
    ];
    if (!isset($allowedColumns[$table]) || $allowedColumns[$table] !== $column) {
        throw new InvalidArgumentException('Invalid CRM closure master lookup.');
    }
    $statusResult = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
    if (!$statusResult) {
        throw new RuntimeException('Unable to verify closure form master data: ' . $conn->error);
    }
    $statusCondition = $statusResult->num_rows > 0
        ? " AND (status = 'Active' OR status IS NULL OR status = '')"
        : '';
    $stmt = $conn->prepare(
        "SELECT 1 FROM `{$table}` WHERE `{$column}` = ?{$statusCondition} LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare closure form master validation: ' . $conn->error);
    }
    $stmt->bind_param('s', $value);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to validate closure form master data: ' . $error);
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}
?>
