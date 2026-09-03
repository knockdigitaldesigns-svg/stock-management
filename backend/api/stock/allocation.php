<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) sendResponse(false, 'Valid allocation ID is required', [], [], 400);

$db = new Database();
$conn = $db->getConnection();
$sql = "
    SELECT sa.*, d.dealer_name, d.mobile_no AS dealer_mobile, t.technician_name, t.mobile_no AS technician_mobile,
           dev.imei_no, dev.status AS device_status, dt.device_type AS device_model, dev.purchase_date AS device_purchase_date,
           s.sim_no, s.sim_type, s.status AS sim_status, sv.months AS sim_validity_months, s.purchase_date AS sim_purchase_date
    FROM stock_allocations sa
    LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = 'dealer'
    LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
    LEFT JOIN devices dev ON dev.id = sa.device_id
    LEFT JOIN device_types dt ON dt.id = dev.device_model_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    LEFT JOIN sim_validities sv ON sv.id = s.sim_validity_id
    WHERE sa.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$row) sendResponse(false, 'Stock allocation not found', [], [], 404);
foreach (['device_amount', 'sim_amount', 'total_amount', 'amount_paid', 'pending_amount'] as $key) $row[$key] = (float) ($row[$key] ?? 0);
$row['pending_amount'] = max(0, $row['total_amount'] - $row['amount_paid']);
$row['payment_status'] = $row['total_amount'] <= 0 ? null : ($row['pending_amount'] <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partially Paid' : 'Not Paid'));
$row['allocation_id'] = (int) $row['id'];
$row['owner_id'] = (int) $row['owner_id'];
sendResponse(true, 'Stock allocation fetched', ['allocation' => $row]);
?>
