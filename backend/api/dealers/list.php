<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

requireAnyPermission([
    'dealers.view',
    'stock.view',
    'stock_transfer.view',
    'customers.view',
    'outward_reports.view'
]);

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$whereConditions = [];
$params = [];
$types = '';

if (isset($_GET['for_alert']) && $_GET['for_alert'] === '1') {
    $whereConditions[] = "LOWER(TRIM(d.installation_status)) IN ('onsite', 'offsite')";
} elseif (isset($_GET['installation_status']) && trim((string)$_GET['installation_status']) !== '') {
    $rawStatuses = array_filter(array_map('trim', explode(',', (string) $_GET['installation_status'])));
    if (!empty($rawStatuses)) {
        $placeholders = implode(',', array_fill(0, count($rawStatuses), '?'));
        $whereConditions[] = "LOWER(TRIM(d.installation_status)) IN ($placeholders)";
        foreach ($rawStatuses as $status) {
            $params[] = strtolower($status);
            $types .= 's';
        }
    }
}

if (($search = trim((string)($_GET['search'] ?? ''))) !== '') {
    $whereConditions[] = '(LOWER(TRIM(d.dealer_name)) LIKE ? OR LOWER(TRIM(d.mobile_no)) LIKE ? OR LOWER(TRIM(d.location)) LIKE ? OR LOWER(TRIM(d.notes)) LIKE ? OR EXISTS (SELECT 1 FROM stock_allocations sa_search JOIN sims s_search ON s_search.id = sa_search.sim_id WHERE sa_search.owner_type = \'dealer\' AND sa_search.owner_id = d.id AND LOWER(TRIM(s_search.sim_no)) LIKE ?))';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

if (($platform = trim((string)($_GET['platform'] ?? ''))) !== '') {
    $whereConditions[] = "LOWER(TRIM(COALESCE(d.software, ''))) = ?";
    $params[] = strtolower($platform);
    $types .= 's';
}

if (($deviceModel = trim((string)($_GET['device_model'] ?? ''))) !== '') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM stock_allocations sa2 JOIN devices dv2 ON dv2.id = sa2.device_id JOIN device_types dt2 ON dt2.id = dv2.device_model_id WHERE sa2.owner_type = 'dealer' AND sa2.owner_id = d.id AND LOWER(TRIM(dt2.device_type)) = ?)";
    $params[] = strtolower($deviceModel);
    $types .= 's';
}

if (($simType = trim((string)($_GET['sim_type'] ?? ''))) !== '') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM stock_allocations sa2 JOIN sims s2 ON s2.id = sa2.sim_id WHERE sa2.owner_type = 'dealer' AND sa2.owner_id = d.id AND LOWER(TRIM(COALESCE(s2.sim_type, ''))) = ?)";
    $params[] = strtolower($simType);
    $types .= 's';
}

if (($simValidity = trim((string)($_GET['sim_validity'] ?? ''))) !== '') {
    $cleanValidity = preg_replace('/[^0-9]/', '', $simValidity);
    if ($cleanValidity !== '') {
        $whereConditions[] = "EXISTS (SELECT 1 FROM stock_allocations sa2 JOIN sims s2 ON s2.id = sa2.sim_id JOIN sim_validities sv2 ON sv2.id = COALESCE(sa2.sim_validity_id, s2.sim_validity_id) WHERE sa2.owner_type = 'dealer' AND sa2.owner_id = d.id AND CAST(sv2.months AS CHAR) = ?)";
        $params[] = $cleanValidity;
        $types .= 's';
    }
}

if (($paymentStatus = trim((string)($_GET['payment_status'] ?? ''))) !== '') {
    $whereConditions[] = "CASE WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) <= 0 THEN 'No Payment Required' WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) THEN 'Paid' WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) > 0 THEN 'Partially Paid' ELSE 'Not Paid' END = ?";
    $params[] = $paymentStatus;
    $types .= 's';
}

if (($year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT)) !== false && $year) {
    $whereConditions[] = 'YEAR(d.enrolled_date) = ?';
    $params[] = $year;
    $types .= 'i';
}

if (($month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT)) !== false && $month >= 1 && $month <= 12) {
    $whereConditions[] = 'MONTH(d.enrolled_date) = ?';
    $params[] = $month;
    $types .= 'i';
}

$givenDate = trim((string)($_GET['given_date'] ?? ''));
if ($givenDate !== '') {
    $givenOperator = strtolower(trim((string)($_GET['given_date_operator'] ?? 'exact')));
    $givenCondition = "EXISTS (SELECT 1 FROM stock_allocations sa2 WHERE sa2.owner_type = 'dealer' AND sa2.owner_id = d.id AND sa2.sim_given_date IS NOT NULL";
    if ($givenOperator === 'year' && preg_match('/^\d{4}$/', $givenDate)) { $givenCondition .= " AND YEAR(sa2.sim_given_date) = ?"; }
    elseif ($givenOperator === 'month' && preg_match('/^\d{4}-\d{2}$/', $givenDate)) { $givenCondition .= " AND DATE_FORMAT(sa2.sim_given_date, '%Y-%m') = ?"; }
    else { $givenCondition .= " AND sa2.sim_given_date = ?"; }
    $givenCondition .= ')';
    $whereConditions[] = $givenCondition;
    $params[] = $givenDate;
    $types .= 's';
}

$activationDate = trim((string)($_GET['activation_date'] ?? ''));
if ($activationDate !== '') {
    $activationOperator = strtolower(trim((string)($_GET['activation_date_operator'] ?? 'exact')));
    $activationCondition = "EXISTS (SELECT 1 FROM stock_allocations sa2 WHERE sa2.owner_type = 'dealer' AND sa2.owner_id = d.id AND sa2.sim_activation_date IS NOT NULL";
    if ($activationOperator === 'year' && preg_match('/^\d{4}$/', $activationDate)) { $activationCondition .= " AND YEAR(sa2.sim_activation_date) = ?"; }
    elseif ($activationOperator === 'month' && preg_match('/^\d{4}-\d{2}$/', $activationDate)) { $activationCondition .= " AND DATE_FORMAT(sa2.sim_activation_date, '%Y-%m') = ?"; }
    else { $activationCondition .= " AND sa2.sim_activation_date = ?"; }
    $activationCondition .= ')';
    $whereConditions[] = $activationCondition;
    $params[] = $activationDate;
    $types .= 's';
}

$whereClause = $whereConditions ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

$sql = "
    SELECT 
        d.id, 
        d.dealer_name, 
        d.mobile_no, 
        d.location, 
        d.enrolled_date, 
        d.installation_status,
        d.software,
        d.threshold_amount,
        d.notes,
        (SELECT sa.sim_given_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_given_date,
        (SELECT sa.sim_given_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as given_date,
        (SELECT sa.sim_activation_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_activation_date,
        (SELECT sa.sim_activation_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as activation_date,
        (SELECT CASE WHEN sa.sim_validity_id IS NOT NULL THEN sv.months ELSE NULL END FROM stock_allocations sa LEFT JOIN sim_validities sv ON sv.id = sa.sim_validity_id WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_validity_months,
        (SELECT sa.sim_expiry_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_expiry_date,
        (SELECT sa.sim_expiry_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as expiry_date,
        (SELECT sa.sim_deactivation_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_deactivation_date,
        (SELECT sa.sim_deactivation_date FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as deactivation_date,
        (SELECT sa.sim_status FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL ORDER BY sa.created_at DESC, sa.id DESC LIMIT 1) as sim_status,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND device_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as device_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND sim_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as sim_count,
        COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND device_id IS NOT NULL), 0) as total_device_count,
        COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND sim_id IS NOT NULL), 0) as total_sim_count,
        COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0) as used_device_count,
        COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0) as used_sim_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT device_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND device_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.device_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id=sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id=sa.device_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.device_id=sa.device_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as available_device_count,
        GREATEST(0, COALESCE((SELECT COUNT(DISTINCT sim_id) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id AND sim_id IS NOT NULL), 0) - COALESCE((SELECT COUNT(DISTINCT sa.sim_id) FROM stock_allocations sa WHERE sa.owner_type='dealer' AND sa.owner_id=d.id AND sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1=sa.sim_id OR cvd.sim_id_2=sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id=sa.sim_id AND st.from_owner_type=sa.owner_type AND st.from_owner_id=sa.owner_id AND st.id=(SELECT MAX(x.id) FROM stock_transactions x WHERE x.sim_id=sa.sim_id AND x.from_owner_type=sa.owner_type AND x.from_owner_id=sa.owner_id) AND st.transaction_type='USE'))), 0)) as available_sim_count,
        COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) as total_amount,
        COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) as amount_paid,
        GREATEST(0, COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) - COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0)) as pending_amount,
        CASE
            WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) <= 0 THEN 'No Payment Required'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) THEN 'Paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='dealer' AND owner_id=d.id), 0) > 0 THEN 'Partially Paid'
            ELSE 'Not Paid'
        END as payment_status,
        COALESCE((SELECT sas.minimum_device_count FROM stock_alert_settings sas WHERE sas.owner_type='dealer' AND sas.owner_id=d.id LIMIT 1), 0) as minimum_device_count,
        COALESCE((SELECT sas.minimum_sim_count FROM stock_alert_settings sas WHERE sas.owner_type='dealer' AND sas.owner_id=d.id LIMIT 1), 0) as minimum_sim_count,
        (SELECT GROUP_CONCAT(DISTINCT dt.device_type SEPARATOR '||')
         FROM stock_allocations sa
         JOIN devices dev ON dev.id = sa.device_id
         JOIN device_types dt ON dt.id = dev.device_model_id
         WHERE sa.owner_type = 'dealer' AND sa.owner_id = d.id) as allocated_device_models,
        (SELECT GROUP_CONCAT(DISTINCT s.sim_type SEPARATOR '||')
         FROM stock_allocations sa
         JOIN sims s ON s.id = sa.sim_id
         WHERE sa.owner_type = 'dealer' AND sa.owner_id = d.id AND s.sim_type IS NOT NULL AND s.sim_type != '') as allocated_sim_types,
        (SELECT GROUP_CONCAT(DISTINCT sv.months SEPARATOR '||')
         FROM stock_allocations sa
         JOIN sims s ON s.id = sa.sim_id
         JOIN sim_validities sv ON sv.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
         WHERE sa.owner_type = 'dealer' AND sa.owner_id = d.id) as allocated_sim_validities,
        (SELECT GROUP_CONCAT(DISTINCT sa.software SEPARATOR '||')
         FROM stock_allocations sa
         WHERE sa.owner_type = 'dealer' AND sa.owner_id = d.id AND sa.software IS NOT NULL AND sa.software != '') as allocated_platforms
    FROM dealers d
    $whereClause
    ORDER BY d.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    sendResponse(false, 'Unable to fetch dealers: ' . $conn->error, [], [], 500);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$dealerIds = [];
$dealerRows = [];
while ($result && ($row = $result->fetch_assoc())) {
    $dealerIds[] = (int) $row['id'];
    $dealerRows[] = $row;
}

$simsByDealer = [];
$softwareByDealer = [];
if (!empty($dealerIds)) {
    $dealerIdList = implode(',', array_map('intval', $dealerIds));
    $simDetailsSql = "
        SELECT sa.owner_id, sa.id AS allocation_id, s.sim_no, s.sim_type,
               sa.software, sa.sim_given_date, sa.sim_activation_date,
               COALESCE(sa.sim_validity_id, s.sim_validity_id) AS sim_validity_id,
               v.months AS sim_validity_months, sa.sim_expiry_date,
               sa.sim_deactivation_date, COALESCE(sa.sim_status, 'Available') AS sim_status
        FROM stock_allocations sa
        INNER JOIN sims s ON s.id = sa.sim_id
        LEFT JOIN sim_validities v ON v.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
        WHERE sa.owner_type = 'dealer' AND sa.owner_id IN ({$dealerIdList})
        ORDER BY sa.owner_id, sa.id ASC
    ";
    $simDetailsResult = $conn->query($simDetailsSql);
    if ($simDetailsResult) {
        while ($sim = $simDetailsResult->fetch_assoc()) {
            $ownerId = (int) $sim['owner_id'];
            $simsByDealer[$ownerId][] = [
                'allocation_id' => (int) $sim['allocation_id'],
                'sim_no' => $sim['sim_no'],
                'sim_type' => $sim['sim_type'],
                'software' => $sim['software'],
                'given_date' => $sim['sim_given_date'],
                'activation_date' => $sim['sim_activation_date'],
                'sim_validity_id' => $sim['sim_validity_id'] !== null ? (int) $sim['sim_validity_id'] : null,
                'validity_months' => $sim['sim_validity_months'] !== null ? (int) $sim['sim_validity_months'] : null,
                'expiry_date' => $sim['sim_expiry_date'],
                'deactivation_date' => $sim['sim_deactivation_date'],
                'status' => $sim['sim_status']
            ];
        }
    }

    $swRes = $conn->query("SELECT dealer_id, software FROM dealer_software WHERE dealer_id IN ({$dealerIdList}) ORDER BY id ASC");
    if ($swRes) {
        while ($swRow = $swRes->fetch_assoc()) {
            $dId = (int) $swRow['dealer_id'];
            $softwareByDealer[$dId][] = $swRow['software'];
        }
    }
}

$dealers = [];
foreach ($dealerRows as $row) {
        $row['sims'] = $simsByDealer[(int) $row['id']] ?? [];
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

        $assignedSw = $softwareByDealer[(int)$row['id']] ?? [];
        if (!empty($assignedSw)) {
            $row['software_list'] = $assignedSw;
            $row['software'] = implode(', ', $assignedSw);
        } else {
            $swList = !empty($row['software']) ? array_values(array_filter(array_map('trim', explode(',', $row['software'])))) : [];
            $row['software_list'] = $swList;
        }

        $platformsList = [];
        if (!empty($row['software'])) {
            foreach (explode(',', $row['software']) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $platformsList, true)) {
                    $platformsList[] = $p;
                }
            }
        }
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

        $dealers[] = $row;
}

sendResponse(true, "Dealers fetched successfully", ["dealers" => $dealers]);

$stmt->close();
$conn->close();
?>
