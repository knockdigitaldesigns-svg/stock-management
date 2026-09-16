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

$where = [];
$params = [];
$types = '';
if (($search = trim((string)($_GET['search'] ?? ''))) !== '') { $where[] = '(d.imei_no LIKE ? OR dm.device_type LIKE ? OR d.notes LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like); $types .= 'sss'; }
if (($year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT)) !== false && $year) { $where[] = 'YEAR(d.purchase_date) = ?'; $params[] = $year; $types .= 'i'; }
if (($month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT)) !== false && $month >= 1 && $month <= 12) { $where[] = 'MONTH(d.purchase_date) = ?'; $params[] = $month; $types .= 'i'; }
if (($deviceType = trim((string)($_GET['deviceType'] ?? ''))) !== '') { $where[] = 'dm.device_type = ?'; $params[] = $deviceType; $types .= 's'; }
$sql = "
                SELECT d.id, d.purchase_date, d.device_model_id, d.imei_no, d.notes,
                        CASE
                                WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = d.id)
                                    OR EXISTS (
                                        SELECT 1 FROM stock_transactions st
                                        WHERE st.device_id = d.id
                                            AND st.from_owner_type = sa.owner_type
                                            AND st.from_owner_id = sa.owner_id
                                            AND st.id = (
                                                SELECT MAX(st_latest.id) FROM stock_transactions st_latest
                                                WHERE st_latest.device_id = d.id
                                                    AND st_latest.from_owner_type = sa.owner_type
                                                    AND st_latest.from_owner_id = sa.owner_id
                                            )
                                            AND st.transaction_type = 'USE'
                                    ) THEN 'used'
                                ELSE d.status
                        END AS status,
                        dm.device_type AS model_name,
            sa.allocation_date, sa.owner_type, sa.owner_id, sa.payment_status, sa.software,
            CASE WHEN sa.owner_type = 'dealer' THEN dl.dealer_name WHEN sa.owner_type = 'technician' THEN t.technician_name END AS owner_name
    FROM devices d
    JOIN device_types dm ON d.device_model_id = dm.id
        LEFT JOIN stock_allocations sa ON sa.device_id = d.id AND sa.id = (SELECT MAX(id) FROM stock_allocations WHERE device_id = d.id)
        LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY d.created_at DESC';
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

sendResponse(true, "Devices fetched successfully", ["devices" => $devices]);

$stmt->close();
$conn->close();
?>
