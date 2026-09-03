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

$sql = "SELECT status, COUNT(id) as count FROM devices GROUP BY status";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['count'];
        $summary['total'] += (int)$row['count'];
    }
}

$model_counts = [];
$sql_models = "
    SELECT dm.device_type AS model_name, COUNT(d.id) as count 
    FROM devices d 
    JOIN device_types dm ON d.device_model_id = dm.id 
    GROUP BY dm.device_type
";
$res_models = $conn->query($sql_models);
if ($res_models) {
    while ($row = $res_models->fetch_assoc()) {
        $model_counts[$row['model_name']] = (int)$row['count'];
    }
}

sendResponse(true, "Device summary fetched", [
    "summary" => $summary,
    "model_counts" => $model_counts
]);
$conn->close();
?>
