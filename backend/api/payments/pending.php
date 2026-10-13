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

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

try {
    ensureRenewalHistoryPaymentActionType($conn);
} catch (Throwable $error) {
    sendResponse(false, 'Unable to prepare renewal payment totals: ' . $error->getMessage(), [], [], 500);
}

$rows = [];
$allocationRows = [];
$customerRows = [];

/*
|--------------------------------------------------------------------------
| 1. CUSTOMER PENDING PAYMENTS
|--------------------------------------------------------------------------
|
| Pending:
| - Pending
| - Not Paid
| - Partially Paid
|
*/

$customerSql = "
    SELECT
        cp.id,
        cp.customer_id,
        DATE(cp.created_at) AS payment_date,
        c.username,
        cp.total_sale_amount,
        cp.total_amount,
        cp.amount_paid,
        cp.amount_pending,
        cp.payment_status
    FROM customer_payments cp
    INNER JOIN customers c
        ON c.id = cp.customer_id
    ORDER BY cp.created_at DESC, cp.id DESC
";

$customerResult = $conn->query($customerSql);
if (!$customerResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to fetch customer payment totals: ' . $error, [], [], 500);
}

while ($row = $customerResult->fetch_assoc()) {
    $totalAmount = (float)($row['total_amount'] ?? $row['total_sale_amount'] ?? 0);
    $amountPaid = (float)($row['amount_paid'] ?? 0);
    $pendingAmount = max(0, (float)($row['amount_pending'] ?? ($totalAmount - $amountPaid)));
    $displayStatus = $totalAmount <= 0
        ? 'No Payment Required'
        : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Pending'));
    $customerRow = [
        'id' => (int)$row['id'],
        'category' => 'Customer',
        'name' => $row['username'] ?? '',
        'type' => 'Customer',
        'reference' => 'Customer #' . (int)$row['customer_id'],
        'date' => $row['payment_date'] ?? null,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'pending_amount' => $pendingAmount,
        'status_group' => $displayStatus,
        'display_status' => $displayStatus
    ];
    $customerRows[] = $customerRow;
    if ($totalAmount > 0 && $pendingAmount > 0) {
        $rows[] = $customerRow;
    }
}

/*
|--------------------------------------------------------------------------
| 2. DEALER / TECHNICIAN PENDING PAYMENTS
|--------------------------------------------------------------------------
|
| Allocation totals include their linked SIM renewal charges and
| payments so the summary matches Dealer Management.
|
*/

$allocationSql = "
    SELECT
        sa.id,
        sa.owner_type,
        sa.owner_id,
        sa.allocation_date,
        COALESCE(sa.total_amount, 0) + COALESCE(renewal_summary.total_amount, 0) AS total_payment_amount,
        COALESCE(sa.amount_paid, 0) + COALESCE(renewal_summary.amount_paid, 0) AS total_amount_paid,
        GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0))
            + COALESCE(renewal_summary.amount_pending, 0) AS total_amount_pending,

        CASE
            WHEN sa.owner_type = 'dealer'
                THEN d.dealer_name
            WHEN sa.owner_type = 'technician'
                THEN t.technician_name
            ELSE 'Unknown'
        END AS owner_name

    FROM stock_allocations sa

    LEFT JOIN dealers d
        ON sa.owner_type = 'dealer'
        AND sa.owner_id = d.id

    LEFT JOIN technicians t
        ON sa.owner_type = 'technician'
        AND sa.owner_id = t.id

    LEFT JOIN (
        SELECT
            rh.allocation_id,
            SUM(rh.payment_amount) AS total_amount,
            SUM(rh.amount_paid + COALESCE(rp.amount_paid, 0)) AS amount_paid,
            SUM(GREATEST(0, rh.amount_pending - COALESCE(rp.amount_paid, 0))) AS amount_pending
        FROM renewal_history rh
        LEFT JOIN (
            SELECT source_history_id, SUM(amount_paid) AS amount_paid
            FROM renewal_history
            WHERE action_type = 'Renewal Payment'
              AND source_balance_updated = 0
            GROUP BY source_history_id
        ) rp ON rp.source_history_id = rh.id
        WHERE rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
        GROUP BY rh.allocation_id
    ) renewal_summary ON renewal_summary.allocation_id = sa.id

    WHERE (sa.device_id IS NOT NULL OR sa.sim_id IS NOT NULL)

    ORDER BY sa.allocation_date DESC, sa.id DESC
";

$allocationResult = $conn->query($allocationSql);
if (!$allocationResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to fetch allocation payment totals: ' . $error, [], [], 500);
}

while ($row = $allocationResult->fetch_assoc()) {
    $ownerType = strtolower((string)($row['owner_type'] ?? ''));

    $category = $ownerType === 'dealer'
        ? 'Dealer'
        : 'Technician';

    $totalAmount = (float)($row['total_payment_amount'] ?? 0);
    $amountPaid = (float)($row['total_amount_paid'] ?? 0);
    $pendingAmount = (float)($row['total_amount_pending'] ?? 0);
    $displayStatus = $totalAmount <= 0
        ? 'No Payment Required'
        : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Pending'));

    $allocationRow = [
        'id' => (int)$row['id'],
        'category' => $category,
        'name' => $row['owner_name'] ?? '',
        'type' => $category,
        'reference' => 'Allocation #' . (int)$row['id'],
        'date' => $row['allocation_date'] ?? null,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'pending_amount' => $pendingAmount,
        'status_group' => $displayStatus,
        'display_status' => $displayStatus
    ];

    $allocationRows[] = $allocationRow;
    if ($totalAmount > 0 && $pendingAmount > 0) {
        $rows[] = $allocationRow;
    }
}

/*
|--------------------------------------------------------------------------
| 3. SORT ALL RECORDS TOGETHER BY DATE DESC
|--------------------------------------------------------------------------
*/

usort($rows, function ($a, $b) {
    $dateA = $a['date'] ?? '';
    $dateB = $b['date'] ?? '';

    if ($dateA === $dateB) {
        return $b['id'] <=> $a['id'];
    }

    return strcmp($dateB, $dateA);
});

sendResponse(
    true,
    'Pending payments fetched successfully',
    [
        'rows' => $rows,
        'allocation_rows' => $allocationRows,
        'customer_rows' => $customerRows,
        'count' => count($rows)
    ]
);

$conn->close();
?>