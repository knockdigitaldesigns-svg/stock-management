<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../utils/payment_modes.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim((string)($payload['action_type'] ?? ''));
$paymentMode = trim((string)($payload['payment_mode'] ?? ''));
if ($paymentMode !== '' && !in_array($paymentMode, getPaymentModes(), true)) throw new Exception('Invalid payment mode.');
$permission = in_array($action, ['Renew SIM', 'Reactivate SIM'], true) ? 'customer_renewals.renew' : 'customer_renewals.edit';
requirePermission($permission);
$token = getCurrentUserFromToken();
$userId = (int)($token['user_id'] ?? 0);
$renewalId = (int)($payload['renewal_id'] ?? 0);
$allowedActions = ['', 'Renew SIM', 'Deactivate SIM', 'Safe Custody', 'Reactivate SIM', 'Renewal Payment'];
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
if (!in_array($action, $allowedActions, true)) sendResponse(false, 'Invalid renewal action.', [], [], 400);
$conn = (new Database())->getConnection();
try {
    ensureRenewalTables($conn);
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT * FROM customer_renewals WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $renewalId); $stmt->execute(); $current = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$current) throw new Exception('Renewal not found.');
    $lifecycleExpiredDays = $payload['expired_to_safe_days'] ?? $current['expired_to_safe_days'];
    $lifecycleDeactiveDays = $payload['safe_to_deactive_days'] ?? $current['safe_to_deactive_days'];
    if (filter_var($lifecycleExpiredDays, FILTER_VALIDATE_INT) === false || filter_var($lifecycleDeactiveDays, FILTER_VALIDATE_INT) === false || (int)$lifecycleExpiredDays < 0 || (int)$lifecycleDeactiveDays < 0) {
        throw new Exception('Lifecycle days must be non-negative whole numbers.');
    }
    $lifecycleExpiredDays = (int)$lifecycleExpiredDays;
    $lifecycleDeactiveDays = (int)$lifecycleDeactiveDays;
    $newStatus = $current['sim_status'];
    $newValidity = (int)$current['validity_months'];
    $newDate = $current['next_renewal_date'];
    $lastRenewedDate = $current['last_renewed_date'];
    $payment = [];
    $historyActionDate = null;
    $notes = trim((string)($payload['notes'] ?? '')) ?: null;

    if ($action === 'Deactivate SIM') {
        if (!in_array($current['sim_status'], ['Active', 'Expired', 'Safe Custody'], true)) throw new Exception('Deactivate SIM is not available for this status.');
        if ($current['sim_status'] === 'Deactive') throw new Exception('SIM is already Deactive.');
        $newStatus = 'Deactive';
    } elseif ($action === 'Safe Custody') {
        if (!in_array($current['sim_status'], ['Active', 'Expired'], true)) throw new Exception('Safe Custody is not available for this status.');
        if ($current['sim_status'] === 'Safe Custody') throw new Exception('SIM is already in Safe Custody.');
        $actionDate = trim((string)($payload['action_date'] ?? ''));
        $actionDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $actionDate);
        $actionDateErrors = DateTimeImmutable::getLastErrors();
        if (!$actionDateValue
            || ($actionDateErrors !== false && ($actionDateErrors['warning_count'] > 0 || $actionDateErrors['error_count'] > 0))
            || $actionDateValue->format('Y-m-d') !== $actionDate) {
            throw new Exception('A valid Safe Custody Date is required.');
        }
        $newStatus = 'Safe Custody';
        $historyActionDate = $actionDate;
    } elseif ($action === 'Reactivate SIM') {
        if ($current['sim_status'] !== 'Deactive') throw new Exception('Reactivate SIM is only available for Deactive SIMs.');
        $reactivationDate = trim((string)($payload['reactivation_date'] ?? ''));
        $newValidity = (int)($payload['validity_months'] ?? 0);
        $reactivation = DateTime::createFromFormat('!Y-m-d', $reactivationDate);
        if (!$reactivation || $reactivation->format('Y-m-d') !== $reactivationDate || $newValidity <= 0) throw new Exception('Actual Reactivation Date and a selected validity are required.');
        if (empty($current['next_renewal_date']) || $current['next_renewal_date'] === '0000-00-00') {
            throw new Exception('The previously saved Expiry Date is missing. SIM reactivation cannot calculate the next renewal cycle.');
        }
        $validityCheck = $conn->prepare('SELECT months FROM sim_validities WHERE months = ? AND status = ? LIMIT 1');
        $activeStatus = 'active';
        $validityCheck->bind_param('is', $newValidity, $activeStatus);
        $validityCheck->execute();
        $validityExists = $validityCheck->get_result()->num_rows > 0;
        $validityCheck->close();
        if (!$validityExists) throw new Exception('Selected validity is not available.');
        $newDate = renewalAddCalendarMonths($current['next_renewal_date'], $newValidity);
        $lastRenewedDate = $reactivationDate;
        $historyActionDate = $reactivationDate;
        $newStatus = 'Active';
    } elseif ($action === 'Renew SIM') {
        if (!in_array($current['sim_status'], ['Active', 'Expired', 'Safe Custody'], true)) throw new Exception('Renew SIM is not available for this status.');
        $renewalDate = (string) ($current['next_renewal_date'] ?? '');
        $actionDate = trim((string)($payload['action_date'] ?? ''));
        $actionDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $actionDate);
        $actionDateErrors = DateTimeImmutable::getLastErrors();
        if (!$actionDateValue
            || ($actionDateErrors !== false && ($actionDateErrors['warning_count'] > 0 || $actionDateErrors['error_count'] > 0))
            || $actionDateValue->format('Y-m-d') !== $actionDate) {
            throw new Exception('A valid Renewal Processed Date is required.');
        }
        $newValidity = (int)($payload['validity_months'] ?? 0);
        $date = DateTime::createFromFormat('!Y-m-d', $renewalDate);
        if (!$date || $date->format('Y-m-d') !== $renewalDate || $newValidity <= 0) throw new Exception('A saved Next Renewal Date and Validity are required.');
        $validityCheck = $conn->prepare('SELECT months FROM sim_validities WHERE months = ? AND status = ? LIMIT 1');
        $activeStatus = 'active';
        $validityCheck->bind_param('is', $newValidity, $activeStatus);
        $validityCheck->execute();
        $validityExists = $validityCheck->get_result()->num_rows > 0;
        $validityCheck->close();
        if (!$validityExists) throw new Exception('Selected validity is not available.');
        $newDate = renewalAddCalendarMonths($renewalDate, $newValidity);
        $lastRenewedDate = $actionDate;
        $historyActionDate = $actionDate;
        $newStatus = 'Active';
    }

    if (in_array($action, ['Renew SIM', 'Reactivate SIM'], true)) {
        $total = (float)($payload['payment_amount'] ?? 0);
        $paid = (float)($payload['amount_paid'] ?? 0);
        $paymentStatus = trim((string)($payload['payment_status'] ?? ''));
        $paymentMode = trim((string)($payload['payment_mode'] ?? ''));
        $paymentDate = trim((string)($payload['payment_date'] ?? ''));
        $transactionId = normalizeTransactionId($payload['transaction_id'] ?? '');
        if ($total <= 0) throw new Exception(
            $action === 'Renew SIM'
                ? 'Renewal Total Amount is required.'
                : 'Reactivation Total Amount is required.'
        );
        if ($paid < 0) throw new Exception('Amount Paid cannot be negative.');
        if ($paid > $total) throw new Exception('Amount Paid cannot exceed Total Amount.');
        $pending = max(0, $total - $paid);
        $expectedStatus = $total <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Not Paid'));
        if ($paymentStatus !== '' && $paymentStatus !== $expectedStatus) throw new Exception('Payment Status does not match the amounts.');
        if ($paid > 0 && $paymentMode === '') throw new Exception('Payment Mode is required when an amount has been paid.');
        if ($paid > 0) {
            $paymentDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
            $paymentDateErrors = DateTimeImmutable::getLastErrors();
            if (!$paymentDateValue
                || ($paymentDateErrors !== false && ($paymentDateErrors['warning_count'] > 0 || $paymentDateErrors['error_count'] > 0))
                || $paymentDateValue->format('Y-m-d') !== $paymentDate) {
                throw new Exception('A valid Payment Date is required when an amount has been paid.');
            }
        }
        if ($paid > 0 && $paymentMode !== 'Cash' && $transactionId === '') throw new Exception('Transaction ID is required for the selected Payment Mode.');
        if ($paid <= 0) {
            $paymentMode = '';
            $paymentDate = '';
            $transactionId = '';
        }
        $payment = ['payment_amount' => $total, 'amount_paid' => $paid, 'amount_pending' => $pending, 'payment_mode' => $paymentMode ?: null, 'payment_date' => $paymentDate ?: null, 'transaction_id' => $transactionId ?: null];
    }

    $previousPayment = $payload['previous_pending_payment'] ?? [];
    if (!is_array($previousPayment)) throw new InvalidArgumentException('Invalid old renewal payment details.');
    $previousPaidRaw = $previousPayment['amount_paid'] ?? '';
    if ($previousPaidRaw !== '' && !is_numeric($previousPaidRaw)) {
        throw new InvalidArgumentException('Old renewal payment amount must be a valid number.');
    }
    $previousPaid = round((float)($previousPaidRaw ?: 0), 2);
    if ($previousPaid < 0) throw new InvalidArgumentException('Old renewal payment amount cannot be negative.');
    if ($action === 'Renewal Payment' && $previousPaid <= 0) {
        throw new InvalidArgumentException('Enter an old renewal payment greater than zero.');
    }
    $previousMode = trim((string)($previousPayment['payment_mode'] ?? ''));
    $previousDate = trim((string)($previousPayment['payment_date'] ?? ''));
    $previousTransactionId = normalizeTransactionId($previousPayment['transaction_id'] ?? '');
    if ($previousPaid > 0) {
        if (!in_array($action, ['Renew SIM', 'Renewal Payment'], true)) {
            throw new InvalidArgumentException('Old renewal payments can only be closed from the renewal payment section.');
        }
        if (!in_array($previousMode, getPaymentModes(), true)) {
            throw new InvalidArgumentException('Select a valid Payment Mode for the old renewal payment.');
        }
        $previousDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $previousDate);
        $previousDateErrors = DateTimeImmutable::getLastErrors();
        if (!$previousDateValue
            || ($previousDateErrors !== false && ($previousDateErrors['warning_count'] > 0 || $previousDateErrors['error_count'] > 0))
            || $previousDateValue->format('Y-m-d') !== $previousDate) {
            throw new InvalidArgumentException('A valid Payment Date is required for the old renewal payment.');
        }
        if ($previousMode !== 'Cash' && $previousTransactionId === '') {
            throw new InvalidArgumentException('Transaction ID is required for the old renewal payment.');
        }
        if ($previousMode === 'Cash') {
            $previousTransactionId = '';
        }
    } else {
        $previousMode = '';
        $previousDate = '';
        $previousTransactionId = '';
    }

    /*
     * Handle installation_date change from the renewal edit modal.
     * If the user changed the installation_date, sync it to both
     * customer_renewals and customer_installations, and recalculate
     * next_renewal_date for un-renewed customers.
     */
    $newInstallationDate = $current['installation_date'];
    $installationDatePayload = trim((string)($payload['installation_date'] ?? ''));

    if ($installationDatePayload !== '' && $installationDatePayload !== $current['installation_date']) {
        $instDate = DateTime::createFromFormat('Y-m-d', $installationDatePayload);
        if (!$instDate || $instDate->format('Y-m-d') !== $installationDatePayload) {
            throw new Exception('Invalid Installation Date format.');
        }
        $newInstallationDate = $installationDatePayload;

        /* Update customer_installations table as well */
        $instUpdate = $conn->prepare(
            'UPDATE customer_installations
             SET installation_date = ?, updated_at = CURRENT_TIMESTAMP
             WHERE customer_id = ?'
        );
        if ($instUpdate) {
            $instUpdate->bind_param('si', $newInstallationDate, $current['customer_id']);
            $instUpdate->execute();
            $instUpdate->close();
        }

        /*
         * Recalculate next_renewal_date if customer has never been renewed
         * AND no Renew/Reactivate action is being performed right now.
         */
        if (
            ($lastRenewedDate === null || $lastRenewedDate === '') &&
            !in_array($action, ['Renew SIM', 'Reactivate SIM', 'Safe Custody'], true)
        ) {
            $newDate = renewalAddCalendarMonths($newInstallationDate, $newValidity);
        }
    }

    if ($previousPaid > 0) {
        $pendingStmt = $conn->prepare(
            "SELECT id, amount_paid, amount_pending
             FROM renewal_history
             WHERE renewal_id = ?
               AND action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
               AND amount_pending > 0
             ORDER BY id ASC
             FOR UPDATE"
        );
        if (!$pendingStmt) throw new RuntimeException('Unable to prepare old renewal balance lookup: ' . $conn->error);
        $pendingStmt->bind_param('i', $renewalId);
        if (!$pendingStmt->execute()) {
            $error = $pendingStmt->error;
            $pendingStmt->close();
            throw new RuntimeException('Unable to load old renewal balances: ' . $error);
        }
        $pendingRows = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pendingStmt->close();
        $availablePending = array_sum(array_map(
            static fn($row) => (float)$row['amount_pending'],
            $pendingRows
        ));
        if ($availablePending <= 0 || $previousPaid > $availablePending) {
            throw new InvalidArgumentException(
                'Old renewal payment cannot exceed the current outstanding balance of ₹' . number_format($availablePending, 2) . '.'
            );
        }

        $remainingPaid = $previousPaid;
        $pendingAfterPayment = $availablePending;
        foreach ($pendingRows as $pendingRow) {
            if ($remainingPaid <= 0) break;
            $rowPending = round((float)$pendingRow['amount_pending'], 2);
            $applied = min($rowPending, $remainingPaid);
            $rowPaid = round((float)$pendingRow['amount_paid'] + $applied, 2);
            $rowPendingAfter = round($rowPending - $applied, 2);
            $pendingUpdate = $conn->prepare('UPDATE renewal_history SET amount_paid = ?, amount_pending = ? WHERE id = ?');
            if (!$pendingUpdate) throw new RuntimeException('Unable to prepare old renewal balance update: ' . $conn->error);
            $historyId = (int)$pendingRow['id'];
            $pendingUpdate->bind_param('ddi', $rowPaid, $rowPendingAfter, $historyId);
            if (!$pendingUpdate->execute()) {
                $error = $pendingUpdate->error;
                $pendingUpdate->close();
                throw new RuntimeException('Unable to update old renewal balance: ' . $error);
            }
            $pendingUpdate->close();
            $remainingPaid = round($remainingPaid - $applied, 2);
            $pendingAfterPayment = round($pendingAfterPayment - $applied, 2);
        }

        $oldPaymentLog = $conn->prepare(
            "INSERT INTO renewal_history
                (renewal_id, customer_id, action_type, action_date, old_status, new_status,
                 old_validity_months, new_validity_months, old_renewal_date, new_renewal_date,
                 payment_amount, amount_paid, amount_pending, payment_mode, payment_date, transaction_id, changed_by)
             VALUES (?, ?, 'Renewal Payment', ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NULLIF(?, ''), ?)"
        );
        if (!$oldPaymentLog) throw new RuntimeException('Unable to prepare old renewal payment history: ' . $conn->error);
        $previousCustomerId = (int)$current['customer_id'];
        $previousOldValidity = (int)$current['validity_months'];
        $previousNewValidity = $previousOldValidity;
        $previousOldDate = (string)($current['next_renewal_date'] ?? '');
        $previousNewDate = $previousOldDate;
        $previousNewStatus = (string)$current['sim_status'];
        $previousUserId = $userId ?: null;
        $oldPaymentLog->bind_param(
            'iisssiissddsssi',
            $renewalId,
            $previousCustomerId,
            $previousDate,
            $previousNewStatus,
            $previousNewStatus,
            $previousOldValidity,
            $previousNewValidity,
            $previousOldDate,
            $previousNewDate,
            $previousPaid,
            $pendingAfterPayment,
            $previousMode,
            $previousDate,
            $previousTransactionId,
            $previousUserId
        );
        if (!$oldPaymentLog->execute()) {
            $error = $oldPaymentLog->error;
            $oldPaymentLog->close();
            throw new RuntimeException('Unable to save old renewal payment history: ' . $error);
        }
        $oldPaymentHistoryId = (string)$oldPaymentLog->insert_id;
        $oldPaymentLog->close();
        if ($previousTransactionId !== '') {
            reserveTransactionId($conn, $previousTransactionId, 'renewal_history', $oldPaymentHistoryId);
        }
    }

    if ($action === 'Renewal Payment') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Old renewal payment recorded successfully.', [
            'amount_paid' => $previousPaid,
            'amount_pending' => $pendingAfterPayment
        ]);
    }

    $newSafeCustodyDate = $current['safe_custody_date'];
    if ($action === 'Safe Custody') {
        $newSafeCustodyDate = $historyActionDate;
    } elseif (in_array($action, ['Renew SIM', 'Reactivate SIM'], true)) {
        $newSafeCustodyDate = null;
    }

    $update = $conn->prepare('UPDATE customer_renewals SET sim_status = ?, validity_months = ?, next_renewal_date = ?, last_renewed_date = ?, installation_date = ?, expired_to_safe_days = ?, safe_to_deactive_days = ?, safe_custody_date = ? WHERE id = ?');
    if (!$update) throw new Exception('Failed to prepare renewal update.');
    $update->bind_param('sisssiisi', $newStatus, $newValidity, $newDate, $lastRenewedDate, $newInstallationDate, $lifecycleExpiredDays, $lifecycleDeactiveDays, $newSafeCustodyDate, $renewalId);
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new Exception('Failed to update renewal: ' . $error);
    }
    $update->close();
    if ($action !== '' && !renewalHistoryInsert($conn, $current, $action, $userId, $newStatus, $newValidity, $newDate, $payment, $notes, $historyActionDate)) throw new Exception('Failed to save renewal history.');
    $conn->commit(); $conn->close();
    sendResponse(true, 'Renewal action completed successfully.', ['status' => $newStatus, 'next_renewal_date' => $newDate, 'last_renewed_date' => $lastRenewedDate, 'installation_date' => $newInstallationDate]);
} catch (Throwable $error) {
    $conn->rollback(); $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>