<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

$where = ["(sa.owner_type = 'technician' OR (sa.owner_type = 'dealer' AND d.installation_status IN ('Onsite', 'Offsite')))" ];
$params = [];
$types = '';
$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(d.dealer_name LIKE ? OR t.technician_name LIKE ? OR sa.owner_type LIKE ? OR dev.imei_no LIKE ? OR s.sim_no LIKE ? OR sa.software LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}
foreach ([
    'date_from' => ['sa.allocation_date >= ?', 's'],
    'date_to' => ['sa.allocation_date <= ?', 's']
] as $key => [$condition, $type]) {
    $value = trim((string) ($_GET[$key] ?? ''));
    if ($value !== '') {
        $where[] = $condition;
        $params[] = $value;
        $types .= $type;
    }
}
$ownerType = strtolower(trim((string) ($_GET['owner_type'] ?? '')));
if ($ownerType !== '') {
    $where[] = 'sa.owner_type = ?';
    $params[] = $ownerType;
    $types .= 's';
}
$asset = strtolower(trim((string) ($_GET['asset'] ?? '')));
if ($asset === 'device') $where[] = 'sa.device_id IS NOT NULL';
if ($asset === 'sim') $where[] = 'sa.sim_id IS NOT NULL';
$platform = trim((string) ($_GET['platform'] ?? ''));
if ($platform !== '') {
    $where[] = 'LOWER(TRIM(COALESCE(sa.software, \'\'))) = ?';
    $params[] = strtolower($platform);
    $types .= 's';
}
$paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
if ($paymentStatus !== '') {
    $where[] = "CASE
        WHEN COALESCE(sa.total_amount, 0) <= 0 THEN 'No Payment Required'
        WHEN COALESCE(sa.amount_paid, 0) >= COALESCE(sa.total_amount, 0) THEN 'Paid'
        WHEN COALESCE(sa.amount_paid, 0) > 0 THEN 'Partially Paid'
        ELSE 'Not Paid'
    END = ?";
    $params[] = $paymentStatus;
    $types .= 's';
}
foreach ([
    'given_date' => 'sa.sim_given_date',
    'activation_date' => 'sa.sim_activation_date'
] as $key => $column) {
    $value = trim((string) ($_GET[$key] ?? ''));
    if ($value === '') continue;
    $operator = strtolower(trim((string) ($_GET[$key . '_operator'] ?? 'exact')));
    if ($operator === 'year' && preg_match('/^\d{4}$/', $value)) {
        $where[] = "YEAR($column) = ?";
    } elseif ($operator === 'month' && preg_match('/^\d{4}-\d{2}$/', $value)) {
        $where[] = "DATE_FORMAT($column, '%Y-%m') = ?";
    } else {
        $where[] = "$column = ?";
    }
    $params[] = $value;
    $types .= 's';
}
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;

$sql = "
    SELECT sa.id AS allocation_id, sa.owner_type, sa.owner_id, sa.allocation_type, sa.allocation_date,
           sa.device_id, sa.sim_id, sa.device_amount, sa.sim_amount, sa.total_amount,
           sa.amount_paid, sa.pending_amount, sa.software, sa.payment_status, sa.payment_mode, sa.notes,
           d.dealer_name, d.installation_status AS dealer_installation_status,
           t.technician_name, dev.imei_no, dev.status AS device_status,
           dt.device_type AS device_model, s.sim_no, s.sim_type, COALESCE(sa.sim_validity_id, s.sim_validity_id) AS sim_validity_id, sv.months AS sim_validity_months,
           sa.sim_given_date AS given_date, sa.sim_activation_date AS activation_date,
           s.status AS sim_status
    FROM stock_allocations sa
    LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = 'dealer'
    LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
    LEFT JOIN devices dev ON dev.id = sa.device_id
    LEFT JOIN device_types dt ON dt.id = dev.device_model_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    LEFT JOIN sim_validities sv ON sv.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
    WHERE " . implode(' AND ', $where) . "
    ORDER BY sa.created_at DESC, sa.id DESC
";
$dataParams = $params;
$dataTypes = $types;
if ($paginated) {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM stock_allocations sa
        LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
        LEFT JOIN devices dev ON dev.id = sa.device_id
        LEFT JOIN device_types dt ON dt.id = dev.device_model_id
        LEFT JOIN sims s ON s.id = sa.sim_id
        LEFT JOIN sim_validities sv ON sv.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
        WHERE " . implode(' AND ', $where);
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare stock allocation count query.', [], [], 500);
    }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Failed to count stock allocations.', [], [], 500);
    }
    $pagination = [
        'total' => (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0),
        'page' => $page,
        'page_size' => $pageSize
    ];
    $pagination['total_pages'] = max(1, (int) ceil($pagination['total'] / $pageSize));
    $countStmt->close();
    $sql .= ' LIMIT ? OFFSET ?';
    $dataParams[] = $pageSize;
    $dataParams[] = ($page - 1) * $pageSize;
    $dataTypes .= 'ii';
}
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare stock allocation query.', [], [], 500);
}
if ($dataParams) $stmt->bind_param($dataTypes, ...$dataParams);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch stock allocations.', [], [], 500);
}
$result = $stmt->get_result();
$allocations = [];
while ($result && ($row = $result->fetch_assoc())) {
    $row['allocation_id'] = (int) $row['allocation_id'];
    $row['owner_id'] = (int) $row['owner_id'];
    foreach (['device_id', 'sim_id'] as $key) $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
    foreach (['device_amount', 'sim_amount', 'total_amount', 'amount_paid', 'pending_amount'] as $key) $row[$key] = (float) ($row[$key] ?? 0);
    $row['pending_amount'] = max(0, $row['total_amount'] - $row['amount_paid']);
    $row['payment_status'] = $row['total_amount'] <= 0 ? null : ($row['pending_amount'] <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partially Paid' : 'Not Paid'));
    $row['owner_name'] = $row['owner_type'] === 'dealer' ? $row['dealer_name'] : $row['technician_name'];
    $row['item_type'] = $row['device_id'] !== null ? 'Device' : 'SIM';
    
    // Calculate asset status
    $rawStatus = $row['device_id'] !== null ? $row['device_status'] : $row['sim_status'];
    $row['asset_status'] = !empty($rawStatus) ? ucfirst($rawStatus) : 'Allocated';

    $allocations[] = $row;
}
$stmt->close();
$conn->close();
$responseData = ['allocations' => $allocations];
if ($paginated) $responseData['pagination'] = $pagination;
sendResponse(true, 'Stock allocations fetched', $responseData);
?>
