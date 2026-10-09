<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/transaction_ids.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['dealer_sim_activation.edit', 'dealers.edit', 'stock.update']);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    sendResponse(false, 'Invalid payment request.', [], [], 400);
}

$allocationId = (int) ($data['allocation_id'] ?? 0);
$idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
$amountPaidRaw = $data['amount_paid'] ?? null;
$paymentMode = trim((string) ($data['payment_mode'] ?? ''));
$transactionIdRaw = trim((string) ($data['transaction_id'] ?? ''));
$paymentDate = trim((string) ($data['payment_date'] ?? ''));
$remarks = trim((string) ($data['remarks'] ?? ''));
$userId = (int) ($currentUser['user_id'] ?? 0);

if ($allocationId <= 0 || !preg_match('/^[A-Za-z0-9-]{16,64}$/', $idempotencyKey)) {
    sendResponse(false, 'A valid SIM activation and payment request key are required.', [], [], 400);
}
if (!is_numeric($amountPaidRaw) || round((float) $amountPaidRaw, 2) <= 0) {
    sendResponse(false, 'Payment Amount must be greater than zero.', [], [], 400);
}
if ($transactionIdRaw !== '' && !preg_match('/^[0-9]{6}$/', $transactionIdRaw)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}
$transactionId = normalizeTransactionId($transactionIdRaw);
if (!in_array($paymentMode, getPaymentModes(), true)) {
    sendResponse(false, 'Select a valid Payment Mode.', [], [], 400);
}
if ($paymentMode !== 'Cash' && $transactionId === '') {
    sendResponse(false, 'Transaction ID is required for the selected Payment Mode.', [], [], 400);
}
if ($paymentMode === 'Cash') {
    $transactionId = '';
}
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paymentDate, $dateParts)
    || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
    sendResponse(false, 'Please select a valid Payment Date.', [], [], 400);
}
if (strlen($remarks) > 4000) {
    sendResponse(false, 'Payment remarks cannot exceed 1000 characters.', [], [], 400);
}

$amountPaid = round((float) $amountPaidRaw, 2);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();
try {
    $allocationStmt = $conn->prepare(
        "SELECT id, total_amount, amount_paid, pending_amount, payment_status
         FROM stock_allocations
         WHERE id = ? AND owner_type = 'dealer' AND sim_id IS NOT NULL
         FOR UPDATE"
    );
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to prepare SIM payment lookup: ' . $conn->error);
    }
    $allocationStmt->bind_param('i', $allocationId);
    if (!$allocationStmt->execute()) {
        $error = $allocationStmt->error;
        $allocationStmt->close();
        throw new RuntimeException('Unable to load SIM payment details: ' . $error);
    }
    $allocation = $allocationStmt->get_result()->fetch_assoc();
    $allocationStmt->close();
    if (!$allocation) {
        throw new InvalidArgumentException('Dealer SIM activation record not found.');
    }

    $existingStmt = $conn->prepare(
        'SELECT id, amount_paid FROM dealer_sim_allocation_payments WHERE allocation_id = ? AND idempotency_key = ? LIMIT 1'
    );
    if (!$existingStmt) {
        throw new RuntimeException('Unable to prepare duplicate payment check: ' . $conn->error);
    }
    $existingStmt->bind_param('is', $allocationId, $idempotencyKey);
    if (!$existingStmt->execute()) {
        $error = $existingStmt->error;
        $existingStmt->close();
        throw new RuntimeException('Unable to check for a repeated payment: ' . $error);
    }
    $existingPayment = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if ($existingPayment) {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Payment was already recorded.', [
            'payment_id' => (int) $existingPayment['id'],
            'amount_paid' => (float) $existingPayment['amount_paid'],
            'idempotent_replay' => true
        ]);
    }

    $totalAmount = round((float) $allocation['total_amount'], 2);
    $previouslyPaid = round((float) $allocation['amount_paid'], 2);
    $pendingAmount = max(0, round($totalAmount - $previouslyPaid, 2));
    if ($amountPaid > $pendingAmount) {
        throw new InvalidArgumentException(
            'Payment Amount cannot exceed the current outstanding balance of ₹' . number_format($pendingAmount, 2) . '.'
        );
    }

    $newAmountPaid = round($previouslyPaid + $amountPaid, 2);
    $newPendingAmount = max(0, round($totalAmount - $newAmountPaid, 2));
    $newPaymentStatus = $newPendingAmount <= 0 ? 'Paid' : 'Partially Paid';
    $paymentStatus = $newPaymentStatus;
    $storedTransactionId = $transactionId;
    $remarksValue = $remarks;
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
        $idempotencyKey,
        $totalAmount,
        $amountPaid,
        $newPendingAmount,
        $paymentMode,
        $storedTransactionId,
        $paymentDate,
        $paymentStatus,
        $remarksValue,
        $userId
    );
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        throw new RuntimeException('Unable to record SIM payment: ' . $error);
    }
    $paymentId = (int) $insert->insert_id;
    $insert->close();

    if ($transactionId !== '') {
        reserveTransactionId($conn, $transactionId, 'dealer_sim_allocation_payments', (string) $paymentId);
    }

    $update = $conn->prepare(
        'UPDATE stock_allocations
         SET amount_paid = ?, pending_amount = ?, payment_status = ?, payment_mode = ?,
             payment_date = ?, transaction_id = NULLIF(?, \'\')
         WHERE id = ?'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare SIM payment summary update: ' . $conn->error);
    }
    $update->bind_param(
        'ddssssi',
        $newAmountPaid,
        $newPendingAmount,
        $newPaymentStatus,
        $paymentMode,
        $paymentDate,
        $storedTransactionId,
        $allocationId
    );
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to update SIM payment summary: ' . $error);
    }
    $update->close();

    writeAuditSnapshot(
        $conn,
        $allocationId,
        'Stock Allocation Payment',
        'Payment Added',
        [
            'total_amount' => $totalAmount,
            'amount_paid' => $previouslyPaid,
            'pending_amount' => $pendingAmount,
            'payment_status' => $allocation['payment_status']
        ],
        [
            'payment_id' => $paymentId,
            'amount_paid' => $amountPaid,
            'total_amount' => $totalAmount,
            'amount_paid_total' => $newAmountPaid,
            'pending_amount' => $newPendingAmount,
            'payment_mode' => $paymentMode,
            'transaction_id' => $transactionId !== '' ? $transactionId : null,
            'payment_date' => $paymentDate,
            'payment_status' => $newPaymentStatus,
            'remarks' => $remarks !== '' ? $remarks : null
        ],
        $currentUser
    );

    $conn->commit();
    $conn->close();
    sendResponse(true, 'SIM payment recorded successfully.', [
        'payment_id' => $paymentId,
        'total_amount' => $totalAmount,
        'amount_paid' => $newAmountPaid,
        'pending_amount' => $newPendingAmount,
        'payment_status' => $newPaymentStatus
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    sendResponse(false, $error->getMessage(), [], [], $status);
}
?>
