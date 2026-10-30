<?php

require_once __DIR__ . '/customer_payment_verification.php';
require_once __DIR__ . '/courier_customer_validation.php';

function createCourierCustomerVerification(mysqli $conn, array $courierRows, int $submitterUserId): int
{
    if (!$courierRows) {
        throw new RuntimeException('No courier requests were found for verification.');
    }

    $request = $courierRows[0];
    $workflow = json_decode((string) ($request['new_customer_data'] ?? ''), true);
    $workflow = is_array($workflow) ? $workflow : [];
    unset($workflow['_verification']);
    $workflow = mapCourierSoftwareToCustomerPlatform(
        $conn,
        $workflow,
        (string) ($request['software'] ?? '')
    );

    $customer = is_array($workflow['step1'] ?? null) ? $workflow['step1'] : [];
    $customerId = !empty($request['is_new_customer']) ? 0 : (int) ($request['customer_id'] ?? 0);

    if ($customerId <= 0 && !empty($request['is_new_customer'])) {
        $username = trim((string) ($customer['username'] ?? ''));
        $mobile = trim((string) ($customer['primary_mobile_no'] ?? ''));
        $platformId = (int) ($customer['platform_id'] ?? 0);
        if ($platformId > 0 && $username !== '' && $mobile !== '') {
            $match = $conn->prepare(
                'SELECT id FROM customers
                 WHERE platform_id = ?
                   AND LOWER(TRIM(username)) = LOWER(?)
                   AND TRIM(primary_mobile_no) = ?
                 ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            if (!$match) {
                throw new RuntimeException('Unable to prepare customer identity lookup.');
            }
            $match->bind_param('iss', $platformId, $username, $mobile);
            if (!$match->execute()) {
                $match->close();
                throw new RuntimeException('Unable to identify an existing customer.');
            }
            $customerId = (int) ($match->get_result()->fetch_assoc()['id'] ?? 0);
            $match->close();
        }
    }

    if ($customerId > 0) {
        $customerStmt = $conn->prepare(
            'SELECT platform_id, username, primary_mobile_no, secondary_mobile_no,
                    email, location, pincode, status
             FROM customers WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$customerStmt) {
            throw new RuntimeException('Unable to prepare customer details lookup.');
        }
        $customerStmt->bind_param('i', $customerId);
        if (!$customerStmt->execute()) {
            $customerStmt->close();
            throw new RuntimeException('Unable to load the existing customer details.');
        }
        $existingCustomer = $customerStmt->get_result()->fetch_assoc() ?: [];
        $customerStmt->close();
        $submittedCustomer = array_filter($customer, static function ($value): bool {
            return $value !== null && trim((string) $value) !== '';
        });
        $customer = array_replace($existingCustomer, $submittedCustomer);
    }

    $requestIds = [];
    foreach ($courierRows as $row) {
        $requestIds[] = (int) $row['id'];
    }
    $requestIds = array_values(array_unique($requestIds));

    $submission = [
        'source' => 'courier',
        'existing_customer_id' => $customerId ?: null,
        'customer_changed' => $customerId > 0 && !empty($workflow['step1']),
        'step1' => $customer,
        'customer' => $customer,
        'courier_request_ids' => $requestIds,
    ];

    $step2 = is_array($workflow['step2'] ?? null) ? $workflow['step2'] : [];
    $hasVehicleData = hasCourierWorkflowStepData($step2);
    $step3 = is_array($workflow['step3'] ?? null) ? $workflow['step3'] : [];
    $step4 = is_array($workflow['step4'] ?? null) ? $workflow['step4'] : [];
    $step5 = is_array($workflow['step5'] ?? null) ? $workflow['step5'] : [];
    $hasInstallationData = hasCourierWorkflowStepData($step3);
    $hasPaymentData = hasCourierPaymentData($step4, $step5);
    $totalSaleAmount = courierPaymentTotalSaleAmount($step4, $step5);
    if ($hasPaymentData && $totalSaleAmount <= 0) {
        throw new InvalidArgumentException('Total Sale Amount is required when entering payment details.');
    }
    $paymentPart2 = null;
    if ($hasPaymentData) {
        $paymentPart2 = buildCourierPaymentPart2($step4, $step5);
    }
    if ($hasVehicleData) {
        $vehicle = [
            'vehicle_no' => (string) ($step2['vehicle_no'] ?? ''),
            'vehicle_type_id' => (int) ($step2['vehicle_type_id'] ?? 0),
            'device_model_id' => (int) ($step2['device_model_id'] ?? 0),
            'imei_no' => (string) ($step2['imei_no'] ?? ''),
            'sim_no_1' => (string) ($step2['sim_no_1'] ?? ''),
            'sim_no_2' => (string) ($step2['sim_no_2'] ?? ''),
            'validity_id' => (int) ($step2['validity_id'] ?? 0),
            'changed' => [
                'vehicle' => true,
                'installation' => $hasInstallationData,
                'paymentPart1' => $hasPaymentData,
                'paymentPart2' => $hasPaymentData
            ],
            'installation' => $hasInstallationData ? $step3 : null,
            'payment_part1' => $hasPaymentData ? $step4 : null
        ];
        if ($paymentPart2 !== null) {
            $vehicle['payment_part2'] = $paymentPart2;
        }

        if ($customerId > 0) {
            $vehicleNo = trim($vehicle['vehicle_no']);
            $imei = trim($vehicle['imei_no']);
            if ($vehicleNo !== '' || $imei !== '') {
                $vehicleStmt = $conn->prepare(
                    "SELECT id FROM customer_vehicle_details
                     WHERE customer_id = ?
                       AND ((? <> '' AND vehicle_no = ?) OR (? <> '' AND imei_no = ?))
                     ORDER BY id LIMIT 2 FOR UPDATE"
                );
                if (!$vehicleStmt) {
                    throw new RuntimeException('Unable to prepare existing vehicle lookup.');
                }
                $vehicleStmt->bind_param('issss', $customerId, $vehicleNo, $vehicleNo, $imei, $imei);
                if (!$vehicleStmt->execute()) {
                    $vehicleStmt->close();
                    throw new RuntimeException('Unable to identify the existing customer vehicle.');
                }
                $matchedVehicles = $vehicleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $vehicleStmt->close();
                if (count($matchedVehicles) > 1) {
                    throw new RuntimeException('The submitted vehicle information matches multiple existing records.');
                }
                if ($matchedVehicles) {
                    $vehicle['vehicle_record_id'] = (int) $matchedVehicles[0]['id'];
                    $vehicle['append_vehicle'] = false;
                } else {
                    $vehicle['append_vehicle'] = true;
                }
            }
        } else {
            $vehicle['append_vehicle'] = true;
        }
        $submission['vehicles'] = [$vehicle];
    }

    $submission['step2'] = $step2;
    foreach (['step3' => $step3, 'step4' => $step4, 'step5' => $step5] as $step => $values) {
        if ($values) {
            $submission[$step] = $values;
        }
    }
    if ($hasPaymentData) {
        $submission['payment_part1'] = $step4;
        $submission['payment_part2'] = $paymentPart2;
    }
    $json = json_encode($submission, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 2000000) {
        throw new RuntimeException('Courier verification details are too large or could not be encoded.');
    }

    $insert = $conn->prepare(
        "INSERT INTO customer_payment_verification_submissions
            (submitter_user_id, existing_customer_id, status, submission_data, submitted_at)
         VALUES (?, NULLIF(?, 0), 'Pending Verification', ?, CURRENT_TIMESTAMP)"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare the courier verification request.');
    }
    $insert->bind_param('iis', $submitterUserId, $customerId, $json);
    if (!$insert->execute()) {
        $insert->close();
        throw new RuntimeException('Unable to save the courier verification request.');
    }
    $submissionId = (int) $insert->insert_id;
    $insert->close();

    insertCustomerPaymentVerificationHistory(
        $conn,
        $submissionId,
        null,
        'Pending Verification',
        'Courier reached its destination; customer details submitted for verification.',
        $submitterUserId
    );

    $workflow['_verification'] = [
        'submission_id' => $submissionId,
        'request_ids' => $requestIds
    ];
    $linkedWorkflowJson = json_encode($workflow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($linkedWorkflowJson === false) {
        throw new RuntimeException('Unable to link courier requests to verification.');
    }
    $link = $conn->prepare('UPDATE courier_requests SET new_customer_data = ?, customer_id = NULLIF(?, 0) WHERE id = ?');
    if (!$link) {
        throw new RuntimeException('Unable to prepare courier verification links.');
    }
    foreach ($requestIds as $requestId) {
        $link->bind_param('sii', $linkedWorkflowJson, $customerId, $requestId);
        if (!$link->execute()) {
            $link->close();
            throw new RuntimeException('Unable to link a courier request to verification.');
        }
    }
    $link->close();
    return $submissionId;
}
