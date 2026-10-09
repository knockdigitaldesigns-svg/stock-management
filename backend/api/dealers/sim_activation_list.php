<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
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
$given_date = isset($_GET['given_date']) ? trim($_GET['given_date']) : '';
$given_date_operator = strtolower(trim((string) ($_GET['given_date_operator'] ?? 'exact')));
$activation_date_from = isset($_GET['activation_date_from']) ? trim($_GET['activation_date_from']) : '';
$activation_date_to = isset($_GET['activation_date_to']) ? trim($_GET['activation_date_to']) : '';
$validity_id = isset($_GET['validity_id']) ? (int)$_GET['validity_id'] : 0;
$payment_status = trim((string)($_GET['payment_status'] ?? ''));
$export_all = filter_var($_GET['export_all'] ?? false, FILTER_VALIDATE_BOOLEAN);

$whereClauses = ["sa.owner_type = 'dealer'", "sa.sim_id IS NOT NULL"];
$params = [];
$types = '';
$totalPaymentPendingExpression = "GREATEST(0, COALESCE(sa.total_amount, 0) - COALESCE(sa.amount_paid, 0)) + COALESCE((
    SELECT SUM(rh.amount_pending)
    FROM renewal_history rh
    WHERE rh.allocation_id = sa.id
      AND rh.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')
), 0)";
$totalPaymentPaidExpression = "COALESCE(sa.amount_paid, 0) + COALESCE((
    SELECT SUM(rh.amount_paid)
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
    ['value' => $given_date, 'operator' => $given_date_operator, 'label' => 'Given Date']
] as $dateFilter) {
    if ($dateFilter['value'] !== '' && !$isValidDateFilter($dateFilter['value'], $dateFilter['operator'])) {
        $conn->close();
        sendResponse(false, 'Invalid ' . $dateFilter['label'] . ' filter.', [], [], 400);
    }
}

foreach ([
    ['value' => $activation_date_from, 'label' => 'Activation Date From'],
    ['value' => $activation_date_to, 'label' => 'Activation Date To']
] as $dateFilter) {
    if ($dateFilter['value'] !== '' && !$isValidDateFilter($dateFilter['value'], 'exact')) {
        $conn->close();
        sendResponse(false, 'Invalid ' . $dateFilter['label'] . ' filter.', [], [], 400);
    }
}

if ($activation_date_from !== '' && $activation_date_to !== '' && $activation_date_to < $activation_date_from) {
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

if ($given_date !== '') {
    $givenDateColumn = "COALESCE(sa.sim_given_date, sa.allocation_date)";
    if ($given_date_operator === 'year') {
        $whereClauses[] = "YEAR($givenDateColumn) = ?";
    } elseif ($given_date_operator === 'month') {
        $whereClauses[] = "DATE_FORMAT($givenDateColumn, '%Y-%m') = ?";
    } else {
        $whereClauses[] = "DATE($givenDateColumn) = ?";
    }
    $params[] = $given_date;
    $types .= 's';
}

if ($activation_date_from !== '') {
    $whereClauses[] = "sa.sim_activation_date >= ?";
    $params[] = $activation_date_from;
    $types .= 's';
}

if ($activation_date_to !== '') {
    $whereClauses[] = "sa.sim_activation_date < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $activation_date_to;
    $types .= 's';
}

if ($validity_id > 0) {
    $whereClauses[] = "COALESCE(sa.sim_validity_id, s.sim_validity_id) = ?";
    $params[] = $validity_id;
    $types .= 'i';
}

$whereSql = "WHERE " . implode(' AND ', $whereClauses);

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

$dateYearsQuery = "SELECT 'given_date' AS date_key, YEAR(COALESCE(sim_given_date, allocation_date)) AS year
                   FROM stock_allocations
                   WHERE owner_type = 'dealer' AND sim_id IS NOT NULL
                     AND COALESCE(sim_given_date, allocation_date) IS NOT NULL
                   UNION
                   SELECT 'activation_date' AS date_key, YEAR(sim_activation_date) AS year
                   FROM stock_allocations
                   WHERE owner_type = 'dealer' AND sim_id IS NOT NULL AND sim_activation_date IS NOT NULL
                   ORDER BY date_key, year DESC";
$dateYearsResult = $conn->query($dateYearsQuery);
$dateYears = ['given_date' => [], 'activation_date' => []];
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
            v.months as validity_months
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
    'date_years' => $dateYears,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages
    ]
]);
?>
