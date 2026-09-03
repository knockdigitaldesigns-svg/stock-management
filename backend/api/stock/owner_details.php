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
        SUM(CASE WHEN sa.device_id IS NOT NULL AND LOWER(COALESCE(d.status, '')) IN ('used', 'deactive', 'inactive') THEN 1 ELSE 0 END) AS used_device,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND LOWER(COALESCE(s.status, '')) IN ('used', 'deactive', 'inactive') THEN 1 ELSE 0 END) AS used_sim,
        SUM(CASE WHEN sa.device_id IS NOT NULL AND LOWER(COALESCE(d.status, '')) IN ('available', 'allocated', 'active') THEN 1 ELSE 0 END) AS available_device,
        SUM(CASE WHEN sa.sim_id IS NOT NULL AND LOWER(COALESCE(s.status, '')) IN ('available', 'allocated', 'active') THEN 1 ELSE 0 END) AS available_sim
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
    SELECT d.id, sa.id AS allocation_id, d.imei_no, d.purchase_date, sa.allocation_date, d.notes, sa.notes AS allocation_notes, dm.device_type AS model_name, d.status, sa.software, sa.total_amount, sa.amount_paid, sa.pending_amount, sa.payment_status, sa.payment_mode
    FROM stock_allocations sa
    JOIN devices d ON d.id = sa.device_id
    JOIN device_types dm ON dm.id = d.device_model_id
    WHERE sa.owner_type = ? AND sa.owner_id = ?
    ORDER BY d.purchase_date DESC, d.imei_no ASC
";

$deviceStmt = $conn->prepare($deviceSql);
$deviceStmt->bind_param('si', $ownerType, $ownerId);
$deviceStmt->execute();
$deviceResult = $deviceStmt->get_result();
$devices = [];
while ($row = $deviceResult->fetch_assoc()) {
    $totalAmount = (float) ($row['total_amount'] ?? 0); $amountPaid = (float) ($row['amount_paid'] ?? 0); $pendingAmount = max(0, $totalAmount - $amountPaid);
    $devices[] = [
        'id' => (int) $row['id'],
        'allocation_id' => (int) $row['allocation_id'],
        'imei_no' => $row['imei_no'],
        'purchase_date' => $row['purchase_date'],
        'allocation_date' => $row['allocation_date'],
        'notes' => $row['allocation_notes'] ?? $row['notes'] ?? '',
        'model_name' => $row['model_name'],
        'status' => $row['status'], 'software' => $row['software'], 'total_amount' => $totalAmount, 'amount_paid' => $amountPaid, 'pending_amount' => $pendingAmount, 'payment_status' => $totalAmount <= 0 ? null : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid')), 'payment_mode' => $row['payment_mode']
    ];
}
$deviceStmt->close();

$simSql = "
    SELECT s.id, sa.id AS allocation_id, s.sim_no, s.purchase_date, sa.allocation_date, s.notes, sa.notes AS allocation_notes, s.sim_type, v.months AS sim_validity_months, s.status, sa.software, sa.total_amount, sa.amount_paid, sa.pending_amount, sa.payment_status, sa.payment_mode
    FROM stock_allocations sa
    JOIN sims s ON s.id = sa.sim_id
    LEFT JOIN sim_validities v ON v.id = s.sim_validity_id
    WHERE sa.owner_type = ? AND sa.owner_id = ?
    ORDER BY s.purchase_date DESC, s.sim_no ASC
";

$simStmt = $conn->prepare($simSql);
$simStmt->bind_param('si', $ownerType, $ownerId);
$simStmt->execute();
$simResult = $simStmt->get_result();
$sims = [];
while ($row = $simResult->fetch_assoc()) {
    $totalAmount = (float) ($row['total_amount'] ?? 0); $amountPaid = (float) ($row['amount_paid'] ?? 0); $pendingAmount = max(0, $totalAmount - $amountPaid);
    $sims[] = [
        'id' => (int) $row['id'],
        'allocation_id' => (int) $row['allocation_id'],
        'sim_no' => $row['sim_no'],
        'purchase_date' => $row['purchase_date'],
        'allocation_date' => $row['allocation_date'],
        'notes' => $row['allocation_notes'] ?? $row['notes'] ?? '',
        'sim_type' => $row['sim_type'],
        'sim_validity_months' => $row['sim_validity_months'] !== null ? (int) $row['sim_validity_months'] : null,
        'status' => $row['status'], 'software' => $row['software'], 'total_amount' => $totalAmount, 'amount_paid' => $amountPaid, 'pending_amount' => $pendingAmount, 'payment_status' => $totalAmount <= 0 ? null : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid')), 'payment_mode' => $row['payment_mode']
    ];
}
$simStmt->close();

$conn->close();

sendResponse(true, "Owner stock details fetched", [
    'summary' => $summary,
    'devices' => $devices,
    'sims' => $sims
]);
?>
