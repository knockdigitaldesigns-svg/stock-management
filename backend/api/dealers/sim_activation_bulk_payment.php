<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/renewal_history.php';
require_once '../../utils/transaction_ids.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['dealer_sim_activation.edit', 'dealers.edit', 'stock.update']);
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    sendResponse(false, 'Invalid bulk SIM payment request.', [], [], 400);
}

$requestKey = trim((string)($payload['request_key'] ?? ''));
$paymentMode = trim((string)($payload['payment_mode'] ?? ''));
$transactionId = trim((string)($payload['transaction_id'] ?? ''));
$paymentDate = trim((string)($payload['payment_date'] ?? ''));
$remarks = trim((string)($payload['remarks'] ?? ''));
$items = $payload['allocations'] ?? null;
$reportedTotal = $payload['total_amount_to_pay'] ?? null;

if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestKey)) {
    sendResponse(false, 'A valid bulk payment request key is required.', [], [], 400);
}
if (!is_array($items) || count($items) < 1) {
    sendResponse(false, 'Select at least one SIM activation for payment.', [], [], 400);
}
if (!is_numeric($reportedTotal) || round((float)$reportedTotal, 2) <= 0) {
    sendResponse(false, 'Total payment amount must be greater than zero.', [], [], 400);
}
if (!in_array($paymentMode, getPaymentModes(), true)) {
    sendResponse(false, 'Select a valid Payment Mode.', [], [], 400);
}
if ($transactionId !== '' && !preg_match('/^[0-9]{6}$/', $transactionId)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}
if ($paymentMode !== 'Cash' && $transactionId === '') {
    sendResponse(false, 'Transaction ID is required for the selected Payment Mode.', [], [], 400);
}
if ($paymentMode === 'Cash') {
    $transactionId = '';
}
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paymentDate, $dateParts)
    || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
    sendResponse(false, 'Please select a valid Payment Date.', [], [], 400);
}
if (strlen($remarks) > 4000) {
    sendResponse(false, 'Payment remarks cannot exceed 4000 characters.', [], [], 400);
}

$normalizedItems = [];
$ids = [];
$calculatedTotal = 0;
foreach ($items as $item) {
    if (!is_array($item)) {
        sendResponse(false, 'Invalid SIM payment details.', [], [], 400);
    }
    $allocationId = (int)($item['allocation_id'] ?? 0);
    $amountRaw = $item['amount_paid'] ?? null;
    if ($allocationId <= 0 || !is_numeric($amountRaw)) {
        sendResponse(false, 'Each selected SIM requires a valid payment amount.', [], [], 400);
    }
    $amount = round((float)$amountRaw, 2);
    if ($amount <= 0) {
        sendResponse(false, 'Payment Amount must be greater than zero for every SIM.', [], [], 400);
    }
    if (isset($normalizedItems[$allocationId])) {
        sendResponse(false, 'A SIM activation was selected more than once.', [], [], 400);
    }
    $normalizedItems[$allocationId] = [
        'allocation_id' => $allocationId,
        'amount_paid' => $amount
    ];
    $ids[] = $allocationId;
    $calculatedTotal += $amount;
}
$calculatedTotal = round($calculatedTotal, 2);
if (abs($calculatedTotal - round((float)$reportedTotal, 2)) > 0.001) {
    sendResponse(false, 'Total payment amount must equal the sum of the individual SIM payment amounts.', [], [], 400);
}
sort($ids, SORT_NUMERIC);

$payloadHashFor = static function (int $allocationId) use (
    $paymentMode,
    $transactionId,
    $paymentDate,
    $remarks,
    $normalizedItems
): string {
    return hash('sha256', json_encode([
        'payment_mode' => $paymentMode,
        'transaction_id' => $transactionId,
        'payment_date' => $paymentDate,
        'remarks' => $remarks,
        'allocation' => $normalizedItems[$allocationId]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$tableSql = "CREATE TABLE IF NOT EXISTS dealer_allocation_payment_requests (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL DEFAULT '',
    transaction_id CHAR(6) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dealer_allocation_payment_request (allocation_id, request_key),
    INDEX idx_dealer_allocation_payment_request_key (request_key),
    CONSTRAINT fk_dealer_allocation_payment_request_allocation
        FOREIGN KEY (allocation_id) REFERENCES stock_allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($tableSql)) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare bulk payment duplicate protection: ' . $error, [], [], 500);
}
$transactionColumn = $conn->query("SHOW COLUMNS FROM dealer_allocation_payment_requests LIKE 'transaction_id'");
if (!$transactionColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to inspect bulk payment transaction tracking: ' . $error, [], [], 500);
}
if ($transactionColumn->num_rows === 0 && !$conn->query(
    "ALTER TABLE dealer_allocation_payment_requests ADD COLUMN transaction_id CHAR(6) DEFAULT NULL AFTER payload_hash"
)) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare bulk payment transaction tracking: ' . $error, [], [], 500);
}
$bulkHistoryColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'bulk_payment_request_key'");
if (!$bulkHistoryColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to inspect SIM payment history tracking: ' . $error, [], [], 500);
}
if ($bulkHistoryColumn->num_rows === 0 && !$conn->query(
    'ALTER TABLE renewal_history ADD COLUMN bulk_payment_request_key VARCHAR(64) DEFAULT NULL AFTER changed_by'
)) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare SIM payment history tracking: ' . $error, [], [], 500);
}
$sourceHistoryColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'source_history_id'");
if (!$sourceHistoryColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to inspect SIM renewal payment links: ' . $error, [], [], 500);
}
if ($sourceHistoryColumn->num_rows === 0 && !$conn->query(
    'ALTER TABLE renewal_history ADD COLUMN source_history_id INT DEFAULT NULL AFTER bulk_payment_request_key'
)) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare SIM renewal payment links: ' . $error, [], [], 500);
}
$sourceBalanceColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'source_balance_updated'");
if (!$sourceBalanceColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to inspect SIM renewal balance tracking: ' . $error, [], [], 500);
}
if ($sourceBalanceColumn->num_rows === 0 && !$conn->query(
    'ALTER TABLE renewal_history ADD COLUMN source_balance_updated TINYINT(1) NOT NULL DEFAULT 1 AFTER source_history_id'
)) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare SIM renewal balance tracking: ' . $error, [], [], 500);
}
try {
    ensureRenewalHistoryPaymentActionType($conn);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal payment history: ' . $error->getMessage(), [], [], 500);
}

$conn->begin_transaction();
try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $select = $conn->prepare(
        "SELECT id, owner_id, sim_id, total_amount, amount_paid, pending_amount, payment_status,
                sim_status, sim_expiry_date
         FROM stock_allocations
         WHERE owner_type = 'dealer' AND sim_id IS NOT NULL AND id IN ($placeholders)
         ORDER BY id FOR UPDATE"
    );
    if (!$select) {
        throw new RuntimeException('Unable to prepare SIM payment validation: ' . $conn->error);
    }
    $types = str_repeat('i', count($ids));
    $values = $ids;
    $bindings = [$types];
    foreach ($values as $index => $value) {
        $bindings[] = &$values[$index];
    }
    call_user_func_array([$select, 'bind_param'], $bindings);
    if (!$select->execute()) {
        $error = $select->error;
        $select->close();
        throw new RuntimeException('Unable to validate selected SIM activations: ' . $error);
    }
    $result = $select->get_result();
    $allocations = [];
    while ($row = $result->fetch_assoc()) {
        $allocations[(int)$row['id']] = $row;
    }
    $select->close();
    if (count($allocations) !== count($ids)) {
        throw new InvalidArgumentException('One or more selected SIM activations are no longer available for payment.');
    }

    $existingStmt = $conn->prepare(
        'SELECT allocation_id, payload_hash FROM dealer_allocation_payment_requests WHERE request_key = ? FOR UPDATE'
    );
    if (!$existingStmt) {
        throw new RuntimeException('Unable to prepare duplicate bulk payment check: ' . $conn->error);
    }
    $existingStmt->bind_param('s', $requestKey);
    if (!$existingStmt->execute()) {
        $error = $existingStmt->error;
        $existingStmt->close();
        throw new RuntimeException('Unable to check for a repeated bulk payment: ' . $error);
    }
    $existingResult = $existingStmt->get_result();
    $existingHashes = [];
    while ($row = $existingResult->fetch_assoc()) {
        $existingHashes[(int)$row['allocation_id']] = (string)$row['payload_hash'];
    }
    $existingStmt->close();
    if ($existingHashes) {
        $existingIds = array_keys($existingHashes);
        sort($existingIds, SORT_NUMERIC);
        if ($existingIds !== $ids) {
            throw new InvalidArgumentException('This bulk payment request key was already used for a different SIM selection.');
        }
        foreach ($ids as $allocationId) {
            if (!isset($existingHashes[$allocationId])
                || !hash_equals($existingHashes[$allocationId], $payloadHashFor($allocationId))) {
                throw new InvalidArgumentException('This bulk payment request key was already used with different payment details.');
            }
        }
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Bulk SIM payment was already recorded.', ['idempotent_replay' => true]);
    }

    $lifecycleBalances = [];
    $lifecyclePendingByAllocation = [];
    $lifecyclePaidByAllocation = [];
    $lifecycleStmt = $conn->prepare(
        "SELECT rh.id, rh.amount_paid, rh.amount_paid AS stored_amount_paid,
                rh.payment_amount AS charge_amount,
                rh.amount_pending, rh.new_renewal_date, rh.old_renewal_date,
                rh.action_date, rh.old_status, rh.new_status, rh.payment_mode,
                rh.payment_date, rh.transaction_id,
                COALESCE((
                    SELECT SUM(payment.amount_paid)
                    FROM renewal_history payment
                    WHERE payment.source_history_id = rh.id
                      AND payment.action_type = 'Renewal Payment'
                      AND COALESCE(payment.source_balance_updated, 1) = 1
                ), 0) AS linked_mutating_paid,
                COALESCE((
                    SELECT SUM(payment.amount_paid)
                    FROM renewal_history payment
                    WHERE payment.source_history_id = rh.id
                      AND payment.action_type = 'Renewal Payment'
                      AND payment.source_balance_updated = 0
                ), 0) AS linked_payments
         FROM renewal_history rh
         WHERE rh.allocation_id = ?
           AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
         ORDER BY rh.id ASC
         FOR UPDATE"
    );
    if (!$lifecycleStmt) {
        throw new RuntimeException('Unable to prepare SIM lifecycle balance lookup: ' . $conn->error);
    }
    foreach ($ids as $allocationId) {
        $lifecycleStmt->bind_param('i', $allocationId);
        if (!$lifecycleStmt->execute()) {
            $error = $lifecycleStmt->error;
            $lifecycleStmt->close();
            throw new RuntimeException('Unable to load SIM lifecycle balances: ' . $error);
        }
        $balances = $lifecycleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($balances as &$balance) {
            $balance['amount_pending'] = max(
                0,
                round((float)$balance['amount_pending'] - (float)$balance['linked_payments'], 2)
            );
            $balance['amount_paid'] = round(
                (float)$balance['amount_paid'] + (float)$balance['linked_payments'],
                2
            );
        }
        unset($balance);
        $lifecycleBalances[$allocationId] = $balances;
        $lifecyclePendingByAllocation[$allocationId] = round(array_sum(array_map(
            static fn($balance) => (float)$balance['amount_pending'],
            $balances
        )), 2);
        $lifecyclePaidByAllocation[$allocationId] = round(array_sum(array_map(
            static fn($balance) => (float)$balance['amount_paid'],
            $balances
        )), 2);
    }
    $lifecycleStmt->close();

    foreach ($ids as $allocationId) {
        $allocation = $allocations[$allocationId];
        $allocationOutstanding = max(0, round((float)$allocation['total_amount'] - (float)$allocation['amount_paid'], 2));
        $outstanding = round($allocationOutstanding + $lifecyclePendingByAllocation[$allocationId], 2);
        if ($outstanding <= 0) {
            throw new InvalidArgumentException('SIM activation #' . $allocationId . ' has no outstanding balance.');
        }
        if ($normalizedItems[$allocationId]['amount_paid'] > $outstanding) {
            throw new InvalidArgumentException(
                'Payment for SIM activation #' . $allocationId . ' cannot exceed its current outstanding balance of ₹' . number_format($outstanding, 2) . '.'
            );
        }
    }

    if ($transactionId !== '') {
        reserveTransactionId($conn, $transactionId, 'dealer_allocation_payment_requests', $requestKey);
    }

    $userId = (int)($currentUser['user_id'] ?? 0);
    $recordedPayments = [];
    $updatedBalances = [];
    foreach ($ids as $allocationId) {
        $allocation = $allocations[$allocationId];
        $totalAmount = round((float)$allocation['total_amount'], 2);
        $previouslyPaid = round((float)$allocation['amount_paid'], 2);
        $previouslyPending = max(0, round($totalAmount - $previouslyPaid, 2));
        $amountToPay = $normalizedItems[$allocationId]['amount_paid'];
        $allocationApplied = min($previouslyPending, $amountToPay);
        $newAmountPaid = round($previouslyPaid + $allocationApplied, 2);
        $newPending = max(0, round($totalAmount - $newAmountPaid, 2));
        $lifecycleApplied = round($amountToPay - $allocationApplied, 2);
        $aggregatePendingAfter = round(
            $newPending + max(0, $lifecyclePendingByAllocation[$allocationId] - $lifecycleApplied),
            2
        );
        $aggregatePaidAfter = round(
            $newAmountPaid + $lifecyclePaidByAllocation[$allocationId] + $lifecycleApplied,
            2
        );
        $newStatus = $aggregatePendingAfter <= 0
            ? 'Paid'
            : ($aggregatePaidAfter > 0 ? 'Partially Paid' : 'Not Paid');

        if ($allocationApplied > 0) {
            $insert = $conn->prepare(
                "INSERT INTO dealer_sim_allocation_payments
                    (allocation_id, idempotency_key, total_amount_due, amount_paid, amount_pending,
                     payment_mode, transaction_id, payment_date, payment_status, remarks, is_legacy_snapshot, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), 0, ?)"
            );
            if (!$insert) {
                throw new RuntimeException('Unable to prepare SIM payment history insert: ' . $conn->error);
            }
            $insert->bind_param(
                'isdddsssssi',
                $allocationId,
                $requestKey,
                $totalAmount,
                $allocationApplied,
                $newPending,
                $paymentMode,
                $transactionId,
                $paymentDate,
                $newStatus,
                $remarks,
                $userId
            );
            if (!$insert->execute()) {
                $error = $insert->error;
                $insert->close();
                throw new RuntimeException('Unable to record SIM payment for activation #' . $allocationId . ': ' . $error);
            }
            $paymentId = (int)$insert->insert_id;
            $insert->close();
            $recordedPayments[] = [
                'allocation_id' => $allocationId,
                'charge_type' => 'SIM Allocation',
                'amount_paid' => $allocationApplied,
                'payment_mode' => $paymentMode,
                'payment_date' => $paymentDate,
                'transaction_id' => $transactionId !== '' ? $transactionId : null,
                'remarks' => $remarks !== '' ? $remarks : null
            ];

            writeAuditSnapshot(
                $conn,
                $allocationId,
                'Stock Allocation Payment',
                'Payment Added',
                [
                    'amount_paid' => $previouslyPaid,
                    'pending_amount' => $previouslyPending,
                    'payment_status' => $allocation['payment_status']
                ],
                [
                    'payment_id' => $paymentId,
                    'amount_paid' => $allocationApplied,
                    'amount_paid_total' => $newAmountPaid,
                    'pending_amount' => $newPending,
                    'payment_mode' => $paymentMode,
                    'transaction_id' => $transactionId !== '' ? $transactionId : null,
                    'payment_date' => $paymentDate,
                    'payment_status' => $newStatus,
                    'remarks' => $remarks !== '' ? $remarks : null
                ],
                $currentUser
            );
        }

        if ($lifecycleApplied > 0) {
            $remainingPayment = $lifecycleApplied;
            foreach ($lifecycleBalances[$allocationId] as $balance) {
                if ($remainingPayment <= 0) break;
                $rowPending = round((float)$balance['amount_pending'], 2);
                if ($rowPending <= 0) continue;
                $applied = min($rowPending, $remainingPayment);
                $newLifecyclePending = max(0, round($rowPending - $applied, 2));
                $historyId = (int)$balance['id'];
                $unrecordedInitialPaid = max(
                    0,
                    round((float)$balance['stored_amount_paid'] - (float)$balance['linked_mutating_paid'], 2)
                );
                if ($unrecordedInitialPaid > 0) {
                    $initialPaymentDate = $balance['payment_date'] ?: $balance['action_date'];
                    $initialPaymentPending = max(
                        0,
                        round((float)$balance['charge_amount'] - $unrecordedInitialPaid, 2)
                    );
                    $initialPayment = $conn->prepare(
                        "INSERT INTO renewal_history
                            (renewal_id, allocation_id, customer_id, action_type, action_date,
                             old_status, new_status, old_renewal_date, new_renewal_date,
                             payment_amount, amount_paid, amount_pending, payment_mode, payment_date,
                             transaction_id, changed_by, source_history_id, source_balance_updated)
                         VALUES (0, ?, 0, 'Renewal Payment', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?,
                                 NULLIF(?, ''), NULL, ?, 1)"
                    );
                    if (!$initialPayment) {
                        throw new RuntimeException('Unable to prepare existing renewal payment history: ' . $conn->error);
                    }
                    $initialPaymentMode = (string)($balance['payment_mode'] ?? '');
                    $initialTransactionId = (string)($balance['transaction_id'] ?? '');
                    $initialPaymentDateValue = (string)$initialPaymentDate;
                    $initialPayment->bind_param(
                        'isssssddsssi',
                        $allocationId,
                        $initialPaymentDateValue,
                        $balance['old_status'],
                        $balance['new_status'],
                        $balance['old_renewal_date'],
                        $balance['new_renewal_date'],
                        $unrecordedInitialPaid,
                        $initialPaymentPending,
                        $initialPaymentMode,
                        $initialPaymentDateValue,
                        $initialTransactionId,
                        $historyId
                    );
                    if (!$initialPayment->execute()) {
                        $error = $initialPayment->error;
                        $initialPayment->close();
                        throw new RuntimeException('Unable to preserve the existing renewal payment history: ' . $error);
                    }
                    $initialPayment->close();
                }
                $newLifecyclePaid = round((float)$balance['amount_paid'] + $applied, 2);
                $chargeUpdate = $conn->prepare(
                    "UPDATE renewal_history
                     SET amount_paid = ?, amount_pending = ?
                     WHERE id = ? AND allocation_id = ?
                       AND action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')"
                );
                if (!$chargeUpdate) {
                    throw new RuntimeException('Unable to prepare SIM lifecycle balance update: ' . $conn->error);
                }
                $chargeUpdate->bind_param(
                    'ddii',
                    $newLifecyclePaid,
                    $newLifecyclePending,
                    $historyId,
                    $allocationId
                );
                if (!$chargeUpdate->execute() || $chargeUpdate->affected_rows !== 1) {
                    $error = $chargeUpdate->error;
                    $chargeUpdate->close();
                    throw new RuntimeException('Unable to update SIM lifecycle charge #' . $historyId . ': ' . $error);
                }
                $chargeUpdate->close();

                $paymentSnapshotUpdate = $conn->prepare(
                    "UPDATE renewal_history
                     SET source_balance_updated = 1
                     WHERE source_history_id = ? AND action_type = 'Renewal Payment'
                       AND source_balance_updated = 0"
                );
                if (!$paymentSnapshotUpdate) {
                    throw new RuntimeException('Unable to prepare existing lifecycle payment history update: ' . $conn->error);
                }
                $paymentSnapshotUpdate->bind_param('i', $historyId);
                if (!$paymentSnapshotUpdate->execute()) {
                    $error = $paymentSnapshotUpdate->error;
                    $paymentSnapshotUpdate->close();
                    throw new RuntimeException('Unable to reconcile existing lifecycle payment history: ' . $error);
                }
                $paymentSnapshotUpdate->close();

                $status = (string)($allocation['sim_status'] ?? '');
                $expiryDate = $balance['new_renewal_date'] ?: ($allocation['sim_expiry_date'] ?: null);
                $paymentLog = $conn->prepare(
                    "INSERT INTO renewal_history
                        (renewal_id, allocation_id, customer_id, action_type, action_date,
                         old_status, new_status, old_renewal_date, new_renewal_date,
                         payment_amount, amount_paid, amount_pending, payment_mode, payment_date,
                         transaction_id, changed_by, bulk_payment_request_key, source_history_id,
                         source_balance_updated, notes)
                     VALUES (0, ?, 0, 'Renewal Payment', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NULLIF(?, ''),
                              ?, ?, ?, 1, NULLIF(?, ''))"
                );
                if (!$paymentLog) {
                    throw new RuntimeException('Unable to prepare SIM lifecycle payment history: ' . $conn->error);
                }
                $historyId = (int)$balance['id'];
                $paymentLog->bind_param(
                    'isssssddsssisis',
                    $allocationId,
                    $paymentDate,
                    $status,
                    $status,
                    $expiryDate,
                    $expiryDate,
                    $applied,
                    $newLifecyclePending,
                    $paymentMode,
                    $paymentDate,
                    $transactionId,
                    $userId,
                    $requestKey,
                    $historyId,
                    $remarks
                );
                if (!$paymentLog->execute()) {
                    $error = $paymentLog->error;
                    $paymentLog->close();
                    throw new RuntimeException('Unable to record SIM lifecycle payment: ' . $error);
                }
                $recordedPayments[] = [
                    'allocation_id' => $allocationId,
                    'charge_type' => 'SIM Lifecycle',
                    'charge_history_id' => $historyId,
                    'payment_id' => (int)$paymentLog->insert_id,
                    'amount_paid' => $applied,
                    'payment_mode' => $paymentMode,
                    'payment_date' => $paymentDate,
                    'transaction_id' => $transactionId !== '' ? $transactionId : null,
                    'remarks' => $remarks !== '' ? $remarks : null
                ];
                $paymentLog->close();
                $remainingPayment = round($remainingPayment - $applied, 2);
            }
            if ($remainingPayment > 0) {
                throw new RuntimeException('Unable to allocate the full payment to the latest SIM lifecycle balances.');
            }
        }

        $update = $conn->prepare(
            "UPDATE stock_allocations
             SET amount_paid = ?, pending_amount = ?, payment_status = ?,
                 payment_mode = ?, payment_date = ?, transaction_id = NULLIF(?, '')
             WHERE id = ? AND owner_type = 'dealer' AND sim_id IS NOT NULL"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare SIM payment summary update: ' . $conn->error);
        }
        $update->bind_param(
            'ddssssi',
            $newAmountPaid,
            $newPending,
            $newStatus,
            $paymentMode,
            $paymentDate,
            $transactionId,
            $allocationId
        );
        if (!$update->execute() || $update->affected_rows !== 1) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to update SIM activation #' . $allocationId . ' payment summary: ' . $error);
        }
        $update->close();
        $updatedBalances[] = [
            'allocation_id' => $allocationId,
            'amount_paid' => $newAmountPaid,
            'allocation_pending_amount' => $newPending,
            'total_pending_amount' => $aggregatePendingAfter,
            'payment_status' => $newStatus
        ];

        $payloadHash = $payloadHashFor($allocationId);
        $marker = $conn->prepare(
            "INSERT INTO dealer_allocation_payment_requests
                (allocation_id, request_key, payload_hash, transaction_id)
             VALUES (?, ?, ?, NULLIF(?, ''))"
        );
        if (!$marker) {
            throw new RuntimeException('Unable to prepare bulk SIM payment duplicate marker: ' . $conn->error);
        }
        $marker->bind_param('isss', $allocationId, $requestKey, $payloadHash, $transactionId);
        if (!$marker->execute()) {
            $error = $marker->error;
            $marker->close();
            throw new RuntimeException('Unable to save bulk SIM payment duplicate marker: ' . $error);
        }
        $marker->close();
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Bulk SIM payment recorded successfully.', [
        'allocations_paid' => count($ids),
        'payments' => $recordedPayments,
        'balances' => $updatedBalances
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    sendResponse(false, $error->getMessage(), [], [], $status);
}
?>
