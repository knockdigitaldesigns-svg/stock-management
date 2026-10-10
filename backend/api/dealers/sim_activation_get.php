<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/renewal_history.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

authenticate();
requireAnyPermission(['dealer_sim_activation.view', 'dealers.view']);

$allocationId = (int) ($_GET['allocation_id'] ?? 0);
if ($allocationId <= 0) {
    sendResponse(false, 'Valid SIM activation ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$historyColumns = [
    'allocation_id' => "ALTER TABLE renewal_history ADD COLUMN allocation_id INT DEFAULT NULL AFTER renewal_id",
    'payment_date' => "ALTER TABLE renewal_history ADD COLUMN payment_date DATE DEFAULT NULL AFTER payment_mode",
    'bulk_payment_request_key' => "ALTER TABLE renewal_history ADD COLUMN bulk_payment_request_key VARCHAR(64) DEFAULT NULL AFTER changed_by",
    'source_history_id' => "ALTER TABLE renewal_history ADD COLUMN source_history_id INT DEFAULT NULL AFTER bulk_payment_request_key",
    'source_balance_updated' => "ALTER TABLE renewal_history ADD COLUMN source_balance_updated TINYINT(1) NOT NULL DEFAULT 1 AFTER source_history_id"
];
foreach ($historyColumns as $column => $alterSql) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM renewal_history LIKE '$column'");
    if (!$columnCheck) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to check SIM payment history schema: ' . $error, [], [], 500);
    }
    if ($columnCheck->num_rows === 0 && !$conn->query($alterSql)) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to prepare SIM payment history: ' . $error, [], [], 500);
    }
}
try {
    ensureRenewalHistoryPaymentActionType($conn);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal payment history: ' . $error->getMessage(), [], [], 500);
}

$lifecyclePendingExpression = "COALESCE((
                  SELECT SUM(GREATEST(0, rh.amount_pending - COALESCE((
                      SELECT SUM(rhp.amount_paid)
                      FROM renewal_history rhp
                      WHERE rhp.source_history_id = rh.id
                        AND rhp.action_type = 'Renewal Payment'
                        AND rhp.source_balance_updated = 0
                  ), 0)))
                  FROM renewal_history rh
                  WHERE rh.allocation_id = sa.id
                    AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
              ), 0)";
$lifecyclePaidExpression = "COALESCE((
                  SELECT SUM(rh.amount_paid + COALESCE((
                      SELECT SUM(rhp.amount_paid)
                      FROM renewal_history rhp
                      WHERE rhp.source_history_id = rh.id
                        AND rhp.action_type = 'Renewal Payment'
                        AND rhp.source_balance_updated = 0
                  ), 0))
                  FROM renewal_history rh
                  WHERE rh.allocation_id = sa.id
                    AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
              ), 0)";

$query = "SELECT
              sa.id AS allocation_id,
              sa.sim_id,
              sa.owner_id AS dealer_id,
              d.dealer_name,
              s.sim_no,
              s.sim_type,
              COALESCE(sa.sim_given_date, sa.allocation_date) AS given_date,
              sa.sim_activation_date AS activation_date,
              sa.sim_reactivation_date AS reactivation_date,
              sa.sim_expiry_date AS renewal_date,
              sa.sim_expiry_date AS expiry_date,
              sa.sim_deactivation_date AS deactivation_date,
              COALESCE(sa.sim_status, 'Available') AS sim_status,
              COALESCE(sa.sim_validity_id, s.sim_validity_id) AS sim_validity_id,
              v.months AS validity_months,
              sa.sim_amount,
              sa.total_amount,
              COALESCE(sa.amount_paid, 0) AS amount_paid,
              GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) AS pending_amount,
              COALESCE(sa.total_amount, 0) + COALESCE((
                  SELECT SUM(rh.payment_amount)
                  FROM renewal_history rh
                  WHERE rh.allocation_id = sa.id
                    AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
              ), 0) AS total_payment_amount,
              COALESCE(sa.amount_paid, 0) + $lifecyclePaidExpression AS total_paid_amount,
              GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) + $lifecyclePendingExpression AS total_pending_amount,
              sa.payment_date,
              sa.payment_mode,
              CASE
                   WHEN GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) + $lifecyclePendingExpression <= 0 THEN 'Paid'
                   WHEN COALESCE(sa.amount_paid, 0) + $lifecyclePaidExpression > 0 THEN 'Partially Paid'
                   ELSE 'Not Paid' END AS payment_status,
              sa.transaction_id
          FROM stock_allocations sa
          INNER JOIN sims s ON s.id = sa.sim_id
          INNER JOIN dealers d ON d.id = sa.owner_id
          LEFT JOIN sim_validities v ON v.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
          WHERE sa.id = ? AND sa.owner_type = 'dealer' AND sa.sim_id IS NOT NULL
          LIMIT 1";
$stmt = $conn->prepare($query);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare SIM activation details query: ' . $error, [], [], 500);
}
$stmt->bind_param('i', $allocationId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch SIM activation details: ' . $error, [], [], 500);
}
$allocation = $stmt->get_result()->fetch_assoc();
$stmt->close();

$payments = [];
$lifecyclePayments = [];
if ($allocation) {
    $paymentStmt = $conn->prepare(
        'SELECT id, total_amount_due, amount_paid, amount_pending, payment_mode, transaction_id,
                payment_date, payment_status, remarks, is_legacy_snapshot, created_at
         FROM dealer_sim_allocation_payments
         WHERE allocation_id = ?
         ORDER BY COALESCE(payment_date, DATE(created_at)), created_at, id'
    );
    if (!$paymentStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to prepare SIM payment history query: ' . $error, [], [], 500);
    }
    $paymentStmt->bind_param('i', $allocationId);
    if (!$paymentStmt->execute()) {
        $error = $paymentStmt->error;
        $paymentStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to fetch SIM payment history: ' . $error, [], [], 500);
    }
    $payments = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentStmt->close();
    if (!$payments && (float)($allocation['amount_paid'] ?? 0) > 0) {
        $payments[] = [
            'id' => 'legacy-' . $allocationId,
            'total_amount_due' => $allocation['total_amount'],
            'amount_paid' => $allocation['amount_paid'],
            'amount_pending' => $allocation['pending_amount'],
            'payment_mode' => $allocation['payment_mode'],
            'transaction_id' => $allocation['transaction_id'],
            'payment_date' => $allocation['payment_date'],
            'payment_status' => $allocation['payment_status'],
            'remarks' => 'Legacy payment summary; individual payment history is unavailable.',
            'is_legacy_snapshot' => 1,
            'created_at' => null
        ];
    }

    $lifecyclePaymentStmt = $conn->prepare(
        "SELECT id, source_history_id, source_balance_updated, action_type, action_date, old_renewal_date, new_renewal_date,
                payment_amount, amount_paid, amount_pending, payment_mode, payment_date, transaction_id, notes
         FROM renewal_history
         WHERE allocation_id = ?
           AND (
               (action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody') AND payment_amount > 0)
               OR action_type = 'Renewal Payment'
           )
         ORDER BY id DESC"
    );
    if (!$lifecyclePaymentStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to prepare SIM lifecycle payment history query: ' . $error, [], [], 500);
    }
    $lifecyclePaymentStmt->bind_param('i', $allocationId);
    if (!$lifecyclePaymentStmt->execute()) {
        $error = $lifecyclePaymentStmt->error;
        $lifecyclePaymentStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to fetch SIM lifecycle payment history: ' . $error, [], [], 500);
    }
    $lifecyclePayments = $lifecyclePaymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lifecyclePaymentStmt->close();

    $lifecycleSources = [];
    foreach ($lifecyclePayments as $index => $payment) {
        if ($payment['action_type'] !== 'Renewal Payment') {
            $lifecycleSources[(int)$payment['id']] = $index;
        }
    }
    $paymentEntriesBySource = [];
    $mutatingPaymentsBySource = [];
    $unmatchedPayments = [];
    foreach ($lifecyclePayments as $payment) {
        if ($payment['action_type'] !== 'Renewal Payment') {
            continue;
        }
        $sourceHistoryId = (int)($payment['source_history_id'] ?? 0);
        if (!$sourceHistoryId || !isset($lifecycleSources[$sourceHistoryId])) {
            $matchingSources = [];
            foreach ($lifecycleSources as $sourceId => $sourceIndex) {
                $source = $lifecyclePayments[$sourceIndex];
                $sameRenewalCycle = (string)($payment['old_renewal_date'] ?? '') === (string)($source['old_renewal_date'] ?? '')
                    && (string)($payment['new_renewal_date'] ?? '') === (string)($source['new_renewal_date'] ?? '');
                if ($sameRenewalCycle
                    && (!empty($payment['old_renewal_date']) || !empty($payment['new_renewal_date']))) {
                    $matchingSources[] = $sourceId;
                }
            }
            if (count($matchingSources) === 1) {
                $sourceHistoryId = $matchingSources[0];
            } elseif (count($lifecycleSources) === 1) {
                $sourceHistoryId = (int)array_key_first($lifecycleSources);
            }
        }
        if ($sourceHistoryId && isset($lifecycleSources[$sourceHistoryId])) {
            $payment['display_type'] = 'payment';
            $paymentEntriesBySource[$sourceHistoryId][] = $payment;
            if ((int)($payment['source_balance_updated'] ?? 1) !== 0) {
                $mutatingPaymentsBySource[$sourceHistoryId] = ($mutatingPaymentsBySource[$sourceHistoryId] ?? 0)
                    + (float)$payment['amount_paid'];
            }
        } else {
            $payment['display_type'] = 'payment';
            $unmatchedPayments[] = $payment;
        }
    }

    $displayHistory = [];
    foreach ($lifecycleSources as $sourceId => $sourceIndex) {
        $charge = $lifecyclePayments[$sourceIndex];
        $mutatingPaid = (float)($mutatingPaymentsBySource[$sourceId] ?? 0);
        $linkedEntries = $paymentEntriesBySource[$sourceId] ?? [];
        $nonMutatingPaid = array_sum(array_map(
            static fn($payment) => (int)($payment['source_balance_updated'] ?? 1) === 0
                ? (float)$payment['amount_paid']
                : 0,
            $linkedEntries
        ));
        $initialPaid = max(0, (float)$charge['amount_paid'] - $mutatingPaid);
        $charge['display_type'] = 'charge';
        $charge['amount_paid'] = round((float)$charge['amount_paid'] + $nonMutatingPaid, 2);
        $charge['amount_pending'] = max(0, round((float)$charge['amount_pending'] - $nonMutatingPaid, 2));
        $charge['payment_mode'] = null;
        $charge['payment_date'] = null;
        $charge['transaction_id'] = null;
        $charge['notes'] = null;
        $displayHistory[] = $charge;
        $chargePayments = [];
        if ($initialPaid > 0) {
            $chargePayments[] = [
                'id' => 'initial-' . $sourceId,
                'source_history_id' => $sourceId,
                'display_type' => 'payment',
                'action_type' => 'Renewal Payment',
                'action_date' => $charge['action_date'],
                'old_renewal_date' => $charge['old_renewal_date'],
                'new_renewal_date' => $charge['new_renewal_date'],
                'payment_amount' => 0,
                'amount_paid' => $initialPaid,
                'amount_pending' => null,
                'payment_mode' => $lifecyclePayments[$sourceIndex]['payment_mode'],
                'payment_date' => $lifecyclePayments[$sourceIndex]['payment_date'],
                'transaction_id' => $lifecyclePayments[$sourceIndex]['transaction_id'],
                'notes' => null
            ];
        }
        foreach ($linkedEntries as $payment) {
            $payment['action_type'] = 'Renewal Payment';
            $chargePayments[] = $payment;
        }
        usort($chargePayments, static function ($left, $right) {
            $leftDate = (string)($left['payment_date'] ?? $left['action_date'] ?? '');
            $rightDate = (string)($right['payment_date'] ?? $right['action_date'] ?? '');
            $dateOrder = strcmp($leftDate, $rightDate);
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            $leftIsInitial = strpos((string)$left['id'], 'initial-') === 0;
            $rightIsInitial = strpos((string)$right['id'], 'initial-') === 0;
            if ($leftIsInitial !== $rightIsInitial) {
                return $leftIsInitial ? -1 : 1;
            }
            return (int)$left['id'] <=> (int)$right['id'];
        });
        $runningPending = max(0, (float)$charge['payment_amount']);
        foreach ($chargePayments as &$payment) {
            $runningPending = max(0, round($runningPending - (float)$payment['amount_paid'], 2));
            $payment['amount_pending'] = $runningPending;
            $payment['payment_status'] = $runningPending <= 0 ? 'Paid' : 'Partially Paid';
            $displayHistory[] = $payment;
        }
        unset($payment);
    }
    $lifecyclePayments = array_merge($displayHistory, $unmatchedPayments);
}
$conn->close();

if (!$allocation) {
    sendResponse(false, 'Dealer SIM activation record not found.', [], [], 404);
}

sendResponse(true, 'Dealer SIM activation details fetched successfully.', [
    'allocation' => $allocation,
    'payments' => $payments,
    'lifecycle_payments' => $lifecyclePayments
]);
?>
