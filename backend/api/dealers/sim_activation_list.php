<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/renewal_history.php';
require_once '../../utils/renewal_undo.php';
require_once '../../middleware/auth.php';

handlePreflight();
$currentUser = authenticate();
requireAnyPermission(['dealer_sim_activation.view', 'dealers.view']);

$db = new Database();
$conn = $db->getConnection();

$historyAllocationColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'allocation_id'");
if (!$historyAllocationColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to check SIM lifecycle payment schema: ' . $error, [], [], 500);
}
if ($historyAllocationColumn->num_rows === 0 && !$conn->query("ALTER TABLE renewal_history ADD COLUMN allocation_id INT DEFAULT NULL AFTER renewal_id")) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to prepare SIM lifecycle payment totals: ' . $error, [], [], 500);
}
foreach ([
    'bulk_payment_request_key' => "ALTER TABLE renewal_history ADD COLUMN bulk_payment_request_key VARCHAR(64) DEFAULT NULL AFTER changed_by",
    'source_history_id' => "ALTER TABLE renewal_history ADD COLUMN source_history_id INT DEFAULT NULL AFTER bulk_payment_request_key",
    'source_balance_updated' => "ALTER TABLE renewal_history ADD COLUMN source_balance_updated TINYINT(1) NOT NULL DEFAULT 1 AFTER source_history_id"
] as $column => $alterSql) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM renewal_history LIKE '$column'");
    if (!$columnCheck) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Failed to check SIM lifecycle payment schema: ' . $error, [], [], 500);
    }
    if ($columnCheck->num_rows === 0 && !$conn->query($alterSql)) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Failed to prepare SIM lifecycle payment totals: ' . $conn->error, [], [], 500);
    }
}
try {
    ensureRenewalHistoryPaymentActionType($conn);
} catch (Throwable $error) {
    $conn->close();
    sendResponse(false, 'Failed to prepare renewal payment history: ' . $error->getMessage(), [], [], 500);
}

$expireStmt = $conn->prepare(
    "UPDATE stock_allocations
     SET sim_status = 'Expired'
     WHERE owner_type = 'dealer'
       AND sim_id IS NOT NULL
       AND sim_status = 'Active'
       AND sim_expiry_date IS NOT NULL
       AND sim_expiry_date <= CURDATE()"
);
if (!$expireStmt || !$expireStmt->execute()) {
    $error = $expireStmt ? $expireStmt->error : $conn->error;
    if ($expireStmt) $expireStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to update expired dealer SIM statuses: ' . $error, [], [], 500);
}
$expireStmt->close();

$renewalDateSelect = "sa.sim_expiry_date AS renewal_date";

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dealer_id = isset($_GET['dealer_id']) ? (int)$_GET['dealer_id'] : 0;
$sim_type = isset($_GET['sim_type']) ? trim($_GET['sim_type']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$expiry_date = isset($_GET['expiry_date']) ? trim($_GET['expiry_date']) : '';
$expiry_date_operator = strtolower(trim((string) ($_GET['expiry_date_operator'] ?? 'exact')));
$expiry_date_from = isset($_GET['expiry_date_from']) ? trim($_GET['expiry_date_from']) : '';
$expiry_date_to = isset($_GET['expiry_date_to']) ? trim($_GET['expiry_date_to']) : '';
$validity_id = isset($_GET['validity_id']) ? (int)$_GET['validity_id'] : 0;
$payment_status = trim((string)($_GET['payment_status'] ?? ''));
$export_all = filter_var($_GET['export_all'] ?? false, FILTER_VALIDATE_BOOLEAN);

$whereClauses = ["sa.owner_type = 'dealer'", "sa.sim_id IS NOT NULL"];
$params = [];
$types = '';
$lifecyclePaymentPendingExpression = "GREATEST(0, rh.amount_pending - COALESCE((
    SELECT SUM(rhp.amount_paid)
    FROM renewal_history rhp
    WHERE rhp.source_history_id = rh.id
      AND rhp.action_type = 'Renewal Payment'
      AND rhp.source_balance_updated = 0
), 0))";
$lifecyclePaymentPaidExpression = "rh.amount_paid + COALESCE((
    SELECT SUM(rhp.amount_paid)
    FROM renewal_history rhp
    WHERE rhp.source_history_id = rh.id
      AND rhp.action_type = 'Renewal Payment'
      AND rhp.source_balance_updated = 0
), 0)";
$totalPaymentPendingExpression = "GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) + COALESCE((
    SELECT SUM($lifecyclePaymentPendingExpression)
    FROM renewal_history rh
    WHERE rh.allocation_id = sa.id
      AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
), 0)";
$totalPaymentPaidExpression = "COALESCE(sa.amount_paid, 0) + COALESCE((
    SELECT SUM($lifecyclePaymentPaidExpression)
    FROM renewal_history rh
    WHERE rh.allocation_id = sa.id
      AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
), 0)";

$isValidDateFilter = static function ($value, $operator) {
    if ($operator === 'year') {
        return preg_match('/^\d{4}$/', $value) === 1;
    }
    if ($operator === 'month') {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }
    if ($operator === 'exact' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
    return false;
};

foreach ([
    ['value' => $expiry_date, 'operator' => $expiry_date_operator, 'label' => 'Expiry Date']
] as $dateFilter) {
    if ($dateFilter['value'] !== '' && !$isValidDateFilter($dateFilter['value'], $dateFilter['operator'])) {
        $conn->close();
        sendResponse(false, 'Invalid ' . $dateFilter['label'] . ' filter.', [], [], 400);
    }
}

foreach ([
    ['value' => $expiry_date_from, 'label' => 'Expiry Date From'],
    ['value' => $expiry_date_to, 'label' => 'Expiry Date To']
] as $dateFilter) {
    if ($dateFilter['value'] !== '' && !$isValidDateFilter($dateFilter['value'], 'exact')) {
        $conn->close();
        sendResponse(false, 'Invalid ' . $dateFilter['label'] . ' filter.', [], [], 400);
    }
}

if ($expiry_date_from !== '' && $expiry_date_to !== '' && $expiry_date_to < $expiry_date_from) {
    $conn->close();
    sendResponse(false, 'To Date must be on or after From Date.', [], [], 400);
}

if ($search !== '') {
    $searchTerm = "%$search%";
    $whereClauses[] = "s.sim_no LIKE ?";
    $params[] = $searchTerm;
    $types .= 's';
}

if ($dealer_id > 0) {
    $whereClauses[] = "sa.owner_id = ?";
    $params[] = $dealer_id;
    $types .= 'i';
}

if ($sim_type !== '') {
    $whereClauses[] = "s.sim_type = ?";
    $params[] = $sim_type;
    $types .= 's';
}

if ($status !== '') {
    $whereClauses[] = "sa.sim_status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($payment_status !== '') {
    switch (strtolower($payment_status)) {
        case 'paid':
            $whereClauses[] = "$totalPaymentPendingExpression <= 0";
            break;
        case 'partially paid':
            $whereClauses[] = "$totalPaymentPendingExpression > 0 AND $totalPaymentPaidExpression > 0";
            break;
        case 'not paid':
            $whereClauses[] = "$totalPaymentPendingExpression > 0 AND $totalPaymentPaidExpression <= 0";
            break;
        default:
            $conn->close();
            sendResponse(false, 'Invalid Payment Status filter.', [], [], 400);
    }
}

if ($expiry_date !== '') {
    if ($expiry_date_operator === 'year') {
        $whereClauses[] = "YEAR(sa.sim_expiry_date) = ?";
    } elseif ($expiry_date_operator === 'month') {
        $whereClauses[] = "DATE_FORMAT(sa.sim_expiry_date, '%Y-%m') = ?";
    } else {
        $whereClauses[] = "DATE(sa.sim_expiry_date) = ?";
    }
    $params[] = $expiry_date;
    $types .= 's';
}

if ($expiry_date_from !== '') {
    $whereClauses[] = "sa.sim_expiry_date >= ?";
    $params[] = $expiry_date_from;
    $types .= 's';
}

if ($expiry_date_to !== '') {
    $whereClauses[] = "sa.sim_expiry_date < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $expiry_date_to;
    $types .= 's';
}

if ($validity_id > 0) {
    $whereClauses[] = "COALESCE(sa.sim_validity_id, s.sim_validity_id) = ?";
    $params[] = $validity_id;
    $types .= 'i';
}

$whereSql = "WHERE " . implode(' AND ', $whereClauses);
$undoHistorySelect = renewalUndoSchemaReady($conn)
    ? "(SELECT candidate.id
        FROM renewal_history candidate
        WHERE candidate.allocation_id = sa.id
          AND candidate.action_type IN ('Renew SIM', 'Reactivate SIM')
          AND candidate.previous_state_json IS NOT NULL
          AND candidate.result_state_json IS NOT NULL
          AND candidate.undone_at IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM renewal_history later
              WHERE later.allocation_id = sa.id
                AND later.id > candidate.id
                AND NOT (
                    later.action_type = 'Renewal Payment'
                    AND later.source_history_id = candidate.id
                )
          )
        ORDER BY candidate.id DESC
        LIMIT 1) AS undo_history_id"
    : 'NULL AS undo_history_id';

// Count total
$countQuery = "SELECT COUNT(*) as total FROM stock_allocations sa 
               JOIN sims s ON sa.sim_id = s.id 
               JOIN dealers d ON sa.owner_id = d.id 
               $whereSql";
$countStmt = $conn->prepare($countQuery);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalRecords / $limit);

$summaryWhere = ["latest.owner_type = 'dealer'"];
$summaryParams = [];
$summaryTypes = '';
if ($dealer_id > 0) {
    $summaryWhere[] = 'latest.owner_id = ?';
    $summaryParams[] = $dealer_id;
    $summaryTypes .= 'i';
}
$summarySql = "
    SELECT
        COUNT(DISTINCT CASE
            WHEN latest.sim_status = 'Active'
             AND (latest.sim_expiry_date IS NULL OR latest.sim_expiry_date > CURDATE())
            THEN latest.sim_id END) AS active_sims,
        COUNT(DISTINCT CASE WHEN latest.sim_status = 'Safe Custody' THEN latest.sim_id END) AS safe_custody_sims,
        COUNT(DISTINCT CASE
            WHEN latest.sim_status = 'Expired'
              OR (latest.sim_status = 'Active' AND latest.sim_expiry_date <= CURDATE())
            THEN latest.sim_id END) AS expired_sims,
        COUNT(DISTINCT CASE WHEN latest.sim_status = 'Deactive' THEN latest.sim_id END) AS deactivated_sims
    FROM stock_allocations latest
    INNER JOIN (
        SELECT sim_id, MAX(id) AS latest_id
        FROM stock_allocations
        WHERE sim_id IS NOT NULL
        GROUP BY sim_id
    ) current_allocation ON current_allocation.latest_id = latest.id
    WHERE " . implode(' AND ', $summaryWhere);
$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to prepare dealer SIM status summary: ' . $error, [], [], 500);
}
if ($summaryParams) {
    $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
}
if (!$summaryStmt->execute()) {
    $error = $summaryStmt->error;
    $summaryStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch dealer SIM status summary: ' . $error, [], [], 500);
}
$summaryCounts = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$dateYearsQuery = "SELECT 'expiry_date' AS date_key, YEAR(sim_expiry_date) AS year
                   FROM stock_allocations
                   WHERE owner_type = 'dealer' AND sim_id IS NOT NULL
                     AND sim_expiry_date IS NOT NULL
                   ORDER BY date_key, year DESC";
$dateYearsResult = $conn->query($dateYearsQuery);
$dateYears = ['expiry_date' => []];
if (!$dateYearsResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to fetch SIM date filter years: ' . $error, [], [], 500);
}
while ($yearRow = $dateYearsResult->fetch_assoc()) {
    $dateYears[$yearRow['date_key']][] = (int) $yearRow['year'];
}
$dateYearsResult->free();

// Fetch data
// We get the given_date from sim_given_date. If null, fallback to allocation_date.
$query = "SELECT 
            sa.id as allocation_id, 
            sa.sim_id,
            d.id as dealer_id,
            d.dealer_name,
            d.mobile_no AS dealer_mobile_no,
            s.sim_no,
            s.sim_type,
            COALESCE(sa.sim_given_date, sa.allocation_date) as given_date,
            sa.sim_activation_date as activation_date,
            sa.sim_reactivation_date as reactivation_date,
            $renewalDateSelect,
            sa.sim_expiry_date as expiry_date,
            sa.sim_deactivation_date as deactivation_date,
            sa.sim_status,
            COALESCE(sa.sim_validity_id, s.sim_validity_id) as sim_validity_id,
            sa.sim_amount,
            GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) AS allocation_pending_amount,
            COALESCE(sa.total_amount, 0) + COALESCE((
                SELECT SUM(rh.payment_amount)
                FROM renewal_history rh
                WHERE rh.allocation_id = sa.id
                  AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
            ), 0) AS total_payment_amount,
            $totalPaymentPendingExpression AS total_pending_amount,
            CASE
                WHEN $totalPaymentPendingExpression <= 0 THEN 'Paid'
                WHEN $totalPaymentPaidExpression > 0 THEN 'Partially Paid'
                ELSE 'Not Paid'
            END AS payment_status,
            v.months as validity_months,
            $undoHistorySelect
          FROM stock_allocations sa 
          JOIN sims s ON sa.sim_id = s.id 
          JOIN dealers d ON sa.owner_id = d.id
          LEFT JOIN sim_validities v ON COALESCE(sa.sim_validity_id, s.sim_validity_id) = v.id
          $whereSql 
          ORDER BY sa.id DESC";

if (!$export_all) {
    $query .= " LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $limit;
    $types .= 'ii';
}

$stmt = $conn->prepare($query);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$sims = [];
while ($row = $result->fetch_assoc()) {
    $sims[] = $row;
}

$stmt->close();
$conn->close();

sendResponse(true, 'Data fetched successfully', [
    'sims' => $sims,
    'summary_counts' => [
        'active_sims' => (int) ($summaryCounts['active_sims'] ?? 0),
        'safe_custody_sims' => (int) ($summaryCounts['safe_custody_sims'] ?? 0),
        'expired_sims' => (int) ($summaryCounts['expired_sims'] ?? 0),
        'deactivated_sims' => (int) ($summaryCounts['deactivated_sims'] ?? 0)
    ],
    'date_years' => $dateYears,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages
    ]
]);
?>
