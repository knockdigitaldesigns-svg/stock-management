<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

authenticate();

$imei = trim((string) ($_GET['imei'] ?? ''));

if ($imei === '' || !preg_match('/^\d{15}$/', $imei)) {
    sendResponse(false, 'IMEI not found in device stock.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare(
    "SELECT d.id, d.imei_no, d.device_model_id, d.status, dt.device_type AS device_model,
            sa.owner_type, sa.owner_id,
            CASE
                WHEN sa.owner_type = 'dealer' THEN dl.dealer_name
                WHEN sa.owner_type = 'technician' THEN t.technician_name
                ELSE NULL
            END AS owner_name,
            dl.installation_status AS owner_installation_status,
            cvd.id AS customer_vehicle_id,
            (SELECT st.usage_type FROM stock_transactions st WHERE st.device_id = d.id AND st.transaction_type = 'USE' ORDER BY st.id DESC LIMIT 1) AS usage_type
     FROM devices d
     JOIN device_types dt ON dt.id = d.device_model_id
     LEFT JOIN stock_allocations sa ON sa.id = (
        SELECT latest_sa.id FROM stock_allocations latest_sa
        WHERE latest_sa.device_id = d.id
        ORDER BY latest_sa.created_at DESC, latest_sa.id DESC LIMIT 1
     )
     LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = 'dealer'
     LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
     LEFT JOIN customer_vehicle_details cvd ON cvd.device_id = d.id
     WHERE d.imei_no = ?
     LIMIT 1"
);

if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare IMEI lookup query.', [], [], 500);
}

$stmt->bind_param('s', $imei);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'IMEI not found in device stock.', [], [], 404);
}

$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

$status = strtolower(trim((string) ($row['status'] ?? '')));
$usageType = !empty($row['customer_vehicle_id']) ? 'ET' : strtoupper(trim((string) ($row['usage_type'] ?? '')));
$isUsed = !empty($row['customer_vehicle_id']) || in_array($usageType, ['ET', 'TECHNICIAN', 'DEALER'], true);

sendResponse(true, 'IMEI lookup successful', [
    'id' => (int) $row['id'],
    'imei_no' => $row['imei_no'],
    'device_model_id' => (int) $row['device_model_id'],
    'device_model' => $row['device_model'],
    'status' => $status,
    'usage_type' => $usageType ?: null,
    'is_used' => $isUsed,
    'owner_type' => $row['owner_type'] ?? null,
    'owner_id' => $row['owner_id'] ? (int) $row['owner_id'] : null,
    'owner_name' => $row['owner_name'] ?? null,
    'owner_installation_status' => $row['owner_installation_status'] ?? null
]);
?>
