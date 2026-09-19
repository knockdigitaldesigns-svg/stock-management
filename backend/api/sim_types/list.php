<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requireAnyPermission([
    'sims.view',
    'dealers.view',
    'technicians.view',
    'customers.view',
    'stock.view'
]);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$sql = "SELECT id, sim_type FROM sim_types ORDER BY id ASC";
$result = $conn->query($sql);
$simTypes = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $simTypes[] = [
            'id' => (int) $row['id'],
            'sim_type' => $row['sim_type']
        ];
    }
}
$conn->close();

sendResponse(true, 'SIM types fetched successfully', ['sim_types' => $simTypes]);
