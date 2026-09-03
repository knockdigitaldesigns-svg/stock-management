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

$summary = [
    'total' => 0,
    'available' => 0,
    'allocated' => 0,
    'used' => 0
];

$sql = "SELECT status, COUNT(id) as count FROM sims GROUP BY status";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['count'];
        $summary['total'] += (int)$row['count'];
    }
}

sendResponse(true, "SIM summary fetched", ["summary" => $summary]);
$conn->close();
?>
