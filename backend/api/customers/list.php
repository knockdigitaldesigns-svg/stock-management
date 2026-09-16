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

        cv.vehicle_no,
        vt.vehicle_type,
        dt.device_type AS device_model,
        cv.imei_no,
        cv.sim_no_1,
        cv.sim_no_2,
        cv.sim_no_1 AS sim_no,
        cv.validity_months,

        ci.installation_person_type,
        CASE
            WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name
            ELSE t.technician_name
        END AS installation_person,
        lc.lead_closure_name AS lead_closure,
        ci.installation_date,

        cp.total_sale_amount,
        cp.transaction_id,
        cp.payment_mode,
        cp.device_charge,
        cp.software_charge,
        cp.technician_charge,
        cp.sim_charge,
        cp.courier_charge,
        cp.total_amount,
        cp.amount_paid,
        cp.amount_pending,
        cp.payment_status,

        c.created_at,
        c.updated_at

    FROM customers c

    LEFT JOIN platforms p
        ON p.id = c.platform_id

    LEFT JOIN customer_vehicle_details cv
        ON cv.id = (
            SELECT id
            FROM customer_vehicle_details
            WHERE customer_id = c.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )

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

    LEFT JOIN customer_payments cp
        ON cp.id = (
            SELECT id
            FROM customer_payments
            WHERE customer_id = c.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )

    ORDER BY c.id DESC
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

while ($row = $result->fetch_assoc()) {

    $customers[] = $row;
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