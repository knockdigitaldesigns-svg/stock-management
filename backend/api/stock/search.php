<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$searchValue = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'));
    if (isset($body->search)) {
        $searchValue = trim((string) $body->search);
    }
} else {
    $searchValue = isset($_GET['q']) ? trim((string) $_GET['q']) : (isset($_GET['search']) ? trim((string) $_GET['search']) : '');
}

if ($searchValue === '') {
    sendResponse(false, "No device/SIM found for this number.", [], [], 404);
}

$searchValue = preg_replace('/\s+/', '', $searchValue);
$devicePattern = '/^\d{15}$/';
$simPattern = '/^(\d{10}|\d{13})$/';

if (preg_match($devicePattern, $searchValue)) {
    $stmt = $conn->prepare("
        SELECT
            d.id,
            d.imei_no,
            d.purchase_date,
            d.notes,
            d.status,
            dm.device_type AS device_model,
            sa.owner_type,
            sa.owner_id,
            CASE
                WHEN sa.owner_type = 'dealer' THEN dl.dealer_name
                WHEN sa.owner_type = 'technician' THEN t.technician_name
                ELSE 'Warehouse'
            END AS owner_name
        FROM devices d
        JOIN device_types dm ON dm.id = d.device_model_id
        LEFT JOIN stock_allocations sa ON sa.device_id = d.id
        LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
        WHERE d.imei_no = ?
        ORDER BY sa.created_at DESC, d.id DESC
        LIMIT 1
    ");

    $stmt->bind_param('s', $searchValue);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $payload = [
            'id' => (int) $row['id'],
            'type' => 'device',
            'imei_no' => $row['imei_no'],
            'device_model' => $row['device_model'],
            'purchase_date' => $row['purchase_date'],
            'notes' => $row['notes'] ?? '',
            'owner_id' => $row['owner_id'] ? (int) $row['owner_id'] : null,
            'owner_name' => $row['owner_name'] ?? 'Warehouse',
            'owner_type' => $row['owner_type'] ?: null,
            'status' => strtolower($row['status'] ?? 'available')
        ];
        sendResponse(true, 'Device found', ['type' => 'device', 'data' => $payload]);
    }

    $stmt->close();
    sendResponse(false, 'No device/SIM found for this number.', [], [], 404);
}

if (preg_match($simPattern, $searchValue)) {
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.sim_no,
            s.purchase_date,
            s.notes,
            s.status,
            sa.owner_type,
            sa.owner_id,
            CASE
                WHEN sa.owner_type = 'dealer' THEN dl.dealer_name
                WHEN sa.owner_type = 'technician' THEN t.technician_name
                ELSE 'Warehouse'
            END AS owner_name
        FROM sims s
        LEFT JOIN stock_allocations sa ON sa.sim_id = s.id
        LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = 'dealer'
        LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
        WHERE s.sim_no = ?
        ORDER BY sa.created_at DESC, s.id DESC
        LIMIT 1
    ");

    $stmt->bind_param('s', $searchValue);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $payload = [
            'id' => (int) $row['id'],
            'type' => 'sim',
            'sim_no' => $row['sim_no'],
            'purchase_date' => $row['purchase_date'],
            'notes' => $row['notes'] ?? '',
            'owner_id' => $row['owner_id'] ? (int) $row['owner_id'] : null,
            'owner_name' => $row['owner_name'] ?? 'Warehouse',
            'owner_type' => $row['owner_type'] ?: null,
            'status' => strtolower($row['status'] ?? 'available')
        ];
        sendResponse(true, 'SIM found', ['type' => 'sim', 'data' => $payload]);
    }

    $stmt->close();
    sendResponse(false, 'No device/SIM found for this number.', [], [], 404);
}

sendResponse(false, 'No device/SIM found for this number.', [], [], 404);
?>
