<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
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
    'payment_date' => "ALTER TABLE renewal_history ADD COLUMN payment_date DATE DEFAULT NULL AFTER payment_mode"
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
              sa.payment_date,
              sa.payment_mode,
              CASE WHEN COALESCE(sa.total_amount, 0) <= 0 THEN 'Not Paid'
                   WHEN COALESCE(sa.amount_paid, 0) >= sa.total_amount THEN 'Paid'
                   WHEN COALESCE(sa.amount_paid, 0) > 0 THEN 'Partially Paid'
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

    $lifecyclePaymentStmt = $conn->prepare(
        "SELECT id, action_type, action_date, payment_amount, amount_paid, amount_pending,
                payment_mode, payment_date, transaction_id
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
