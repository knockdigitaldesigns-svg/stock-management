<?php
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/payment_modes.php';
require_once __DIR__ . '/transaction_ids.php';

function updateStockAllocationPayment(
    mysqli $conn,
    int $allocationId,
    array $data,
    array $currentUser,
    ?array $allocation = null,
    bool $allowDealerSim = false
): array {
    $totalAmountRaw = $data['total_amount'] ?? null;
    $amountPaidRaw = $data['amount_paid'] ?? null;
    if (!is_numeric($totalAmountRaw) || !is_numeric($amountPaidRaw)) {
        throw new InvalidArgumentException('Total Amount and Amount Paid are required.');
    }

    $totalAmount = round((float) $totalAmountRaw, 2);
    $amountPaid = round((float) $amountPaidRaw, 2);
    if ($totalAmount < 0) {
        throw new InvalidArgumentException('Total Amount cannot be negative.');
    }
    if ($amountPaid < 0) {
        throw new InvalidArgumentException('Amount Paid cannot be negative.');
    }
    if ($amountPaid > $totalAmount) {
        throw new InvalidArgumentException('Amount Paid cannot exceed Total Amount.');
    }

    $paymentMode = trim((string) ($data['payment_mode'] ?? ''));
    if ($paymentMode !== '' && !in_array($paymentMode, getPaymentModes(), true)) {
        throw new InvalidArgumentException('Invalid payment mode.');
    }
    $paymentMode = $paymentMode !== '' ? $paymentMode : null;
    $transactionId = normalizeTransactionId($data['transaction_id'] ?? '');
    if ($paymentMode !== null && $paymentMode !== 'Cash' && $transactionId === '') {
        throw new InvalidArgumentException('Transaction ID is required for the selected Payment Mode.');
    }
    if ($paymentMode === 'Cash') {
        $transactionId = '';
    }

    $amountPending = max(0, round($totalAmount - $amountPaid, 2));
    $paymentStatus = $totalAmount <= 0
        ? 'Not Paid'
        : ($amountPending <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid'));
    $requestedStatus = trim((string) ($data['payment_status'] ?? ''));
    if ($requestedStatus !== '' && $requestedStatus !== $paymentStatus) {
        throw new InvalidArgumentException("Payment status must be $paymentStatus for the entered amounts.");
    }
    if ($allocation === null) {
        $select = $conn->prepare('SELECT * FROM stock_allocations WHERE id = ? FOR UPDATE');
        if (!$select) {
            throw new RuntimeException('Unable to prepare allocation payment lookup.');
        }
        $select->bind_param('i', $allocationId);
        if (!$select->execute()) {
            $error = $select->error;
            $select->close();
            throw new RuntimeException('Unable to load allocation payment: ' . $error);
        }
        $allocation = $select->get_result()->fetch_assoc();
        $select->close();
        if (!$allocation) {
            throw new RuntimeException('Allocation not found.');
        }
    }
    if (!$allowDealerSim && ($allocation['owner_type'] ?? '') === 'dealer' && !empty($allocation['sim_id'])) {
        throw new InvalidArgumentException('Dealer SIM payments must be recorded as separate payments from the Dealer SIM Activation page.');
    }

    $paymentDate = array_key_exists('payment_date', $data)
        ? trim((string) $data['payment_date'])
        : (string) ($allocation['payment_date'] ?? '');
    if ($paymentDate !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paymentDate, $dateParts)
            || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
            throw new InvalidArgumentException('Please enter a valid Payment Date.');
        }
    } else {
        $paymentDate = null;
    }

    $paymentFields = [
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'pending_amount' => $amountPending,
        'payment_status' => $paymentStatus,
        'payment_date' => $paymentDate,
        'payment_mode' => $paymentMode,
        'transaction_id' => $transactionId !== '' ? $transactionId : null
    ];
    writeChangedFields($conn, $allocationId, 'Stock Allocation Payment', $allocation, $paymentFields, $currentUser);

    $update = $conn->prepare(
        "UPDATE stock_allocations
         SET total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?,
             payment_date = ?, payment_mode = ?, transaction_id = NULLIF(?, '')
         WHERE id = ?"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare allocation payment update.');
    }
    $update->bind_param(
        'dddssssi',
        $totalAmount,
        $amountPaid,
        $amountPending,
        $paymentStatus,
        $paymentDate,
        $paymentMode,
        $transactionId,
        $allocationId
    );
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to update payment: ' . $error);
    }
    $transactionIdIsPaymentHistory = false;
    if ($allowDealerSim && $transactionId !== '') {
        $historyLookup = $conn->prepare(
            'SELECT is_legacy_snapshot FROM dealer_sim_allocation_payments
             WHERE allocation_id = ? AND transaction_id = ? LIMIT 1'
        );
        if (!$historyLookup) {
            throw new RuntimeException('Unable to prepare SIM payment transaction lookup: ' . $conn->error);
        }
        $historyLookup->bind_param('is', $allocationId, $transactionId);
        if (!$historyLookup->execute()) {
            $error = $historyLookup->error;
            $historyLookup->close();
            throw new RuntimeException('Unable to inspect SIM payment transaction history: ' . $error);
        }
        $historyRow = $historyLookup->get_result()->fetch_assoc();
        $historyLookup->close();
        $transactionIdIsPaymentHistory = $historyRow && (int) $historyRow['is_legacy_snapshot'] !== 1;
    }
    if ($transactionIdIsPaymentHistory) {
        reserveTransactionId($conn, '', 'stock_allocations', (string) $allocationId);
    } else {
        reserveTransactionId($conn, $transactionId, 'stock_allocations', (string) $allocationId);
    }
    $update->close();

    return $paymentFields;
}
?>
