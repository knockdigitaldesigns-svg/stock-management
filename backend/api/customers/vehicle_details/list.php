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

if ($customerId > 0) {

    $sql .= '
        WHERE cvd.customer_id = ?
        ORDER BY cvd.id DESC
    ';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        $conn->close();

        sendResponse(
            false,
            'Failed to prepare vehicle details query.',
            [],
            [],
            500
        );
    }

    $stmt->bind_param('i', $customerId);

} else {

    $sql .= '
        ORDER BY cvd.id DESC
    ';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        $conn->close();

        sendResponse(
            false,
            'Failed to prepare vehicle details query.',
            [],
            [],
            500
        );
    }
}


$stmt->execute();

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
        'vehicle_details' => $vehicleDetails
    ]
);

?>