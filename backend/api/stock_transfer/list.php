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

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query($sql);
}

$transfers = [];
while ($result && ($row = $result->fetch_assoc())) {
    $row['id'] = (int)$row['id'];
    $row['imei_no'] = $row['imei_no'] ?? '-';
    $row['sim_no']  = $row['sim_no']  ?? '-';
    $transfers[] = $row;
}

$conn->close();
sendResponse(true, 'Stock transfers fetched', ['transfers' => $transfers]);
?>
