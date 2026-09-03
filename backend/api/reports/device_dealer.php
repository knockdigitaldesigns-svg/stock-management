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

$dealer_id = isset($_GET['dealer_id']) && !empty($_GET['dealer_id']) ? $_GET['dealer_id'] : null;

$sql = "
    SELECT 
        dl.dealer_name,
        d.imei_no,
        dm.device_type AS model_name,
        d.purchase_date as device_purchase_date,
        sa.notes,
        sa.allocation_date
    FROM stock_allocations sa
    JOIN devices d ON sa.device_id = d.id
    JOIN device_types dm ON d.device_model_id = dm.id
    JOIN dealers dl ON sa.owner_type = 'dealer' AND sa.owner_id = dl.id
";

if ($dealer_id) {
    $sql .= " WHERE dl.id = ?";
    $stmt = $conn->prepare($sql . " ORDER BY sa.allocation_date DESC");
    $stmt->bind_param("i", $dealer_id);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql . " ORDER BY sa.allocation_date DESC");
}

$report = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $report[] = $row;
    }
}

sendResponse(true, "Dealer device report fetched", ["report" => $report]);

if (isset($stmt)) $stmt->close();
$conn->close();
?>
