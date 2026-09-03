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

$summary = [];

$dealerSql = "
    SELECT
        d.id AS owner_id,
        'dealer' AS owner_type,
        d.dealer_name AS name,
        COUNT(DISTINCT sa.device_id) AS total_devices,
        COUNT(DISTINCT sa.sim_id) AS total_sims,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND dev.status = 'used' THEN 1 ELSE 0 END) AS used_devices,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND s.status = 'used' THEN 1 ELSE 0 END) AS used_sims,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND dev.status = 'allocated' THEN 1 ELSE 0 END) AS available_devices,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND s.status = 'allocated' THEN 1 ELSE 0 END) AS available_sims
    FROM dealers d
    LEFT JOIN stock_allocations sa ON sa.owner_type = 'dealer' AND sa.owner_id = d.id
    LEFT JOIN devices dev ON dev.id = sa.device_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    WHERE d.installation_status IN ('Onsite', 'Offsite')
    GROUP BY d.id, d.dealer_name
    ORDER BY d.dealer_name ASC
";

$dealerResult = $conn->query($dealerSql);
if ($dealerResult) {
    while ($row = $dealerResult->fetch_assoc()) {
        $row['total_devices'] = (int) ($row['total_devices'] ?? 0);
        $row['total_sims'] = (int) ($row['total_sims'] ?? 0);
        $row['used_devices'] = (int) ($row['used_devices'] ?? 0);
        $row['used_sims'] = (int) ($row['used_sims'] ?? 0);
        $row['available_devices'] = (int) ($row['available_devices'] ?? 0);
        $row['available_sims'] = (int) ($row['available_sims'] ?? 0);
        $summary[] = $row;
    }
}

$technicianSql = "
    SELECT
        t.id AS owner_id,
        'technician' AS owner_type,
        t.technician_name AS name,
        COUNT(DISTINCT sa.device_id) AS total_devices,
        COUNT(DISTINCT sa.sim_id) AS total_sims,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND dev.status = 'used' THEN 1 ELSE 0 END) AS used_devices,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND s.status = 'used' THEN 1 ELSE 0 END) AS used_sims,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND dev.status = 'allocated' THEN 1 ELSE 0 END) AS available_devices,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND s.status = 'allocated' THEN 1 ELSE 0 END) AS available_sims
    FROM technicians t
    LEFT JOIN stock_allocations sa ON sa.owner_type = 'technician' AND sa.owner_id = t.id
    LEFT JOIN devices dev ON dev.id = sa.device_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    GROUP BY t.id, t.technician_name
    ORDER BY t.technician_name ASC
";

$techResult = $conn->query($technicianSql);
if ($techResult) {
    while ($row = $techResult->fetch_assoc()) {
        $row['total_devices'] = (int) ($row['total_devices'] ?? 0);
        $row['total_sims'] = (int) ($row['total_sims'] ?? 0);
        $row['used_devices'] = (int) ($row['used_devices'] ?? 0);
        $row['used_sims'] = (int) ($row['used_sims'] ?? 0);
        $row['available_devices'] = (int) ($row['available_devices'] ?? 0);
        $row['available_sims'] = (int) ($row['available_sims'] ?? 0);
        $summary[] = $row;
    }
}

sendResponse(true, "Stock summary fetched", ["summary" => $summary]);

$conn->close();
?>
