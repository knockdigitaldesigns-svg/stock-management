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

$where = []; $params = []; $types = '';
if (($search = trim((string)($_GET['search'] ?? ''))) !== '') { $where[] = '(s.sim_no LIKE ? OR s.sim_type LIKE ? OR s.notes LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like); $types .= 'sss'; }
if (($year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT)) !== false && $year) { $where[] = 'YEAR(s.purchase_date) = ?'; $params[] = $year; $types .= 'i'; }
if (($month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT)) !== false && $month >= 1 && $month <= 12) { $where[] = 'MONTH(s.purchase_date) = ?'; $params[] = $month; $types .= 'i'; }
if (($simType = trim((string)($_GET['simType'] ?? ''))) !== '') { $where[] = 's.sim_type = ?'; $params[] = $simType; $types .= 's'; }
$sql = 'SELECT s.id, s.purchase_date, s.sim_no, s.sim_type, s.sim_validity_id, s.notes, v.months AS sim_validity_months,
        CASE
                WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id)
                    OR EXISTS (
                        SELECT 1 FROM stock_transactions st
                        WHERE st.sim_id = s.id
                            AND st.from_owner_type = sa.owner_type
                            AND st.from_owner_id = sa.owner_id
                            AND st.id = (
                                SELECT MAX(st_latest.id) FROM stock_transactions st_latest
                                WHERE st_latest.sim_id = s.id
                                    AND st_latest.from_owner_type = sa.owner_type
                                    AND st_latest.from_owner_id = sa.owner_id
                            )
                            AND st.transaction_type = "USE"
                    ) THEN "used"
                ELSE s.status
        END AS status,
        sa.allocation_date, sa.owner_type, sa.owner_id, sa.payment_status, sa.software,
        CASE WHEN sa.owner_type = "dealer" THEN dl.dealer_name WHEN sa.owner_type = "technician" THEN t.technician_name END AS owner_name
        FROM sims s LEFT JOIN sim_validities v ON v.id = s.sim_validity_id LEFT JOIN stock_allocations sa ON sa.sim_id = s.id AND sa.id = (SELECT MAX(id) FROM stock_allocations WHERE sim_id = s.id) LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = "dealer" LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician"';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY s.created_at DESC';
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$sims = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
        if ($row['status'] === 'active') $row['status'] = 'available';
        if ($row['status'] === 'deactive') $row['status'] = 'used';
        if ($row['status'] === 'expired') $row['status'] = 'used';
        if ($row['status'] === 'safe custody') $row['status'] = 'allocated';
        $sims[] = $row;
    }
}

sendResponse(true, "SIMs fetched successfully", ["sims" => $sims]);

$stmt->close();
$conn->close();
?>
