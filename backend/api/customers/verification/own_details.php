<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

function customerOwnVerificationLookup(mysqli $conn, string $table, string $column, int $id): ?string
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
    if ($id <= 0 || ($allowedLookups[$table] ?? null) !== $column) {
        return null;
    }
    $stmt = $conn->prepare("SELECT {$column} AS display_name FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification display lookup.');
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to resolve verification display value.');
    }
    $value = $stmt->get_result()->fetch_assoc()['display_name'] ?? null;
    $stmt->close();
    return $value !== null && trim((string) $value) !== '' ? (string) $value : null;
}

function customerOwnVerificationValue(array $source, array $fields)
{
    foreach ($fields as $field) {
        if (isset($source[$field]) && is_scalar($source[$field]) && trim((string) $source[$field]) !== '') {
            return $source[$field];
        }
    }
    return null;
}

function customerOwnVerificationNamedValue(
    mysqli $conn,
    array $source,
    array $nameFields,
    array $idFields,
    string $table,
    string $column
): ?string {
    $name = customerOwnVerificationValue($source, $nameFields);
    if ($name !== null && !is_numeric($name)) {
        return (string) $name;
    }
    $id = (int) ($name ?? customerOwnVerificationValue($source, $idFields) ?? 0);
    return $id > 0 ? customerOwnVerificationLookup($conn, $table, $column, $id) : null;
}

function customerOwnVerificationPayment(array $payment, bool $part2): array
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
    $display = [];
    foreach ($fields as $label => $keys) {
        $display[$label] = customerOwnVerificationValue($payment, $keys);
    }
    if ($part2) {
        $display['Cash Collection Recipient'] = customerOwnVerificationValue($payment, [
            'cash_recipient_name',
            'cashRecipientName',
            'recipient_name',
            'recipientName',
            'cash_recipient_type',
            'cashRecipientType'
        ]);
    }
    return $display;
}

function customerOwnVerificationDisplayData(mysqli $conn, array $snapshot): array
{
    $customer = array_replace(
        is_array($snapshot['step1'] ?? null) ? $snapshot['step1'] : [],
        is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : []
    );
    $customerDisplay = [
        'Platform' => customerOwnVerificationNamedValue(
            $conn,
            $customer,
            ['platform_name', 'platformName', 'platform', 'software_name', 'software'],
            ['platform_id', 'platformId'],
            'platforms',
            'platform_name'
        ),
        'Username' => customerOwnVerificationValue($customer, ['username', 'customer_name', 'customerName', 'name']),
        'Primary Mobile No' => customerOwnVerificationValue($customer, ['primary_mobile_no', 'primaryMobileNo']),
        'Secondary Mobile No' => customerOwnVerificationValue($customer, ['secondary_mobile_no', 'secondaryMobileNo']),
        'Email' => customerOwnVerificationValue($customer, ['email']),
        'Location' => customerOwnVerificationValue($customer, ['location', 'customer_location', 'customerLocation', 'address']),
        'Pincode' => customerOwnVerificationValue($customer, ['pincode', 'pin_code', 'pinCode'])
    ];

    $vehicles = $snapshot['vehicles'] ?? null;
    if (!is_array($vehicles) || !$vehicles) {
        $vehicle = $snapshot['vehicle'] ?? $snapshot['step2'] ?? null;
        $vehicles = is_array($vehicle) ? [$vehicle] : [];
    } elseif (isset($vehicles['vehicle_no']) || isset($vehicles['vehicleNo'])) {
        $vehicles = [$vehicles];
    }
    $vehicles = array_values(array_filter($vehicles, 'is_array'));
    $vehicleDisplay = [];
    $installationDisplay = [];
    foreach ($vehicles as $vehicle) {
        $vehicleDisplay[] = [
            'Vehicle No' => customerOwnVerificationValue($vehicle, ['vehicle_no', 'vehicle_number', 'vehicleNo', 'registration_number']),
            'Vehicle Type' => customerOwnVerificationNamedValue(
                $conn,
                $vehicle,
                ['vehicle_type', 'vehicleType'],
                ['vehicle_type_id', 'vehicleTypeId'],
                'vehicle_types',
                'vehicle_type'
            ),
            'IMEI No' => customerOwnVerificationValue($vehicle, ['imei_no', 'imeiNo', 'imei']),
            'SIM No 1' => customerOwnVerificationValue($vehicle, ['sim_no_1', 'simNo1', 'sim_number', 'sim1']),
            'SIM No 2' => customerOwnVerificationValue($vehicle, ['sim_no_2', 'simNo2', 'sim2']),
            'Device Model' => customerOwnVerificationNamedValue(
                $conn,
                $vehicle,
                ['device_model', 'deviceModel'],
                ['device_model_id', 'deviceModelId'],
                'device_types',
                'device_type'
            ),
            'Validity' => (function () use ($conn, $vehicle) {
                $validity = customerOwnVerificationValue($vehicle, ['validity_months', 'validityMonths', 'validity']);
                if ($validity !== null && !is_numeric($validity)) {
                    return (string) $validity;
                }
                $months = $validity ?? customerOwnVerificationLookup(
                    $conn,
                    'sim_validities',
                    'months',
                    (int) customerOwnVerificationValue($vehicle, ['validity_id', 'validityId'])
                );
                return $months !== null && $months !== ''
                    ? (is_numeric($months) ? $months . ' Months' : (string) $months)
                    : null;
            })()
        ];

        $installation = array_replace(
            is_array($snapshot['installation'] ?? null) ? $snapshot['installation'] : [],
            is_array($snapshot['step3'] ?? null) ? $snapshot['step3'] : [],
            is_array($vehicle['installation'] ?? null) ? $vehicle['installation'] : []
        );
        if ($installation) {
            $personType = customerOwnVerificationValue($installation, [
                'installation_person_type',
                'installationPersonType',
                'person_type',
                'personType'
            ]);
            $personId = (int) customerOwnVerificationValue($installation, [
                'installation_person_id',
                'installationPersonId',
                'person_id',
                'personId'
            ]);
            $person = customerOwnVerificationValue($installation, [
                'installation_person',
                'installationPerson',
                'person_name',
                'dealer_name',
                'technician_name'
            ]);
            if ($person === null && $personId > 0) {
                $personTable = stripos((string) $personType, 'dealer') !== false ? 'dealers' : 'technicians';
                $personColumn = $personTable === 'dealers' ? 'dealer_name' : 'technician_name';
                $person = customerOwnVerificationLookup($conn, $personTable, $personColumn, $personId);
            }
            $leadClosure = customerOwnVerificationNamedValue(
                $conn,
                $installation,
                ['lead_closure', 'leadClosure'],
                ['lead_closure_id', 'leadClosureId'],
                'lead_closures',
                'lead_closure_name'
            );
            $installationDisplay[] = [
                'Installation Type' => customerOwnVerificationValue($installation, [
                    'installation_type',
                    'installationType',
                    'vehicle_installation_type'
                ]) ?? $personType,
                'Installation Person' => $person,
                'Installation Person Type' => $personType,
                'Lead Closure By' => $leadClosure,
                'Installation Date' => customerOwnVerificationValue($installation, ['installation_date', 'installationDate'])
            ];
        }
    }
    if (!$installationDisplay) {
        $installation = $snapshot['installation'] ?? $snapshot['installationDetails'] ?? $snapshot['step3'] ?? null;
        if (is_array($installation)) {
            $installationDisplay[] = [
                'Installation Type' => customerOwnVerificationValue($installation, ['installation_type', 'installationType', 'vehicle_installation_type'])
                    ?? customerOwnVerificationValue($installation, ['installation_person_type', 'installationPersonType']),
                'Installation Person' => customerOwnVerificationNamedValue(
                    $conn,
                    $installation,
                    ['installation_person', 'installationPerson'],
                    ['installation_person_id', 'installationPersonId'],
                    stripos((string) customerOwnVerificationValue($installation, ['installation_person_type', 'installationPersonType']), 'dealer') !== false
                        ? 'dealers'
                        : 'technicians',
                    stripos((string) customerOwnVerificationValue($installation, ['installation_person_type', 'installationPersonType']), 'dealer') !== false
                        ? 'dealer_name'
                        : 'technician_name'
                ),
                'Installation Person Type' => customerOwnVerificationValue($installation, ['installation_person_type', 'installationPersonType']),
                'Lead Closure By' => customerOwnVerificationNamedValue(
                    $conn,
                    $installation,
                    ['lead_closure', 'leadClosure'],
                    ['lead_closure_id', 'leadClosureId'],
                    'lead_closures',
                    'lead_closure_name'
                ),
                'Installation Date' => customerOwnVerificationValue($installation, ['installation_date', 'installationDate'])
            ];
        }
    }

    $payment1 = $snapshot['payment_part1'] ?? $snapshot['paymentPart1'] ?? $snapshot['step4'] ?? null;
    $payment2 = $snapshot['payment_part2'] ?? $snapshot['paymentPart2'] ?? $snapshot['step5'] ?? null;
    if (!is_array($payment1) || !$payment1) {
        $payment1 = array_values(array_filter(array_map(
            static function (array $vehicle): ?array {
                return is_array($vehicle['payment_part1'] ?? null) ? $vehicle['payment_part1'] : null;
            },
            $vehicles
        )));
    } else {
        $payment1 = [$payment1];
    }
    if (!is_array($payment2) || !$payment2) {
        $payment2 = array_values(array_filter(array_map(
            static function (array $vehicle): ?array {
                return is_array($vehicle['payment_part2'] ?? null) ? $vehicle['payment_part2'] : null;
            },
            $vehicles
        )));
    } else {
        $payment2 = [$payment2];
    }

    $courierDisplay = [];
    $courier = is_array($snapshot['courier'] ?? null) ? $snapshot['courier'] : [];
    if ($courier) {
        $courierDisplay = [
            'Asset Type' => customerOwnVerificationValue($courier, ['asset_type', 'assetType']),
            'Device Model' => customerOwnVerificationNamedValue(
                $conn,
                $courier,
                ['device_model', 'deviceModel'],
                ['device_model_id', 'deviceModelId'],
                'device_types',
                'device_type'
            ),
            'Device Count' => customerOwnVerificationValue($courier, ['device_count', 'deviceCount']),
            'SIM Type' => customerOwnVerificationValue($courier, ['sim_type', 'simType']),
            'SIM Count' => customerOwnVerificationValue($courier, ['sim_count', 'simCount']),
            'Software' => customerOwnVerificationValue($courier, ['software']),
            'Notes' => customerOwnVerificationValue($courier, ['notes'])
        ];
    }

    return [
        'customer' => $customerDisplay,
        'courier' => $courierDisplay ?: null,
        'vehicle' => $vehicleDisplay,
        'installation' => $installationDisplay,
        'payment_part1' => array_map(
            static function (array $payment): array {
                return customerOwnVerificationPayment($payment, false);
            },
            $payment1
        ),
        'payment_part2' => array_map(
            static function (array $payment): array {
                return customerOwnVerificationPayment($payment, true);
            },
            $payment2
        )
    ];
}

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$currentUser = authenticate();
requireAnyPermission(['customers.add', 'customers.edit', 'courier.add']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$submissionId = (int) ($_GET['id'] ?? 0);
if ($submissionId <= 0) {
    sendResponse(false, 'A valid submission ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare(
    'SELECT id, status, submission_data, rejection_reason, submitted_at, decided_at
     FROM customer_payment_verification_submissions
     WHERE id = ? AND submitter_user_id = ? LIMIT 1'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare submission details.', [], [], 500);
}
$stmt->bind_param('ii', $submissionId, $userId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load submission details.', [], [], 500);
}
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    $conn->close();
    sendResponse(false, 'Verification submission not found.', [], [], 404);
}
try {
    $snapshot = decodeCustomerPaymentSubmission((string) $row['submission_data']);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Saved verification submission is invalid.', [], [], 500);
}
try {
    $row['submission_data'] = customerOwnVerificationDisplayData($conn, $snapshot);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Unable to load verification display details.', [], [], 500);
}
$conn->close();
$row['id'] = (int) $row['id'];
sendResponse(true, 'Your verification submission was fetched.', ['submission' => $row]);
