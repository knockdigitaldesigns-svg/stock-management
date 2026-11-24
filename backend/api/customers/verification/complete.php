<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';
require_once '../../../utils/transaction_ids.php';
require_once '../../../utils/crm_lead_stages.php';

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

    if (($submissionData['source'] ?? '') === 'crm_installation') {
        $closureId = (int) ($submissionData['crm_closure_id'] ?? 0);
        $installationDeviceId = (int) ($submissionData['installation_device_id'] ?? 0);
        if ($closureId <= 0 || $installationDeviceId <= 0 || $vehicleId <= 0 || $paymentId <= 0) {
            throw new RuntimeException('CRM installation verification is missing its device links.');
        }

        $closureLock = $conn->prepare(
            'SELECT id, lead_id FROM crm_lead_closure_forms WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$closureLock) {
            throw new RuntimeException('Unable to prepare CRM installation order lock.');
        }
        $closureLock->bind_param('i', $closureId);
        if (!$closureLock->execute()) {
            $closureLock->close();
            throw new RuntimeException('The CRM installation order could not be found.');
        }
        $closure = $closureLock->get_result()->fetch_assoc();
        $closureLock->close();
        if (!$closure) {
            throw new RuntimeException('The CRM installation order could not be found.');
        }

        $deviceStmt = $conn->prepare(
            'SELECT id, closure_id, device_sequence, installation_status,
                    customer_id, verification_submission_id, customer_installation_id
             FROM crm_closure_installation_devices
             WHERE id = ? AND closure_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$deviceStmt) {
            throw new RuntimeException('Unable to prepare CRM installation verification lookup.');
        }
        $deviceStmt->bind_param('ii', $installationDeviceId, $closureId);
        if (!$deviceStmt->execute()) {
            $deviceStmt->close();
            throw new RuntimeException('Unable to load the CRM installation device for approval.');
        }
        $device = $deviceStmt->get_result()->fetch_assoc();
        $deviceStmt->close();
        if (
            !$device ||
            $device['installation_status'] !== 'Completed' ||
            (int) $device['customer_id'] !== $customerId ||
            (int) $device['verification_submission_id'] !== $submissionId
        ) {
            throw new RuntimeException('The CRM installation device no longer matches this verification submission.');
        }

        $vehicleStmt = $conn->prepare(
            'SELECT id FROM customer_vehicle_details
             WHERE id = ? AND customer_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$vehicleStmt) {
            throw new RuntimeException('Unable to prepare CRM customer vehicle verification.');
        }
        $vehicleStmt->bind_param('ii', $vehicleId, $customerId);
        if (!$vehicleStmt->execute() || $vehicleStmt->get_result()->num_rows !== 1) {
            $vehicleStmt->close();
            throw new RuntimeException('The CRM installation vehicle does not belong to this customer.');
        }
        $vehicleStmt->close();

        $installationId = (int) ($device['customer_installation_id'] ?? 0);
        $installationStmt = $conn->prepare(
            'SELECT id FROM customer_installations
             WHERE id = ? AND customer_id = ? AND vehicle_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$installationStmt) {
            throw new RuntimeException('Unable to prepare CRM installation record verification.');
        }
        $installationStmt->bind_param('iii', $installationId, $customerId, $vehicleId);
        if (!$installationStmt->execute() || $installationStmt->get_result()->num_rows !== 1) {
            $installationStmt->close();
            throw new RuntimeException('The CRM installation record does not match the verified customer vehicle.');
        }
        $installationStmt->close();

        $paymentStmt = $conn->prepare(
            'SELECT id FROM customer_payments
             WHERE id = ? AND customer_id = ? AND vehicle_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$paymentStmt) {
            throw new RuntimeException('Unable to prepare CRM installation payment verification.');
        }
        $paymentStmt->bind_param('iii', $paymentId, $customerId, $vehicleId);
        if (!$paymentStmt->execute() || $paymentStmt->get_result()->num_rows !== 1) {
            $paymentStmt->close();
            throw new RuntimeException('The CRM installation payment does not match the verified customer vehicle.');
        }
        $paymentStmt->close();

        $approveDevice = $conn->prepare(
            "UPDATE crm_closure_installation_devices
             SET verification_status = 'Approved', verification_rejection_reason = NULL
             WHERE id = ? AND closure_id = ? AND verification_submission_id = ?
               AND installation_status = 'Completed'
               AND verification_status IN ('Pending Verification', 'Rejected')"
        );
        if (!$approveDevice) {
            throw new RuntimeException('Unable to prepare CRM installation device approval.');
        }
        $approveDevice->bind_param('iii', $installationDeviceId, $closureId, $submissionId);
        if (!$approveDevice->execute() || $approveDevice->affected_rows !== 1) {
            $approveDevice->close();
            throw new RuntimeException('The CRM installation device could not be marked approved.');
        }
        $approveDevice->close();

        $progressStmt = $conn->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(installation_status = 'Completed') AS installed,
                    SUM(verification_status = 'Approved') AS verified
             FROM crm_closure_installation_devices
             WHERE closure_id = ? FOR UPDATE"
        );
        if (!$progressStmt) {
            throw new RuntimeException('Unable to prepare CRM installation approval progress.');
        }
        $progressStmt->bind_param('i', $closureId);
        if (!$progressStmt->execute()) {
            $progressStmt->close();
            throw new RuntimeException('Unable to load CRM installation approval progress.');
        }
        $progress = $progressStmt->get_result()->fetch_assoc();
        $progressStmt->close();
        $totalDevices = (int) ($progress['total'] ?? 0);
        $verifiedDevices = (int) ($progress['verified'] ?? 0);
        if ($totalDevices <= 0) {
            throw new RuntimeException('The CRM installation order has no device progress records.');
        }
        $allVerified = $verifiedDevices === $totalDevices;
        $customerProgressStmt = $conn->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(verification_status = 'Approved') AS verified
             FROM crm_closure_installation_devices
             WHERE closure_id = ? AND customer_id = ? FOR UPDATE"
        );
        if (!$customerProgressStmt) {
            throw new RuntimeException('Unable to prepare per-customer installation approval progress.');
        }
        $customerProgressStmt->bind_param('ii', $closureId, $customerId);
        if (!$customerProgressStmt->execute()) {
            $customerProgressStmt->close();
            throw new RuntimeException('Unable to load per-customer installation approval progress.');
        }
        $customerProgress = $customerProgressStmt->get_result()->fetch_assoc();
        $customerProgressStmt->close();
        $customerDeviceCount = (int) ($customerProgress['total'] ?? 0);
        $customerVerifiedCount = (int) ($customerProgress['verified'] ?? 0);
        $customerVerificationStatus = $customerDeviceCount > 0 &&
            $customerVerifiedCount === $customerDeviceCount
            ? 'Approved'
            : 'Pending';
        $customerStatusStmt = $conn->prepare(
            'UPDATE customers SET verification_status = ? WHERE id = ?'
        );
        if (!$customerStatusStmt) {
            throw new RuntimeException('Unable to prepare CRM customer verification status update.');
        }
        $customerStatusStmt->bind_param('si', $customerVerificationStatus, $customerId);
        if (!$customerStatusStmt->execute()) {
            $customerStatusStmt->close();
            throw new RuntimeException('Unable to update CRM customer verification status.');
        }
        $customerStatusStmt->close();

        $closureStatus = $allVerified ? 'Customer Handoff Completed' : 'Installation In Progress';
        $closureStmt = $conn->prepare(
            'UPDATE crm_lead_closure_forms
             SET customer_id = COALESCE(customer_id, ?), submission_status = ?
             WHERE id = ?'
        );
        if (!$closureStmt) {
            throw new RuntimeException('Unable to prepare CRM handoff status update.');
        }
        $closureStmt->bind_param('isi', $customerId, $closureStatus, $closureId);
        if (!$closureStmt->execute() || $closureStmt->affected_rows > 1) {
            $closureStmt->close();
            throw new RuntimeException('Unable to update CRM handoff verification status.');
        }
        $closureStmt->close();
        if ($allVerified) {
            crmLeadSetLifecycleStage(
                $conn,
                (int) $closure['lead_id'],
                'Converted Lead',
                $reviewer
            );
        }

        $completeSubmission = $conn->prepare(
            "UPDATE customer_payment_verification_submissions
             SET status = 'Approved', linked_customer_id = ?, linked_vehicle_id = ?,
                 linked_payment_id = ?, decided_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'Applying' AND reviewer_user_id = ?"
        );
        if (!$completeSubmission) {
            throw new RuntimeException('Unable to prepare CRM verification completion.');
        }
        $completeSubmission->bind_param('iiiii', $customerId, $vehicleId, $paymentId, $submissionId, $reviewerId);
        if (!$completeSubmission->execute() || $completeSubmission->affected_rows !== 1) {
            $completeSubmission->close();
            throw new RuntimeException('Unable to complete CRM installation verification.');
        }
        $completeSubmission->close();
        insertCustomerPaymentVerificationHistory(
            $conn,
            $submissionId,
            'Applying',
            'Approved',
            null,
            $reviewerId
        );
        $conn->commit();
        $conn->close();
        sendResponse(true, 'CRM installation device approved.', [
            'status' => 'Approved',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'payment_id' => $paymentId,
            'device_sequence' => (int) $device['device_sequence'],
            'total_devices' => $totalDevices,
            'verified_devices' => $verifiedDevices,
            'customer_visible' => $customerVerificationStatus === 'Approved'
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
