<?php

function courierPaymentAmountToCents($value, string $label): int
{
    if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($label . ' must be a non-negative amount.');
    }
    $amount = trim((string)($value ?? ''));
    if ($amount === '') {
        return 0;
    }
    if (!preg_match('/^\d+(?:\.\d*)?$/', $amount)) {
        throw new InvalidArgumentException($label . ' must be a non-negative amount.');
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 10 || (strlen($whole) === 10 && strcmp($whole, '9999999999') > 0)) {
        throw new InvalidArgumentException($label . ' exceeds the supported amount.');
    }

    $fraction = str_pad($fraction, 3, '0');
    $cents = ((int)$whole * 100) + (int)substr($fraction, 0, 2);
    if ((int)$fraction[2] >= 5) {
        $cents++;
    }
    if ($cents > 999999999999) {
        throw new InvalidArgumentException($label . ' exceeds the supported amount.');
    }

    return $cents;
}

function courierFormatInrCents(int $cents): string
{
    $rupees = (string)intdiv($cents, 100);
    if (strlen($rupees) > 3) {
        $lastThree = substr($rupees, -3);
        $prefix = substr($rupees, 0, -3);
        $groups = [];
        while (strlen($prefix) > 2) {
            array_unshift($groups, substr($prefix, -2));
            $prefix = substr($prefix, 0, -2);
        }
        array_unshift($groups, $prefix);
        $rupees = implode(',', $groups) . ',' . $lastThree;
    }

    $fraction = $cents % 100;
    return '₹' . $rupees . ($fraction > 0 ? '.' . str_pad((string)$fraction, 2, '0', STR_PAD_LEFT) : '');
}

function validateCourierPaymentWorkflow($requestData, string $recipientType): void
{
    if (!is_array($requestData)) {
        return;
    }

    $workflow = in_array($recipientType, ['Dealer', 'Technician'], true)
        ? ($requestData['payment_workflow'] ?? null)
        : $requestData;
    if (!is_array($workflow)) {
        return;
    }

    $step4 = $workflow['step4'] ?? [];
    $step5 = $workflow['step5'] ?? [];
    if (!is_array($step4) || !is_array($step5)) {
        throw new InvalidArgumentException('Courier payment details are invalid.');
    }
    $hasPaymentAmount = array_key_exists('totalSaleAmount', $step4) ||
        array_key_exists('totalAmount', $step5) ||
        array_intersect(['deviceCharge', 'softwareCharge', 'technicianCharge', 'simCharge', 'courierCharge'], array_keys($step5));
    if (!$hasPaymentAmount) {
        return;
    }

    $saleAmount = courierPaymentAmountToCents(
        $step4['totalSaleAmount'] ?? '',
        'Payment Part 1 Total Sale Amount'
    );
    $charges = 0;
    foreach ([
        'deviceCharge' => 'Device Charge',
        'softwareCharge' => 'Software Charge',
        'technicianCharge' => 'Technician Charge',
        'simCharge' => 'SIM Charge',
        'courierCharge' => 'Courier Charge'
    ] as $field => $label) {
        $charges += courierPaymentAmountToCents($step5[$field] ?? '', $label);
    }

    if ($saleAmount !== $charges) {
        throw new InvalidArgumentException(
            'Payment amount mismatch! Payment Part 1 Total Amount (' . courierFormatInrCents($saleAmount) .
            ') must match Payment Part 2 Total Charges (' . courierFormatInrCents($charges) .
            '). Please correct the amounts before saving.'
        );
    }
}
