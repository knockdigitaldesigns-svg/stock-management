<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';
require_once '../../../utils/customer_verification_display.php';

function customerVerificationLookup(mysqli $conn, string $table, string $labelColumn, int $id): ?string
{
    $allowedLookups = [
        'platforms' => 'platform_name',
        'vehicle_types' => 'vehicle_type',
        'device_types' => 'device_type',
        'sim_validities' => 'months',
        'technicians' => 'technician_name',
        'dealers' => 'dealer_name',
        'lead_closures' => 'lead_closure_name'
    ];
    if ($id <= 0 || ($allowedLookups[$table] ?? null) !== $labelColumn) {
        return null;
    }
    $stmt = $conn->prepare("SELECT {$labelColumn} AS display_name FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification detail lookup.');
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve verification detail name.');
    }
    $value = $stmt->get_result()->fetch_assoc()['display_name'] ?? null;
    $stmt->close();
    return $value !== null && trim((string) $value) !== '' ? (string) $value : null;
}

function customerVerificationMergeMissing(array $values, array $fallback): array
{
    foreach ($fallback as $field => $value) {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            continue;
        }
        if (!array_key_exists($field, $values)
            || $values[$field] === null
            || (is_string($values[$field]) && trim($values[$field]) === '')) {
            $values[$field] = $value;
        }
    }

    return $values;
}

function customerVerificationFirstValue(array $values, array $fields)
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $values)
            && is_scalar($values[$field])
            && trim((string) $values[$field]) !== '') {
            return $values[$field];
        }
    }

    return null;
}

function customerVerificationNormalizePayment(array $payment): array
{
    return customerVerificationMergeMissing($payment, [
        'total_sale_amount' => $payment['totalSaleAmount'] ?? null,
        'transaction_id' => $payment['transactionId'] ?? null,
        'payment_mode' => $payment['paymentMode'] ?? null,
        'payment_date' => $payment['paymentDate'] ?? null,
        'device_charge' => $payment['deviceCharge'] ?? null,
        'software_charge' => $payment['softwareCharge'] ?? null,
        'technician_charge' => $payment['technicianCharge'] ?? null,
        'sim_charge' => $payment['simCharge'] ?? null,
        'courier_charge' => $payment['courierCharge'] ?? null,
        'total_amount' => $payment['totalAmount'] ?? null,
        'amount_paid' => $payment['amountPaid'] ?? null,
        'amount_pending' => $payment['amountPending'] ?? null,
        'payment_status' => $payment['paymentStatus'] ?? null,
        'payment_step' => $payment['paymentStep'] ?? null,
        'cash_recipient_type' => $payment['cashRecipientType'] ?? null,
        'cash_recipient_name' => $payment['cashRecipientName'] ?? $payment['recipientName'] ?? null,
        'cash_to_technician' => $payment['cashToTechnician'] ?? null,
        'cash_to_dealer' => $payment['cashToDealer'] ?? null,
        'cash_to_et' => $payment['cashToEt'] ?? null
    ]);
}

function customerVerificationLoadVehicle(
    mysqli $conn,
    int $customerId,
    int $vehicleId
): ?array {
    if ($customerId <= 0 || $vehicleId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT cvd.id, cvd.customer_id, cvd.vehicle_no, cvd.vehicle_type_id,
                vt.vehicle_type, cvd.device_model_id, dt.device_type AS device_model,
                cvd.imei_no, cvd.sim_no_1, cvd.sim_no_2, cvd.validity_months
         FROM customer_vehicle_details cvd
         LEFT JOIN vehicle_types vt ON vt.id = cvd.vehicle_type_id
         LEFT JOIN device_types dt ON dt.id = cvd.device_model_id
         WHERE cvd.id = ? AND cvd.customer_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification vehicle lookup.');
    }
    $stmt->bind_param('ii', $vehicleId, $customerId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve verification vehicle details.');
    }
    $vehicle = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $vehicle;
}

function customerVerificationLoadInstallation(mysqli $conn, int $customerId, int $vehicleId): ?array
{
    if ($customerId <= 0 || $vehicleId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT ci.installation_person_type, ci.installation_person_id,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
                        THEN CASE
                            WHEN LOWER(TRIM(COALESCE(d.installation_status, ''))) = 'offsite'
                                THEN 'Offsite Dealer'
                            ELSE 'Onsite Dealer'
                        END
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) = 'technician'
                        THEN 'Technician'
                    WHEN TRIM(COALESCE(ci.installation_person_type, '')) = ''
                         AND ci.installation_person_id IS NULL THEN 'Direct'
                    ELSE ci.installation_person_type
                END AS installation_type,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
                        THEN d.dealer_name
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) = 'technician'
                        THEN t.technician_name
                    ELSE NULL
                END AS installation_person,
                ci.lead_closure_id, ci.lead_closure_by_user_id,
                COALESCE(NULLIF(TRIM(closure_user.employee_name), ''), NULLIF(TRIM(closure_user.username), ''), lc.lead_closure_name) AS lead_closure,
                COALESCE(NULLIF(TRIM(closure_user.employee_name), ''), NULLIF(TRIM(closure_user.username), '')) AS lead_closure_by_user,
                ci.installation_date
         FROM customer_installations ci
         LEFT JOIN dealers d
            ON d.id = ci.installation_person_id
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
         LEFT JOIN technicians t
            ON t.id = ci.installation_person_id
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) = 'technician'
         LEFT JOIN lead_closures lc ON lc.id = ci.lead_closure_id
         LEFT JOIN users closure_user ON closure_user.id = ci.lead_closure_by_user_id
         WHERE ci.customer_id = ? AND ci.vehicle_id = ?
         ORDER BY ci.id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification installation lookup.');
    }
    $stmt->bind_param('ii', $customerId, $vehicleId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve verification installation details.');
    }
    $installation = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $installation;
}

function customerVerificationLoadCrmInstallationContext(
    mysqli $conn,
    int $closureId,
    int $installationDeviceId
): ?array {
    if ($closureId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT cf.platform_id, p.platform_name, cf.location,
                COALESCE(
                    NULLIF(TRIM(assignee.employee_name), ''),
                    NULLIF(TRIM(assignee.username), ''),
                    NULLIF(TRIM(creator.employee_name), ''),
                    NULLIF(TRIM(creator.username), ''),
                    NULLIF(TRIM(submitter.employee_name), ''),
                    NULLIF(TRIM(submitter.username), '')
                ) AS lead_closure_by,
                COALESCE(
                    NULLIF(TRIM(ci.installation_person_type), ''),
                    NULLIF(TRIM(cf.installation_person_type), '')
                ) AS installation_person_type,
                COALESCE(ci.installation_person_id, cf.installation_person_id) AS installation_person_id,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) LIKE '%dealer%'
                        THEN CASE
                            WHEN LOWER(TRIM(COALESCE(d.installation_status, ''))) = 'offsite'
                                THEN 'Offsite Dealer'
                            ELSE 'Onsite Dealer'
                        END
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) = 'technician'
                        THEN 'Technician'
                    WHEN COALESCE(ci.installation_person_id, cf.installation_person_id) IS NULL
                        THEN 'Direct'
                    ELSE COALESCE(ci.installation_person_type, cf.installation_person_type)
                END AS installation_type,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) LIKE '%dealer%'
                        THEN d.dealer_name
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) = 'technician'
                        THEN t.technician_name
                    ELSE NULL
                END AS installation_person,
                COALESCE(ci.installation_date, DATE(device.installation_datetime), DATE(cf.installation_datetime))
                    AS installation_date
         FROM crm_lead_closure_forms cf
         LEFT JOIN platforms p ON p.id = cf.platform_id
         LEFT JOIN crm_leads crm_lead ON crm_lead.id = cf.lead_id
         LEFT JOIN users assignee ON assignee.id = crm_lead.assigned_user_id
         LEFT JOIN users creator ON creator.id = crm_lead.created_by_user_id
         LEFT JOIN users submitter ON submitter.id = cf.submitted_by_user_id
         LEFT JOIN crm_closure_installation_devices device
            ON device.id = ? AND device.closure_id = cf.id
         LEFT JOIN customer_installations ci
            ON ci.id = COALESCE(
                NULLIF(device.customer_installation_id, 0),
                NULLIF(cf.customer_installation_id, 0),
                (SELECT existing_installation.id
                 FROM customer_installations existing_installation
                 WHERE existing_installation.crm_closure_id = cf.id
                 ORDER BY existing_installation.id DESC
                 LIMIT 1)
            )
         LEFT JOIN dealers d
            ON d.id = COALESCE(ci.installation_person_id, cf.installation_person_id)
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) LIKE '%dealer%'
         LEFT JOIN technicians t
            ON t.id = COALESCE(ci.installation_person_id, cf.installation_person_id)
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, cf.installation_person_type, ''))) = 'technician'
         WHERE cf.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare CRM installation verification lookup.');
    }
    $stmt->bind_param('ii', $installationDeviceId, $closureId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve CRM installation verification details.');
    }
    $context = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $context;
}

function customerVerificationLoadLegacyInstallationMap(mysqli $conn, int $customerId): array
{
    if ($customerId <= 0) {
        return [];
    }

    $vehicleStmt = $conn->prepare(
        'SELECT id FROM customer_vehicle_details WHERE customer_id = ? ORDER BY id ASC'
    );
    if (!$vehicleStmt) {
        throw new RuntimeException('Unable to prepare legacy verification vehicle lookup.');
    }
    $vehicleStmt->bind_param('i', $customerId);
    if (!$vehicleStmt->execute()) {
        $vehicleStmt->close();
        throw new RuntimeException('Unable to resolve legacy verification vehicles.');
    }
    $vehicleIds = [];
    $vehicleResult = $vehicleStmt->get_result();
    while ($vehicle = $vehicleResult->fetch_assoc()) {
        $vehicleIds[] = (int) $vehicle['id'];
    }
    $vehicleStmt->close();

    $installationStmt = $conn->prepare(
        "SELECT ci.installation_person_type, ci.installation_person_id,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
                        THEN CASE
                            WHEN LOWER(TRIM(COALESCE(d.installation_status, ''))) = 'offsite'
                                THEN 'Offsite Dealer'
                            ELSE 'Onsite Dealer'
                        END
                    WHEN TRIM(COALESCE(ci.installation_person_type, '')) = ''
                         AND ci.installation_person_id IS NULL THEN 'Direct'
                    ELSE ci.installation_person_type
                END AS installation_type,
                CASE
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
                        THEN d.dealer_name
                    WHEN LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) = 'technician'
                        THEN t.technician_name
                    ELSE NULL
                END AS installation_person,
                ci.lead_closure_id, ci.lead_closure_by_user_id,
                COALESCE(NULLIF(TRIM(closure_user.employee_name), ''), NULLIF(TRIM(closure_user.username), ''), lc.lead_closure_name) AS lead_closure,
                COALESCE(NULLIF(TRIM(closure_user.employee_name), ''), NULLIF(TRIM(closure_user.username), '')) AS lead_closure_by_user,
                ci.installation_date
         FROM customer_installations ci
         LEFT JOIN dealers d
            ON d.id = ci.installation_person_id
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) LIKE '%dealer%'
         LEFT JOIN technicians t
            ON t.id = ci.installation_person_id
           AND LOWER(TRIM(COALESCE(ci.installation_person_type, ''))) = 'technician'
         LEFT JOIN lead_closures lc ON lc.id = ci.lead_closure_id
         LEFT JOIN users closure_user ON closure_user.id = ci.lead_closure_by_user_id
         WHERE ci.customer_id = ? AND ci.vehicle_id IS NULL
         ORDER BY ci.id ASC"
    );
    if (!$installationStmt) {
        throw new RuntimeException('Unable to prepare legacy verification installation lookup.');
    }
    $installationStmt->bind_param('i', $customerId);
    if (!$installationStmt->execute()) {
        $installationStmt->close();
        throw new RuntimeException('Unable to resolve legacy verification installations.');
    }
    $legacyInstallations = [];
    $installationResult = $installationStmt->get_result();
    while ($installation = $installationResult->fetch_assoc()) {
        $legacyInstallations[] = $installation;
    }
    $installationStmt->close();

    $linkedStmt = $conn->prepare(
        'SELECT DISTINCT vehicle_id
         FROM customer_installations
         WHERE customer_id = ? AND vehicle_id IS NOT NULL'
    );
    if (!$linkedStmt) {
        throw new RuntimeException('Unable to prepare linked verification installation lookup.');
    }
    $linkedStmt->bind_param('i', $customerId);
    if (!$linkedStmt->execute()) {
        $linkedStmt->close();
        throw new RuntimeException('Unable to resolve linked verification installations.');
    }
    $linkedIds = [];
    $linkedResult = $linkedStmt->get_result();
    while ($linked = $linkedResult->fetch_assoc()) {
        $linkedIds[(int) $linked['vehicle_id']] = true;
    }
    $linkedStmt->close();

    $unmatchedVehicleIds = array_values(array_filter(
        $vehicleIds,
        static function (int $vehicleId) use ($linkedIds): bool {
            return !isset($linkedIds[$vehicleId]);
        }
    ));
    if (count($legacyInstallations) !== count($unmatchedVehicleIds)) {
        return [];
    }

    $map = [];
    foreach ($unmatchedVehicleIds as $index => $vehicleId) {
        $map[$vehicleId] = $legacyInstallations[$index];
    }

    return $map;
}

function customerVerificationLoadPayment(
    mysqli $conn,
    int $customerId,
    int $paymentId,
    int $vehicleId
): ?array {
    if ($customerId <= 0 || ($paymentId <= 0 && $vehicleId <= 0)) {
        return null;
    }
    $where = $paymentId > 0
        ? 'cp.id = ? AND cp.customer_id = ?'
        : 'cp.vehicle_id = ? AND cp.customer_id = ?';
    $stmt = $conn->prepare(
        "SELECT cp.id, cp.customer_id, cp.vehicle_id, cp.total_sale_amount,
                cp.transaction_id, cp.payment_mode, cp.device_charge, cp.software_charge,
                cp.technician_charge, cp.sim_charge, cp.courier_charge, cp.total_amount,
                cp.amount_paid, cp.amount_pending, cp.payment_status,
                (
                    SELECT ccc.recipient_type
                    FROM customer_cash_collections ccc
                    WHERE ccc.payment_id = cp.id AND ccc.customer_id = cp.customer_id
                    ORDER BY ccc.created_at DESC, ccc.id DESC
                    LIMIT 1
                ) AS cash_recipient_type,
                (
                    SELECT CASE
                        WHEN ccc.recipient_type = 'Dealer' THEN
                            (SELECT d.dealer_name FROM dealers d WHERE d.id = ccc.recipient_id LIMIT 1)
                        WHEN ccc.recipient_type = 'Technician' THEN
                            (SELECT t.technician_name FROM technicians t WHERE t.id = ccc.recipient_id LIMIT 1)
                        WHEN ccc.recipient_type = 'ET' THEN 'Direct Cash to ET'
                        ELSE NULL
                    END
                    FROM customer_cash_collections ccc
                    WHERE ccc.payment_id = cp.id AND ccc.customer_id = cp.customer_id
                    ORDER BY ccc.created_at DESC, ccc.id DESC
                    LIMIT 1
                ) AS cash_recipient_name
         FROM customer_payments cp
         WHERE {$where}
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification payment lookup.');
    }
    if ($paymentId > 0) {
        $stmt->bind_param('ii', $paymentId, $customerId);
    } else {
        $stmt->bind_param('ii', $vehicleId, $customerId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve verification payment details.');
    }
    $payment = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $payment;
}

function customerVerificationPayment(
    array $payment,
    ?string $vehicleName,
    bool $part2,
    bool $includePaymentStatus = false
): array
{
    $fields = $part2
        ? [
            'Device Charge' => ['device_charge', 'deviceCharge'],
            'Software Charge' => ['software_charge', 'softwareCharge'],
            'Technician Charge' => ['technician_charge', 'technicianCharge'],
            'SIM Charge' => ['sim_charge', 'simCharge'],
            'Courier Charge' => ['courier_charge', 'courierCharge'],
            'Total Amount' => ['total_amount', 'totalAmount'],
            'Amount Paid' => ['amount_paid', 'amountPaid'],
            'Amount Pending' => ['amount_pending', 'amountPending'],
            'Payment Status' => ['payment_status', 'paymentStatus'],
            'Payment Mode' => ['payment_mode', 'paymentMode'],
            'Transaction ID' => ['transaction_id', 'transactionId']
        ]
        : [
            'Total Sale Amount' => ['total_sale_amount', 'totalSaleAmount'],
            'Transaction ID' => ['transaction_id', 'transactionId'],
            'Payment Mode' => ['payment_mode', 'paymentMode']
        ];
    if (!$part2 && $includePaymentStatus) {
        $fields['Payment Status'] = ['payment_status', 'paymentStatus'];
    }
    $display = ['Vehicle' => $vehicleName];
    foreach ($fields as $label => $keys) {
        $display[$label] = customerVerificationFirstValue($payment, $keys);
    }
    if ($part2) {
        $recipient = trim((string) (
            $payment['cash_recipient_name']
            ?? $payment['cashRecipientName']
            ?? $payment['recipient_name']
            ?? $payment['recipientName']
            ?? ''
        ));
        $recipientType = trim((string) ($payment['cash_recipient_type'] ?? $payment['cashRecipientType'] ?? ''));
        if ($recipient === '') {
            if ($recipientType === 'ET') {
                $recipient = 'Direct Cash to ET';
            } elseif ($recipientType !== '') {
                $recipient = $recipientType;
            } elseif (!empty($payment['cash_to_technician']) || !empty($payment['cashToTechnician'])) {
                $recipient = 'Cash to Technician';
            } elseif (!empty($payment['cash_to_dealer']) || !empty($payment['cashToDealer'])) {
                $recipient = 'Cash to Dealer';
            } elseif (!empty($payment['cash_to_et']) || !empty($payment['cashToEt'])) {
                $recipient = 'Direct Cash to ET';
            }
        }
        $display['Cash Collection Recipient'] = $recipient !== '' ? $recipient : null;
    }
    return $display;
}

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireCustomerPaymentVerificationReviewer();
$reviewerId = (int) ($reviewer['user_id'] ?? 0);
$submissionId = (int) ($_GET['id'] ?? 0);
if ($submissionId <= 0) {
    sendResponse(false, 'A valid submission ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'SELECT s.id, s.submitter_user_id, s.existing_customer_id, s.linked_customer_id, s.linked_vehicle_id,
            s.linked_payment_id, s.status, s.submission_data, s.rejection_reason,
            s.submitted_at, s.decided_at, submitter.employee_name AS submitted_by,
            reviewer.employee_name AS reviewed_by
     FROM customer_payment_verification_submissions s
     INNER JOIN users submitter ON submitter.id = s.submitter_user_id
     LEFT JOIN users reviewer ON reviewer.id = s.reviewer_user_id
     WHERE s.id = ? LIMIT 1'
);
$stmt->bind_param('i', $submissionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    $conn->close();
    sendResponse(false, 'Verification submission not found.', [], [], 404);
}
try {
    $row['submission_data'] = decodeCustomerPaymentSubmission((string) $row['submission_data']);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Saved verification submission is invalid.', [], [], 500);
}

$snapshot = $row['submission_data'];
$source = (string) ($snapshot['source'] ?? '');
$crmInstallationContext = $source === 'crm_installation'
    ? customerVerificationLoadCrmInstallationContext(
        $conn,
        (int) ($snapshot['crm_closure_id'] ?? 0),
        (int) ($snapshot['installation_device_id'] ?? 0)
    )
    : null;
$step1Customer = is_array($snapshot['step1'] ?? null) ? $snapshot['step1'] : [];
$customer = customerVerificationMergeMissing(
    array_replace($step1Customer, is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : []),
    $step1Customer
);
$customer = customerVerificationMergeMissing($customer, [
    'platform_id' => $customer['platformId'] ?? null,
    'platform_name' => customerVerificationFirstValue(
        $customer,
        ['platform_name', 'platformName', 'platform', 'software_name', 'software']
    ),
    'primary_mobile_no' => $customer['primaryMobileNo'] ?? null,
    'secondary_mobile_no' => $customer['secondaryMobileNo'] ?? null,
    'location' => customerVerificationFirstValue(
        $customer,
        ['location', 'customer_location', 'customerLocation', 'address']
    ),
    'status' => $customer['customer_status'] ?? $customer['customerStatus'] ?? null
]);
$existingCustomerId = (int) ($row['existing_customer_id'] ?? 0);
$linkedCustomerId = (int) ($row['linked_customer_id'] ?? 0);
if ($existingCustomerId <= 0) {
    $existingCustomerId = (int) ($snapshot['existing_customer_id'] ?? 0);
}
if ($linkedCustomerId <= 0) {
    $linkedCustomerId = (int) ($snapshot['linked_customer_id'] ?? 0);
}
$customerId = $existingCustomerId > 0 ? $existingCustomerId : $linkedCustomerId;
$linkedVehicleId = (int) ($row['linked_vehicle_id'] ?? 0);
$linkedPaymentId = (int) ($row['linked_payment_id'] ?? 0);
if ($linkedVehicleId <= 0) {
    $linkedVehicleId = (int) ($snapshot['linked_vehicle_id'] ?? 0);
}
if ($linkedPaymentId <= 0) {
    $linkedPaymentId = (int) ($snapshot['linked_payment_id'] ?? 0);
}
$actualCustomer = null;
if ($customerId > 0) {
    $customerStmt = $conn->prepare(
        'SELECT c.id, c.platform_id, c.username, c.primary_mobile_no, c.secondary_mobile_no,
                c.email, c.location, c.pincode, c.status
         FROM customers c
         WHERE c.id = ?
         LIMIT 1'
    );
    if (!$customerStmt) {
        throw new RuntimeException('Unable to prepare verification customer lookup.');
    }
    $customerStmt->bind_param('i', $customerId);
    if (!$customerStmt->execute()) {
        $customerStmt->close();
        throw new RuntimeException('Unable to resolve verification customer details.');
    }
    $actualCustomer = $customerStmt->get_result()->fetch_assoc() ?: null;
    $customerStmt->close();
    if ($actualCustomer) {
        $customer = customerVerificationMergeMissing($customer, $actualCustomer);
        if ((int) ($customer['platform_id'] ?? 0) <= 0) {
            $customer['platform_id'] = (int) ($actualCustomer['platform_id'] ?? 0);
        }
    }
}
    if ($crmInstallationContext) {
        $customer = customerVerificationMergeMissing($customer, [
            'platform_id' => $crmInstallationContext['platform_id'] ?? null,
            'platform_name' => $crmInstallationContext['platform_name'] ?? null,
            'location' => $crmInstallationContext['location'] ?? null
        ]);
    }
$platformId = (int) customerVerificationFirstValue($customer, ['platform_id', 'platformId']);
$platformName = trim((string) customerVerificationFirstValue(
    $customer,
    ['platform_name', 'platformName', 'platform', 'software_name', 'software']
));
if ($platformId <= 0 && ctype_digit($platformName)) {
    $platformId = (int) $platformName;
    $platformName = '';
}
if ($platformId > 0) {
    $platformName = customerVerificationLookup($conn, 'platforms', 'platform_name', $platformId) ?? $platformName;
}
$customerName = '';
foreach ([$customer, $snapshot] as $customerSource) {
    foreach (['username', 'customer_name', 'customer_username', 'customerName', 'name'] as $nameField) {
        if (isset($customerSource[$nameField]) && trim((string) $customerSource[$nameField]) !== '') {
            $customerName = trim((string) $customerSource[$nameField]);
            break 2;
        }
    }
}

  $customerDisplay = [
    'Platform' => $platformName !== '' ? $platformName : null,
    'Username' => $customerName !== '' ? $customerName : null,
];
foreach ([
    'Primary Mobile No' => ['primary_mobile_no', 'primaryMobileNo'],
    'Secondary Mobile No' => ['secondary_mobile_no', 'secondaryMobileNo'],
    'Email' => ['email'],
    'Location' => ['location'],
    'Pincode' => ['pincode']
] as $label => $fields) {
    $customerDisplay[$label] = null;
    foreach ($fields as $field) {
        if (isset($customer[$field]) && trim((string) $customer[$field]) !== '') {
            $customerDisplay[$label] = $customer[$field];
            break;
        }
    }
}

$vehicles = $snapshot['vehicles'] ?? [];

if (!is_array($vehicles)) {
    $vehicles = [];
}

$vehicles = array_values($vehicles);

if (!$vehicles && is_array($snapshot['vehicle'] ?? null)) {
    $vehicles[] = $snapshot['vehicle'];
}

if (!$vehicles && is_array($snapshot['step2'] ?? null)) {
    $vehicles[] = $snapshot['step2'];
}

if (!$vehicles && $linkedVehicleId > 0) {
    $vehicles[] = ['vehicle_record_id' => $linkedVehicleId];
}
$topLevelInstallation = $snapshot['installation']
    ?? $snapshot['installationDetails']
    ?? $snapshot['step3']
    ?? null;
$topLevelPayment1 = $snapshot['payment_part1']
    ?? $snapshot['paymentPart1']
    ?? $snapshot['step4']
    ?? null;
$topLevelPayment2 = $snapshot['payment_part2']
    ?? $snapshot['paymentPart2']
    ?? $snapshot['step5']
    ?? null;
if (count($vehicles) === 1 && is_array($vehicles[0])) {
    foreach ([
        'installation' => $topLevelInstallation,
        'payment_part1' => $topLevelPayment1,
        'payment_part2' => $topLevelPayment2
    ] as $field => $topLevelDetails) {
        if (!is_array($topLevelDetails)) {
            continue;
        }
        $vehicleDetails = $vehicles[0][$field] ?? null;
        $vehicles[0][$field] = is_array($vehicleDetails)
            ? customerVerificationMergeMissing($vehicleDetails, $topLevelDetails)
            : $topLevelDetails;
    }
    $snapshot['vehicles'] = $vehicles;
}
if (is_array($topLevelPayment1)) {
    $topLevelPayment1 = customerVerificationNormalizePayment($topLevelPayment1);
}
if (is_array($topLevelPayment2)) {
    $topLevelPayment2 = customerVerificationNormalizePayment($topLevelPayment2);
}
$linkedPayment = null;
if ($source !== 'payment_installment' && $source !== 'courier' && $customerId > 0 && $linkedPaymentId > 0) {
    $linkedPayment = customerVerificationLoadPayment($conn, $customerId, $linkedPaymentId, 0);
    if ($linkedVehicleId <= 0 && $linkedPayment) {
        $linkedVehicleId = (int) ($linkedPayment['vehicle_id'] ?? 0);
    }
}
if (!$vehicles && $linkedVehicleId > 0) {
    $vehicles[] = ['vehicle_record_id' => $linkedVehicleId];
}
if (count($vehicles) === 1 && is_array($vehicles[0])) {
    $snapshotVehicleId = (int) ($vehicles[0]['vehicle_record_id'] ?? $vehicles[0]['id'] ?? 0);
    if ($snapshotVehicleId <= 0 && $linkedVehicleId > 0) {
        $vehicles[0]['vehicle_record_id'] = $linkedVehicleId;
    }
}
$displayVehicles = [];
$displayInstallations = [];
$displayPaymentPart1 = [];
$displayPaymentPart2 = [];
$vehicleAssociations = [];
$legacyInstallationMap = null;
foreach ($vehicles as $vehicle) {
    if (!is_array($vehicle)) {
        continue;
    }
    $vehicle = customerVerificationMergeMissing($vehicle, [
        'vehicle_record_id' => $vehicle['vehicleRecordId']
            ?? $vehicle['vehicle_id']
            ?? $vehicle['vehicleId']
            ?? null,
        'vehicle_no' => $vehicle['vehicleNo'] ?? $vehicle['vehicle_number'] ?? null,
        'vehicle_type_id' => $vehicle['vehicleTypeId'] ?? null,
        'vehicle_type' => $vehicle['vehicleType'] ?? null,
        'device_model_id' => $vehicle['deviceModelId'] ?? null,
        'device_model' => $vehicle['deviceModel'] ?? null,
        'imei_no' => $vehicle['imeiNo'] ?? null,
        'sim_no_1' => $vehicle['simNo1'] ?? null,
        'sim_no_2' => $vehicle['simNo2'] ?? null,
        'validity_months' => $vehicle['validityMonths'] ?? null,
        'validity_id' => $vehicle['validityId'] ?? null,
        'installation' => $vehicle['installationDetails'] ?? null,
        'payment_part1' => $vehicle['paymentPart1'] ?? null,
        'payment_part2' => $vehicle['paymentPart2'] ?? null
    ]);
    $vehicleId = (int) ($vehicle['vehicle_record_id'] ?? $vehicle['id'] ?? 0);
    $vehicleNumber = trim((string) ($vehicle['vehicle_no'] ?? ''));
    if ($vehicleId <= 0 && $customerId > 0 && $vehicleNumber !== '') {
        $vehicleMatchStmt = $conn->prepare(
            'SELECT id
             FROM customer_vehicle_details
             WHERE customer_id = ? AND BINARY vehicle_no = BINARY ?
             ORDER BY id DESC
             LIMIT 2'
        );
        if (!$vehicleMatchStmt) {
            throw new RuntimeException('Unable to prepare verification vehicle number lookup.');
        }
        $vehicleMatchStmt->bind_param('is', $customerId, $vehicleNumber);
        if (!$vehicleMatchStmt->execute()) {
            $vehicleMatchStmt->close();
            throw new RuntimeException('Unable to resolve verification vehicle number.');
        }
        $vehicleMatches = $vehicleMatchStmt->get_result();
        $matchedVehicle = $vehicleMatches->fetch_assoc();
        $hasUniqueMatch = $matchedVehicle && $vehicleMatches->num_rows === 1;
        $vehicleMatchStmt->close();
        if ($hasUniqueMatch) {
            $vehicleId = (int) $matchedVehicle['id'];
            $vehicle['vehicle_record_id'] = $vehicleId;
        }
    }
    $actualVehicle = customerVerificationLoadVehicle($conn, $customerId, $vehicleId);
    if ($actualVehicle) {
        $vehicle = customerVerificationMergeMissing($vehicle, $actualVehicle);
        foreach (['vehicle_type_id', 'device_model_id', 'validity_months'] as $identifierField) {
            if ((int) ($vehicle[$identifierField] ?? 0) <= 0) {
                $vehicle[$identifierField] = $actualVehicle[$identifierField] ?? null;
            }
        }
        $vehicleId = (int) $actualVehicle['id'];
        $vehicleNumber = trim((string) ($vehicle['vehicle_no'] ?? ''));
    }
    if ($vehicleId <= 0 && count($vehicles) === 1 && $linkedVehicleId > 0) {
        $vehicleId = $linkedVehicleId;
    }
    $vehicleLabel = $vehicleNumber !== '' ? $vehicleNumber : null;
    $vehicleAssociations[] = ['id' => $vehicleId, 'label' => $vehicleLabel];
    $vehicleDisplay = ['Vehicle No' => $vehicleLabel];
    $vehicleType = $vehicle['vehicle_type'] ?? null;
    if (empty($vehicleType) || is_numeric($vehicle['vehicle_type_id'] ?? null)) {
        $vehicleType = customerVerificationLookup(
            $conn,
            'vehicle_types',
            'vehicle_type',
            (int) ($vehicle['vehicle_type_id'] ?? 0)
        );
    }
    $vehicleDisplay['Vehicle Type'] = $vehicleType ?: null;
    $deviceModel = $vehicle['device_model'] ?? null;
    if (empty($deviceModel) || is_numeric($vehicle['device_model_id'] ?? null)) {
        $deviceModel = customerVerificationLookup(
            $conn,
            'device_types',
            'device_type',
            (int) ($vehicle['device_model_id'] ?? 0)
        );
    }
    $vehicleDisplay['Device Model'] = $deviceModel ?: null;
    foreach ([
        'IMEI No' => 'imei_no',
        'SIM No 1' => 'sim_no_1',
        'SIM No 2' => 'sim_no_2'
    ] as $label => $field) {
        if (!empty($vehicle[$field])) {
            $vehicleDisplay[$label] = $vehicle[$field];
        }
    }
    $validityMonths = $vehicle['validity_months'] ?? null;
    if (empty($validityMonths) && !empty($vehicle['validity_id'])) {
        $validityMonths = customerVerificationLookup(
            $conn,
            'sim_validities',
            'months',
            (int) $vehicle['validity_id']
        );
    }
    $vehicleDisplay['Validity'] = is_numeric($validityMonths)
        ? $validityMonths . ' Months'
        : null;
    $displayVehicles[] = $vehicleDisplay;

    $installation = $vehicle['installation'] ?? null;

    $actualInstallation = customerVerificationLoadInstallation($conn, $customerId, $vehicleId);
    if ($actualInstallation) {
        $installation = customerVerificationMergeMissing(
            is_array($installation) ? $installation : [],
            $actualInstallation
        );
    } elseif ($customerId > 0 && $vehicleId > 0) {
        if ($legacyInstallationMap === null) {
            $legacyInstallationMap = customerVerificationLoadLegacyInstallationMap($conn, $customerId);
        }
        if (isset($legacyInstallationMap[$vehicleId])) {
            $installation = customerVerificationMergeMissing(
                is_array($installation) ? $installation : [],
                $legacyInstallationMap[$vehicleId]
            );
        }
    }

// Handle missing or empty installation data
if (!is_array($installation) || !$installation) {
    $installation = null;
}

// Normalize installation fields
if (is_array($installation)) {
    $installation = customerVerificationMergeMissing($installation, [
        'installation_person_type' => $installation['installationPersonType'] ?? null,
        'installation_type' => $installation['installationType'] ?? null,
        'installation_person_id' => $installation['installationPersonId'] ?? null,
        'installation_person' => $installation['installationPerson'] ?? null,
        'lead_closure_id' => $installation['leadClosureId'] ?? null,
        'lead_closure_by_user_id' => $installation['leadClosureByUserId'] ?? null,
        'lead_closure_by_user' => $installation['leadClosureByUser'] ?? null,
        'lead_closure' => $installation['leadClosure'] ?? null,
        'installation_date' => $installation['installationDate'] ?? null
    ]);
}
if ($crmInstallationContext) {
    $installation = customerVerificationMergeMissing(
        is_array($installation) ? $installation : [],
        [
            'installation_person_type' => $crmInstallationContext['installation_person_type'] ?? null,
            'installation_type' => $crmInstallationContext['installation_type'] ?? null,
            'installation_person_id' => $crmInstallationContext['installation_person_id'] ?? null,
            'installation_person' => $crmInstallationContext['installation_person'] ?? null,
            'lead_closure_by' => $crmInstallationContext['lead_closure_by'] ?? null,
            'installation_date' => $crmInstallationContext['installation_date'] ?? null
        ]
    );
}

// Fallback to legacy vehicle installation fields
if (!is_array($installation) && (
    !empty($vehicle['vehicle_installation_date']) ||
    !empty($vehicle['vehicle_installation_person_type']) ||
    !empty($vehicle['vehicle_installation_person'])
)) {
    $installation = [
        'installation_type' => $vehicle['vehicle_installation_type'] ?? null,
        'installation_person_type' => $vehicle['vehicle_installation_person_type'] ?? '',
        'installation_person_id' => $vehicle['vehicle_installation_person_id'] ?? 0,
        'installation_person' => $vehicle['vehicle_installation_person'] ?? null,
        'lead_closure_id' => $vehicle['vehicle_lead_closure_id'] ?? 0,
        'lead_closure_by_user_id' => $vehicle['vehicle_lead_closure_by_user_id'] ?? null,
        'lead_closure_by_user' => $vehicle['vehicle_lead_closure_by_user'] ?? null,
        'lead_closure' => $vehicle['vehicle_lead_closure'] ?? null,
        'installation_date' => $vehicle['vehicle_installation_date'] ?? null
    ];
}
    if (!is_array($installation) && $vehicle === ($snapshot['vehicle'] ?? null) && is_array($snapshot['installation'] ?? null)) {
        $installation = $snapshot['installation'];
    }
    if (is_array($installation)) {
        $personType = trim((string) customerVerificationFirstValue($installation, [
            'installation_person_type',
            'installationPersonType',
            'person_type',
            'personType'
        ]));
        if ($personType === '') {
            $personType = trim((string) customerVerificationFirstValue($installation, [
                'installation_type',
                'installationType',
                'vehicle_installation_type'
            ]));
        }
        $displayPersonType = trim((string) customerVerificationFirstValue($installation, [
            'installation_type',
            'installationType',
            'vehicle_installation_type'
        ]));
        if ($displayPersonType === '') {
            $displayPersonType = $personType;
        }
        $personId = (int) customerVerificationFirstValue($installation, [
            'installation_person_id',
            'installationPersonId',
            'person_id',
            'personId'
        ]);
        $personTable = stripos($personType, 'dealer') !== false ? 'dealers' : 'technicians';
        $personLabelColumn = $personTable === 'dealers' ? 'dealer_name' : 'technician_name';
        $installationPerson = trim((string) customerVerificationFirstValue($installation, [
            'installation_person',
            'installationPerson',
            'person_name'
        ]));

if ($installationPerson === '') {
    $installationPerson = strcasecmp($personType, 'Direct') === 0
        ? 'Not applicable'
        : (customerVerificationLookup(
            $conn,
            $personTable,
            $personLabelColumn,
            $personId
        ) ?? '');
}

$leadClosure = trim((string) customerVerificationFirstValue($installation, [
    'lead_closure_by_user',
    'leadClosureByUser',
    'lead_closure_by',
    'leadClosureBy',
    'lead_closure',
    'leadClosure'
]));

if ($leadClosure === '') {
    $leadClosure = customerVerificationLookup(
        $conn,
        'lead_closures',
        'lead_closure_name',
        (int) customerVerificationFirstValue($installation, ['lead_closure_id', 'leadClosureId'])
    ) ?? '';
}
        $displayInstallations[] = [
    'Installation Type' => $displayPersonType,
    'Installation Person' => $installationPerson !== '' ? $installationPerson : null,
    'Installation Person Type' => $personType,
    'Lead Closure By' => $leadClosure !== '' ? $leadClosure : null,
    'Installation Date' => customerVerificationFirstValue($installation, ['installation_date', 'installationDate'])
        ];
    }

    $payment1 = $vehicle['payment_part1'] ?? null;
    if (!is_array($payment1) && $vehicle === ($snapshot['vehicle'] ?? null) && is_array($snapshot['payment_part1'] ?? null)) {
        $payment1 = $snapshot['payment_part1'];
    }
    $payment2 = $vehicle['payment_part2'] ?? null;
    if (!is_array($payment2) && $vehicle === ($snapshot['vehicle'] ?? null) && is_array($snapshot['payment_part2'] ?? null)) {
        $payment2 = $snapshot['payment_part2'];
    }

    $actualPayment = null;
    if ($customerId > 0 && $source !== 'payment_installment' && $source !== 'courier') {
        if ($vehicleId > 0) {
            $actualPayment = customerVerificationLoadPayment($conn, $customerId, 0, $vehicleId);
        }
        if (!$actualPayment && $linkedPayment) {
            $linkedPaymentVehicleId = (int) ($linkedPayment['vehicle_id'] ?? 0);
            if ($linkedPaymentVehicleId === $vehicleId
                || (count($vehicles) === 1 && $linkedPaymentVehicleId === 0)) {
                $actualPayment = $linkedPayment;
            }
        }
    }
    if ($actualPayment) {
        $payment1 = customerVerificationMergeMissing(
            is_array($payment1) ? $payment1 : [],
            $actualPayment
        );
        $payment2 = customerVerificationMergeMissing(
            is_array($payment2) ? $payment2 : [],
            $actualPayment
        );
    }
    if (is_array($payment1)) {
        $displayPaymentPart1[] = customerVerificationPayment(
            $payment1,
            $vehicleLabel,
            false,
            $source === 'crm_installation'
        );
    }
    if (is_array($payment2)) {
        $displayPaymentPart2[] = customerVerificationPayment($payment2, $vehicleLabel, true);
    }
}

$resolvePaymentVehicle = static function (array $payment) use ($vehicleAssociations): ?string {
    $requestedVehicleNumber = trim((string) (
        $payment['vehicle_no'] ??
        $payment['vehicle_number'] ??
        $payment['vehicleNo'] ??
        ''
    ));
    if ($requestedVehicleNumber !== '') {
        foreach ($vehicleAssociations as $vehicle) {
            if (is_string($vehicle['label']) && strcasecmp($vehicle['label'], $requestedVehicleNumber) === 0) {
                return $vehicle['label'];
            }
        }
    }
    $requestedVehicleId = (int) ($payment['vehicle_record_id'] ?? $payment['vehicle_id'] ?? $payment['vehicleId'] ?? 0);
    if ($requestedVehicleId > 0) {
        foreach ($vehicleAssociations as $vehicle) {
            if ($vehicle['id'] === $requestedVehicleId) {
                return $vehicle['label'];
            }
        }
    }
    if (count($vehicleAssociations) === 1) {
        return $vehicleAssociations[0]['label'];
    }
    if (!empty($payment['customer_level']) || ($payment['payment_scope'] ?? '') === 'customer') {
        return 'Customer-level payment';
    }
    return null;
};

if (!$displayPaymentPart1 && is_array($topLevelPayment1)) {
    $payment1 = $topLevelPayment1;
    $displayPaymentPart1[] = customerVerificationPayment(
        $payment1,
        $resolvePaymentVehicle($payment1),
        false,
        $source === 'crm_installation'
    );
}
if (!$displayPaymentPart2 && is_array($topLevelPayment2)) {
    $payment2 = $topLevelPayment2;
    $displayPaymentPart2[] = customerVerificationPayment($payment2, $resolvePaymentVehicle($payment2), true);
}
if (!$vehicles && $linkedPayment && $source !== 'payment_installment' && $source !== 'courier') {
    $displayPaymentPart1 = [customerVerificationPayment(customerVerificationMergeMissing(
        is_array($topLevelPayment1) ? $topLevelPayment1 : [],
        [
            'total_sale_amount' => $linkedPayment['total_sale_amount'] ?? null,
            'transaction_id' => $linkedPayment['transaction_id'] ?? null,
            'payment_mode' => $linkedPayment['payment_mode'] ?? null
        ]
    ), null, false, $source === 'crm_installation')];
    $displayPaymentPart2 = [customerVerificationPayment(customerVerificationMergeMissing(
        is_array($topLevelPayment2) ? $topLevelPayment2 : [],
        [
            'payment_mode' => $linkedPayment['payment_mode'] ?? null,
            'device_charge' => $linkedPayment['device_charge'] ?? null,
            'software_charge' => $linkedPayment['software_charge'] ?? null,
            'technician_charge' => $linkedPayment['technician_charge'] ?? null,
            'sim_charge' => $linkedPayment['sim_charge'] ?? null,
            'courier_charge' => $linkedPayment['courier_charge'] ?? null,
            'total_amount' => $linkedPayment['total_amount'] ?? null,
            'amount_paid' => $linkedPayment['amount_paid'] ?? null,
            'amount_pending' => $linkedPayment['amount_pending'] ?? null,
            'payment_status' => $linkedPayment['payment_status'] ?? null,
            'cash_recipient_type' => $linkedPayment['cash_recipient_type'] ?? null,
            'cash_recipient_name' => $linkedPayment['cash_recipient_name'] ?? null
        ]
    ), null, true)];
}
if (!$displayPaymentPart2 && !empty($snapshot['payment_status'])) {
    $displayPaymentPart2[] = [
        'Vehicle' => count($vehicleAssociations) === 1
            ? $vehicleAssociations[0]['label']
            : 'Customer-level payment',
        'Payment Status' => $snapshot['payment_status']
    ];
}
$snapshotDisplayData = customerVerificationSnapshotDisplayData($conn, $snapshot);
$mergeDisplayRows = static function (array $current, array $fallback): array {
    $current = array_values($current);
    foreach (array_values($fallback) as $index => $fallbackRow) {
        if (!isset($current[$index]) || !is_array($current[$index])) {
            $current[$index] = $fallbackRow;
            continue;
        }
        foreach ($fallbackRow as $field => $value) {
            if (($current[$index][$field] ?? null) === null
                || (is_string($current[$index][$field] ?? null) && trim($current[$index][$field]) === '')) {
                $current[$index][$field] = $value;
            }
        }
    }
    return $current;
};
$customerDisplay = customerVerificationMergeMissing(
    $customerDisplay,
    $snapshotDisplayData['customer'] ?? []
);
if ($source === 'crm_installation') {
    foreach ($snapshotDisplayData['payment_part1'] ?? [] as $index => $payment) {
        $paymentStatus = $payment['Payment Status'] ?? null;
        if ($paymentStatus === null || trim((string) $paymentStatus) === '') {
            continue;
        }
        $part2Index = array_key_exists($index, $snapshotDisplayData['payment_part2'] ?? [])
            ? $index
            : count($snapshotDisplayData['payment_part2'] ?? []);
        if (!isset($snapshotDisplayData['payment_part2'][$part2Index])) {
            $snapshotDisplayData['payment_part2'][$part2Index] = ['Payment Status' => $paymentStatus];
        } elseif (empty($snapshotDisplayData['payment_part2'][$part2Index]['Payment Status'])) {
            $snapshotDisplayData['payment_part2'][$part2Index]['Payment Status'] = $paymentStatus;
        }
    }
}
$displayVehicles = $mergeDisplayRows($displayVehicles, $snapshotDisplayData['vehicle'] ?? []);
$displayInstallations = $mergeDisplayRows($displayInstallations, $snapshotDisplayData['installation'] ?? []);
$displayPaymentPart1 = $mergeDisplayRows($displayPaymentPart1, $snapshotDisplayData['payment_part1'] ?? []);
$displayPaymentPart2 = $mergeDisplayRows($displayPaymentPart2, $snapshotDisplayData['payment_part2'] ?? []);
$row['display_data'] = [
    'customer' => $customerDisplay,
    'vehicle' => $displayVehicles,
    'installation' => $displayInstallations,
    'payment_part1' => $displayPaymentPart1,
    'payment_part2' => $displayPaymentPart2,
    'installment_request' => ($snapshot['source'] ?? '') === 'payment_installment'
        ? [
            'Installment Amount' => $snapshot['payment_installment']['amount'] ?? null,
            'Payment Method' => $snapshot['payment_installment']['payment_mode'] ?? null,
            'Transaction ID / UTR' => $snapshot['payment_installment']['transaction_id'] ?? null,
            'Payment Date' => $snapshot['payment_installment']['payment_date'] ?? null,
            'Notes' => $snapshot['payment_installment']['notes'] ?? null,
            'Current Total Amount' => $snapshot['payment_installment']['current_total_amount'] ?? null,
            'Current Amount Paid' => $snapshot['payment_installment']['current_amount_paid'] ?? null,
            'Current Amount Pending' => $snapshot['payment_installment']['current_amount_pending'] ?? null,
            'Current Payment Status' => $snapshot['payment_installment']['current_payment_status'] ?? null
        ]
        : null
];
$row['submission_type'] = 'Customer Payment';
if (($snapshot['source'] ?? '') === 'courier') {
    $row['submission_type'] = 'Courier Request';
} elseif (($snapshot['source'] ?? '') === 'payment_installment') {
    $row['submission_type'] = 'Payment Installment';
} elseif (($snapshot['source'] ?? '') === 'customer_vehicle_addition') {
    $row['submission_type'] = 'Additional Customer Vehicle';
} elseif (($snapshot['source'] ?? '') === 'crm_installation') {
    $row['submission_type'] = 'CRM Installation Device';
}
$row['is_own_submission'] = $reviewerId > 0
    && (int) $row['submitter_user_id'] === $reviewerId;
$isSuperAdminReviewer = isSuperAdminUser($reviewerId);
$row['can_decide'] = $isSuperAdminReviewer
    && in_array($row['status'], ['Pending Verification', 'Applying'], true);
$row['decision_unavailable_reason'] = !$isSuperAdminReviewer
    ? 'Only a Super Admin can approve or reject customer verification requests.'
    : (!in_array($row['status'], ['Pending Verification', 'Applying'], true)
        ? 'This request is no longer pending a decision.'
        : null);
$conn->close();
$row['id'] = (int) $row['id'];
foreach (['submitter_user_id', 'existing_customer_id', 'linked_customer_id', 'linked_vehicle_id', 'linked_payment_id'] as $idField) {
    $row[$idField] = $row[$idField] !== null ? (int) $row[$idField] : null;
}
sendResponse(true, 'Verification submission fetched.', ['submission' => $row]);
