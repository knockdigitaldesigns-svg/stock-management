<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/date.php';
require_once '../../utils/dealer_sim_activation.php';
require_once '../../utils/renewal_history.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/transaction_ids.php';
require_once '../../utils/stock_allocation_payment.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($data['allocation_id'] ?? 0);
$action = trim((string) ($data['action'] ?? 'update'));
$isSuperAdmin = isSuperAdminUser((int)($currentUser['user_id'] ?? 0));

if ($action === 'update') {
    if ($isSuperAdmin) {
        requirePermission('dealer_sim_activation.edit');
    } else {
        requireAnyPermission(['dealer_sim_activation.edit', 'dealers.edit']);
    }
} else {
    requireAnyPermission(['dealer_sim_activation.edit', 'dealers.edit']);
}

if ($id <= 0) sendResponse(false, 'Valid allocation ID is required', [], [], 400);

$db = new Database();
$conn = $db->getConnection();

try {
    foreach ([
        'allocation_id' => "ALTER TABLE renewal_history ADD COLUMN allocation_id INT DEFAULT NULL AFTER renewal_id",
        'payment_date' => "ALTER TABLE renewal_history ADD COLUMN payment_date DATE DEFAULT NULL AFTER payment_mode",
        'bulk_payment_request_key' => "ALTER TABLE renewal_history ADD COLUMN bulk_payment_request_key VARCHAR(64) DEFAULT NULL AFTER changed_by",
        'source_history_id' => "ALTER TABLE renewal_history ADD COLUMN source_history_id INT DEFAULT NULL AFTER bulk_payment_request_key",
        'source_balance_updated' => "ALTER TABLE renewal_history ADD COLUMN source_balance_updated TINYINT(1) NOT NULL DEFAULT 1 AFTER source_history_id"
    ] as $column => $alterSql) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM renewal_history LIKE '$column'");
        if (!$columnCheck) {
            throw new RuntimeException('Unable to check renewal history schema: ' . $conn->error);
        }
        if ($columnCheck->num_rows === 0 && !$conn->query($alterSql)) {
            $alterError = $conn->error;
            $retryCheck = $conn->query("SHOW COLUMNS FROM renewal_history LIKE '$column'");
            if (!$retryCheck || $retryCheck->num_rows === 0) {
                throw new RuntimeException('Unable to add renewal history column ' . $column . ': ' . $alterError);
            }
        }
    }
    ensureRenewalHistoryPaymentActionType($conn);
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT * FROM stock_allocations WHERE id = ? AND owner_type = 'dealer' AND sim_id IS NOT NULL FOR UPDATE");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $oldAllocation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$oldAllocation) {
        throw new Exception('Stock allocation not found or not a valid dealer SIM.');
    }

    $previousRequestData = is_array($data['previous_pending_payment'] ?? null)
        ? $data['previous_pending_payment']
        : [];
    $lifecycleRequestData = is_array($data['lifecycle_payment'] ?? null)
        ? $data['lifecycle_payment']
        : [];
    $paymentRequestKey = trim((string)(
        $previousRequestData['request_key'] ?? $lifecycleRequestData['request_key'] ?? ''
    ));
    if ($action === 'renewal_payment' && !preg_match('/^[A-Za-z0-9-]{16,64}$/', $paymentRequestKey)) {
        throw new InvalidArgumentException('A valid renewal payment request key is required.');
    }
    if ($paymentRequestKey !== '' && !preg_match('/^[A-Za-z0-9-]{16,64}$/', $paymentRequestKey)) {
        throw new InvalidArgumentException('Invalid renewal payment request key.');
    }
    if (($action === 'renew' || $action === 'reactivate') && (float)($lifecycleRequestData['amount_paid'] ?? 0) > 0
        && $paymentRequestKey === '') {
        throw new InvalidArgumentException('A valid lifecycle payment request key is required.');
    }
    if ($paymentRequestKey !== '') {
        $replayCheck = $conn->prepare(
            "SELECT id FROM renewal_history
             WHERE allocation_id = ? AND action_type = 'Renewal Payment'
               AND bulk_payment_request_key = ?
             LIMIT 1 FOR UPDATE"
        );
        if (!$replayCheck) {
            throw new RuntimeException('Unable to prepare renewal payment duplicate check: ' . $conn->error);
        }
        $replayCheck->bind_param('is', $id, $paymentRequestKey);
        if (!$replayCheck->execute()) {
            $error = $replayCheck->error;
            $replayCheck->close();
            throw new RuntimeException('Unable to check for a repeated renewal payment: ' . $error);
        }
        $alreadyRecorded = $replayCheck->get_result()->num_rows > 0;
        $replayCheck->close();
        if ($alreadyRecorded) {
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Renewal payment was already recorded.', ['idempotent_replay' => true]);
        }
    }
    if ($action === 'update' && !$isSuperAdmin) {
        if (strtolower(trim((string)($oldAllocation['sim_status'] ?? ''))) !== 'available') {
            throw new InvalidArgumentException('Only Super Admin can edit an already activated SIM.');
        }
        $allowedActivationFields = ['allocation_id', 'action', 'activation_date', 'sim_validity_id'];
        if (array_diff(array_keys($data), $allowedActivationFields)) {
            throw new InvalidArgumentException('You can only activate an Available SIM by setting its Activation Date and Validity.');
        }
        if (trim((string)($data['activation_date'] ?? '')) === '' || (int)($data['sim_validity_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Enter an Activation Date and select a Validity to activate this SIM.');
        }
    }

    $lifecyclePayment = $data['lifecycle_payment'] ?? [];
    if (!is_array($lifecyclePayment)) {
        throw new InvalidArgumentException('Invalid lifecycle payment details.');
    }
    $paymentTotalRaw = $lifecyclePayment['total_amount'] ?? '';
    $paymentPaidRaw = $lifecyclePayment['amount_paid'] ?? '';
    if (($paymentTotalRaw !== '' && !is_numeric($paymentTotalRaw))
        || ($paymentPaidRaw !== '' && !is_numeric($paymentPaidRaw))) {
        throw new InvalidArgumentException('Payment amounts must be valid numbers.');
    }
    $paymentAmount = round((float) ($paymentTotalRaw ?: 0), 2);
    $paymentPaid = round((float) ($paymentPaidRaw ?: 0), 2);
    if ($paymentAmount < 0 || $paymentPaid < 0 || $paymentAmount > 99999999.99 || $paymentPaid > 99999999.99) {
        throw new InvalidArgumentException('Payment amounts must be between ₹0.00 and ₹99,999,999.99.');
    }
    if (in_array($action, ['renew', 'reactivate'], true) && $paymentAmount <= 0) {
        throw new InvalidArgumentException(
            $action === 'renew'
                ? 'New renewal Total Amount must be greater than zero.'
                : 'New reactivation Total Amount must be greater than zero.'
        );
    }
    if ($paymentPaid > $paymentAmount) {
        throw new InvalidArgumentException('Amount Paid cannot exceed Total Amount.');
    }
    $paymentPending = round($paymentAmount - $paymentPaid, 2);
    $paymentMode = trim((string) ($lifecyclePayment['payment_mode'] ?? ''));
    $paymentDate = trim((string) ($lifecyclePayment['payment_date'] ?? ''));
    $transactionId = normalizeTransactionId(trim((string) ($lifecyclePayment['transaction_id'] ?? '')));
    if ($paymentMode !== '' && !in_array($paymentMode, getPaymentModes(), true)) {
        throw new InvalidArgumentException('Select a valid Payment Mode.');
    }
    if ($paymentPaid > 0) {
        if ($paymentMode === '') {
            throw new InvalidArgumentException('Please select the Payment Mode for the payment.');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paymentDate, $paymentDateParts)
            || !checkdate((int) $paymentDateParts[2], (int) $paymentDateParts[3], (int) $paymentDateParts[1])) {
            throw new InvalidArgumentException('Please select a valid Payment Date.');
        }
        if ($paymentMode !== 'Cash' && $transactionId === '') {
            throw new InvalidArgumentException('Transaction ID is required for the selected Payment Mode.');
        }
        if ($paymentMode === 'Cash') {
            $transactionId = '';
        }
    } else {
        $paymentMode = '';
        $paymentDate = '';
        $transactionId = '';
    }

    $previousPendingPayment = $data['previous_pending_payment'] ?? [];
    if (!is_array($previousPendingPayment)) {
        throw new InvalidArgumentException('Invalid previous renewal payment details.');
    }
    $previousPendingPaidRaw = $previousPendingPayment['amount_paid'] ?? '';
    if ($previousPendingPaidRaw !== '' && !is_numeric($previousPendingPaidRaw)) {
        throw new InvalidArgumentException('Previous renewal payment amount must be a valid number.');
    }
    $previousPendingPaid = round((float) ($previousPendingPaidRaw ?: 0), 2);
    if ($previousPendingPaid < 0 || $previousPendingPaid > 99999999.99) {
        throw new InvalidArgumentException('Previous renewal payment amount is invalid.');
    }
    $previousPendingMode = trim((string) ($previousPendingPayment['payment_mode'] ?? ''));
    $previousPendingDate = trim((string) ($previousPendingPayment['payment_date'] ?? ''));
    $previousPendingTransactionId = normalizeTransactionId(
        trim((string) ($previousPendingPayment['transaction_id'] ?? ''))
    );
    if ($previousPendingPaid > 0) {
        if (!in_array($previousPendingMode, getPaymentModes(), true)) {
            throw new InvalidArgumentException('Select a valid Payment Mode for the previous renewal payment.');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $previousPendingDate, $previousPaymentDateParts)
            || !checkdate((int) $previousPaymentDateParts[2], (int) $previousPaymentDateParts[3], (int) $previousPaymentDateParts[1])) {
            throw new InvalidArgumentException('Please select a valid date for the previous renewal payment.');
        }
        if ($previousPendingMode !== 'Cash' && $previousPendingTransactionId === '') {
            throw new InvalidArgumentException('Transaction ID is required for the previous renewal payment.');
        }
        if ($previousPendingMode === 'Cash') {
            $previousPendingTransactionId = '';
        }
    } else {
        $previousPendingMode = '';
        $previousPendingDate = '';
        $previousPendingTransactionId = '';
    }

    $oldStatus = $oldAllocation['sim_status'] ?: 'Available';
    if ($action === 'renewal_payment' && $oldStatus === 'Available') {
        throw new InvalidArgumentException('Previous renewal payments can only be recorded for an activated SIM.');
    }
    if ($action === 'renewal_payment' && $previousPendingPaid <= 0) {
        throw new InvalidArgumentException('Previous renewal payment amount must be greater than zero.');
    }
    $oldValidityId = (int) ($oldAllocation['sim_validity_id'] ?? 0);
    $oldActivation = $oldAllocation['sim_activation_date'] ?: null;
    $oldDeactivation = $oldAllocation['sim_deactivation_date'] ?: null;
    $oldReactivation = $oldAllocation['sim_reactivation_date'] ?: null;
    $oldExpiry = $oldAllocation['sim_expiry_date'] ?: null;
    
    $newStatus = $oldStatus;
    $newActivation = $oldActivation;
    $newDeactivation = $oldDeactivation;
    $newReactivation = $oldReactivation;
    $newExpiry = $oldExpiry;
    $newValidityId = $oldValidityId;
    
    $actionTypeLog = null;
    $logActionDate = null;
    $logNewRenewalDate = null;
    $logNewValidityMonths = null;
    
    function getValidityMonths($conn, $vid, $requireActive = false) {
        $vStmt = $conn->prepare("SELECT months, status FROM sim_validities WHERE id = ? LIMIT 1");
        $vStmt->bind_param('i', $vid);
        $vStmt->execute();
        $vRow = $vStmt->get_result()->fetch_assoc();
        $vStmt->close();
        if (!$vRow) throw new Exception('Selected SIM validity is invalid');
        if ($requireActive && !empty($vRow['status']) && strtolower((string) $vRow['status']) !== 'active') {
            throw new InvalidArgumentException('Selected SIM validity is inactive.');
        }
        return (int) $vRow['months'];
    }

    if ($oldStatus === 'Available') {
        // Initial activation
        $activationDate = trim((string) ($data['activation_date'] ?? ''));
        $validityId = (int) ($data['sim_validity_id'] ?? 0);
        
        if ($activationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) throw new Exception('Valid activation date is required');
        if ($activationDate !== '' && $validityId <= 0) throw new Exception('Validity is required when activating');
        
        if ($activationDate !== '') {
            $months = getValidityMonths($conn, $validityId);
            $newExpiry = calculateDealerSimExpiryDate($activationDate, $months);
            $newStatus = 'Active';
            $newActivation = $activationDate;
            $newValidityId = $validityId;
        } else {
            if ($validityId > 0) $newValidityId = $validityId;
        }
        
    } else {
        // Active, Expired, Safe Custody, Deactive
        if ($action === 'renew') {
            if ($oldStatus === 'Deactive') throw new Exception('Cannot renew a deactivated SIM');
            
            $renewalDate = $oldExpiry;
            $renewalValidityId = (int) ($data['renewal_validity_id'] ?? 0);
            
            if (!$renewalDate) throw new InvalidArgumentException('The saved Expiry Date is missing. SIM renewal cannot calculate the next renewal cycle.');
            if ($renewalValidityId <= 0) throw new Exception('Renewal validity is required');
            
            $months = getValidityMonths($conn, $renewalValidityId);
            
            $newExpiry = calculateDealerSimExpiryDate($renewalDate, $months);
            $newStatus = 'Active';
            $newValidityId = $renewalValidityId;
            
            $actionTypeLog = 'Renew SIM';
            $logActionDate = $renewalDate;
            $logNewRenewalDate = $newExpiry;
            $logNewValidityMonths = $months;
            
        } elseif ($action === 'deactivate') {
            if ($oldStatus === 'Deactive') throw new Exception('SIM is already deactivated');
            
            $deactivationDate = trim((string) ($data['deactivation_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deactivationDate)) throw new Exception('Valid deactivation date is required');
            if ($oldActivation && $deactivationDate < $oldActivation) throw new Exception('Deactivation date cannot be before activation date');
            
            $newStatus = 'Deactive';
            $newDeactivation = $deactivationDate;
            
            $actionTypeLog = 'Deactivate SIM';
            $logActionDate = $deactivationDate;
            
        } elseif ($action === 'safe_custody') {
            if ($oldStatus === 'Deactive') throw new Exception('Cannot move deactivated SIM to safe custody');
            
            $newStatus = 'Safe Custody';
            $actionTypeLog = 'Safe Custody';
            $logActionDate = date('Y-m-d');
            
        } elseif ($action === 'reactivate') {
            if (!in_array($oldStatus, ['Deactive', 'Safe Custody'], true)) {
                throw new Exception('Only Deactive or Safe Custody SIMs can be moved to Active.');
            }
            
            $reactivationDate = trim((string) ($data['reactivation_date'] ?? ''));
            
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $reactivationDate, $reactivationParts)
                || !checkdate((int) $reactivationParts[2], (int) $reactivationParts[3], (int) $reactivationParts[1])) {
                throw new Exception('Valid reactivation date is required');
            }
            if ($oldStatus === 'Deactive' && $oldDeactivation && $reactivationDate < $oldDeactivation) {
                throw new Exception('Reactivation date cannot be before deactivation date');
            }
            
            $reactivationValidityId = (int) ($data['reactivation_validity_id'] ?? 0);
            if ($reactivationValidityId <= 0) {
                throw new InvalidArgumentException('Reactivation validity is required.');
            }
            $months = getValidityMonths($conn, $reactivationValidityId, true);
            if (!$oldExpiry) {
                throw new InvalidArgumentException('The previously saved Expiry Date is missing. SIM reactivation cannot calculate the next renewal cycle.');
            }
            $newExpiry = calculateDealerSimExpiryDate($oldExpiry, $months);
            $newStatus = 'Active';
            $newReactivation = $reactivationDate;
            $newValidityId = $reactivationValidityId;
            
            $actionTypeLog = 'Reactivate SIM';
            $logActionDate = $reactivationDate;
            $logNewRenewalDate = $newExpiry;
            $logNewValidityMonths = $months;
            
        } elseif ($action === 'update') {
            $activationDate = trim((string) ($data['activation_date'] ?? ''));
            $validityId = (int) ($data['sim_validity_id'] ?? 0);

            if ($activationDate !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $activationDate, $dateParts)
                || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
                throw new InvalidArgumentException('Please enter a valid Activation Date.');
            }
            if (($activationDate === '') !== ($validityId <= 0)) {
                throw new InvalidArgumentException('Activation Date and Validity must both be provided.');
            }
            if ($oldStatus !== 'Available' && ($activationDate === '' || $validityId <= 0)) {
                throw new InvalidArgumentException('Activation Date and Validity cannot be cleared after the SIM has been activated.');
            }
            if ($activationDate !== $oldActivation || $validityId !== $oldValidityId) {
                if ($activationDate !== '') {
                    $months = getValidityMonths($conn, $validityId);
                    $newExpiry = calculateDealerSimExpiryDate($activationDate, $months);
                } else {
                    $newExpiry = null;
                }
                $newActivation = $activationDate !== '' ? $activationDate : null;
                $newValidityId = $validityId > 0 ? $validityId : null;
            }
            foreach (['deactivation_date', 'reactivation_date'] as $dateField) {
                if (!array_key_exists($dateField, $data)) {
                    continue;
                }
                $requestedDate = trim((string) $data[$dateField]);
                if ($requestedDate !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $requestedDate, $dateParts)
                    || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
                    throw new InvalidArgumentException('Please enter a valid ' . str_replace('_', ' ', ucfirst($dateField)) . '.');
                }
                if ($dateField === 'deactivation_date') {
                    $newDeactivation = $requestedDate !== '' ? $requestedDate : null;
                } else {
                    $newReactivation = $requestedDate !== '' ? $requestedDate : null;
                }
            }
        } elseif ($action === 'renewal_payment') {
            // Record payment against existing renewal balances without changing SIM lifecycle dates.
        } else {
            throw new Exception('Invalid action');
        }
    }

    $auditFields = [
        'sim_activation_date' => $newActivation,
        'sim_validity_id' => $newValidityId,
        'sim_expiry_date' => $newExpiry,
        'sim_deactivation_date' => $newDeactivation,
        'sim_reactivation_date' => $newReactivation,
        'sim_status' => $newStatus
    ];
    if ($action === 'update' && array_key_exists('given_date', $data)) {
        $givenDate = trim((string) $data['given_date']);
        if ($givenDate !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $givenDate, $dateParts)
            || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
            throw new InvalidArgumentException('Please enter a valid Given Date.');
        }
        $auditFields['sim_given_date'] = $givenDate !== '' ? $givenDate : null;
    }

    $paymentFields = ['total_amount', 'amount_paid', 'payment_mode', 'transaction_id', 'payment_status', 'payment_date'];
    if (array_intersect($paymentFields, array_keys($data))) {
        throw new InvalidArgumentException('Payment summary edits must be provided in payment_details.');
    }

    if (in_array($action, ['renew', 'reactivate', 'renewal_payment'], true) && $previousPendingPaid > 0) {
        $pendingStmt = $conn->prepare(
            "SELECT rh.id, rh.amount_pending, rh.old_status, rh.new_status,
                    rh.old_renewal_date, rh.new_renewal_date,
                    GREATEST(0, rh.amount_pending - COALESCE((
                        SELECT SUM(payment.amount_paid)
                        FROM renewal_history payment
                        WHERE payment.source_history_id = rh.id
                          AND payment.action_type = 'Renewal Payment'
                          AND payment.source_balance_updated = 0
                    ), 0)) AS current_pending
             FROM renewal_history rh
             WHERE rh.allocation_id = ?
               AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
               AND GREATEST(0, rh.amount_pending - COALESCE((
                   SELECT SUM(payment.amount_paid)
                   FROM renewal_history payment
                   WHERE payment.source_history_id = rh.id
                     AND payment.action_type = 'Renewal Payment'
                     AND payment.source_balance_updated = 0
               ), 0)) > 0
             ORDER BY rh.id ASC
             FOR UPDATE"
        );
        if (!$pendingStmt) {
            throw new RuntimeException('Unable to prepare previous renewal balance lookup: ' . $conn->error);
        }
        $pendingStmt->bind_param('i', $id);
        if (!$pendingStmt->execute()) {
            $error = $pendingStmt->error;
            $pendingStmt->close();
            throw new RuntimeException('Unable to load previous renewal balances: ' . $error);
        }
        $pendingRows = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pendingStmt->close();
        $availablePending = array_sum(array_map(
            static fn($row) => (float) $row['current_pending'],
            $pendingRows
        ));
        if ($previousPendingPaid > $availablePending) {
            throw new InvalidArgumentException(
                'Previous renewal payment cannot exceed the outstanding balance of ₹' . number_format($availablePending, 2) . '.'
            );
        }

        $remainingPayment = $previousPendingPaid;
        $reservedPaymentId = null;
        foreach ($pendingRows as $pendingRow) {
            if ($remainingPayment <= 0) break;
            $rowPending = round((float) $pendingRow['current_pending'], 2);
            $appliedPayment = min($rowPending, $remainingPayment);
            $newRowPending = round($rowPending - $appliedPayment, 2);
            $remainingPayment = round($remainingPayment - $appliedPayment, 2);

            $sourceHistoryId = (int)$pendingRow['id'];

            $paymentLog = $conn->prepare(
                "INSERT INTO renewal_history
                    (renewal_id, allocation_id, customer_id, action_type, action_date, old_status, new_status,
                     old_renewal_date, new_renewal_date, payment_amount, amount_paid, amount_pending,
                     payment_mode, payment_date, transaction_id, changed_by, bulk_payment_request_key,
                     source_history_id, source_balance_updated)
                 VALUES (0, ?, 0, 'Renewal Payment', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NULLIF(?, ''),
                         ?, ?, ?, 0)"
            );
            if (!$paymentLog) {
                throw new RuntimeException('Unable to prepare previous renewal payment history: ' . $conn->error);
            }
            $paymentUserId = (int)($currentUser['id'] ?? 0);
            $paymentLog->bind_param(
                'isssssddsssisi',
                $id,
                $previousPendingDate,
                $pendingRow['old_status'],
                $pendingRow['new_status'],
                $pendingRow['old_renewal_date'],
                $pendingRow['new_renewal_date'],
                $appliedPayment,
                $newRowPending,
                $previousPendingMode,
                $previousPendingDate,
                $previousPendingTransactionId,
                $paymentUserId,
                $paymentRequestKey,
                $sourceHistoryId
            );
            if (!$paymentLog->execute()) {
                $error = $paymentLog->error;
                $paymentLog->close();
                throw new RuntimeException('Unable to save previous renewal payment history: ' . $error);
            }
            if ($reservedPaymentId === null) {
                $reservedPaymentId = (string)$paymentLog->insert_id;
            }
            $paymentLog->close();
        }
        if ($remainingPayment > 0) {
            throw new RuntimeException('Unable to allocate the full renewal payment to the current outstanding balances.');
        }
        if ($previousPendingTransactionId !== '' && $reservedPaymentId !== null) {
            reserveTransactionId($conn, $previousPendingTransactionId, 'renewal_history', $reservedPaymentId);
        }
    }

    if ($action === 'renewal_payment') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Previous renewal payment recorded successfully.', []);
    }

    saveDealerSimLifecycle($conn, $id, $oldAllocation, $auditFields, $currentUser);

    if ($action === 'update' && array_key_exists('payment_details', $data)) {
        if (!is_array($data['payment_details'])) {
            throw new InvalidArgumentException('Invalid payment details.');
        }
        $paymentDetails = $data['payment_details'];
        $amountPaid = round((float) ($paymentDetails['amount_paid'] ?? -1), 2);
        $totalAmount = round((float) ($paymentDetails['total_amount'] ?? -1), 2);
        $paymentMode = trim((string) ($paymentDetails['payment_mode'] ?? ''));
        $paymentDate = trim((string) ($paymentDetails['payment_date'] ?? ''));
        $transactionId = trim((string) ($paymentDetails['transaction_id'] ?? ''));
        if ($amountPaid > 0) {
            if ($paymentMode === '' || $paymentDate === '') {
                throw new InvalidArgumentException('Payment Mode and Payment Date are required when Amount Paid is greater than zero.');
            }
            if ($paymentMode !== 'Cash' && $transactionId === '') {
                throw new InvalidArgumentException('Transaction ID is required for the selected Payment Mode.');
            }
        }
        updateStockAllocationPayment(
            $conn,
            $id,
            $paymentDetails,
            $currentUser,
            $oldAllocation,
            true
        );
    }
    
    if ($actionTypeLog) {
        $oldValidityMonths = null;
        if ($oldValidityId > 0) {
            $vStmt = $conn->prepare("SELECT months FROM sim_validities WHERE id = ? LIMIT 1");
            $vStmt->bind_param('i', $oldValidityId);
            $vStmt->execute();
            $vRow = $vStmt->get_result()->fetch_assoc();
            $vStmt->close();
            if ($vRow) $oldValidityMonths = (int) $vRow['months'];
        }
        
        $histSql = "INSERT INTO renewal_history
                    (renewal_id, allocation_id, customer_id, action_type, action_date, old_status, new_status,
                     old_validity_months, new_validity_months, old_renewal_date, new_renewal_date,
                     payment_amount, amount_paid, amount_pending, payment_mode, payment_date, transaction_id, changed_by)
                    VALUES (0, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?)";
        $histStmt = $conn->prepare($histSql);
        $userId = $currentUser['id'] ?? null;
        $histStmt->bind_param('issssiissdddsssi',
            $id,
            $actionTypeLog,
            $logActionDate,
            $oldStatus,
            $newStatus,
            $oldValidityMonths,
            $logNewValidityMonths,
            $oldExpiry,
            $logNewRenewalDate,
            $paymentAmount,
            $paymentPaid,
            $paymentPending,
            $paymentMode,
            $paymentDate,
            $transactionId,
            $userId
        );
        if (!$histStmt->execute()) throw new Exception('Failed to write SIM lifecycle history: ' . $histStmt->error);
        $historyId = (int)$histStmt->insert_id;
        $histStmt->close();

        if ($paymentPaid > 0 && in_array($actionTypeLog, ['Renew SIM', 'Reactivate SIM'], true)) {
            $paymentLog = $conn->prepare(
                "INSERT INTO renewal_history
                    (renewal_id, allocation_id, customer_id, action_type, action_date,
                     old_status, new_status, old_renewal_date, new_renewal_date,
                     payment_amount, amount_paid, amount_pending, payment_mode, payment_date,
                     transaction_id, changed_by, bulk_payment_request_key, source_history_id,
                     source_balance_updated)
                 VALUES (0, ?, 0, 'Renewal Payment', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?,
                         NULLIF(?, ''), ?, ?, ?, 1)"
            );
            if (!$paymentLog) {
                throw new RuntimeException('Unable to prepare initial renewal payment history: ' . $conn->error);
            }
            $paymentHistoryDate = $paymentDate;
            $paymentUserId = (int)($currentUser['id'] ?? 0);
            $paymentLog->bind_param(
                'isssssddsssisi',
                $id,
                $paymentHistoryDate,
                $oldStatus,
                $newStatus,
                $oldExpiry,
                $logNewRenewalDate,
                $paymentPaid,
                $paymentPending,
                $paymentMode,
                $paymentHistoryDate,
                $transactionId,
                $paymentUserId,
                $paymentRequestKey,
                $historyId
            );
            if (!$paymentLog->execute()) {
                $error = $paymentLog->error;
                $paymentLog->close();
                throw new RuntimeException('Unable to save initial renewal payment history: ' . $error);
            }
            $paymentHistoryId = (string)$paymentLog->insert_id;
            $paymentLog->close();
            if ($transactionId !== '') {
                reserveTransactionId($conn, $transactionId, 'renewal_history', $paymentHistoryId);
            }
        }
    }
    
    $conn->commit();
    $conn->close();
    
    sendResponse(true, 'SIM lifecycle updated successfully', []);
} catch (InvalidArgumentException $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
