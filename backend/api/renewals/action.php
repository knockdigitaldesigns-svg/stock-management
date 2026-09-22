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
$allowedActions = ['', 'Renew SIM', 'Deactivate SIM', 'Safe Custody', 'Reactivate SIM'];
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
if (!in_array($action, $allowedActions, true)) sendResponse(false, 'Invalid renewal action.', [], [], 400);
$conn = (new Database())->getConnection();
$conn->begin_transaction();
try {
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
    $notes = trim((string)($payload['notes'] ?? '')) ?: null;

    if ($action === 'Deactivate SIM') {
        if (!in_array($current['sim_status'], ['Active', 'Expired', 'Safe Custody'], true)) throw new Exception('Deactivate SIM is not available for this status.');
        if ($current['sim_status'] === 'Deactive') throw new Exception('SIM is already Deactive.');
        $newStatus = 'Deactive';
    } elseif ($action === 'Safe Custody') {
        if (!in_array($current['sim_status'], ['Active', 'Expired'], true)) throw new Exception('Safe Custody is not available for this status.');
        if ($current['sim_status'] === 'Safe Custody') throw new Exception('SIM is already in Safe Custody.');
        $newStatus = 'Safe Custody';
    } elseif ($action === 'Reactivate SIM') {
        if ($current['sim_status'] !== 'Deactive') throw new Exception('Reactivate SIM is only available for Deactive SIMs.');
        $reactivationDate = trim((string)($payload['reactivation_date'] ?? ''));
        $newValidity = (int)($payload['validity_months'] ?? 0);
        $date = DateTime::createFromFormat('Y-m-d', $reactivationDate);
        if (!$date || $date->format('Y-m-d') !== $reactivationDate || $newValidity <= 0) throw new Exception('Actual Reactivation Date and Validity are required.');
        $validityCheck = $conn->prepare('SELECT months FROM sim_validities WHERE months = ? LIMIT 1'); $validityCheck->bind_param('i', $newValidity); $validityCheck->execute(); $validityExists = $validityCheck->get_result()->num_rows > 0; $validityCheck->close();
        if (!$validityExists) throw new Exception('Selected validity is not available.');
        $date->modify('+' . $newValidity . ' months');
        $newDate = $date->format('Y-m-d');
        $lastRenewedDate = $reactivationDate;
        $newStatus = 'Active';
    } elseif ($action === 'Renew SIM') {
        if (!in_array($current['sim_status'], ['Active', 'Expired', 'Safe Custody'], true)) throw new Exception('Renew SIM is not available for this status.');
        $renewalDate = trim((string)($payload['renewal_date'] ?? date('Y-m-d')));
        $newValidity = (int)($payload['validity_months'] ?? 0);
        $date = DateTime::createFromFormat('Y-m-d', $renewalDate);
        if (!$date || $date->format('Y-m-d') !== $renewalDate || $newValidity <= 0) throw new Exception('Renewal Date and Validity are required.');
        $validityCheck = $conn->prepare('SELECT months FROM sim_validities WHERE months = ? LIMIT 1'); $validityCheck->bind_param('i', $newValidity); $validityCheck->execute(); $validityExists = $validityCheck->get_result()->num_rows > 0; $validityCheck->close();
        if (!$validityExists) throw new Exception('Selected validity is not available.');
        $date->modify('+' . $newValidity . ' months');
        $newDate = $date->format('Y-m-d');
        $lastRenewedDate = $renewalDate;
        $newStatus = 'Active';
        $total = (float)($payload['payment_amount'] ?? 0);
        $paid = (float)($payload['amount_paid'] ?? 0);
        $pending = $total - $paid;
        $paymentStatus = trim((string)($payload['payment_status'] ?? ''));
        $paymentMode = trim((string)($payload['payment_mode'] ?? ''));
        $transactionId = trim((string)($payload['transaction_id'] ?? ''));
        if ($total <= 0) throw new Exception('Total Amount is required.');
        if ($paid < 0) throw new Exception('Amount Paid cannot be negative.');
        if ($paid > $total) throw new Exception('Amount Paid cannot exceed Total Amount.');
        $pending = max(0, $total - $paid);
        $expectedStatus = $total <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Not Paid'));
        if ($paymentStatus !== '' && $paymentStatus !== $expectedStatus) throw new Exception('Payment Status does not match the amounts.');
        if ($transactionId !== '' && $paymentMode === '') throw new Exception('Payment Mode is required when Transaction ID is entered.');
        if ($paymentMode !== '' && $paymentMode !== 'Cash' && $transactionId === '') throw new Exception('Transaction ID is required for the selected Payment Mode.');
        $payment = ['payment_amount' => $total, 'amount_paid' => $paid, 'amount_pending' => $pending, 'payment_mode' => $paymentMode ?: null, 'transaction_id' => $transactionId ?: null];
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
            !in_array($action, ['Renew SIM', 'Reactivate SIM'], true)
        ) {
            $calcDate = new DateTime($newInstallationDate);
            $calcDate->modify('+' . $newValidity . ' months');
            $newDate = $calcDate->format('Y-m-d');
        }
    }

    $newSafeCustodyDate = $current['safe_custody_date'];
    if ($action === 'Safe Custody') {
        $newSafeCustodyDate = date('Y-m-d');
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
    if ($action !== '' && !renewalHistoryInsert($conn, $current, $action, $userId, $newStatus, $newValidity, $newDate, $payment, $notes)) throw new Exception('Failed to save renewal history.');
    $conn->commit(); $conn->close();
    sendResponse(true, 'Renewal action completed successfully.', ['status' => $newStatus, 'next_renewal_date' => $newDate, 'last_renewed_date' => $lastRenewedDate, 'installation_date' => $newInstallationDate]);
} catch (Throwable $error) {
    $conn->rollback(); $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>