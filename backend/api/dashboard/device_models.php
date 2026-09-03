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

$sql = "
    SELECT dm.device_type AS model_name, COUNT(d.id) as count
    FROM device_types dm
    LEFT JOIN devices d ON d.device_model_id = dm.id
    GROUP BY dm.device_type
    ORDER BY dm.device_type ASC
";
$result = $conn->query($sql);

$models = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $models[] = [
            'name' => $row['model_name'],
            'count' => (int) $row['count']
        ];
    }
}

sendResponse(true, "Device model distribution fetched", ['models' => $models]);

$conn->close();
?>
