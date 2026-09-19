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
        SELECT s.id, s.owner_type, s.owner_id, s.asset_type, s.device_model_id, s.sim_type_id, s.min_count,
            s.minimum_device_count, s.minimum_sim_count, s.notes,
           dt.device_type AS device_model_name, st.sim_type AS sim_type_name
    FROM stock_alert_settings s
    LEFT JOIN device_types dt ON s.device_model_id = dt.id
    LEFT JOIN sim_types st ON s.sim_type_id = st.id
    ORDER BY s.owner_type ASC, s.owner_id ASC
";

$configResult = $conn->query($configSql);
$owners = [];

if ($configResult) {
    while ($row = $configResult->fetch_assoc()) {
        $ownerType = strtolower((string) ($row['owner_type'] ?? ''));
        $ownerId = (int) ($row['owner_id'] ?? 0);
        $assetType = strtolower((string) ($row['asset_type'] ?? ''));
        $legacyDeviceMinimum = (int) ($row['minimum_device_count'] ?? 0);
        $legacySimMinimum = (int) ($row['minimum_sim_count'] ?? 0);
        if (!in_array($assetType, ['device', 'sim', 'both'], true) || ($assetType === 'device' && empty($row['device_model_id']) && empty($row['sim_type_id']) && $legacySimMinimum > 0)) {
            $assetType = $legacyDeviceMinimum > 0 && $legacySimMinimum > 0 ? 'both' : ($legacySimMinimum > 0 ? 'sim' : 'device');
        }
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

        $deviceAvailableCount = 0;
        $simAvailableCount = 0;
        
        if (in_array($assetType, ['device', 'both'], true) && !empty($row['device_model_id'])) {
            $deviceModelId = (int) $row['device_model_id'];
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
                JOIN devices d ON sa.device_id = d.id
                WHERE sa.owner_type = ? AND sa.owner_id = ? AND sa.device_id IS NOT NULL AND d.device_model_id = ?
            ";
            $deviceStmt = $conn->prepare($deviceCountSql);
            $deviceStmt->bind_param('sii', $ownerType, $ownerId, $deviceModelId);
            $deviceStmt->execute();
            $deviceData = $deviceStmt->get_result()->fetch_assoc() ?: [];
            $deviceStmt->close();
            $totalCount = (int) ($deviceData['total_count'] ?? 0);
            $usedCount = (int) ($deviceData['used_count'] ?? 0);
            $deviceAvailableCount = max(0, $totalCount - $usedCount);
        }
        if (in_array($assetType, ['sim', 'both'], true) && !empty($row['sim_type_id'])) {
            $simTypeId = (int) $row['sim_type_id'];
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
                JOIN sims s ON sa.sim_id = s.id
                JOIN sim_types st ON s.sim_type = st.sim_type
                WHERE sa.owner_type = ? AND sa.owner_id = ? AND sa.sim_id IS NOT NULL AND st.id = ?
            ";
            $simStmt = $conn->prepare($simCountSql);
            $simStmt->bind_param('sii', $ownerType, $ownerId, $simTypeId);
            $simStmt->execute();
            $simData = $simStmt->get_result()->fetch_assoc() ?: [];
            $simStmt->close();
            $totalCount = (int) ($simData['total_count'] ?? 0);
            $usedCount = (int) ($simData['used_count'] ?? 0);
            $simAvailableCount = max(0, $totalCount - $usedCount);
        }

        $minimumDeviceCount = (int) ($row['minimum_device_count'] ?? 0);
        $minimumSimCount = (int) ($row['minimum_sim_count'] ?? 0);
        if ($minimumDeviceCount === 0 && $assetType === 'device') {
            $minimumDeviceCount = (int) ($row['min_count'] ?? 0);
        }
        if ($minimumSimCount === 0 && $assetType === 'sim') {
            $minimumSimCount = (int) ($row['min_count'] ?? 0);
        }

        $deviceStatus = $assetType === 'sim' ? null : ($deviceAvailableCount < $minimumDeviceCount ? 'ALERT' : ($deviceAvailableCount === $minimumDeviceCount ? 'WARNING' : 'SAFE'));
        $simStatus = $assetType === 'device' ? null : ($simAvailableCount < $minimumSimCount ? 'ALERT' : ($simAvailableCount === $minimumSimCount ? 'WARNING' : 'SAFE'));
        $statuses = array_filter([$deviceStatus, $simStatus]);
        $status = in_array('ALERT', $statuses, true) ? 'ALERT' : (in_array('WARNING', $statuses, true) ? 'WARNING' : 'SAFE');

        $owners[] = [
            'id' => (int) $row['id'],
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'installation_status' => $installationStatus,
            'asset_type' => $assetType,
            'device_model_id' => $row['device_model_id'] ? (int)$row['device_model_id'] : null,
            'sim_type_id' => $row['sim_type_id'] ? (int)$row['sim_type_id'] : null,
            'device_model_name' => $row['device_model_name'] ?? '',
            'sim_type_name' => $row['sim_type_name'] ?? '',
            'min_count' => (int) ($row['min_count'] ?? 0),
            'minimum_device_count' => $minimumDeviceCount,
            'minimum_sim_count' => $minimumSimCount,
            'notes' => $row['notes'] ?? '',
            'device_available_count' => $deviceAvailableCount,
            'sim_available_count' => $simAvailableCount,
            'available_count' => $assetType === 'sim' ? $simAvailableCount : $deviceAvailableCount,
            'device_status' => $deviceStatus,
            'sim_status' => $simStatus,
            'status' => $status,
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
