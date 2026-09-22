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

// Calculate Device and SIM counts dynamically for technicians
$sql = "
    SELECT 
        t.id, 
        t.technician_name, 
        t.mobile_no, 
        t.alternate_mobile_no,
        t.location, 
        t.enrolled_date, 
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
    ORDER BY t.created_at DESC
";
$result = $conn->query($sql);

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

sendResponse(true, "Technicians fetched successfully", ["technicians" => $technicians]);

$conn->close();
?>
