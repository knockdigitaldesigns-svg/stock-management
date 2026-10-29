<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$where = ['d.deleted_at IS NULL'];
$params = [];
$types = '';
if (($search = trim((string)($_GET['search'] ?? ''))) !== '') { $where[] = '(d.imei_no LIKE ? OR dm.device_type LIKE ? OR d.notes LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like); $types .= 'sss'; }
if (($year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT)) !== false && $year) { $where[] = 'YEAR(d.purchase_date) = ?'; $params[] = $year; $types .= 'i'; }
if (($month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT)) !== false && $month >= 1 && $month <= 12) { $where[] = 'MONTH(d.purchase_date) = ?'; $params[] = $month; $types .= 'i'; }
if (($deviceType = trim((string)($_GET['deviceType'] ?? $_GET['device_model'] ?? ''))) !== '') { $where[] = 'dm.device_type = ?'; $params[] = $deviceType; $types .= 's'; }
if (($dateFrom = trim((string)($_GET['date_from'] ?? ''))) !== '') { $where[] = 'd.purchase_date >= ?'; $params[] = $dateFrom; $types .= 's'; }
if (($dateTo = trim((string)($_GET['date_to'] ?? ''))) !== '') { $where[] = 'd.purchase_date <= ?'; $params[] = $dateTo; $types .= 's'; }
$effectiveStatusSql = "LOWER(CASE
    WHEN EXISTS (
        SELECT 1 FROM customer_vehicle_details cvd_status
        WHERE cvd_status.device_id = d.id
           OR BINARY cvd_status.imei_no = BINARY d.imei_no
    )
      OR EXISTS (
        SELECT 1 FROM stock_transactions st_status
        WHERE st_status.device_id = d.id
          AND st_status.from_owner_type = sa.owner_type
          AND st_status.from_owner_id = sa.owner_id
          AND st_status.id = (
            SELECT MAX(st_latest_status.id) FROM stock_transactions st_latest_status
            WHERE st_latest_status.device_id = d.id
              AND st_latest_status.from_owner_type = sa.owner_type
              AND st_latest_status.from_owner_id = sa.owner_id
          )
          AND st_status.transaction_type = 'USE'
      ) THEN 'used'
    WHEN LOWER(COALESCE(d.status, 'available')) = 'active' THEN 'available'
    WHEN LOWER(COALESCE(d.status, '')) IN ('deactive', 'expired') THEN 'used'
    ELSE LOWER(COALESCE(d.status, 'available'))
END)";
if (($status = strtolower(trim((string)($_GET['status'] ?? '')))) !== '') { $where[] = "$effectiveStatusSql = ?"; $params[] = $status; $types .= 's'; }
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$sql = "
                SELECT d.id, d.purchase_date, d.device_model_id, d.imei_no, d.notes,
                        $effectiveStatusSql AS status,
                        dm.device_type AS model_name,
                        EXISTS (
                            SELECT 1 FROM stock_allocations dep WHERE dep.device_id = d.id
                            UNION ALL
                            SELECT 1 FROM stock_transactions dep WHERE dep.device_id = d.id
                            UNION ALL
                            SELECT 1 FROM stock_transfers dep WHERE dep.device_id = d.id
                            UNION ALL
                            SELECT 1 FROM customer_vehicle_details dep
                            WHERE dep.device_id = d.id
                               OR BINARY dep.imei_no = BINARY d.imei_no
                            UNION ALL
                            SELECT 1 FROM courier_requests dep WHERE dep.device_id = d.id
                        ) AS has_history_dependencies,
            sa.allocation_date, sa.owner_type, sa.owner_id, sa.payment_status, sa.software,
            CASE WHEN sa.owner_type = 'dealer' THEN dl.dealer_name WHEN sa.owner_type = 'technician' THEN t.technician_name END AS owner_name
    FROM devices d
    JOIN device_types dm ON d.device_model_id = dm.id
        LEFT JOIN stock_allocations sa ON sa.device_id = d.id AND sa.id = (SELECT MAX(id) FROM stock_allocations WHERE device_id = d.id)
        LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY d.created_at DESC, d.id DESC';
if ($paginated) {
    $countSql = 'SELECT COUNT(*) AS total FROM devices d JOIN device_types dm ON d.device_model_id = dm.id LEFT JOIN stock_allocations sa ON sa.device_id = d.id AND sa.id = (SELECT MAX(id) FROM stock_allocations WHERE device_id = d.id) WHERE ' . implode(' AND ', $where);
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) { $conn->close(); sendResponse(false, 'Failed to prepare device count query.', [], [], 500); }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) { $countStmt->close(); $conn->close(); sendResponse(false, 'Failed to count devices.', [], [], 500); }
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
    $sql .= ' LIMIT ? OFFSET ?';
    $params[] = $pageSize;
    $params[] = ($page - 1) * $pageSize;
    $types .= 'ii';
}
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$devices = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
        if ($row['status'] === 'active') $row['status'] = 'available';
        if ($row['status'] === 'deactive') $row['status'] = 'used';
        if ($row['status'] === 'expired') $row['status'] = 'used';
        $devices[] = $row;
    }
}

sendResponse(true, "Devices fetched successfully", [
    "devices" => $devices,
    ...($paginated ? ['pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]] : [])
]);

$stmt->close();
$conn->close();
?>
