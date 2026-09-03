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
$sql = "SELECT t.technician_name, d.imei_no, dm.device_type AS model_name, d.purchase_date AS device_purchase_date, sa.notes, sa.allocation_date FROM stock_allocations sa JOIN devices d ON sa.device_id = d.id JOIN device_types dm ON d.device_model_id = dm.id JOIN technicians t ON sa.owner_type = 'technician' AND sa.owner_id = t.id";
if ($technicianId) { $stmt = $conn->prepare($sql . ' WHERE t.id = ? ORDER BY sa.allocation_date DESC'); $stmt->bind_param('i', $technicianId); $stmt->execute(); $result = $stmt->get_result(); } else { $result = $conn->query($sql . ' ORDER BY sa.allocation_date DESC'); }
$report = [];
while ($result && ($row = $result->fetch_assoc())) $report[] = $row;
if (isset($stmt)) $stmt->close();
$conn->close();
sendResponse(true, 'Technician device report fetched', ['report' => $report]);
?>
