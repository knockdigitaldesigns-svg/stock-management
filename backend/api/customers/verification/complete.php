<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';
require_once '../../../utils/transaction_ids.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireSuperAdminCustomerPaymentVerificationReviewer();
$reviewerId = (int) ($reviewer['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
$customerId = (int) ($data['customer_id'] ?? 0);
$vehicleId = (int) ($data['vehicle_id'] ?? 0);
$paymentId = (int) ($data['payment_id'] ?? 0);
if ($submissionId <= 0 || $customerId <= 0) {
    sendResponse(false, 'Submission and customer IDs are required to complete approval.', [], [], 400);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT status, reviewer_user_id, existing_customer_id, linked_customer_id, submission_data
         FROM customer_payment_verification_submissions WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Verification submission not found.');
    }
    $submissionData = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    $isCourierSubmission = ($submissionData['source'] ?? '') === 'courier';
    if ($paymentId <= 0 && !$isCourierSubmission) {
        throw new RuntimeException('Payment ID is required to complete this customer payment approval.');
    }
    if ($isCourierSubmission) {
        $requestIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($submissionData['courier_request_ids'] ?? null)
                ? $submissionData['courier_request_ids']
                : []),
            static function (int $requestId): bool {
                return $requestId > 0;
            }
        )));
        $finalizedIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($submissionData['courier_finalized_request_ids'] ?? null)
                ? $submissionData['courier_finalized_request_ids']
                : []),
            static function (int $requestId): bool {
                return $requestId > 0;
            }
        )));
        if (!$requestIds || count(array_diff($requestIds, $finalizedIds)) > 0) {
            throw new RuntimeException('Courier assets must be allocated through the linked verification approval before completion.');
        }
    }
    if ($row['status'] === 'Approved') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Submission is already approved.', ['status' => 'Approved']);
    }
    if ($row['status'] !== 'Applying' || (int) $row['reviewer_user_id'] !== $reviewerId) {
        throw new RuntimeException('This submission is not being applied by the current reviewer.');
    }
    $existingCustomerId = (int) ($row['existing_customer_id'] ?? 0);
    $linkedCustomerId = (int) ($row['linked_customer_id'] ?? 0);
    if (($existingCustomerId > 0 && $existingCustomerId !== $customerId)
        || ($linkedCustomerId > 0 && $linkedCustomerId !== $customerId)) {
        throw new RuntimeException('The customer does not match this verification submission.');
    }
    if (($submissionData['source'] ?? '') === 'payment_installment') {
        $installmentStmt = $conn->prepare(
            'SELECT id, request_key, customer_payment_id, customer_id, amount, payment_mode,
                    transaction_id, payment_date, notes, status, created_by_user_id
             FROM customer_payment_installment_requests
             WHERE verification_submission_id = ? FOR UPDATE'
        );
        if (!$installmentStmt) {
            throw new RuntimeException('Unable to prepare approved installment lookup.');
        }
        $installmentStmt->bind_param('i', $submissionId);
        if (!$installmentStmt->execute()) {
            $installmentStmt->close();
            throw new RuntimeException('Unable to load the installment awaiting approval.');
        }
        $installment = $installmentStmt->get_result()->fetch_assoc();
        $installmentStmt->close();
        if (
            !$installment ||
            $installment['status'] !== 'Applying' ||
            (int) $installment['customer_id'] !== $customerId ||
            (int) $installment['customer_payment_id'] !== $paymentId
        ) {
            throw new RuntimeException('The installment request does not match the payment approval.');
        }

        $paymentStmt = $conn->prepare(
            "SELECT cp.id, cp.total_amount, cp.amount_paid, cp.amount_pending
             FROM customer_payments cp
             INNER JOIN customers c ON c.id = cp.customer_id
             WHERE cp.id = ? AND cp.customer_id = ? AND c.verification_status = 'Approved'
             LIMIT 1 FOR UPDATE"
        );
        if (!$paymentStmt) {
            throw new RuntimeException('Unable to prepare payment balance check.');
        }
        $paymentStmt->bind_param('ii', $paymentId, $customerId);
        if (!$paymentStmt->execute()) {
            $paymentStmt->close();
            throw new RuntimeException('Unable to load the payment balance.');
        }
        $payment = $paymentStmt->get_result()->fetch_assoc();
        $paymentStmt->close();
        if (!$payment) {
            throw new RuntimeException('The approved customer payment was not found.');
        }

        $amount = round((float) $installment['amount'], 2);
        $pending = round((float) $payment['amount_pending'], 2);
        if ($amount <= 0 || $amount > $pending) {
            throw new RuntimeException(
                'Installment cannot be approved because the remaining balance is ₹' .
                number_format($pending, 2) . '. Reject this request or correct the payment balance.'
            );
        }
        $nextPaid = round((float) $payment['amount_paid'] + $amount, 2);
        $nextPending = round(max(0, (float) $payment['total_amount'] - $nextPaid), 2);
        $paymentStatus = $nextPending <= 0
            ? 'Paid'
            : ($nextPaid > 0 ? 'Partially Paid' : 'Not Paid');

        $transactionStmt = $conn->prepare(
            'INSERT INTO customer_payment_transactions
                (payment_id, customer_id, request_key, amount, payment_mode, transaction_id,
                 payment_date, payment_status, notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, NULLIF(?, \'\'), ?)'
        );
        if (!$transactionStmt) {
            throw new RuntimeException('Unable to prepare approved payment history entry.');
        }
        $transactionStmt->bind_param(
            'iisdsssssi',
            $paymentId,
            $customerId,
            $installment['request_key'],
            $amount,
            $installment['payment_mode'],
            $installment['transaction_id'],
            $installment['payment_date'],
            $paymentStatus,
            $installment['notes'],
            $installment['created_by_user_id']
        );
        if (!$transactionStmt->execute()) {
            $transactionStmt->close();
            throw new RuntimeException('Unable to save the approved installment to payment history.');
        }
        $transactionId = (int) $transactionStmt->insert_id;
        $transactionStmt->close();
        if (!empty($installment['transaction_id'])) {
            reserveTransactionId(
                $conn,
                (string) $installment['transaction_id'],
                'customer_payment_transactions',
                (string) $transactionId
            );
        }

        $updatePayment = $conn->prepare(
            'UPDATE customer_payments
             SET amount_paid = ?, amount_pending = ?, payment_status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND customer_id = ?'
        );
        if (!$updatePayment) {
            throw new RuntimeException('Unable to prepare approved payment total update.');
        }
        $updatePayment->bind_param('ddsii', $nextPaid, $nextPending, $paymentStatus, $paymentId, $customerId);
        if (!$updatePayment->execute()) {
            $updatePayment->close();
            throw new RuntimeException('Unable to update approved payment totals.');
        }
        $updatePayment->close();

        $updateInstallment = $conn->prepare(
            "UPDATE customer_payment_installment_requests
             SET status = 'Approved', reviewed_by_user_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE verification_submission_id = ? AND status = 'Applying'"
        );
        if (!$updateInstallment) {
            throw new RuntimeException('Unable to prepare installment approval status update.');
        }
        $updateInstallment->bind_param('ii', $reviewerId, $submissionId);
        if (!$updateInstallment->execute() || $updateInstallment->affected_rows !== 1) {
            $updateInstallment->close();
            throw new RuntimeException('Unable to update the installment approval status.');
        }
        $updateInstallment->close();

        $updateSubmission = $conn->prepare(
            "UPDATE customer_payment_verification_submissions
             SET status = 'Approved', linked_customer_id = ?, linked_vehicle_id = NULLIF(?, 0),
                 linked_payment_id = ?, decided_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'Applying' AND reviewer_user_id = ?"
        );
        if (!$updateSubmission) {
            throw new RuntimeException('Unable to prepare installment verification completion.');
        }
        $vehicleId = (int) ($submissionData['vehicle']['vehicle_record_id'] ?? 0);
        $updateSubmission->bind_param('iiiii', $customerId, $vehicleId, $paymentId, $submissionId, $reviewerId);
        if (!$updateSubmission->execute() || $updateSubmission->affected_rows !== 1) {
            $updateSubmission->close();
            throw new RuntimeException('Unable to complete installment verification.');
        }
        $updateSubmission->close();
        insertCustomerPaymentVerificationHistory($conn, $submissionId, 'Applying', 'Approved', null, $reviewerId);
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Installment approved and payment totals updated.', [
            'status' => 'Approved',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'payment_id' => $paymentId,
            'amount_paid' => $nextPaid,
            'amount_pending' => $nextPending,
            'payment_status' => $paymentStatus
        ]);
    }

    if (($submissionData['source'] ?? '') !== 'customer_vehicle_addition') {
        $customerStatusStmt = $conn->prepare(
            "UPDATE customers SET verification_status = 'Approved' WHERE id = ?"
        );
        if (!$customerStatusStmt) {
            throw new RuntimeException('Unable to prepare customer approval status update.');
        }
        $customerStatusStmt->bind_param('i', $customerId);
        if (!$customerStatusStmt->execute() || $customerStatusStmt->affected_rows > 1) {
            $customerStatusStmt->close();
            throw new RuntimeException('Unable to update the customer approval status.');
        }
        $customerStatusStmt->close();
    }
    $stmt = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET status = 'Approved', linked_customer_id = ?, linked_vehicle_id = NULLIF(?, 0),
             linked_payment_id = NULLIF(?, 0), decided_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->bind_param('iiii', $customerId, $vehicleId, $paymentId, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to complete customer payment approval.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, 'Applying', 'Approved', null, $reviewerId);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Customer payment submission approved.', ['status' => 'Approved', 'customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'payment_id' => $paymentId]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
