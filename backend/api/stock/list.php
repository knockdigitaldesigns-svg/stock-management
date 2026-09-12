<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

$sql = "
    SELECT sa.id AS allocation_id, sa.owner_type, sa.owner_id, sa.allocation_type, sa.allocation_date,
           sa.device_id, sa.sim_id, sa.device_amount, sa.sim_amount, sa.total_amount,
           sa.amount_paid, sa.pending_amount, sa.software, sa.payment_status, sa.payment_mode, sa.notes,
           d.dealer_name, d.installation_status AS dealer_installation_status,
           t.technician_name, dev.imei_no, dev.status AS device_status,
           dt.device_type AS device_model, s.sim_no, s.sim_type, sv.months AS sim_validity_months,
           s.status AS sim_status
    FROM stock_allocations sa
    LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = 'dealer'
    LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = 'technician'
    LEFT JOIN devices dev ON dev.id = sa.device_id
    LEFT JOIN device_types dt ON dt.id = dev.device_model_id
    LEFT JOIN sims s ON s.id = sa.sim_id
    LEFT JOIN sim_validities sv ON sv.id = s.sim_validity_id
    WHERE sa.owner_type = 'technician'
       OR (sa.owner_type = 'dealer' AND d.installation_status IN ('Onsite', 'Offsite'))
    ORDER BY sa.created_at DESC, sa.id DESC
";
$result = $conn->query($sql);
$allocations = [];
while ($result && ($row = $result->fetch_assoc())) {
    $row['allocation_id'] = (int) $row['allocation_id'];
    $row['owner_id'] = (int) $row['owner_id'];
    foreach (['device_id', 'sim_id'] as $key) $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
    foreach (['device_amount', 'sim_amount', 'total_amount', 'amount_paid', 'pending_amount'] as $key) $row[$key] = (float) ($row[$key] ?? 0);
    $row['pending_amount'] = max(0, $row['total_amount'] - $row['amount_paid']);
    $row['payment_status'] = $row['total_amount'] <= 0 ? null : ($row['pending_amount'] <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partially Paid' : 'Not Paid'));
    $row['owner_name'] = $row['owner_type'] === 'dealer' ? $row['dealer_name'] : $row['technician_name'];
    $row['item_type'] = $row['device_id'] !== null ? 'Device' : 'SIM';
    
    // Calculate asset status
    $rawStatus = $row['device_id'] !== null ? $row['device_status'] : $row['sim_status'];
    $row['asset_status'] = !empty($rawStatus) ? ucfirst($rawStatus) : 'Allocated';

    $allocations[] = $row;
}
$conn->close();
sendResponse(true, 'Stock allocations fetched', ['allocations' => $allocations]);
?>
