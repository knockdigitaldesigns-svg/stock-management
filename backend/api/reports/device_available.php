<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, "Method not allowed", [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, "Database connection failed", [], [], 500);

$sql = "
    SELECT 
        d.id, 
        d.imei_no, 
        d.purchase_date,
        d.notes,
        dm.device_type AS model_name
    FROM devices d
    JOIN device_types dm ON d.device_model_id = dm.id
    WHERE LOWER(COALESCE(d.status, 'available')) IN ('available', 'active')
    ORDER BY d.purchase_date DESC
";
$result = $conn->query($sql);
$devices = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
}

sendResponse(true, "Available devices fetched", ["devices" => $devices]);
$conn->close();
?>
