<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('device_alert.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$configSql = "
    SELECT s.id, s.owner_type, s.owner_id, s.minimum_device_count, s.minimum_sim_count, s.notes
    FROM stock_alert_settings s
    ORDER BY s.owner_type ASC, s.owner_id ASC
";

$configResult = $conn->query($configSql);
$owners = [];

if ($configResult) {
    while ($row = $configResult->fetch_assoc()) {
        $ownerType = strtolower((string) ($row['owner_type'] ?? ''));
        $ownerId = (int) ($row['owner_id'] ?? 0);
        $ownerName = '';

        if ($ownerType === 'dealer') {
            $ownerStmt = $conn->prepare('SELECT dealer_name, installation_status FROM dealers WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $ownerId);
            $ownerStmt->execute();
            $ownerData = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            $ownerName = $ownerData['dealer_name'] ?? '';
            $installationStatus = $ownerData['installation_status'] ?? '';
            if (!in_array(strtolower(trim((string) $installationStatus)), ['onsite', 'offsite'], true)) {
                continue;
            }
        } elseif ($ownerType === 'technician') {
            $ownerStmt = $conn->prepare('SELECT technician_name FROM technicians WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $ownerId);
            $ownerStmt->execute();
            $ownerData = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            $ownerName = $ownerData['technician_name'] ?? '';
            $installationStatus = '';
        }

        $deviceCountSql = "
            SELECT
                COUNT(DISTINCT sa.device_id) AS total_count,
                COUNT(DISTINCT CASE WHEN
                    EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = sa.device_id)
                    OR EXISTS (
                        SELECT 1 FROM stock_transactions st
                        WHERE st.device_id = sa.device_id
                          AND st.from_owner_type = sa.owner_type
                          AND st.from_owner_id = sa.owner_id
                                                    AND st.id = (
                                                            SELECT MAX(st_latest.id)
                                                            FROM stock_transactions st_latest
                                                            WHERE st_latest.device_id = sa.device_id
                                                                AND st_latest.from_owner_type = sa.owner_type
                                                                AND st_latest.from_owner_id = sa.owner_id
                                                    )
                                                    AND st.transaction_type = 'USE'
                    )
                THEN sa.device_id END) AS used_count
            FROM stock_allocations sa
            WHERE sa.owner_type = ? AND sa.owner_id = ? AND sa.device_id IS NOT NULL
        ";
        $deviceStmt = $conn->prepare($deviceCountSql);
        $deviceStmt->bind_param('si', $ownerType, $ownerId);
        $deviceStmt->execute();
        $deviceData = $deviceStmt->get_result()->fetch_assoc() ?: [];
        $deviceStmt->close();
        $totalDeviceCount = (int) ($deviceData['total_count'] ?? 0);
        $usedDeviceCount = (int) ($deviceData['used_count'] ?? 0);
        $deviceCount = max(0, $totalDeviceCount - $usedDeviceCount);

        $simCountSql = "
            SELECT
                COUNT(DISTINCT sa.sim_id) AS total_count,
                COUNT(DISTINCT CASE WHEN
                    EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = sa.sim_id OR cvd.sim_id_2 = sa.sim_id)
                    OR EXISTS (
                        SELECT 1 FROM stock_transactions st
                        WHERE st.sim_id = sa.sim_id
                          AND st.from_owner_type = sa.owner_type
                          AND st.from_owner_id = sa.owner_id
                                                    AND st.id = (
                                                            SELECT MAX(st_latest.id)
                                                            FROM stock_transactions st_latest
                                                            WHERE st_latest.sim_id = sa.sim_id
                                                                AND st_latest.from_owner_type = sa.owner_type
                                                                AND st_latest.from_owner_id = sa.owner_id
                                                    )
                                                    AND st.transaction_type = 'USE'
                    )
                THEN sa.sim_id END) AS used_count
            FROM stock_allocations sa
            WHERE sa.owner_type = ? AND sa.owner_id = ? AND sa.sim_id IS NOT NULL
        ";
        $simStmt = $conn->prepare($simCountSql);
        $simStmt->bind_param('si', $ownerType, $ownerId);
        $simStmt->execute();
        $simData = $simStmt->get_result()->fetch_assoc() ?: [];
        $simStmt->close();
        $totalSimCount = (int) ($simData['total_count'] ?? 0);
        $usedSimCount = (int) ($simData['used_count'] ?? 0);
        $simCount = max(0, $totalSimCount - $usedSimCount);

        $minimumDevice = (int) ($row['minimum_device_count'] ?? 0);
        $minimumSim = (int) ($row['minimum_sim_count'] ?? 0);

        $deviceStatus = 'SAFE';
        if ($deviceCount < $minimumDevice) {
            $deviceStatus = 'ALERT';
        } elseif ($deviceCount == $minimumDevice) {
            $deviceStatus = 'WARNING';
        }

        $simStatus = 'SAFE';
        if ($simCount < $minimumSim) {
            $simStatus = 'ALERT';
        } elseif ($simCount == $minimumSim) {
            $simStatus = 'WARNING';
        }

        $overallStatus = 'SAFE';
        if ($deviceStatus === 'ALERT' || $simStatus === 'ALERT') {
            $overallStatus = 'ALERT';
        } elseif ($deviceStatus === 'WARNING' || $simStatus === 'WARNING') {
            $overallStatus = 'WARNING';
        }

        $owners[] = [
            'id' => (int) $row['id'],
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'installation_status' => $installationStatus,
            'minimum_device_count' => $minimumDevice,
            'minimum_sim_count' => $minimumSim,
            'notes' => $row['notes'] ?? '',
            'total_device_count' => $totalDeviceCount,
            'used_device_count' => $usedDeviceCount,
            'available_device_count' => $deviceCount,
            'total_sim_count' => $totalSimCount,
            'used_sim_count' => $usedSimCount,
            'available_sim_count' => $simCount,
            'device_status' => $deviceStatus,
            'sim_status' => $simStatus,
            'status' => $overallStatus,
        ];
    }
}

usort($owners, function ($a, $b) {
    $statusOrder = ['ALERT' => 0, 'WARNING' => 1, 'SAFE' => 2];
    $statusDiff = ($statusOrder[$a['status']] ?? 99) - ($statusOrder[$b['status']] ?? 99);
    if ($statusDiff !== 0) {
        return $statusDiff;
    }

    return strcmp(($a['owner_name'] ?? ''), ($b['owner_name'] ?? ''));
});

$conn->close();

sendResponse(true, 'Device alert list fetched successfully', ['owners' => $owners]);
