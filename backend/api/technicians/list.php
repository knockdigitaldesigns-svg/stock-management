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

if (isset($_GET['for_lookup']) && $_GET['for_lookup'] === '1') {
    $result = $conn->query('SELECT id, technician_name FROM technicians ORDER BY technician_name ASC, id ASC');
    if (!$result) {
        $conn->close();
        sendResponse(false, 'Failed to fetch technician lookup options.', [], [], 500);
    }
    $technicians = [];
    while ($row = $result->fetch_assoc()) {
        $technicians[] = $row;
    }
    $conn->close();
    sendResponse(true, 'Technician lookup options fetched successfully.', ['technicians' => $technicians]);
}

$where = [];
$params = [];
$types = '';
$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(t.technician_name LIKE ? OR t.mobile_no LIKE ? OR t.location LIKE ? OR t.notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
if ($dateFrom !== '') {
    $where[] = 't.enrolled_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateTo !== '') {
    $where[] = 't.enrolled_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
$technicianId = filter_input(INPUT_GET, 'technician_id', FILTER_VALIDATE_INT);
if ($technicianId !== false && $technicianId > 0) {
    $where[] = 't.id = ?';
    $params[] = $technicianId;
    $types .= 'i';
}
$deviceModel = trim((string) ($_GET['device_model'] ?? ''));
if ($deviceModel !== '') {
    $where[] = "EXISTS (SELECT 1 FROM stock_allocations sa_filter JOIN devices dev_filter ON dev_filter.id = sa_filter.device_id JOIN device_types dt_filter ON dt_filter.id = dev_filter.device_model_id WHERE sa_filter.owner_type = 'technician' AND sa_filter.owner_id = t.id AND LOWER(TRIM(dt_filter.device_type)) = ?)";
    $params[] = strtolower($deviceModel);
    $types .= 's';
}
$simType = trim((string) ($_GET['sim_type'] ?? ''));
if ($simType !== '') {
    $where[] = "EXISTS (SELECT 1 FROM stock_allocations sa_filter JOIN sims s_filter ON s_filter.id = sa_filter.sim_id WHERE sa_filter.owner_type = 'technician' AND sa_filter.owner_id = t.id AND LOWER(TRIM(s_filter.sim_type)) = ?)";
    $params[] = strtolower($simType);
    $types .= 's';
}
$simValidity = trim((string) ($_GET['sim_validity'] ?? ''));
if ($simValidity !== '') {
    $validityMonths = preg_replace('/[^0-9]/', '', $simValidity);
    if ($validityMonths !== '') {
        $where[] = "EXISTS (SELECT 1 FROM stock_allocations sa_filter JOIN sims s_filter ON s_filter.id = sa_filter.sim_id LEFT JOIN sim_validities sv_filter ON sv_filter.id = COALESCE(sa_filter.sim_validity_id, s_filter.sim_validity_id) WHERE sa_filter.owner_type = 'technician' AND sa_filter.owner_id = t.id AND CAST(sv_filter.months AS CHAR) = ?)";
        $params[] = $validityMonths;
        $types .= 's';
    }
}
$platform = trim((string) ($_GET['platform'] ?? ''));
if ($platform !== '') {
    $where[] = "EXISTS (SELECT 1 FROM stock_allocations sa_filter WHERE sa_filter.owner_type = 'technician' AND sa_filter.owner_id = t.id AND LOWER(TRIM(sa_filter.software)) = ?)";
    $params[] = strtolower($platform);
    $types .= 's';
}
$paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
if ($paymentStatus !== '') {
    $where[] = "CASE
        WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) <= 0 THEN 'No Payment Required'
        WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) THEN 'Paid'
        WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) > 0 THEN 'Partially Paid'
        ELSE 'Not Paid'
    END = ?";
    $params[] = $paymentStatus;
    $types .= 's';
}
$paginated = isset($_GET['page']) || isset($_GET['page_size']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$pagination = null;
if ($paginated) {
    $countSql = 'SELECT COUNT(*) AS total FROM technicians t' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        $conn->close();
        sendResponse(false, 'Failed to prepare technician count query.', [], [], 500);
    }
    if ($params) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) {
        $countStmt->close();
        $conn->close();
        sendResponse(false, 'Failed to count technicians.', [], [], 500);
    }
    $pagination = [
        'total' => (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0),
        'page' => $page,
        'page_size' => $pageSize
    ];
    $pagination['total_pages'] = max(1, (int) ceil($pagination['total'] / $pageSize));
    $countStmt->close();
}

// Calculate Device and SIM counts dynamically for technicians
$sql = "
    SELECT 
        t.id, 
        t.technician_name, 
        t.mobile_no, 
        t.alternate_mobile_no,
        t.location, 
        t.enrolled_date, 
        t.status,
        t.notes,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND device_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as device_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND sim_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as sim_count,
        COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND device_id IS NOT NULL), 0) as total_device_count,
        COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND sim_id IS NOT NULL), 0) as total_sim_count,
        COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0) as used_device_count,
        COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0) as used_sim_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND device_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as available_device_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND sim_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='technician' AND sa.owner_id=t.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as available_sim_count,
        CASE
            WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) <= 0 THEN 'No Payment Required'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) THEN 'Paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) > 0 THEN 'Partially Paid'
            ELSE 'Not Paid'
        END as payment_status,
        COALESCE((SELECT minimum_device_count FROM stock_alert_settings WHERE owner_type='technician' AND owner_id=t.id LIMIT 1), 0) as minimum_device_count,
        COALESCE((SELECT minimum_sim_count FROM stock_alert_settings WHERE owner_type='technician' AND owner_id=t.id LIMIT 1), 0) as minimum_sim_count,
        (SELECT GROUP_CONCAT(DISTINCT dt.device_type SEPARATOR '||')
         FROM stock_allocations sa
         JOIN devices dev ON dev.id = sa.device_id
         JOIN device_types dt ON dt.id = dev.device_model_id
         WHERE sa.owner_type = 'technician' AND sa.owner_id = t.id) as allocated_device_models,
        (SELECT GROUP_CONCAT(DISTINCT s.sim_type SEPARATOR '||')
         FROM stock_allocations sa
         JOIN sims s ON s.id = sa.sim_id
         WHERE sa.owner_type = 'technician' AND sa.owner_id = t.id AND s.sim_type IS NOT NULL AND s.sim_type != '') as allocated_sim_types,
        (SELECT GROUP_CONCAT(DISTINCT sv.months SEPARATOR '||')
         FROM stock_allocations sa
         JOIN sims s ON s.id = sa.sim_id
         JOIN sim_validities sv ON sv.id = s.sim_validity_id
         WHERE sa.owner_type = 'technician' AND sa.owner_id = t.id) as allocated_sim_validities,
        (SELECT GROUP_CONCAT(DISTINCT sa.software SEPARATOR '||')
         FROM stock_allocations sa
         WHERE sa.owner_type = 'technician' AND sa.owner_id = t.id AND sa.software IS NOT NULL AND sa.software != '') as allocated_platforms
    FROM technicians t
";
$sql .= $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql .= ' ORDER BY t.created_at DESC, t.id DESC';
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
    sendResponse(false, 'Failed to prepare technician query.', [], [], 500);
}
if ($dataParams) $stmt->bind_param($dataTypes, ...$dataParams);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch technicians.', [], [], 500);
}
$result = $stmt->get_result();

$technicians = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $minDev = (int) ($row['minimum_device_count'] ?? 0);
        $availDev = (int) ($row['available_device_count'] ?? 0);
        if ($minDev > 0) {
            if ($availDev < $minDev) {
                $row['device_alert_status'] = 'ALERT';
            } elseif ($availDev === $minDev) {
                $row['device_alert_status'] = 'WARNING';
            } else {
                $row['device_alert_status'] = 'SAFE';
            }
        } else {
            $row['device_alert_status'] = 'SAFE';
        }

        $platformsList = [];
        if (!empty($row['allocated_platforms'])) {
            foreach (explode('||', $row['allocated_platforms']) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $platformsList, true)) {
                    $platformsList[] = $p;
                }
            }
        }
        $row['platforms'] = $platformsList;
        $row['device_models'] = !empty($row['allocated_device_models']) ? array_values(array_filter(array_map('trim', explode('||', $row['allocated_device_models'])))) : [];
        $row['sim_types'] = !empty($row['allocated_sim_types']) ? array_values(array_filter(array_map('trim', explode('||', $row['allocated_sim_types'])))) : [];
        $row['sim_validities'] = !empty($row['allocated_sim_validities']) ? array_values(array_filter(array_map('trim', explode('||', $row['allocated_sim_validities'])))) : [];

        $technicians[] = $row;
    }
}

 $responseData = ["technicians" => $technicians];
if ($paginated) $responseData['pagination'] = $pagination;
sendResponse(true, "Technicians fetched successfully", $responseData);

$stmt->close();
$conn->close();
?>
