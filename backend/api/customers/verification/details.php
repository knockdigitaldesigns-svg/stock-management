<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

function customerVerificationLookup(mysqli $conn, string $table, string $labelColumn, int $id): string
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
        return 'Not available';
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
    return $value !== null && $value !== '' ? (string) $value : 'Not available';
}

function customerVerificationPayment(array $payment, ?string $vehicleName, bool $part2): array
{
    $display = ['Vehicle' => $vehicleName !== null && $vehicleName !== '' ? $vehicleName : 'Not specified'];
    if (!$part2) {
        $totalSaleAmount = $payment['total_sale_amount'] ?? $payment['totalSaleAmount'] ?? null;
        if ($totalSaleAmount !== null && $totalSaleAmount !== '') {
            $display['Total Sale Amount'] = $totalSaleAmount;
        }
    }
    $paymentMode = $payment['payment_mode'] ?? $payment['paymentMode'] ?? null;
    if ($paymentMode !== null && $paymentMode !== '') {
        $display['Payment Mode'] = $paymentMode;
    }
    $transactionId = $payment['transaction_id'] ?? $payment['transactionId'] ?? null;
    if ($transactionId !== null && $transactionId !== '') {
        $display['Transaction ID'] = $transactionId;
    }
    $paymentDate = $payment['payment_date'] ?? $payment['paymentDate'] ?? null;
    if ($paymentDate !== null && $paymentDate !== '') {
        $display['Payment Date'] = $paymentDate;
    }
    if (!$part2) {
        foreach ([
            'payment_date',
            'paymentDate'
        ] as $field) {
            if (isset($payment[$field]) && $payment[$field] !== '') {
                $display['Payment Date'] = $payment[$field];
                break;
            }
        }
        foreach ($payment as $field => $value) {
            if (in_array($field, [
                'total_sale_amount', 'totalSaleAmount', 'payment_mode', 'paymentMode',
                'transaction_id', 'transactionId', 'payment_date', 'paymentDate'
            ], true) || preg_match('/^(?:_.*|id|.*_id)$/i', $field)
                || $value === null || $value === '') {
                continue;
            }
            $display[$field] = $value;
        }
        return $display;
    }
    foreach ([
        'Device Charge' => 'device_charge',
        'Software Charge' => 'software_charge',
        'Technician Charge' => 'technician_charge',
        'SIM Charge' => 'sim_charge',
        'Courier Charge' => 'courier_charge',
        'Total Amount' => 'total_amount',
        'Amount Paid' => 'amount_paid',
        'Amount Pending' => 'amount_pending',
        'Payment Status' => 'payment_status'
    ] as $label => $field) {
        if (array_key_exists($field, $payment)) {
            $display[$label] = $payment[$field];
        }
    }
    $recipient = trim((string) ($payment['cash_recipient_type'] ?? ''));
    if ($recipient === '') {
        $recipient = !empty($payment['cash_to_technician'])
            ? 'Cash to Technician'
            : (!empty($payment['cash_to_dealer'])
                ? 'Cash to Dealer'
                : (!empty($payment['cash_to_et']) ? 'Direct Cash to ET' : 'Not specified'));
    }
    $display['Cash Collection Recipient'] = $recipient;
    foreach ($payment as $field => $value) {
        if (in_array($field, [
            'total_sale_amount', 'totalSaleAmount', 'payment_mode', 'paymentMode',
            'transaction_id', 'transactionId', 'payment_date', 'paymentDate',
            'device_charge', 'deviceCharge', 'software_charge', 'softwareCharge',
            'technician_charge', 'technicianCharge', 'sim_charge', 'simCharge',
            'courier_charge', 'courierCharge', 'total_amount', 'totalAmount',
            'amount_paid', 'amountPaid', 'amount_pending', 'amountPending',
            'payment_status', 'paymentStatus', 'cash_recipient_type',
            'cash_to_technician', 'cashToTechnician', 'cash_to_dealer',
            'cashToDealer', 'cash_to_et', 'cashToEt'
        ], true) || preg_match('/^(?:_.*|id|.*_id)$/i', $field)
            || $value === null || $value === '') {
            continue;
        }
        $display[$field] = $value;
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
$customer = array_replace(
    is_array($snapshot['step1'] ?? null) ? $snapshot['step1'] : [],
    is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : []
);
$existingCustomerId = (int) ($row['existing_customer_id'] ?? $row['linked_customer_id'] ?? 0);
$customerName = '';
foreach ([$customer, $snapshot] as $customerSource) {
    foreach (['username', 'customer_name', 'customer_username', 'customerName', 'name'] as $nameField) {
        if (isset($customerSource[$nameField]) && trim((string) $customerSource[$nameField]) !== '') {
            $customerName = trim((string) $customerSource[$nameField]);
            break 2;
        }
    }
}
if ($existingCustomerId > 0 && $customerName === '') {
    $customerStmt = $conn->prepare('SELECT username FROM customers WHERE id = ? LIMIT 1');
    if (!$customerStmt) {
        throw new RuntimeException('Unable to prepare verification customer lookup.');
    }
    $customerStmt->bind_param('i', $existingCustomerId);
    if (!$customerStmt->execute()) {
        $customerStmt->close();
        throw new RuntimeException('Unable to resolve verification customer name.');
    }
    $customerName = trim((string) ($customerStmt->get_result()->fetch_assoc()['username'] ?? ''));
    $customerStmt->close();
}
$customerDisplay = [
    'Username' => $customerName !== ''
        ? $customerName
        : ($existingCustomerId > 0 ? 'Existing Customer' : 'New Customer — Pending Approval'),
    'Platform' => $customer['platform_name'] ?? null
];
if (empty($customerDisplay['Platform']) || is_numeric($customer['platform_id'] ?? null)) {
    $customerDisplay['Platform'] = customerVerificationLookup(
        $conn,
        'platforms',
        'platform_name',
        (int) ($customer['platform_id'] ?? 0)
    );
}
foreach ([
    'Primary Mobile No' => 'primary_mobile_no',
    'Secondary Mobile No' => 'secondary_mobile_no',
    'Email' => 'email',
    'Location' => 'location',
    'Pincode' => 'pincode',
    'Status' => 'status'
] as $label => $field) {
    if (!empty($customer[$field])) {
        $customerDisplay[$label] = $customer[$field];
    }
}

$vehicles = $snapshot['vehicles'] ?? [];
if (!is_array($vehicles)) {
    $vehicles = [];
}
if (!$vehicles && is_array($snapshot['vehicle'] ?? null)) {
    $vehicles[] = $snapshot['vehicle'];
}
$displayVehicles = [];
$displayInstallations = [];
$displayPaymentPart1 = [];
$displayPaymentPart2 = [];
$vehicleAssociations = [];
foreach ($vehicles as $vehicle) {
    if (!is_array($vehicle)) {
        continue;
    }
    $vehicleId = (int) ($vehicle['vehicle_record_id'] ?? $vehicle['id'] ?? 0);
    $vehicleNumber = trim((string) ($vehicle['vehicle_no'] ?? ''));
    if ($vehicleNumber === '' && $vehicleId > 0 && $existingCustomerId > 0) {
        $vehicleStmt = $conn->prepare(
            'SELECT vehicle_no FROM customer_vehicle_details WHERE id = ? AND customer_id = ? LIMIT 1'
        );
        if (!$vehicleStmt) {
            throw new RuntimeException('Unable to prepare verification vehicle lookup.');
        }
        $vehicleStmt->bind_param('ii', $vehicleId, $existingCustomerId);
        if (!$vehicleStmt->execute()) {
            $vehicleStmt->close();
            throw new RuntimeException('Unable to resolve verification vehicle number.');
        }
        $vehicleNumber = (string) ($vehicleStmt->get_result()->fetch_assoc()['vehicle_no'] ?? '');
        $vehicleStmt->close();
    }
    $vehicleLabel = $vehicleNumber !== ''
        ? $vehicleNumber
        : ($vehicleId > 0 ? 'Existing vehicle — details unavailable' : 'New Vehicle — Pending Approval');
    $vehicleAssociations[] = ['id' => $vehicleId, 'label' => $vehicleLabel];
    $vehicleDisplay = ['Vehicle' => $vehicleLabel];
    $vehicleType = $vehicle['vehicle_type'] ?? null;
    if (empty($vehicleType) || is_numeric($vehicle['vehicle_type_id'] ?? null)) {
        $vehicleType = customerVerificationLookup(
            $conn,
            'vehicle_types',
            'vehicle_type',
            (int) ($vehicle['vehicle_type_id'] ?? 0)
        );
    }
    $vehicleDisplay['Vehicle Type'] = $vehicleType ?: 'Not available';
    $deviceModel = $vehicle['device_model'] ?? null;
    if (empty($deviceModel) || is_numeric($vehicle['device_model_id'] ?? null)) {
        $deviceModel = customerVerificationLookup(
            $conn,
            'device_types',
            'device_type',
            (int) ($vehicle['device_model_id'] ?? 0)
        );
    }
    $vehicleDisplay['Device Model'] = $deviceModel ?: 'Not available';
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
    $vehicleDisplay['SIM Validity'] = is_numeric($validityMonths)
        ? $validityMonths . ' Months'
        : 'Not available';
    $displayVehicles[] = $vehicleDisplay;

    $installation = $vehicle['installation'] ?? null;
    if (!is_array($installation) && !empty($vehicle['vehicle_installation_date'])) {
        $installation = [
            'installation_person_type' => $vehicle['vehicle_installation_type'] ?? '',
            'installation_person_id' => $vehicle['vehicle_installation_person_id'] ?? 0,
            'lead_closure_id' => $vehicle['vehicle_lead_closure_id'] ?? 0,
            'installation_date' => $vehicle['vehicle_installation_date']
        ];
    }
    if (!is_array($installation) && $vehicle === ($snapshot['vehicle'] ?? null) && is_array($snapshot['installation'] ?? null)) {
        $installation = $snapshot['installation'];
    }
    if (is_array($installation)) {
        $personType = trim((string) ($installation['installation_person_type'] ?? ''));
        $personId = (int) ($installation['installation_person_id'] ?? 0);
        $personTable = stripos($personType, 'dealer') !== false ? 'dealers' : 'technicians';
        $personLabelColumn = $personTable === 'dealers' ? 'dealer_name' : 'technician_name';
        $installationPerson = $personType === 'Direct'
            ? 'Not applicable'
            : customerVerificationLookup($conn, $personTable, $personLabelColumn, $personId);
        $leadClosure = customerVerificationLookup(
            $conn,
            'lead_closures',
            'lead_closure_name',
            (int) ($installation['lead_closure_id'] ?? 0)
        );
        $displayInstallations[] = [
            'Vehicle' => $vehicleLabel,
            'Installation Person Type' => $personType !== '' ? $personType : 'Not available',
            'Installation Person' => $installationPerson,
            'Lead Closure' => $leadClosure,
            'Installation Date' => $installation['installation_date'] ?? 'Not available'
        ];
    }

    $payment1 = $vehicle['payment_part1'] ?? null;
    if (!is_array($payment1) && $vehicle === ($snapshot['vehicle'] ?? null) && is_array($snapshot['payment_part1'] ?? null)) {
        $payment1 = $snapshot['payment_part1'];
    }
    if (is_array($payment1)) {
        $displayPaymentPart1[] = customerVerificationPayment($payment1, $vehicleLabel, false);
    }
    $payment2 = $vehicle['payment_part2'] ?? null;
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
            if (strcasecmp($vehicle['label'], $requestedVehicleNumber) === 0) {
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

if (!$displayPaymentPart1 && is_array($snapshot['payment_part1'] ?? null)) {
    $payment1 = $snapshot['payment_part1'];
    $displayPaymentPart1[] = customerVerificationPayment($payment1, $resolvePaymentVehicle($payment1), false);
}
if (!$displayPaymentPart2 && is_array($snapshot['payment_part2'] ?? null)) {
    $payment2 = $snapshot['payment_part2'];
    $displayPaymentPart2[] = customerVerificationPayment($payment2, $resolvePaymentVehicle($payment2), true);
}
$row['display_data'] = [
    'customer' => $customerDisplay,
    'vehicle' => $displayVehicles,
    'installation' => $displayInstallations,
    'payment_part1' => $displayPaymentPart1,
    'payment_part2' => $displayPaymentPart2
];
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
