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
        d.dealer_name,
        s.sim_no,
        s.sim_type,
        v.months AS sim_validity_months,
        s.purchase_date as sim_purchase_date,
        sa.notes,
        sa.allocation_date
    FROM stock_allocations sa
    JOIN sims s ON sa.sim_id = s.id
    LEFT JOIN sim_validities v ON v.id = s.sim_validity_id
    JOIN dealers d ON sa.owner_type = 'dealer' AND sa.owner_id = d.id
";

if ($dealer_id) {
    $sql .= " WHERE d.id = ?";
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

sendResponse(true, "Dealer SIM report fetched", ["report" => $report]);

if (isset($stmt)) $stmt->close();
$conn->close();
?>
