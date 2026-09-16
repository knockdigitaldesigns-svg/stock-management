<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$ownerType = isset($_GET['owner_type']) ? strtolower(trim((string) $_GET['owner_type'])) : '';
$ownerId = isset($_GET['owner_id']) ? (int) $_GET['owner_id'] : 0;
$includeAvailable = isset($_GET['include_available']) ? filter_var($_GET['include_available'], FILTER_VALIDATE_BOOLEAN) : false;

if (!in_array($ownerType, ['dealer', 'technician'], true) || $ownerId <= 0) {
    sendResponse(false, "Valid owner_type and owner_id are required", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$summarySql = "
    SELECT
        COUNT(CASE WHEN sa.device_id IS NOT NULL THEN 1 END) AS total_device,
        COUNT(CASE WHEN sa.sim_id IS NOT NULL THEN 1 END) AS total_sim,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id = sa.device_id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE')) THEN 1 ELSE 0 END) AS used_device,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = sa.sim_id OR cvd.sim_id_2 = sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id = sa.sim_id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE')) THEN 1 ELSE 0 END) AS used_sim,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND NOT (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = sa.device_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id = sa.device_id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE')) THEN 1 ELSE 0 END) AS available_device,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND NOT (EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = sa.sim_id OR cvd.sim_id_2 = sa.sim_id) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id = sa.sim_id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE')) THEN 1 ELSE 0 END) AS available_sim
    FROM stock_allocations sa
    LEFT JOIN devices d ON d.id = sa.device_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    WHERE sa.owner_type = ? AND sa.owner_id = ?
";

$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param('si', $ownerType, $ownerId);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$summary = [
    'total_device' => (int) ($summaryResult['total_device'] ?? 0),
    'total_sim' => (int) ($summaryResult['total_sim'] ?? 0),
    'used_device' => (int) ($summaryResult['used_device'] ?? 0),
    'used_sim' => (int) ($summaryResult['used_sim'] ?? 0),
    'available_device' => (int) ($summaryResult['available_device'] ?? 0),
    'available_sim' => (int) ($summaryResult['available_sim'] ?? 0),
];

$deviceSql = "
        SELECT d.id, sa.id AS allocation_id, d.imei_no, d.purchase_date, sa.allocation_date, d.notes, sa.notes AS allocation_notes, dm.device_type AS model_name, d.status, sa.software, sa.total_amount, sa.amount_paid, sa.pending_amount, sa.payment_status, sa.payment_mode, sa.transaction_id,
            cvd.id AS customer_vehicle_id, c.username AS customer_name, cvd.vehicle_no,
            (SELECT st.usage_type FROM stock_transactions st WHERE st.device_id = d.id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE' ORDER BY st.id DESC LIMIT 1) AS usage_type
    FROM stock_allocations sa
    JOIN devices d ON d.id = sa.device_id
    JOIN device_types dm ON dm.id = d.device_model_id
    LEFT JOIN customer_vehicle_details cvd ON cvd.device_id = d.id
    LEFT JOIN customers c ON c.id = cvd.customer_id
    WHERE sa.owner_type = ? AND sa.owner_id = ?
    ORDER BY d.purchase_date DESC, d.imei_no ASC
";

$deviceStmt = $conn->prepare($deviceSql);
$deviceStmt->bind_param('si', $ownerType, $ownerId);
$deviceStmt->execute();
$deviceResult = $deviceStmt->get_result();
$devices = [];
while ($row = $deviceResult->fetch_assoc()) {
    $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
    $totalAmount = (float) ($row['total_amount'] ?? 0);
    $amountPaid = (float) ($row['amount_paid'] ?? 0);
    $pendingAmount = max(0, $totalAmount - $amountPaid);

    $devices[] = [
        'id' => (int) $row['id'],
        'allocation_id' => (int) $row['allocation_id'],
        'imei_no' => $row['imei_no'],
        'purchase_date' => $row['purchase_date'],
        'allocation_date' => $row['allocation_date'],
        'notes' => $row['allocation_notes'] ?? $row['notes'] ?? '',
        'model_name' => $row['model_name'],
        'status' => $row['status'],
        'software' => $row['software'],
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'pending_amount' => $pendingAmount,
        'payment_status' => $totalAmount <= 0 ? null : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid')),
        'payment_mode' => $row['payment_mode'],
        'transaction_id' => $row['transaction_id'],
        'is_current_owner' => true,
        'is_used_for_customer' => !empty($row['customer_vehicle_id']),
        'usage_type' => $row['customer_vehicle_id'] ? 'ET' : ($row['usage_type'] ?: null),
        'is_used_for_et' => !empty($row['customer_vehicle_id']) || ($row['usage_type'] ?? '') === 'ET',
        'customer_name' => $row['customer_name'] ? $row['customer_name'] . ' (' . $row['vehicle_no'] . ')' : null,
        'is_read_only' => !empty($row['customer_vehicle_id']) || !empty($row['usage_type']),
        'status_label' => !empty($row['customer_vehicle_id']) ? 'Used for ET' : ($row['usage_type'] ? 'Used for ' . ucfirst(strtolower($row['usage_type'])) : 'Available')
    ];
}
$deviceStmt->close();

if (false && $includeAvailable) {
    $availableDeviceSql = "
        SELECT d.id, d.imei_no, d.purchase_date, d.notes, d.status, dm.device_type AS model_name,
               NULL AS allocation_id, NULL AS allocation_date, NULL AS allocation_notes,
               NULL AS software, 0 AS total_amount, 0 AS amount_paid, 0 AS pending_amount,
               NULL AS payment_status, NULL AS payment_mode
        FROM devices d
        JOIN device_types dm ON dm.id = d.device_model_id
        WHERE d.status = 'available'
          AND NOT EXISTS (
              SELECT 1
              FROM stock_allocations sa
              WHERE sa.device_id = d.id
          )
        ORDER BY d.purchase_date DESC, d.imei_no ASC
    ";

    $availableDeviceStmt = $conn->prepare($availableDeviceSql);
    $availableDeviceStmt->execute();
    $availableDeviceResult = $availableDeviceStmt->get_result();
    while ($row = $availableDeviceResult->fetch_assoc()) {
        $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
        $devices[] = [
            'id' => (int) $row['id'],
            'allocation_id' => null,
            'imei_no' => $row['imei_no'],
            'purchase_date' => $row['purchase_date'],
            'allocation_date' => null,
            'notes' => $row['notes'] ?? '',
            'model_name' => $row['model_name'],
            'status' => $row['status'],
            'software' => null,
            'total_amount' => 0.0,
            'amount_paid' => 0.0,
            'pending_amount' => 0.0,
            'payment_status' => null,
            'payment_mode' => null,
            'is_current_owner' => false,
            'is_read_only' => false,
            'status_label' => 'Available'
        ];
    }
    $availableDeviceStmt->close();
}

$simSql = "
        SELECT s.id, sa.id AS allocation_id, s.sim_no, s.purchase_date, sa.allocation_date, sa.sim_given_date AS given_date, sa.sim_activation_date AS activation_date, sa.sim_expiry_date AS expiry_date, sa.sim_deactivation_date AS deactivation_date, sa.sim_status, s.notes, sa.notes AS allocation_notes, s.sim_type, COALESCE(sa.sim_validity_id, s.sim_validity_id) AS current_validity_id, COALESCE(sa.sim_validity_id, s.sim_validity_id) AS sim_validity_id, v.months AS sim_validity_months, s.status, sa.software, sa.total_amount, sa.amount_paid, sa.pending_amount, sa.payment_status, sa.payment_mode, sa.transaction_id,
            cvd.id AS customer_vehicle_id, c.username AS customer_name, cvd.vehicle_no,
            (SELECT st.usage_type FROM stock_transactions st WHERE st.sim_id = s.id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.transaction_type = 'USE' ORDER BY st.id DESC LIMIT 1) AS usage_type
    FROM stock_allocations sa
    JOIN sims s ON s.id = sa.sim_id
    LEFT JOIN sim_validities v ON v.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
    LEFT JOIN customer_vehicle_details cvd ON cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id
    LEFT JOIN customers c ON c.id = cvd.customer_id
    WHERE sa.owner_type = ? AND sa.owner_id = ?
    ORDER BY s.purchase_date DESC, s.sim_no ASC
";

$simStmt = $conn->prepare($simSql);
$simStmt->bind_param('si', $ownerType, $ownerId);
$simStmt->execute();
$simResult = $simStmt->get_result();
$sims = [];
while ($row = $simResult->fetch_assoc()) {
    $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
    $totalAmount = (float) ($row['total_amount'] ?? 0);
    $amountPaid = (float) ($row['amount_paid'] ?? 0);
    $pendingAmount = max(0, $totalAmount - $amountPaid);

    $sims[] = [
        'id' => (int) $row['id'],
        'allocation_id' => (int) $row['allocation_id'],
        'sim_no' => $row['sim_no'],
        'purchase_date' => $row['purchase_date'],
        'allocation_date' => $row['allocation_date'],
        'given_date' => $row['given_date'] ?? $row['allocation_date'] ?? null,
        'activation_date' => $row['activation_date'] ?? null,
        'validity_months' => $row['sim_validity_months'] !== null ? (int) $row['sim_validity_months'] : null,
        'expiry_date' => $row['expiry_date'] ?? null,
        'deactivation_date' => $row['deactivation_date'] ?? null,
        'sim_status' => $row['sim_status'] ?? null,
        'notes' => $row['allocation_notes'] ?? $row['notes'] ?? '',
        'sim_type' => $row['sim_type'],
        'sim_validity_months' => $row['sim_validity_months'] !== null ? (int) $row['sim_validity_months'] : null,
        'status' => $row['status'],
        'software' => $row['software'],
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'pending_amount' => $pendingAmount,
        'payment_status' => $totalAmount <= 0 ? null : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid')),
        'payment_mode' => $row['payment_mode'],
        'transaction_id' => $row['transaction_id'],
        'is_current_owner' => true,
        'is_used_for_customer' => !empty($row['customer_vehicle_id']),
        'usage_type' => $row['customer_vehicle_id'] ? 'ET' : ($row['usage_type'] ?: null),
        'is_used_for_et' => !empty($row['customer_vehicle_id']) || ($row['usage_type'] ?? '') === 'ET',
        'customer_name' => $row['customer_name'] ? $row['customer_name'] . ' (' . $row['vehicle_no'] . ')' : null,
        'is_read_only' => !empty($row['customer_vehicle_id']) || !empty($row['usage_type']),
        'status_label' => !empty($row['customer_vehicle_id']) ? 'Used for ET' : ($row['usage_type'] ? 'Used for ' . ucfirst(strtolower($row['usage_type'])) : 'Available')
    ];
}
$simStmt->close();

if (false && $includeAvailable) {
    $availableSimSql = "
        SELECT s.id, s.sim_no, s.purchase_date, s.notes, s.status, s.sim_type, v.months AS sim_validity_months,
               NULL AS allocation_id, NULL AS allocation_date, NULL AS allocation_notes,
               NULL AS software, 0 AS total_amount, 0 AS amount_paid, 0 AS pending_amount,
               NULL AS payment_status, NULL AS payment_mode
        FROM sims s
        LEFT JOIN sim_validities v ON v.id = s.sim_validity_id
        WHERE s.status = 'available'
          AND NOT EXISTS (
              SELECT 1
              FROM stock_allocations sa
              WHERE sa.sim_id = s.id
          )
        ORDER BY s.purchase_date DESC, s.sim_no ASC
    ";

    $availableSimStmt = $conn->prepare($availableSimSql);
    $availableSimStmt->execute();
    $availableSimResult = $availableSimStmt->get_result();
    while ($row = $availableSimResult->fetch_assoc()) {
        $row['status'] = strtolower((string) ($row['status'] ?? 'available'));
        $sims[] = [
            'id' => (int) $row['id'],
            'allocation_id' => null,
            'sim_no' => $row['sim_no'],
            'purchase_date' => $row['purchase_date'],
            'allocation_date' => null,
            'notes' => $row['notes'] ?? '',
            'sim_type' => $row['sim_type'],
            'sim_validity_months' => $row['sim_validity_months'] !== null ? (int) $row['sim_validity_months'] : null,
            'status' => $row['status'],
            'software' => null,
            'total_amount' => 0.0,
            'amount_paid' => 0.0,
            'pending_amount' => 0.0,
            'payment_status' => null,
            'payment_mode' => null,
            'is_current_owner' => false,
            'is_read_only' => false,
            'status_label' => 'Available'
        ];
    }
    $availableSimStmt->close();
}

$cashStmt = $conn->prepare("SELECT ccc.id, ccc.customer_id, c.username, c.primary_mobile_no, ci.installation_date, p.platform_name, cv.validity_months, ccc.amount_collected, ccc.amount_remitted, ccc.pending_amount, ccc.settlement_status, ccc.settlement_date, ccc.payment_mode AS settlement_payment_mode, ccc.transaction_id AS settlement_transaction_id FROM customer_cash_collections ccc INNER JOIN customers c ON c.id = ccc.customer_id INNER JOIN customer_installations ci ON ci.id = ccc.installation_id LEFT JOIN platforms p ON p.id = c.platform_id LEFT JOIN customer_vehicle_details cv ON cv.id = (SELECT latest_cv.id FROM customer_vehicle_details latest_cv WHERE latest_cv.customer_id = c.id ORDER BY latest_cv.created_at DESC, latest_cv.id DESC LIMIT 1) WHERE ccc.recipient_type = ? AND ccc.recipient_id = ? ORDER BY ci.installation_date DESC, ccc.id DESC");
$cashStmt->bind_param('si', $ownerType, $ownerId);
$cashStmt->execute();
$cashResult = $cashStmt->get_result();
$cashCollections = [];
while ($cashRow = $cashResult->fetch_assoc()) {
    foreach (['amount_collected', 'amount_remitted', 'pending_amount'] as $field) $cashRow[$field] = (float) $cashRow[$field];
    $cashCollections[] = $cashRow;
}
$cashStmt->close();

$conn->close();

sendResponse(true, "Owner stock details fetched", [
    'summary' => $summary,
    'devices' => $devices,
    'sims' => $sims,
    'cash_collections' => $cashCollections
]);
?>
