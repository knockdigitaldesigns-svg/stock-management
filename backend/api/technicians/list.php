<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Calculate Device and SIM counts dynamically for technicians
$sql = "
    SELECT 
        t.id, 
        t.technician_name, 
        t.mobile_no, 
        t.location, 
        t.enrolled_date, 
        t.notes,
        COALESCE((SELECT COUNT(*) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND device_id IS NOT NULL), 0) as device_count,
        COALESCE((SELECT COUNT(*) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id AND sim_id IS NOT NULL), 0) as sim_count,
        CASE
            WHEN COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) <= 0 THEN NULL
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) >= COALESCE((SELECT SUM(total_amount) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) THEN 'Paid'
            WHEN COALESCE((SELECT SUM(amount_paid) FROM stock_allocations WHERE owner_type='technician' AND owner_id=t.id), 0) > 0 THEN 'Partially Paid'
            ELSE 'Not Paid'
        END as payment_status
    FROM technicians t
    ORDER BY t.created_at DESC
";
$result = $conn->query($sql);

$technicians = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $technicians[] = $row;
    }
}

sendResponse(true, "Technicians fetched successfully", ["technicians" => $technicians]);

$conn->close();
?>
