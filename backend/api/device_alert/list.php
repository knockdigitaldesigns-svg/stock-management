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
        } elseif ($ownerType === 'technician') {
            $ownerStmt = $conn->prepare('SELECT technician_name FROM technicians WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $ownerId);
            $ownerStmt->execute();
            $ownerData = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            $ownerName = $ownerData['technician_name'] ?? '';
            $installationStatus = '';
        }

        $deviceAvailableSql = "
            SELECT COUNT(*) AS available_count
            FROM stock_allocations sa
            INNER JOIN devices d ON d.id = sa.device_id
            WHERE sa.owner_type = ? AND sa.owner_id = ? AND d.status IN ('available', 'allocated', 'active')
        ";
        $deviceStmt = $conn->prepare($deviceAvailableSql);
        $deviceStmt->bind_param('si', $ownerType, $ownerId);
        $deviceStmt->execute();
        $deviceCount = (int) $deviceStmt->get_result()->fetch_assoc()['available_count'];
        $deviceStmt->close();

        $simAvailableSql = "
            SELECT COUNT(*) AS available_count
            FROM stock_allocations sa
            INNER JOIN sims s ON s.id = sa.sim_id
            WHERE sa.owner_type = ? AND sa.owner_id = ? AND s.status IN ('available', 'allocated', 'active')
        ";
        $simStmt = $conn->prepare($simAvailableSql);
        $simStmt->bind_param('si', $ownerType, $ownerId);
        $simStmt->execute();
        $simCount = (int) $simStmt->get_result()->fetch_assoc()['available_count'];
        $simStmt->close();

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
            'available_device_count' => $deviceCount,
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
