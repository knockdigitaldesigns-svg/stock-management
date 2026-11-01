<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customers.view');

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}


/*
|--------------------------------------------------------------------------
| OPTIONAL CUSTOMER ID FILTER
|--------------------------------------------------------------------------
*/

$customerId = isset($_GET['customer_id'])
    ? (int) $_GET['customer_id']
    : 0;


/*
|--------------------------------------------------------------------------
| BASE QUERY
|--------------------------------------------------------------------------
*/

$sql = '
    SELECT
        cvd.id,
        cvd.customer_id,
        cvd.vehicle_no,
        cvd.vehicle_type_id,
        cvd.device_id,
        cvd.device_model_id,
        cvd.imei_no,
        cvd.sim_id_1,
        cvd.sim_no_1,
        cvd.sim_id_2,
        cvd.sim_no_2,
        cvd.validity_months,
        cvd.created_at,
        cvd.updated_at,

        vt.vehicle_type,

        dt.device_type AS device_model

    FROM customer_vehicle_details cvd

    LEFT JOIN vehicle_types vt
        ON vt.id = cvd.vehicle_type_id

    LEFT JOIN device_types dt
        ON dt.id = cvd.device_model_id
';


/*
|--------------------------------------------------------------------------
| CUSTOMER FILTER
|--------------------------------------------------------------------------
*/

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$countSql = 'SELECT COUNT(*) AS total FROM customer_vehicle_details cvd'
    . ($customerId > 0 ? ' WHERE cvd.customer_id = ?' : '');
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare vehicle details count query.', [], [], 500);
}
if ($customerId > 0) {
    $countStmt->bind_param('i', $customerId);
}
if (!$countStmt->execute()) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to count vehicle details.', [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql .= $customerId > 0 ? ' WHERE cvd.customer_id = ?' : '';
$sql .= ' ORDER BY cvd.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare vehicle details query.', [], [], 500);
}
if ($customerId > 0) {
    $stmt->bind_param('iii', $customerId, $pageSize, $offset);
} else {
    $stmt->bind_param('ii', $pageSize, $offset);
}
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch vehicle details.', [], [], 500);
}

$result = $stmt->get_result();

$vehicleDetails = [];

while ($row = $result->fetch_assoc()) {

    $row['id'] = (int) $row['id'];
    $row['customer_id'] = (int) $row['customer_id'];
    $row['vehicle_type_id'] = (int) $row['vehicle_type_id'];
    $row['device_id'] = (int) $row['device_id'];
    $row['device_model_id'] = (int) $row['device_model_id'];
    $row['sim_id_1'] = (int) $row['sim_id_1'];

    if ($row['sim_id_2'] !== null) {
        $row['sim_id_2'] = (int) $row['sim_id_2'];
    }

    $row['validity_months'] = (int) $row['validity_months'];

    $vehicleDetails[] = $row;
}


$stmt->close();
$conn->close();


sendResponse(
    true,
    'Vehicle details fetched successfully.',
    [
        'vehicle_details' => $vehicleDetails,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ]
    ]
);

?>