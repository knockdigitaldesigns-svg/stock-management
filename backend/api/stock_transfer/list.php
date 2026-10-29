<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('stock_transfer.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

// Filters
$search       = isset($_GET['search'])         ? trim((string)$_GET['search'])         : '';
$fromOwner    = isset($_GET['from_owner'])     ? trim((string)$_GET['from_owner'])     : '';
$fromType     = isset($_GET['from_owner_type'])? trim((string)$_GET['from_owner_type']): '';
$toOwner      = isset($_GET['to_owner'])       ? trim((string)$_GET['to_owner'])       : '';
$dateFrom     = isset($_GET['date_from'])      ? trim((string)$_GET['date_from'])      : '';
$dateTo       = isset($_GET['date_to'])        ? trim((string)$_GET['date_to'])        : '';

$where = ['1=1'];
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(st.from_owner_name LIKE ? OR st.to_owner_name LIKE ? OR dev.imei_no LIKE ? OR s.sim_no LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}
if ($fromOwner !== '') {
    $likeOwner = '%' . $fromOwner . '%';
    $where[] = "st.from_owner_name LIKE ?";
    $params[] = $likeOwner;
    $types .= 's';
}
if ($fromType !== '') {
    $where[] = "st.from_owner_type = ?";
    $params[] = strtolower($fromType);
    $types .= 's';
}
if ($toOwner !== '') {
    $like2 = '%' . $toOwner . '%';
    $where[] = "st.to_owner_name LIKE ?";
    $params[] = $like2;
    $types .= 's';
}
if ($dateFrom !== '') {
    $where[] = "st.transfer_date >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = "st.transfer_date <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$whereSQL = implode(' AND ', $where);
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));

$pagination = null;
if ($paginated) {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM stock_transfers st
        LEFT JOIN devices dev ON dev.id = st.device_id
        LEFT JOIN sims s ON s.id = st.sim_id
        WHERE $whereSQL
    ";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare stock transfer count query.', [], [], 500);
    }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Failed to count stock transfers.', [], [], 500);
    }
    $pagination = [
        'total' => (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0),
        'page' => $page,
        'page_size' => $pageSize
    ];
    $pagination['total_pages'] = max(1, (int) ceil($pagination['total'] / $pageSize));
    $countStmt->close();
}

$sql = "
    SELECT
        st.id,
        st.allocation_id,
        st.device_id,
        st.sim_id,
        st.from_owner_type,
        st.from_owner_id,
        st.from_owner_name,
        st.to_owner_type,
        st.to_owner_id,
        st.to_owner_name,
        st.transfer_date,
        st.previous_status,
        st.new_status,
        st.transferred_by_user_id,
        st.notes,
        st.created_at,
        dev.imei_no,
        s.sim_no,
        dt.device_type AS device_model,
        u.username AS transferred_by
    FROM stock_transfers st
    LEFT JOIN devices dev ON dev.id = st.device_id
    LEFT JOIN sims s ON s.id = st.sim_id
    LEFT JOIN device_types dt ON dt.id = dev.device_model_id
    LEFT JOIN users u ON u.id = st.transferred_by_user_id
    WHERE $whereSQL
    ORDER BY st.created_at DESC, st.id DESC
";

$dataParams = $params;
$dataTypes = $types;
if ($paginated) {
    $sql .= ' LIMIT ? OFFSET ?';
    $dataParams[] = $pageSize;
    $dataParams[] = ($page - 1) * $pageSize;
    $dataTypes .= 'ii';
}
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare stock transfer query.', [], [], 500);
}
if ($dataParams) $stmt->bind_param($dataTypes, ...$dataParams);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch stock transfers.', [], [], 500);
}
$result = $stmt->get_result();

$transfers = [];
while ($result && ($row = $result->fetch_assoc())) {
    $row['id'] = (int)$row['id'];
    $row['imei_no'] = $row['imei_no'] ?? '-';
    $row['sim_no']  = $row['sim_no']  ?? '-';
    $transfers[] = $row;
}

$stmt->close();
$conn->close();
$responseData = ['transfers' => $transfers];
if ($paginated) $responseData['pagination'] = $pagination;
sendResponse(true, 'Stock transfers fetched', $responseData);
?>
