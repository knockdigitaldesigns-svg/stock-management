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
$technicianId = isset($_GET['technician_id']) && $_GET['technician_id'] !== '' ? (int) $_GET['technician_id'] : null;
$sql = "SELECT t.technician_name, s.sim_no, s.sim_type, v.months AS sim_validity_months, s.purchase_date AS sim_purchase_date, COALESCE(sa.sim_status, 'Available') AS sim_status, sa.notes, sa.allocation_date FROM stock_allocations sa JOIN sims s ON sa.sim_id = s.id LEFT JOIN sim_validities v ON v.id = s.sim_validity_id JOIN technicians t ON sa.owner_type = 'technician' AND sa.owner_id = t.id";
if ($technicianId) { $stmt = $conn->prepare($sql . ' WHERE t.id = ? ORDER BY sa.allocation_date DESC'); $stmt->bind_param('i', $technicianId); $stmt->execute(); $result = $stmt->get_result(); } else { $result = $conn->query($sql . ' ORDER BY sa.allocation_date DESC'); }
$report = [];
while ($result && ($row = $result->fetch_assoc())) $report[] = $row;
if (isset($stmt)) $stmt->close();
$conn->close();
sendResponse(true, 'Technician SIM report fetched', ['report' => $report]);
?>
