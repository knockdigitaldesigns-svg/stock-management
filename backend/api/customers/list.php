<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customers.view');

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}


/*
|--------------------------------------------------------------------------
| Fetch Customers
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.platform_id,
        p.platform_name,
        c.username,
        c.primary_mobile_no,
        c.secondary_mobile_no,
        c.email,
        c.location,
        c.pincode,
        c.status,

        cv.id AS vehicle_id,
        cv.customer_id AS vehicle_customer_id,
        cv.vehicle_no,
        cv.vehicle_type_id,
        vt.vehicle_type,
        cv.device_id,
        cv.device_model_id,
        dt.device_type AS device_model,
        cv.imei_no,
        cv.sim_id_1,
        cv.sim_no_1,
        cv.sim_id_2,
        cv.sim_no_2,
        cv.sim_no_1 AS sim_no,
        cv.validity_months,
        cv.created_at AS vehicle_created_at,
        cv.updated_at AS vehicle_updated_at,

        ci.installation_person_type,
        CASE
            WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name
            ELSE t.technician_name
        END AS installation_person,
        lc.lead_closure_name AS lead_closure,
        ci.installation_date,

        COALESCE(cpa.total_sale_amount, 0) AS total_sale_amount,
        cp.transaction_id,
        cp.payment_mode,
        cp.device_charge,
        cp.software_charge,
        cp.technician_charge,
        cp.sim_charge,
        cp.courier_charge,
        COALESCE(cpa.total_amount, 0) AS total_amount,
        COALESCE(cpa.amount_paid, 0) AS amount_paid,
        COALESCE(cpa.amount_pending, 0) AS amount_pending,
        CASE
            WHEN COALESCE(cpa.total_amount, 0) = 0 THEN COALESCE(cp.payment_status, 'Pending')
            WHEN COALESCE(cpa.amount_paid, 0) >= COALESCE(cpa.total_amount, 0) THEN 'Paid'
            WHEN COALESCE(cpa.amount_paid, 0) = 0 THEN 'Not Paid'
            WHEN COALESCE(cpa.amount_pending, 0) > 0 THEN 'Partially Paid'
            ELSE 'Paid'
        END AS payment_status,

        c.created_at,
        c.updated_at

    FROM customers c

    LEFT JOIN platforms p
        ON p.id = c.platform_id

    LEFT JOIN customer_vehicle_details cv
        ON cv.customer_id = c.id

    LEFT JOIN vehicle_types vt
        ON vt.id = cv.vehicle_type_id

    LEFT JOIN device_types dt
        ON dt.id = cv.device_model_id

    LEFT JOIN customer_installations ci
        ON ci.id = (
            SELECT id
            FROM customer_installations
            WHERE customer_id = c.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )

    LEFT JOIN technicians t
        ON t.id = ci.installation_person_id AND ci.installation_person_type = 'Technician'

    LEFT JOIN dealers d
        ON d.id = ci.installation_person_id AND ci.installation_person_type = 'Dealer'

    LEFT JOIN lead_closures lc
        ON lc.id = ci.lead_closure_id

    LEFT JOIN (
        SELECT
            customer_id,
            SUM(COALESCE(total_sale_amount, 0)) AS total_sale_amount,
            SUM(COALESCE(total_amount, 0)) AS total_amount,
            SUM(COALESCE(amount_paid, 0)) AS amount_paid,
            SUM(COALESCE(amount_pending, 0)) AS amount_pending
        FROM customer_payments
        GROUP BY customer_id
    ) cpa
        ON cpa.customer_id = c.id

    LEFT JOIN customer_payments cp
        ON cp.id = (
            SELECT id
            FROM customer_payments
            WHERE customer_id = c.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )

    ORDER BY c.id DESC, cv.created_at DESC, cv.id DESC
";

$result = $conn->query($sql);

if (!$result) {

    $error = $conn->error;

    $conn->close();

    sendResponse(
        false,
        'Failed to fetch customers.',
        [],
        ['error' => $error],
        500
    );
}

$customers = [];
$customerIndexes = [];

while ($row = $result->fetch_assoc()) {
    $customerId = (int) $row['id'];
    if (!isset($customerIndexes[$customerId])) {
        $customerIndexes[$customerId] = count($customers);
        $customers[] = [
            'id' => $row['id'],
            'platform_id' => $row['platform_id'],
            'platform_name' => $row['platform_name'],
            'username' => $row['username'],
            'primary_mobile_no' => $row['primary_mobile_no'],
            'secondary_mobile_no' => $row['secondary_mobile_no'],
            'email' => $row['email'],
            'location' => $row['location'],
            'pincode' => $row['pincode'],
            'status' => $row['status'],
            'installation_person_type' => $row['installation_person_type'],
            'installation_person' => $row['installation_person'],
            'lead_closure' => $row['lead_closure'],
            'installation_date' => $row['installation_date'],
            'total_sale_amount' => $row['total_sale_amount'],
            'transaction_id' => $row['transaction_id'],
            'payment_mode' => $row['payment_mode'],
            'device_charge' => $row['device_charge'],
            'software_charge' => $row['software_charge'],
            'technician_charge' => $row['technician_charge'],
            'sim_charge' => $row['sim_charge'],
            'courier_charge' => $row['courier_charge'],
            'total_amount' => $row['total_amount'],
            'amount_paid' => $row['amount_paid'],
            'amount_pending' => $row['amount_pending'],
            'payment_status' => $row['payment_status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'vehicles' => []
        ];
    }

    if ($row['vehicle_id'] !== null) {
        $vehicle = [
            'id' => (int) $row['vehicle_id'],
            'customer_id' => (int) $row['vehicle_customer_id'],
            'vehicle_no' => $row['vehicle_no'],
            'vehicle_type_id' => $row['vehicle_type_id'],
            'vehicle_type' => $row['vehicle_type'],
            'device_id' => $row['device_id'],
            'device_model_id' => $row['device_model_id'],
            'device_model' => $row['device_model'],
            'imei_no' => $row['imei_no'],
            'sim_id_1' => $row['sim_id_1'],
            'sim_no_1' => $row['sim_no_1'],
            'sim_id_2' => $row['sim_id_2'],
            'sim_no_2' => $row['sim_no_2'],
            'sim_no' => $row['sim_no'],
            'validity_months' => $row['validity_months'],
            'created_at' => $row['vehicle_created_at'],
            'updated_at' => $row['vehicle_updated_at']
        ];
        $customers[$customerIndexes[$customerId]]['vehicles'][] = $vehicle;
    }
}

$conn->close();


sendResponse(
    true,
    'Customers fetched successfully.',
    [
        'customers' => $customers
    ]
);

?>