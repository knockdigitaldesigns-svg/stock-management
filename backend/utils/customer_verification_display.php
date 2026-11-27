<?php

function customerVerificationDisplayLookup(mysqli $conn, string $table, string $column, int $id): ?string
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

function customerVerificationDisplayValue(array $source, array $fields)
{
    foreach ($fields as $field) {
        if (isset($source[$field]) && is_scalar($source[$field]) && trim((string) $source[$field]) !== '') {
            return $source[$field];
        }
    }
    return null;
}

function customerVerificationDisplayNamedValue(
    mysqli $conn,
    array $source,
    array $nameFields,
    array $idFields,
    string $table,
    string $column
): ?string {
    $name = customerVerificationDisplayValue($source, $nameFields);
    if ($name !== null && !is_numeric($name)) {
        return (string) $name;
    }
    $id = (int) ($name ?? customerVerificationDisplayValue($source, $idFields) ?? 0);
    return $id > 0 ? customerVerificationDisplayLookup($conn, $table, $column, $id) : null;
}

function customerVerificationDisplayPayment(array $payment, bool $part2): array
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
        $display[$label] = customerVerificationDisplayValue($payment, $keys);
    }
    if (!$part2) {
        $paymentStatus = customerVerificationDisplayValue($payment, ['payment_status', 'paymentStatus']);
        if ($paymentStatus !== null) {
            $display['Payment Status'] = $paymentStatus;
        }
    }
    if ($part2) {
        $display['Cash Collection Recipient'] = customerVerificationDisplayValue($payment, [
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

function customerVerificationSnapshotDisplayData(mysqli $conn, array $snapshot): array
{
    $customer = array_replace(
        is_array($snapshot['step1'] ?? null) ? $snapshot['step1'] : [],
        is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : []
    );
    $customerDisplay = [
        'Platform' => customerVerificationDisplayNamedValue(
            $conn,
            $customer,
            ['platform_name', 'platformName', 'platform', 'software_name', 'software'],
            ['platform_id', 'platformId'],
            'platforms',
            'platform_name'
        ),
        'Username' => customerVerificationDisplayValue($customer, ['username', 'customer_name', 'customerName', 'name']),
        'Primary Mobile No' => customerVerificationDisplayValue($customer, ['primary_mobile_no', 'primaryMobileNo']),
        'Secondary Mobile No' => customerVerificationDisplayValue($customer, ['secondary_mobile_no', 'secondaryMobileNo']),
        'Email' => customerVerificationDisplayValue($customer, ['email']),
        'Location' => customerVerificationDisplayValue($customer, ['location', 'customer_location', 'customerLocation', 'address']),
        'Pincode' => customerVerificationDisplayValue($customer, ['pincode', 'pin_code', 'pinCode'])
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
            'Vehicle No' => customerVerificationDisplayValue($vehicle, ['vehicle_no', 'vehicle_number', 'vehicleNo', 'registration_number']),
            'Vehicle Type' => customerVerificationDisplayNamedValue(
                $conn,
                $vehicle,
                ['vehicle_type', 'vehicleType'],
                ['vehicle_type_id', 'vehicleTypeId'],
                'vehicle_types',
                'vehicle_type'
            ),
            'IMEI No' => customerVerificationDisplayValue($vehicle, ['imei_no', 'imeiNo', 'imei']),
            'SIM No 1' => customerVerificationDisplayValue($vehicle, ['sim_no_1', 'simNo1', 'sim_number', 'sim1']),
            'SIM No 2' => customerVerificationDisplayValue($vehicle, ['sim_no_2', 'simNo2', 'sim2']),
            'Device Model' => customerVerificationDisplayNamedValue(
                $conn,
                $vehicle,
                ['device_model', 'deviceModel'],
                ['device_model_id', 'deviceModelId'],
                'device_types',
                'device_type'
            ),
            'Validity' => (function () use ($conn, $vehicle) {
                $validity = customerVerificationDisplayValue($vehicle, ['validity_months', 'validityMonths', 'validity']);
                if ($validity !== null && !is_numeric($validity)) {
                    return (string) $validity;
                }
                $months = $validity ?? customerVerificationDisplayLookup(
                    $conn,
                    'sim_validities',
                    'months',
                    (int) customerVerificationDisplayValue($vehicle, ['validity_id', 'validityId'])
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
        foreach ([
            'installation_person_type' => ['vehicle_installation_person_type'],
            'installation_person_id' => ['vehicle_installation_person_id'],
            'installation_person' => ['vehicle_installation_person'],
            'installation_type' => ['vehicle_installation_type'],
            'lead_closure_id' => ['vehicle_lead_closure_id'],
            'lead_closure' => ['vehicle_lead_closure'],
            'installation_date' => ['vehicle_installation_date']
        ] as $field => $vehicleFields) {
            if (customerVerificationDisplayValue($installation, [$field]) === null) {
                $value = customerVerificationDisplayValue($vehicle, $vehicleFields);
                if ($value !== null) {
                    $installation[$field] = $value;
                }
            }
        }
        if ($installation) {
            $personType = customerVerificationDisplayValue($installation, [
                'installation_person_type',
                'installationPersonType',
                'person_type',
                'personType'
            ]);
            $personId = (int) customerVerificationDisplayValue($installation, [
                'installation_person_id',
                'installationPersonId',
                'person_id',
                'personId'
            ]);
            $person = customerVerificationDisplayValue($installation, [
                'installation_person',
                'installationPerson',
                'person_name',
                'dealer_name',
                'technician_name'
            ]);
            if ($person === null && $personId > 0) {
                $personTable = stripos((string) $personType, 'dealer') !== false ? 'dealers' : 'technicians';
                $personColumn = $personTable === 'dealers' ? 'dealer_name' : 'technician_name';
                $person = customerVerificationDisplayLookup($conn, $personTable, $personColumn, $personId);
            }
            $leadClosure = customerVerificationDisplayNamedValue(
                $conn,
                $installation,
                ['lead_closure', 'leadClosure'],
                ['lead_closure_id', 'leadClosureId'],
                'lead_closures',
                'lead_closure_name'
            );
            $installationDisplay[] = [
                'Installation Type' => customerVerificationDisplayValue($installation, [
                    'installation_type',
                    'installationType',
                    'vehicle_installation_type'
                ]) ?? $personType,
                'Installation Person' => $person,
                'Installation Person Type' => $personType,
                'Lead Closure By' => $leadClosure,
                'Installation Date' => customerVerificationDisplayValue($installation, ['installation_date', 'installationDate'])
            ];
        }
    }
    if (!$installationDisplay) {
        $installation = $snapshot['installation'] ?? $snapshot['installationDetails'] ?? $snapshot['step3'] ?? null;
        if (is_array($installation)) {
            $personType = customerVerificationDisplayValue($installation, ['installation_person_type', 'installationPersonType']);
            $isDealer = stripos((string) $personType, 'dealer') !== false;
            $installationDisplay[] = [
                'Installation Type' => customerVerificationDisplayValue($installation, ['installation_type', 'installationType', 'vehicle_installation_type'])
                    ?? $personType,
                'Installation Person' => customerVerificationDisplayNamedValue(
                    $conn,
                    $installation,
                    ['installation_person', 'installationPerson', 'dealer_name', 'technician_name'],
                    ['installation_person_id', 'installationPersonId'],
                    $isDealer ? 'dealers' : 'technicians',
                    $isDealer ? 'dealer_name' : 'technician_name'
                ),
                'Installation Person Type' => $personType,
                'Lead Closure By' => customerVerificationDisplayNamedValue(
                    $conn,
                    $installation,
                    ['lead_closure', 'leadClosure'],
                    ['lead_closure_id', 'leadClosureId'],
                    'lead_closures',
                    'lead_closure_name'
                ),
                'Installation Date' => customerVerificationDisplayValue($installation, ['installation_date', 'installationDate'])
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
            'Asset Type' => customerVerificationDisplayValue($courier, ['asset_type', 'assetType']),
            'Device Model' => customerVerificationDisplayNamedValue(
                $conn,
                $courier,
                ['device_model', 'deviceModel'],
                ['device_model_id', 'deviceModelId'],
                'device_types',
                'device_type'
            ),
            'Device Count' => customerVerificationDisplayValue($courier, ['device_count', 'deviceCount']),
            'SIM Type' => customerVerificationDisplayValue($courier, ['sim_type', 'simType']),
            'SIM Count' => customerVerificationDisplayValue($courier, ['sim_count', 'simCount']),
            'Software' => customerVerificationDisplayValue($courier, ['software']),
            'Notes' => customerVerificationDisplayValue($courier, ['notes'])
        ];
    }

    return [
        'customer' => $customerDisplay,
        'courier' => $courierDisplay ?: null,
        'vehicle' => $vehicleDisplay,
        'installation' => $installationDisplay,
        'payment_part1' => array_map(
            static function (array $payment): array {
                return customerVerificationDisplayPayment($payment, false);
            },
            $payment1
        ),
        'payment_part2' => array_map(
            static function (array $payment): array {
                return customerVerificationDisplayPayment($payment, true);
            },
            $payment2
        )
    ];
}
