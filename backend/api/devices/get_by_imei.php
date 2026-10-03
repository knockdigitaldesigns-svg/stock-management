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
$customerId = max(0, (int) ($_GET['customer_id'] ?? 0));
$vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? 0));

if ($imei === '' || !preg_match('/^\d{15}$/', $imei)) {
    sendResponse(false, 'IMEI not found in device stock.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$stmt = $conn->prepare(
    "SELECT d.id, d.imei_no, d.device_model_id,
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
            dt.device_type AS device_model,
            sa.owner_type, sa.owner_id,
            CASE
                WHEN sa.owner_type = 'dealer' THEN dl.dealer_name
                WHEN sa.owner_type = 'technician' THEN t.technician_name
                ELSE NULL
            END AS owner_name,
            dl.installation_status AS owner_installation_status,
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
$deviceId = (int) $row['id'];

$assignmentStmt = $conn->prepare(
    'SELECT
        EXISTS (
            SELECT 1 FROM customer_vehicle_details
            WHERE device_id = ? AND (? = 0 OR customer_id <> ?)
        ) AS assigned_to_another_customer,
        EXISTS (
            SELECT 1 FROM customer_vehicle_details
            WHERE device_id = ? AND customer_id = ? AND ? > 0 AND id = ?
        ) AS assigned_to_current_customer'
);
$assignmentStmt->bind_param('iiiiiii', $deviceId, $customerId, $customerId, $deviceId, $customerId, $vehicleId, $vehicleId);
$assignmentStmt->execute();
$assignment = $assignmentStmt->get_result()->fetch_assoc() ?: [];
$assignmentStmt->close();
$conn->close();

$status = strtolower(trim((string) ($row['status'] ?? '')));
$isAssignedToOtherCustomer = !empty($assignment['assigned_to_another_customer']);
$isAssignedToCurrentCustomer = !empty($assignment['assigned_to_current_customer']);
$usageType = ($isAssignedToOtherCustomer || $isAssignedToCurrentCustomer)
    ? 'ET'
    : strtoupper(trim((string) ($row['usage_type'] ?? '')));

sendResponse(true, 'IMEI lookup successful', [
    'id' => (int) $row['id'],
    'imei_no' => $row['imei_no'],
    'device_model_id' => (int) $row['device_model_id'],
    'device_model' => $row['device_model'],
    'status' => $status,
    'usage_type' => $usageType ?: null,
    'is_used' => $isAssignedToOtherCustomer,
    'is_assigned_to_another_customer' => $isAssignedToOtherCustomer,
    'is_assigned_to_current_customer' => $isAssignedToCurrentCustomer,
    'owner_type' => $row['owner_type'] ?? null,
    'owner_id' => $row['owner_id'] ? (int) $row['owner_id'] : null,
    'owner_name' => $row['owner_name'] ?? null,
    'owner_installation_status' => $row['owner_installation_status'] ?? null
]);
?>
