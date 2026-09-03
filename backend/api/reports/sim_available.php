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

$sql = "SELECT s.id, s.sim_no, s.purchase_date, s.sim_type, s.sim_validity_id, s.notes, v.months AS sim_validity_months FROM sims s LEFT JOIN sim_validities v ON v.id = s.sim_validity_id WHERE LOWER(COALESCE(s.status, 'available')) IN ('available', 'active') ORDER BY s.purchase_date DESC";
$result = $conn->query($sql);
$sims = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sims[] = $row;
    }
}

sendResponse(true, "Available SIMs fetched", ["sims" => $sims]);
$conn->close();
?>
