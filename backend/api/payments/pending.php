<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
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

$rows = [];

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
    WHERE cp.payment_status IN (
        'Pending',
        'Not Paid',
        'Partially Paid'
    )
    ORDER BY cp.created_at DESC, cp.id DESC
";

$customerResult = $conn->query($customerSql);

if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {

        $status = trim((string)($row['payment_status'] ?? ''));

        $displayStatus = 'Pending';

        if ($status === 'Partially Paid') {
            $displayStatus = 'Partially Paid';
        }

        $rows[] = [
            'id' => (int)$row['id'],
            'category' => 'Customer',
            'name' => $row['username'] ?? '',
            'type' => 'Customer',
            'reference' => 'Customer #' . (int)$row['customer_id'],
            'date' => $row['payment_date'] ?? null,
            'total_amount' => (float)($row['total_amount'] ?? $row['total_sale_amount'] ?? 0),
            'amount_paid' => (float)($row['amount_paid'] ?? 0),
            'pending_amount' => (float)($row['amount_pending'] ?? 0),
            'status_group' => $status === 'Partially Paid'
                ? 'Partially Paid'
                : 'Pending',
            'display_status' => $displayStatus
        ];
    }
}

/*
|--------------------------------------------------------------------------
| 2. DEALER / TECHNICIAN PENDING PAYMENTS
|--------------------------------------------------------------------------
|
| stock_allocations already contains owner_type / owner_id /
| payment information according to your existing dashboard flow.
|
*/

$allocationSql = "
    SELECT
        sa.id,
        sa.owner_type,
        sa.owner_id,
        sa.allocation_date,
        sa.total_amount,
        sa.amount_paid,
        (
    COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)
) AS amount_pending,
        sa.payment_status,

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

    WHERE sa.payment_status <> 'Paid'

    ORDER BY sa.allocation_date DESC, sa.id DESC
";

$allocationResult = $conn->query($allocationSql);

if ($allocationResult) {
    while ($row = $allocationResult->fetch_assoc()) {

        $ownerType = strtolower((string)($row['owner_type'] ?? ''));

        $category = $ownerType === 'dealer'
            ? 'Dealer'
            : 'Technician';

        $status = trim((string)($row['payment_status'] ?? ''));

        $displayStatus =
            strtolower($status) === 'partially paid'
                ? 'Partially Paid'
                : 'Pending';

        $rows[] = [
            'id' => (int)$row['id'],
            'category' => $category,
            'name' => $row['owner_name'] ?? '',
            'type' => $category,
            'reference' => 'Allocation #' . (int)$row['id'],
            'date' => $row['allocation_date'] ?? null,
            'total_amount' => (float)($row['total_amount'] ?? 0),
            'amount_paid' => (float)($row['amount_paid'] ?? 0),
            'pending_amount' => (float)($row['amount_pending'] ?? 0),
            'status_group' => $displayStatus === 'Partially Paid'
                ? 'Partially Paid'
                : 'Pending',
            'display_status' => $displayStatus
        ];
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
        'count' => count($rows)
    ]
);

$conn->close();
?>