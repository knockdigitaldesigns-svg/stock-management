<?php
require_once __DIR__ . '/payment_modes.php';

function courierRequestDetails($data, ?array $existing = null, bool $requireAddress = false): array
{
    $fields = [
        'delivery_recipient_name' => 150,
        'delivery_mobile_no' => 20,
        'delivery_address_line1' => 255,
        'delivery_address_line2' => 255,
        'delivery_city' => 100,
        'delivery_district' => 100,
        'delivery_state' => 100,
        'delivery_pincode' => 10,
        'delivery_notes' => null,
        'payment_method' => 50,
        'payment_transaction_id' => 100,
        'payment_date' => 10,
        'payment_notes' => null
    ];
    $details = [];
    foreach ($fields as $field => $maxLength) {
        $value = property_exists($data, $field)
            ? trim((string) ($data->{$field} ?? ''))
            : trim((string) ($existing[$field] ?? ''));
        if ($maxLength !== null && strlen($value) > $maxLength) {
            throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' is too long.');
        }
        $details[$field] = $value === '' ? null : $value;
    }

    $amountValue = property_exists($data, 'payment_amount')
        ? trim((string) ($data->payment_amount ?? ''))
        : trim((string) ($existing['payment_amount'] ?? ''));
    if ($amountValue !== '' && (!is_numeric($amountValue) || (float) $amountValue < 0 || (float) $amountValue > 9999999999.99)) {
        throw new InvalidArgumentException('Payment Amount must be a non-negative number.');
    }
    $details['payment_amount'] = $amountValue === '' ? null : round((float) $amountValue, 2);

    $paymentStatus = property_exists($data, 'payment_status')
        ? trim((string) ($data->payment_status ?? 'Pending'))
        : trim((string) ($existing['payment_status'] ?? 'Pending'));
    if (!in_array($paymentStatus, ['Pending', 'Paid'], true)) {
        throw new InvalidArgumentException('Payment Status must be Pending or Paid.');
    }
    $details['payment_status'] = $paymentStatus;

    $paymentMethod = $details['payment_method'];
    if ($paymentMethod !== null && !in_array($paymentMethod, getPaymentModes(), true)) {
        throw new InvalidArgumentException('Select a valid Payment Method.');
    }
    $hasAddress = false;
    foreach ([
        'delivery_recipient_name',
        'delivery_mobile_no',
        'delivery_address_line1',
        'delivery_address_line2',
        'delivery_city',
        'delivery_district',
        'delivery_state',
        'delivery_pincode'
    ] as $field) {
        if ($details[$field] !== null) {
            $hasAddress = true;
            break;
        }
    }
    if ($existing) {
        foreach ([
            'delivery_recipient_name',
            'delivery_mobile_no',
            'delivery_address_line1',
            'delivery_address_line2',
            'delivery_city',
            'delivery_district',
            'delivery_state',
            'delivery_pincode'
        ] as $field) {
            if (!empty($existing[$field])) {
                $hasAddress = true;
                break;
            }
        }
    }
    if ($requireAddress || $hasAddress) {
        foreach ([
            'delivery_recipient_name' => 'Recipient Name',
            'delivery_mobile_no' => 'Mobile Number',
            'delivery_address_line1' => 'Address Line 1',
            'delivery_city' => 'City',
            'delivery_state' => 'State',
            'delivery_pincode' => 'PIN Code'
        ] as $field => $label) {
            if ($details[$field] === null) {
                throw new InvalidArgumentException($label . ' is required for delivery.');
            }
        }
        if (!preg_match('/^\+?[\d\s()-]+$/', (string) $details['delivery_mobile_no'])) {
            throw new InvalidArgumentException('Mobile Number contains invalid characters.');
        }
        $mobileDigits = preg_replace('/\D/', '', (string) $details['delivery_mobile_no']);
        if (strlen($mobileDigits) < 8 || strlen($mobileDigits) > 15) {
            throw new InvalidArgumentException('Mobile Number must contain 8 to 15 digits.');
        }
        if (!preg_match('/^\d{6}$/', (string) $details['delivery_pincode'])) {
            throw new InvalidArgumentException('PIN Code must contain exactly 6 digits.');
        }
    }

    $paymentDate = $details['payment_date'];
    if ($paymentDate !== null) {
        $parsedDate = DateTime::createFromFormat('!Y-m-d', $paymentDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $paymentDate || $paymentDate > date('Y-m-d')) {
            throw new InvalidArgumentException('Payment Date must be a valid date no later than today.');
        }
    }
    if ($paymentStatus === 'Paid') {
        if ($details['payment_amount'] === null || $details['payment_amount'] <= 0) {
            throw new InvalidArgumentException('Payment Amount must be greater than zero when status is Paid.');
        }
        if ($paymentDate === null) {
            throw new InvalidArgumentException('Payment Date is required when status is Paid.');
        }
        if ($paymentMethod !== null && $paymentMethod !== 'Cash' && $details['payment_transaction_id'] === null) {
            throw new InvalidArgumentException('Payment Reference is required for non-cash paid payments.');
        }
    }

    return $details;
}

function saveCourierRequestDetails(mysqli $conn, int $requestId, array $details): void
{
    $stmt = $conn->prepare(
        'UPDATE courier_requests SET
            delivery_recipient_name = ?,
            delivery_mobile_no = ?,
            delivery_address_line1 = ?,
            delivery_address_line2 = ?,
            delivery_city = ?,
            delivery_district = ?,
            delivery_state = ?,
            delivery_pincode = ?,
            delivery_notes = ?,
            payment_amount = ?,
            payment_status = ?,
            payment_method = ?,
            payment_transaction_id = ?,
            payment_date = ?,
            payment_notes = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare courier payment and delivery details: ' . $conn->error);
    }

    $stmt->bind_param(
        'sssssssssdsssssi',
        $details['delivery_recipient_name'],
        $details['delivery_mobile_no'],
        $details['delivery_address_line1'],
        $details['delivery_address_line2'],
        $details['delivery_city'],
        $details['delivery_district'],
        $details['delivery_state'],
        $details['delivery_pincode'],
        $details['delivery_notes'],
        $details['payment_amount'],
        $details['payment_status'],
        $details['payment_method'],
        $details['payment_transaction_id'],
        $details['payment_date'],
        $details['payment_notes'],
        $requestId
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to save courier payment and delivery details: ' . $error);
    }
    $stmt->close();
}
