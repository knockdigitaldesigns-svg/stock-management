<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim((string)($payload['action_type'] ?? ''));
$permission = in_array($action, ['Renew SIM', 'Reactivate SIM'], true) ? 'customer_renewals.renew' : 'customer_renewals.edit';
requirePermission($permission);
$token = getCurrentUserFromToken();
$userId = (int)($token['user_id'] ?? 0);
$renewalId = (int)($payload['renewal_id'] ?? 0);
$allowedActions = ['Renew SIM', 'Deactivate SIM', 'Safe Custody', 'Reactivate SIM'];
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
        if ($paid < 0 || $paid > $total) throw new Exception('Amount Paid cannot exceed Total Amount.');
        $expectedStatus = $paid <= 0 ? 'Not Paid' : ($paid < $total ? 'Partially Paid' : 'Paid');
        if ($paymentStatus !== $expectedStatus) throw new Exception('Payment Status does not match the amounts.');
        if ($transactionId !== '' && $paymentMode === '') throw new Exception('Payment Mode is required when Transaction ID is entered.');
        if ($paymentMode !== '' && $paymentMode !== 'Cash' && $transactionId === '') throw new Exception('Transaction ID is required for the selected Payment Mode.');
        $payment = ['payment_amount' => $total, 'amount_paid' => $paid, 'amount_pending' => $pending, 'payment_mode' => $paymentMode ?: null, 'transaction_id' => $transactionId ?: null];
    }

    $update = $conn->prepare('UPDATE customer_renewals SET sim_status = ?, validity_months = ?, next_renewal_date = ?, last_renewed_date = ?, expired_to_safe_days = ?, safe_to_deactive_days = ? WHERE id = ?');
    $update->bind_param('sissiii', $newStatus, $newValidity, $newDate, $lastRenewedDate, $lifecycleExpiredDays, $lifecycleDeactiveDays, $renewalId); $update->execute(); $update->close();
    if (!renewalHistoryInsert($conn, $current, $action, $userId, $newStatus, $newValidity, $newDate, $payment, $notes)) throw new Exception('Failed to save renewal history.');
    $conn->commit(); $conn->close();
    sendResponse(true, 'Renewal action completed successfully.', ['status' => $newStatus, 'next_renewal_date' => $newDate, 'last_renewed_date' => $lastRenewedDate]);
} catch (Throwable $error) {
    $conn->rollback(); $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>