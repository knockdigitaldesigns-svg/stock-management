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
$allowedStatuses = ['available', 'allocated', 'used', 'reserved', 'active', 'deactive', 'expired', 'safe custody'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid SIM status filter.', [], [], 400);
}

$sql = "
    SELECT report_rows.*
    FROM (
        SELECT
            inventory_rows.*,
            CASE
                WHEN inventory_rows.status = 'allocated'
                    AND LOWER(COALESCE(inventory_rows.allocation_sim_status, 'available')) <> 'available'
                    THEN COALESCE(inventory_rows.allocation_sim_status, 'Available')
                ELSE NULL
            END AS lifecycle_status
        FROM (
            SELECT
                s.id,
                s.sim_no,
                s.purchase_date,
                s.sim_type,
                s.sim_validity_id,
                s.notes,
                v.months AS sim_validity_months,
                sa.sim_status AS allocation_sim_status,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM customer_vehicle_details cvd
                        WHERE cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id
                    ) OR EXISTS (
                        SELECT 1
                        FROM stock_transactions st
                        WHERE st.sim_id = s.id
                          AND st.from_owner_type = sa.owner_type
                          AND st.from_owner_id = sa.owner_id
                          AND st.id = (
                              SELECT MAX(st_latest.id)
                              FROM stock_transactions st_latest
                              WHERE st_latest.sim_id = s.id
                                AND st_latest.from_owner_type = sa.owner_type
                                AND st_latest.from_owner_id = sa.owner_id
                          )
                          AND st.transaction_type = 'USE'
                    ) THEN 'used'
                    ELSE LOWER(COALESCE(s.status, 'available'))
                END AS status
            FROM sims s
            LEFT JOIN sim_validities v ON v.id = s.sim_validity_id
            LEFT JOIN stock_allocations sa
                ON sa.id = (
                    SELECT latest_sa.id
                    FROM stock_allocations latest_sa
                    WHERE latest_sa.sim_id = s.id
                    ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                    LIMIT 1
                )
            WHERE s.deleted_at IS NULL
        ) inventory_rows
    ) report_rows
";

$where = [];
$params = [];
$types = '';
if ($statusFilter !== '') {
    $where[] = "(LOWER(report_rows.status) = ? OR LOWER(COALESCE(report_rows.lifecycle_status, '')) = ?)";
    $params[] = $statusFilter;
    $params[] = $statusFilter;
    $types .= 'ss';
}
if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
    $where[] = '(report_rows.sim_no LIKE ? OR report_rows.sim_type LIKE ? OR report_rows.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$simType = trim((string) ($_GET['sim_type'] ?? ''));
if ($simType !== '') {
    $where[] = 'LOWER(report_rows.sim_type) = ?';
    $params[] = strtolower($simType);
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
$paginated = true;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;
if ($paginated) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $sql . ') report_rows' . $whereSql);
    if (!$countStmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare SIM report count query.', [], [], 500);
    }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Failed to count SIM report rows.', [], [], 500);
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
    sendResponse(false, 'Failed to prepare SIM report query.', [], [], 500);
}
if ($paramsForList) $stmt->bind_param($typesForList, ...$paramsForList);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch SIM report.', [], [], 500);
}

$sims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$responseData = ["sims" => $sims];
if ($paginated) $responseData['pagination'] = $pagination;
sendResponse(true, "SIMs fetched", $responseData);
?>
