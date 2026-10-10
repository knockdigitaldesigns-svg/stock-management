<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/transaction_ids.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['dealers.edit', 'stock.update']);
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    sendResponse(false, 'Invalid bulk payment request.', [], [], 400);
}

$dealerId = (int)($payload['dealer_id'] ?? 0);
$requestKey = trim((string)($payload['request_key'] ?? ''));
$paymentMode = trim((string)($payload['payment_mode'] ?? ''));
$paymentDate = trim((string)($payload['payment_date'] ?? ''));
$transactionId = trim((string)($payload['transaction_id'] ?? ''));
$remarks = trim((string)($payload['remarks'] ?? ''));
$items = $payload['allocations'] ?? null;

if ($dealerId <= 0 || !preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestKey)) {
    sendResponse(false, 'A valid dealer and bulk payment request key are required.', [], [], 400);
}
if (!is_array($items) || count($items) < 1) {
    sendResponse(false, 'Select at least one allocation for payment.', [], [], 400);
}
if (!in_array($paymentMode, getPaymentModes(), true)) {
    sendResponse(false, 'Select a valid Payment Mode.', [], [], 400);
}
if ($transactionId !== '' && !preg_match('/^[0-9]{6}$/', $transactionId)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}
if ($paymentMode !== 'Cash' && $transactionId === '') {
    sendResponse(false, 'A 6-digit Transaction ID is required for the selected Payment Mode.', [], [], 400);
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
$allocationIds = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        sendResponse(false, 'Invalid allocation payment details.', [], [], 400);
    }
    $allocationId = (int)($item['allocation_id'] ?? 0);
    $amountRaw = $item['amount_to_pay'] ?? null;
    if ($allocationId <= 0 || !is_numeric($amountRaw)) {
        sendResponse(false, 'Each selected allocation requires a valid payment amount.', [], [], 400);
    }
    $amount = round((float)$amountRaw, 2);
    if ($amount <= 0) {
        sendResponse(false, 'Payment Amount must be greater than zero for every allocation.', [], [], 400);
    }
    if (isset($allocationIds[$allocationId])) {
        sendResponse(false, 'An allocation was selected more than once.', [], [], 400);
    }
    $allocationIds[$allocationId] = true;
    $normalizedItems[$allocationId] = [
        'allocation_id' => $allocationId,
        'amount_to_pay' => $amount
    ];
}

$ids = array_keys($allocationIds);
sort($ids, SORT_NUMERIC);
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

$conn->begin_transaction();
try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT sa.* FROM stock_allocations sa
            WHERE sa.owner_type = 'dealer' AND sa.owner_id = ?
              AND sa.id IN ($placeholders)
            ORDER BY sa.id FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare dealer allocation validation: ' . $conn->error);
    }
    $types = 'i' . str_repeat('i', count($ids));
    $values = array_merge([$dealerId], $ids);
    $bindings = [$types];
    foreach ($values as $index => $value) {
        $bindings[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindings);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to validate selected allocations: ' . $error);
    }
    $result = $stmt->get_result();
    $allocations = [];
    while ($row = $result->fetch_assoc()) {
        $allocations[(int)$row['id']] = $row;
    }
    $stmt->close();
    if (count($allocations) !== count($ids)) {
        throw new InvalidArgumentException('One or more selected allocations do not belong to this dealer or are no longer available.');
    }

    $existingStmt = $conn->prepare('SELECT allocation_id, payload_hash FROM dealer_allocation_payment_requests WHERE request_key = ? FOR UPDATE');
    if (!$existingStmt) {
        throw new RuntimeException('Unable to prepare duplicate payment check: ' . $conn->error);
    }
    $existingStmt->bind_param('s', $requestKey);
    if (!$existingStmt->execute()) {
        $error = $existingStmt->error;
        $existingStmt->close();
        throw new RuntimeException('Unable to check for a repeated bulk payment: ' . $error);
    }
    $existingResult = $existingStmt->get_result();
    $existingIds = [];
    $existingHashes = [];
    while ($existingRow = $existingResult->fetch_assoc()) {
        $existingAllocationId = (int)$existingRow['allocation_id'];
        $existingIds[] = $existingAllocationId;
        $existingHashes[$existingAllocationId] = (string)$existingRow['payload_hash'];
    }
    $existingStmt->close();
    sort($existingIds, SORT_NUMERIC);
    if ($existingIds) {
        if ($existingIds !== $ids) {
            throw new InvalidArgumentException('This bulk payment request key was already used for a different allocation selection.');
        }
        foreach ($ids as $allocationId) {
            $payloadHash = hash('sha256', json_encode([
                'dealer_id' => $dealerId,
                'payment_mode' => $paymentMode,
                'payment_date' => $paymentDate,
                'transaction_id' => $transactionId,
                'remarks' => $remarks,
                'allocation' => $normalizedItems[$allocationId]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!isset($existingHashes[$allocationId]) || !hash_equals($existingHashes[$allocationId], $payloadHash)) {
                throw new InvalidArgumentException('This bulk payment request key was already used with different payment details.');
            }
        }
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Bulk payment was already recorded.', ['idempotent_replay' => true]);
    }

    foreach ($ids as $allocationId) {
        $allocation = $allocations[$allocationId];
        $totalAmount = round((float)$allocation['total_amount'], 2);
        $previouslyPaid = round((float)$allocation['amount_paid'], 2);
        $outstanding = max(0, round($totalAmount - $previouslyPaid, 2));
        $amountToPay = $normalizedItems[$allocationId]['amount_to_pay'];
        if ($outstanding <= 0) {
            throw new InvalidArgumentException("Allocation #$allocationId has no outstanding balance.");
        }
        if ($amountToPay > $outstanding) {
            throw new InvalidArgumentException(
                'Payment for allocation #' . $allocationId . ' cannot exceed its current outstanding balance of ₹' . number_format($outstanding, 2) . '.'
            );
        }
    }
    if ($transactionId !== '') {
        reserveTransactionId($conn, $transactionId, 'dealer_allocation_payment_requests', $requestKey);
    }

    $userId = (int)($currentUser['user_id'] ?? 0);
    foreach ($ids as $allocationId) {
        $allocation = $allocations[$allocationId];
        $totalAmount = round((float)$allocation['total_amount'], 2);
        $previouslyPaid = round((float)$allocation['amount_paid'], 2);
        $outstanding = max(0, round($totalAmount - $previouslyPaid, 2));
        $amountToPay = $normalizedItems[$allocationId]['amount_to_pay'];
        $newAmountPaid = round($previouslyPaid + $amountToPay, 2);
        $newPending = max(0, round($totalAmount - $newAmountPaid, 2));
        $newStatus = $newPending <= 0 ? 'Paid' : ($newAmountPaid > 0 ? 'Partially Paid' : 'Not Paid');

        if (!empty($allocation['sim_id'])) {
            $paymentInsert = $conn->prepare(
                "INSERT INTO dealer_sim_allocation_payments
                    (allocation_id, idempotency_key, total_amount_due, amount_paid, amount_pending,
                     payment_mode, transaction_id, payment_date, payment_status, remarks, is_legacy_snapshot, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), 0, ?)"
            );
            if (!$paymentInsert) {
                throw new RuntimeException('Unable to prepare SIM payment history insert: ' . $conn->error);
            }
            $paymentInsert->bind_param(
                'isdddsssssi',
                $allocationId,
                $requestKey,
                $totalAmount,
                $amountToPay,
                $newPending,
                $paymentMode,
                $transactionId,
                $paymentDate,
                $newStatus,
                $remarks,
                $userId
            );
            if (!$paymentInsert->execute()) {
                $error = $paymentInsert->error;
                $paymentInsert->close();
                throw new RuntimeException('Unable to record SIM payment for allocation #' . $allocationId . ': ' . $error);
            }
            $paymentInsert->close();
        }

        $newValues = [
            'amount_paid' => $newAmountPaid,
            'pending_amount' => $newPending,
            'payment_status' => $newStatus,
            'payment_mode' => $paymentMode,
            'payment_date' => $paymentDate,
            'transaction_id' => $transactionId !== '' ? $transactionId : null
        ];
        writeChangedFields($conn, $allocationId, 'Stock Allocation Payment', $allocation, $newValues, $currentUser);

        $update = $conn->prepare(
            "UPDATE stock_allocations
             SET amount_paid = ?, pending_amount = ?, payment_status = ?,
                 payment_mode = ?, payment_date = ?, transaction_id = NULLIF(?, '')
             WHERE id = ? AND owner_type = 'dealer' AND owner_id = ?"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare allocation payment update: ' . $conn->error);
        }
        $update->bind_param(
            'ddssssii',
            $newAmountPaid,
            $newPending,
            $newStatus,
            $paymentMode,
            $paymentDate,
            $transactionId,
            $allocationId,
            $dealerId
        );
        if (!$update->execute() || $update->affected_rows !== 1) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to update allocation #' . $allocationId . ' payment summary: ' . $error);
        }
        $update->close();

        $payloadHash = hash('sha256', json_encode([
            'dealer_id' => $dealerId,
            'payment_mode' => $paymentMode,
            'payment_date' => $paymentDate,
            'transaction_id' => $transactionId,
            'remarks' => $remarks,
            'allocation' => $normalizedItems[$allocationId]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $marker = $conn->prepare('INSERT INTO dealer_allocation_payment_requests (allocation_id, request_key, payload_hash, transaction_id) VALUES (?, ?, ?, NULLIF(?, \'\'))');
        if (!$marker) {
            throw new RuntimeException('Unable to prepare bulk payment duplicate marker: ' . $conn->error);
        }
        $marker->bind_param('isss', $allocationId, $requestKey, $payloadHash, $transactionId);
        if (!$marker->execute()) {
            $error = $marker->error;
            $marker->close();
            throw new RuntimeException('Unable to save bulk payment duplicate marker: ' . $error);
        }
        $marker->close();
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Bulk payment recorded successfully.', ['allocations_paid' => count($ids)]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    sendResponse(false, $error->getMessage(), [], [], $status);
}
?>
