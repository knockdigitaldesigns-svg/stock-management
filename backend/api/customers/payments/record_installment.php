<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/payment_modes.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('customers.edit');
$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    sendResponse(false, 'Invalid request data.', [], [], 400);
}

$customerId = (int) ($data['customer_id'] ?? 0);
$paymentId = (int) ($data['payment_id'] ?? 0);
$amount = round((float) ($data['amount'] ?? 0), 2);
$paymentMode = trim((string) ($data['payment_mode'] ?? ''));
$transactionId = trim((string) ($data['transaction_id'] ?? ''));
$paymentDate = trim((string) ($data['payment_date'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));
$requestKey = trim((string) ($data['request_key'] ?? ''));

if ($customerId <= 0 || $paymentId <= 0) {
    sendResponse(false, 'Customer and payment IDs are required.', [], [], 400);
}
if ($amount <= 0) {
    sendResponse(false, 'Payment amount must be greater than zero.', [], [], 400);
}
if ($paymentMode === '' || !in_array($paymentMode, getPaymentModes(), true)) {
    sendResponse(false, 'Select a valid payment method.', [], [], 400);
}
if ($transactionId !== '' && !preg_match('/^[0-9]{6}$/', $transactionId)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}
if ($paymentMode !== 'Cash' && $transactionId === '') {
    sendResponse(false, 'Transaction ID is required for this payment method.', [], [], 400);
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
if (!$date || $date->format('Y-m-d') !== $paymentDate) {
    sendResponse(false, 'Enter a valid payment date.', [], [], 400);
}
if ($requestKey === '' || strlen($requestKey) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestKey)) {
    sendResponse(false, 'A valid payment request key is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $conn->begin_transaction();

    $duplicateStmt = $conn->prepare(
        'SELECT r.customer_payment_id, r.customer_id, r.amount, r.payment_mode,
                r.transaction_id, r.payment_date, r.notes, r.verification_submission_id,
                s.status
         FROM customer_payment_installment_requests r
         INNER JOIN customer_payment_verification_submissions s
            ON s.id = r.verification_submission_id
         WHERE r.request_key = ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$duplicateStmt) {
        throw new RuntimeException('Unable to check for duplicate installment requests.');
    }
    $duplicateStmt->bind_param('s', $requestKey);
    if (!$duplicateStmt->execute()) {
        $duplicateStmt->close();
        throw new RuntimeException('Unable to check for duplicate installment requests.');
    }
    $existing = $duplicateStmt->get_result()->fetch_assoc();
    $duplicateStmt->close();
    if ($existing) {
        if (
            (int) $existing['customer_payment_id'] !== $paymentId ||
            (int) $existing['customer_id'] !== $customerId ||
            round((float) $existing['amount'], 2) !== $amount ||
            $existing['payment_mode'] !== $paymentMode ||
            (string) ($existing['transaction_id'] ?? '') !== $transactionId ||
            $existing['payment_date'] !== $paymentDate ||
            (string) ($existing['notes'] ?? '') !== $notes
        ) {
            throw new InvalidArgumentException('This payment request key has already been used.');
        }
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Installment is already submitted for Admin verification.', [
            'submission_id' => (int) $existing['verification_submission_id'],
            'status' => $existing['status'],
            'payment_id' => $paymentId
        ]);
    }

    $paymentStmt = $conn->prepare(
        "SELECT cp.id, cp.customer_id, cp.vehicle_id, cp.total_sale_amount,
                cp.total_amount, cp.amount_paid, cp.amount_pending, cp.payment_status,
                cp.payment_mode AS original_payment_mode, cp.transaction_id AS original_transaction_id,
                c.username, c.platform_id, p.platform_name, c.primary_mobile_no,
                c.secondary_mobile_no, c.email, c.location, c.pincode, c.status AS customer_status,
                c.verification_status, cvd.vehicle_no
         FROM customer_payments cp
         INNER JOIN customers c ON c.id = cp.customer_id
         LEFT JOIN platforms p ON p.id = c.platform_id
         LEFT JOIN customer_vehicle_details cvd ON cvd.id = cp.vehicle_id AND cvd.customer_id = c.id
         WHERE cp.id = ? AND cp.customer_id = ? AND c.verification_status = 'Approved'
         LIMIT 1 FOR UPDATE"
    );
    if (!$paymentStmt) {
        throw new RuntimeException('Unable to prepare customer payment lookup.');
    }
    $paymentStmt->bind_param('ii', $paymentId, $customerId);
    if (!$paymentStmt->execute()) {
        $paymentStmt->close();
        throw new RuntimeException('Unable to load customer payment.');
    }
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();
    if (!$payment) {
        throw new InvalidArgumentException('Approved customer payment was not found.');
    }
    if ($amount > round((float) $payment['amount_pending'], 2)) {
        throw new InvalidArgumentException(
            'Installment amount cannot exceed the remaining balance of ₹' .
            number_format((float) $payment['amount_pending'], 2) . '.'
        );
    }
    $pendingAmountStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS pending_installment_total
         FROM customer_payment_installment_requests
         WHERE customer_payment_id = ? AND status IN ('Pending Verification', 'Applying')"
    );
    if (!$pendingAmountStmt) {
        throw new RuntimeException('Unable to check other pending installment requests.');
    }
    $pendingAmountStmt->bind_param('i', $paymentId);
    if (!$pendingAmountStmt->execute()) {
        $pendingAmountStmt->close();
        throw new RuntimeException('Unable to check other pending installment requests.');
    }
    $pendingInstallmentTotal = (float) (
        $pendingAmountStmt->get_result()->fetch_assoc()['pending_installment_total'] ?? 0
    );
    $pendingAmountStmt->close();
    $availableAfterPendingRequests = round(
        (float) $payment['amount_pending'] - $pendingInstallmentTotal,
        2
    );
    if ($amount > $availableAfterPendingRequests) {
        throw new InvalidArgumentException(
            'Installment amount exceeds the available balance after pending requests of ₹' .
            number_format(max(0, $availableAfterPendingRequests), 2) . '.'
        );
    }

    $pendingTransaction = $conn->prepare(
        "SELECT id
         FROM customer_payment_installment_requests
         WHERE transaction_id = ? AND transaction_id IS NOT NULL AND status IN ('Pending Verification', 'Applying')
         LIMIT 1"
    );
    if (!$pendingTransaction) {
        throw new RuntimeException('Unable to check duplicate pending transaction IDs.');
    }
    $pendingTransaction->bind_param('s', $transactionId);
    if (!$pendingTransaction->execute()) {
        $pendingTransaction->close();
        throw new RuntimeException('Unable to check duplicate pending transaction IDs.');
    }
    $hasPendingTransaction = $transactionId !== '' && $pendingTransaction->get_result()->num_rows > 0;
    $pendingTransaction->close();
    if ($hasPendingTransaction) {
        throw new InvalidArgumentException('Transaction ID is already in a pending installment request.');
    }
    if ($transactionId !== '') {
        $registeredTransaction = $conn->prepare(
            'SELECT source_type, source_id FROM transaction_id_registry WHERE transaction_id = ? LIMIT 1'
        );
        if (!$registeredTransaction) {
            throw new RuntimeException('Unable to check whether the transaction ID is already in use.');
        }
        $registeredTransaction->bind_param('s', $transactionId);
        if (!$registeredTransaction->execute()) {
            $registeredTransaction->close();
            throw new RuntimeException('Unable to check whether the transaction ID is already in use.');
        }
        $registered = $registeredTransaction->get_result()->fetch_assoc();
        $registeredTransaction->close();
        if ($registered) {
            throw new InvalidArgumentException('Transaction ID is already in use.');
        }
    }

    $paymentInstallment = [
        'payment_id' => $paymentId,
        'amount' => $amount,
        'payment_mode' => $paymentMode,
        'transaction_id' => $transactionId,
        'payment_date' => $paymentDate,
        'notes' => $notes,
        'request_key' => $requestKey,
        'current_total_amount' => (float) $payment['total_amount'],
        'current_amount_paid' => (float) $payment['amount_paid'],
        'current_amount_pending' => (float) $payment['amount_pending'],
        'current_payment_status' => (string) $payment['payment_status']
    ];
    $submissionData = [
        'source' => 'payment_installment',
        'customer' => [
            'platform_id' => (int) $payment['platform_id'],
            'platform_name' => (string) ($payment['platform_name'] ?? ''),
            'username' => (string) $payment['username'],
            'primary_mobile_no' => (string) $payment['primary_mobile_no'],
            'secondary_mobile_no' => (string) ($payment['secondary_mobile_no'] ?? ''),
            'email' => (string) ($payment['email'] ?? ''),
            'location' => (string) $payment['location'],
            'pincode' => (string) $payment['pincode'],
            'status' => (string) $payment['customer_status']
        ],
        'vehicle' => [
            'vehicle_record_id' => (int) $payment['vehicle_id'],
            'vehicle_no' => (string) ($payment['vehicle_no'] ?? '')
        ],
        'payment_installment' => $paymentInstallment,
        'payment_part1' => [
            'total_sale_amount' => (float) $payment['total_sale_amount'],
            'payment_mode' => (string) ($payment['original_payment_mode'] ?? ''),
            'transaction_id' => (string) ($payment['original_transaction_id'] ?? '')
        ],
        'payment_part2' => [
            'total_amount' => (float) $payment['total_amount'],
            'amount_paid' => (float) $payment['amount_paid'],
            'amount_pending' => (float) $payment['amount_pending'],
            'payment_status' => (string) $payment['payment_status']
        ]
    ];
    $submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($submissionJson === false || strlen($submissionJson) > 2000000) {
        throw new RuntimeException('Installment verification request could not be encoded.');
    }

    $submissionStmt = $conn->prepare(
        "INSERT INTO customer_payment_verification_submissions
            (submitter_user_id, existing_customer_id, linked_customer_id, linked_vehicle_id,
             linked_payment_id, status, submission_data, submitted_at)
         VALUES (?, ?, ?, ?, ?, 'Pending Verification', ?, CURRENT_TIMESTAMP)"
    );
    if (!$submissionStmt) {
        throw new RuntimeException('Unable to prepare installment verification request.');
    }
    $vehicleId = (int) ($payment['vehicle_id'] ?? 0);
    $submissionStmt->bind_param(
        'iiiiis',
        $userId,
        $customerId,
        $customerId,
        $vehicleId,
        $paymentId,
        $submissionJson
    );
    if (!$submissionStmt->execute()) {
        $submissionStmt->close();
        throw new RuntimeException('Unable to submit installment for Admin verification.');
    }
    $submissionId = (int) $submissionStmt->insert_id;
    $submissionStmt->close();

    $requestStmt = $conn->prepare(
        "INSERT INTO customer_payment_installment_requests
            (verification_submission_id, request_key, customer_payment_id, customer_id,
             amount, payment_mode, transaction_id, payment_date, notes, status, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'Pending Verification', ?)"
    );
    if (!$requestStmt) {
        throw new RuntimeException('Unable to prepare installment verification tracking.');
    }
    $requestStmt->bind_param(
        'isiidssssi',
        $submissionId,
        $requestKey,
        $paymentId,
        $customerId,
        $amount,
        $paymentMode,
        $transactionId,
        $paymentDate,
        $notes,
        $userId
    );
    if (!$requestStmt->execute()) {
        $duplicateTransaction = $requestStmt->errno === 1062;
        $requestStmt->close();
        if ($duplicateTransaction) {
            throw new InvalidArgumentException('Transaction ID is already in use by another installment request.');
        }
        throw new RuntimeException('Unable to save installment verification tracking.');
    }
    $requestStmt->close();
    insertCustomerPaymentVerificationHistory(
        $conn,
        $submissionId,
        'Draft',
        'Pending Verification',
        'Customer installment submitted for Admin verification.',
        $userId
    );

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Installment submitted successfully for Admin verification. Totals will update after approval.', [
        'submission_id' => $submissionId,
        'status' => 'Pending Verification',
        'payment_id' => $paymentId
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    sendResponse(false, $error->getMessage(), [], [], $statusCode);
}
