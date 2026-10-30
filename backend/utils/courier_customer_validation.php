<?php

function hasCourierWorkflowStepData(array $step): bool
{
    foreach ($step as $value) {
        if (is_array($value) && hasCourierWorkflowStepData($value)) {
            return true;
        }
        if ($value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== '0') {
            return true;
        }
    }

    return false;
}

function buildCourierPaymentPart2(array $step4, array $step5): array
{
    $extraFields = $step5;
    foreach ([
        'totalSaleAmount',
        'deviceCharge',
        'softwareCharge',
        'technicianCharge',
        'simCharge',
        'courierCharge',
        'totalAmount',
        'amountPaid',
        'amountPending',
        'paymentStatus',
        'paymentMode',
        'transactionId',
        'cashToTechnician',
        'cashToDealer',
        'cashToEt'
    ] as $field) {
        unset($extraFields[$field]);
    }

    $step5TotalSaleAmount = $step5['totalSaleAmount'] ?? null;
    $step4TotalSaleAmount = $step4['totalSaleAmount'] ?? null;
    $step5PaymentMode = $step5['paymentMode'] ?? null;
    $step4PaymentMode = $step4['paymentMode'] ?? null;
    $step5TransactionId = $step5['transactionId'] ?? null;
    $step4TransactionId = $step4['transactionId'] ?? null;

    return array_merge($extraFields, [
        'total_sale_amount' => trim((string) $step5TotalSaleAmount) !== ''
            ? $step5TotalSaleAmount
            : $step4TotalSaleAmount,
        'device_charge' => $step5['deviceCharge'] ?? 0,
        'software_charge' => $step5['softwareCharge'] ?? 0,
        'technician_charge' => $step5['technicianCharge'] ?? 0,
        'sim_charge' => $step5['simCharge'] ?? 0,
        'courier_charge' => $step5['courierCharge'] ?? 0,
        'total_amount' => $step5['totalAmount'] ?? 0,
        'amount_paid' => $step5['amountPaid'] ?? 0,
        'amount_pending' => $step5['amountPending'] ?? 0,
        'payment_status' => $step5['paymentStatus'] ?? '',
        'payment_mode' => trim((string) $step5PaymentMode) !== '' ? $step5PaymentMode : ($step4PaymentMode ?? ''),
        'transaction_id' => trim((string) $step5TransactionId) !== ''
            ? $step5TransactionId
            : ($step4TransactionId ?? ''),
        'cash_to_technician' => !empty($step5['cashToTechnician']),
        'cash_to_dealer' => !empty($step5['cashToDealer']),
        'cash_to_et' => !empty($step5['cashToEt'])
    ]);
}

function courierPaymentTotalSaleAmount(array $step4, array $step5): float
{
    $step4Value = $step4['totalSaleAmount'] ?? null;
    $step5Value = $step5['totalSaleAmount'] ?? null;
    $value = trim((string) $step4Value) !== '' ? $step4Value : $step5Value;
    return (float) ($value ?? 0);
}

function hasCourierPaymentData(array $step4, array $step5): bool
{
    return courierPaymentTotalSaleAmount($step4, $step5) > 0
        || trim((string) (($step4['paymentMode'] ?? '') !== '' ? $step4['paymentMode'] : ($step5['paymentMode'] ?? ''))) !== ''
        || trim((string) (($step4['transactionId'] ?? '') !== '' ? $step4['transactionId'] : ($step5['transactionId'] ?? ''))) !== ''
        || (float) ($step5['deviceCharge'] ?? 0) > 0
        || (float) ($step5['softwareCharge'] ?? 0) > 0
        || (float) ($step5['technicianCharge'] ?? 0) > 0
        || (float) ($step5['simCharge'] ?? 0) > 0
        || (float) ($step5['courierCharge'] ?? 0) > 0
        || (float) ($step5['amountPaid'] ?? 0) > 0;
}

function mapCourierSoftwareToCustomerPlatform(mysqli $conn, array $workflow, string $software): array
{
    $software = trim($software);
    if ($software === '') {
        throw new InvalidArgumentException('Software is required to select the Customer Platform.');
    }
    if (!is_array($workflow['step1'] ?? null)) {
        throw new InvalidArgumentException('Customer Details are required for a customer courier request.');
    }

    $stmt = $conn->prepare(
        "SELECT id FROM platforms
         WHERE BINARY TRIM(platform_name) = BINARY ? AND status = 'Active'
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Software-to-Platform mapping.');
    }
    $stmt->bind_param('s', $software);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to map the selected Software to a Customer Platform.');
    }
    $platformId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($platformId <= 0) {
        throw new InvalidArgumentException('Selected Software must exactly match an active Customer Platform.');
    }

    $workflow['step1']['platform_id'] = $platformId;
    return $workflow;
}

function validateCourierNewCustomerProfile(array $profile): array
{
    $platformId = (int) ($profile['platform_id'] ?? 0);
    $username = trim((string) ($profile['username'] ?? ''));
    $primaryMobile = trim((string) ($profile['primary_mobile_no'] ?? ''));
    $secondaryMobile = trim((string) ($profile['secondary_mobile_no'] ?? ''));
    $email = trim((string) ($profile['email'] ?? ''));
    $location = trim((string) ($profile['location'] ?? ''));
    $pincode = trim((string) ($profile['pincode'] ?? ''));

    if ($platformId <= 0) {
        throw new InvalidArgumentException('Platform is required.');
    }
    if ($username === '') {
        throw new InvalidArgumentException('Username is required.');
    }
    if (strlen($username) > 100) {
        throw new InvalidArgumentException('Username must not exceed 100 characters.');
    }
    if (!preg_match('/^[a-zA-Z0-9.@_-]+$/', $username)) {
        throw new InvalidArgumentException('Username can contain only letters, numbers, @, dot, underscore and hyphen.');
    }
    if (!preg_match('/^[0-9]{10}$/', $primaryMobile)) {
        throw new InvalidArgumentException('Primary mobile number must contain exactly 10 digits.');
    }
    if ($secondaryMobile !== '') {
        if (!preg_match('/^[0-9]{10}$/', $secondaryMobile)) {
            throw new InvalidArgumentException('Secondary mobile number must contain exactly 10 digits.');
        }
        if ($secondaryMobile === $primaryMobile) {
            throw new InvalidArgumentException('Secondary mobile number cannot be the same as primary mobile number.');
        }
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }
    if ($location === '') {
        throw new InvalidArgumentException('Location is required.');
    }
    if (!preg_match('/^[0-9]{6}$/', $pincode)) {
        throw new InvalidArgumentException('Pincode must contain exactly 6 digits.');
    }

    return [
        'platform_id' => $platformId,
        'username' => $username,
        'primary_mobile_no' => $primaryMobile,
        'secondary_mobile_no' => $secondaryMobile,
        'email' => $email,
        'location' => $location,
        'pincode' => $pincode
    ];
}
