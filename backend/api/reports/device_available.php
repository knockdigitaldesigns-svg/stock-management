<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, "Method not allowed", [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, "Database connection failed", [], [], 500);

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['available', 'allocated', 'used', 'reserved'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid device status filter.', [], [], 400);
}

$sql = "
    SELECT report_rows.*
    FROM (
        SELECT
            d.id,
            d.imei_no,
            d.purchase_date,
            d.notes,
            dm.device_type AS model_name,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM customer_vehicle_details cvd
                    WHERE cvd.device_id = d.id
                       OR BINARY cvd.imei_no = BINARY d.imei_no
                ) OR EXISTS (
                    SELECT 1
                    FROM stock_transactions st
                    WHERE st.device_id = d.id
                      AND st.from_owner_type = sa.owner_type
                      AND st.from_owner_id = sa.owner_id
                      AND st.id = (
                          SELECT MAX(st_latest.id)
                          FROM stock_transactions st_latest
                          WHERE st_latest.device_id = d.id
                            AND st_latest.from_owner_type = sa.owner_type
                            AND st_latest.from_owner_id = sa.owner_id
                      )
                      AND st.transaction_type = 'USE'
                ) THEN 'used'
                ELSE CASE
                    WHEN LOWER(COALESCE(d.status, 'available')) = 'active' THEN 'available'
                    WHEN LOWER(COALESCE(d.status, 'available')) IN ('deactive', 'expired') THEN 'used'
                    ELSE LOWER(COALESCE(d.status, 'available'))
                END
            END AS status
        FROM devices d
        JOIN device_types dm ON dm.id = d.device_model_id
        LEFT JOIN stock_allocations sa
            ON sa.id = (
                SELECT latest_sa.id
                FROM stock_allocations latest_sa
                WHERE latest_sa.device_id = d.id
                ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                LIMIT 1
            )
        WHERE d.deleted_at IS NULL
    ) report_rows
";

$where = [];
$params = [];
$types = '';
if ($statusFilter !== '') {
    $where[] = 'report_rows.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
    $where[] = '(report_rows.imei_no LIKE ? OR report_rows.model_name LIKE ? OR report_rows.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$deviceModel = trim((string) ($_GET['device_model'] ?? ''));
if ($deviceModel !== '') {
    $where[] = 'LOWER(report_rows.model_name) = ?';
    $params[] = strtolower($deviceModel);
    $types .= 's';
}
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
if ($dateFrom !== '') {
    $where[] = 'report_rows.purchase_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateTo !== '') {
    $where[] = 'report_rows.purchase_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;
if ($paginated) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $sql . ') report_rows' . $whereSql);
    if (!$countStmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare device report count query.', [], [], 500);
    }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Failed to count device report rows.', [], [], 500);
    }
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pagination = [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ];
    $countStmt->close();
}
$sql = 'SELECT report_rows.* FROM (' . $sql . ') report_rows' . $whereSql . ' ORDER BY report_rows.purchase_date DESC, report_rows.id DESC';
$paramsForList = $params;
$typesForList = $types;
if ($paginated) {
    $sql .= ' LIMIT ? OFFSET ?';
    $paramsForList[] = $pageSize;
    $paramsForList[] = ($page - 1) * $pageSize;
    $typesForList .= 'ii';
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare device report query.', [], [], 500);
}
if ($paramsForList) $stmt->bind_param($typesForList, ...$paramsForList);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch device report.', [], [], 500);
}

$devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$responseData = ["devices" => $devices];
if ($paginated) $responseData['pagination'] = $pagination;
sendResponse(true, "Devices fetched", $responseData);
?>
