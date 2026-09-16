<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customers.view');

$customerId = isset($_GET['customer_id'])
    ? (int) $_GET['customer_id']
    : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

if ($customerId <= 0) {
    sendResponse(false, 'Customer ID is required.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $customerStmt = $conn->prepare(
        'SELECT
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
            c.created_at,
            c.updated_at
         FROM customers c
         LEFT JOIN platforms p
            ON p.id = c.platform_id
         WHERE c.id = ?
         LIMIT 1'
    );

    if (!$customerStmt) {
        throw new Exception('Failed to prepare customer query.');
    }

    $customerStmt->bind_param('i', $customerId);
    $customerStmt->execute();
    $customerResult = $customerStmt->get_result();

    $customer = $customerResult->fetch_assoc();
    $customerStmt->close();

    if (!$customer) {
        throw new Exception('Customer not found.');
    }

    $vehicleStmt = $conn->prepare(
        "SELECT
            cvd.id,
            cvd.customer_id,
            cvd.vehicle_no,
            cvd.vehicle_type_id,
            vt.vehicle_type,
            cvd.device_id,
            cvd.device_model_id,
            dt.device_type AS device_model,
            cvd.imei_no,
            cvd.sim_id_1,
            cvd.sim_no_1,
            cvd.sim_id_2,
            cvd.sim_no_2,
            cvd.validity_months,
            cvd.created_at,
            cvd.updated_at,
            sa.owner_type AS device_owner_type,
            sa.owner_id AS device_owner_id,
            CASE
                WHEN sa.owner_type = 'dealer' THEN dlr.dealer_name
                WHEN sa.owner_type = 'technician' THEN tech.technician_name
                ELSE NULL
            END AS device_owner_name
            , dlr.installation_status AS device_owner_installation_status,
            simsa.owner_type AS sim_owner_type,
            simsa.owner_id AS sim_owner_id,
            CASE
                WHEN simsa.owner_type = 'dealer' THEN simdlr.dealer_name
                WHEN simsa.owner_type = 'technician' THEN simtech.technician_name
                ELSE NULL
            END AS sim_owner_name,
            simdlr.installation_status AS sim_owner_installation_status
         FROM customer_vehicle_details cvd
         LEFT JOIN vehicle_types vt
            ON vt.id = cvd.vehicle_type_id
         LEFT JOIN device_types dt
            ON dt.id = cvd.device_model_id
         LEFT JOIN devices d
            ON d.id = cvd.device_id
            LEFT JOIN stock_allocations sa
                ON sa.id = (
                     SELECT latest_sa.id
                     FROM stock_allocations latest_sa
                     WHERE latest_sa.device_id = cvd.device_id
                     ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                     LIMIT 1
                )
            LEFT JOIN dealers dlr
                ON dlr.id = sa.owner_id AND sa.owner_type = 'dealer'
            LEFT JOIN technicians tech
                ON tech.id = sa.owner_id AND sa.owner_type = 'technician'
            LEFT JOIN stock_allocations simsa
                ON simsa.id = (
                    SELECT latest_sim_sa.id
                    FROM stock_allocations latest_sim_sa
                    WHERE latest_sim_sa.sim_id = cvd.sim_id_1
                    ORDER BY latest_sim_sa.created_at DESC, latest_sim_sa.id DESC
                    LIMIT 1
                )
            LEFT JOIN dealers simdlr
                ON simdlr.id = simsa.owner_id AND simsa.owner_type = 'dealer'
            LEFT JOIN technicians simtech
                ON simtech.id = simsa.owner_id AND simsa.owner_type = 'technician'
         WHERE cvd.customer_id = ?
         ORDER BY cvd.created_at DESC, cvd.id DESC
         LIMIT 1"
    );

    if (!$vehicleStmt) {
        throw new Exception('Failed to prepare vehicle query.');
    }

    $vehicleStmt->bind_param('i', $customerId);
    $vehicleStmt->execute();
    $vehicleResult = $vehicleStmt->get_result();
    $vehicle = $vehicleResult->fetch_assoc();
    $vehicleStmt->close();

$installationStmt = $conn->prepare(
    "SELECT
        ci.id,
        ci.customer_id,
        ci.installation_person_type,
        ci.installation_person_id,
        CASE
            WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name
            ELSE t.technician_name
        END AS installation_person,
        ci.lead_closure_id,
        lc.lead_closure_name AS lead_closure,
        ci.installation_date,
        ci.created_at,
        ci.updated_at
    FROM customer_installations ci
    LEFT JOIN technicians t
        ON t.id = ci.installation_person_id AND ci.installation_person_type = 'Technician'
    LEFT JOIN dealers d
        ON d.id = ci.installation_person_id AND ci.installation_person_type = 'Dealer'
    LEFT JOIN lead_closures lc
        ON lc.id = ci.lead_closure_id
    WHERE ci.customer_id = ?
    LIMIT 1"
);


    if (!$installationStmt) {
        throw new Exception('Failed to prepare installation query.');
    }

    $installationStmt->bind_param('i', $customerId);
    $installationStmt->execute();
    $installationResult = $installationStmt->get_result();
    $installation = $installationResult->fetch_assoc();
    $installationStmt->close();

    $paymentStmt = $conn->prepare(
        'SELECT
            id,
            customer_id,
            total_sale_amount,
            transaction_id,
            payment_mode,
            device_charge,
            software_charge,
            technician_charge,
            sim_charge,
            courier_charge,
            total_amount,
            amount_paid,
            amount_pending,
            payment_status,
            created_at,
            updated_at
         FROM customer_payments
         WHERE customer_id = ?
         ORDER BY created_at DESC, id DESC'
    );

    if (!$paymentStmt) {
        throw new Exception('Failed to prepare payment query.');
    }

    $paymentStmt->bind_param('i', $customerId);
    $paymentStmt->execute();
    $paymentResult = $paymentStmt->get_result();

    $paymentRows = [];

    while ($row = $paymentResult->fetch_assoc()) {
        $paymentRows[] = $row;
    }

    $payment = null;
    $bestPaymentScore = -1;

    foreach ($paymentRows as $row) {
        $score = 0;

        if ((float) $row['total_sale_amount'] > 0) {
            $score += 3;
        }

        if (!empty($row['transaction_id'])) {
            $score += 2;
        }

        if (!empty($row['payment_mode'])) {
            $score += 2;
        }

        $chargeFields = ['device_charge', 'software_charge', 'technician_charge', 'sim_charge', 'courier_charge'];
        foreach ($chargeFields as $field) {
            if ((float) $row[$field] > 0) {
                $score += 2;
            }
        }

        if ((float) $row['total_amount'] > 0) {
            $score += 3;
        }

        if ((float) $row['amount_paid'] > 0) {
            $score += 3;
        }

        if ((float) $row['amount_pending'] > 0) {
            $score += 1;
        }

        if (!empty($row['payment_status'])) {
            $score += 1;
        }

        if ($score > $bestPaymentScore) {
            $bestPaymentScore = $score;
            $payment = $row;
        }
    }

    if (!$payment && !empty($paymentRows)) {
        $payment = $paymentRows[0];
    }

    $paymentStmt->close();

    $collectionStmt = $conn->prepare(
        'SELECT
            ccc.id,
            ccc.recipient_type,
            ccc.recipient_id,
            ccc.amount_collected,
            ccc.amount_remitted,
            ccc.pending_amount,
            ccc.settlement_status,
            ccc.settlement_date,
            ccc.payment_mode AS settlement_payment_mode,
            ccc.transaction_id AS settlement_transaction_id,
            ccc.notes AS settlement_notes
         FROM customer_cash_collections ccc
         WHERE ccc.customer_id = ?
         LIMIT 1'
    );
    $collectionStmt->bind_param('i', $customerId);
    $collectionStmt->execute();
    $cashCollection = $collectionStmt->get_result()->fetch_assoc();
    $collectionStmt->close();

    $validityId = null;

    if ($vehicle && isset($vehicle['validity_months']) && $vehicle['validity_months']) {
        $validityStmt = $conn->prepare(
            'SELECT id
             FROM sim_validities
             WHERE months = ?
             LIMIT 1'
        );

        if ($validityStmt) {
            $validityMonths = (int) $vehicle['validity_months'];
            $validityStmt->bind_param('i', $validityMonths);
            $validityStmt->execute();
            $validityResult = $validityStmt->get_result();
            $validityRow = $validityResult->fetch_assoc();
            $validityStmt->close();

            if ($validityRow) {
                $validityId = (int) $validityRow['id'];
            }
        }
    }

    if ($vehicle) {
        $vehicle['validity_id'] = $validityId;
    }

    $paymentPart1 = [
        'total_sale_amount' => $payment ? (float) $payment['total_sale_amount'] : 0,
        'transaction_id' => $payment ? ($payment['transaction_id'] ?? '') : '',
        'payment_mode' => $payment ? ($payment['payment_mode'] ?? '') : ''
    ];

    $paymentPart2 = [
        'device_charge' => $payment ? (float) $payment['device_charge'] : 0,
        'software_charge' => $payment ? (float) $payment['software_charge'] : 0,
        'technician_charge' => $payment ? (float) $payment['technician_charge'] : 0,
        'sim_charge' => $payment ? (float) $payment['sim_charge'] : 0,
        'courier_charge' => $payment ? (float) $payment['courier_charge'] : 0,
        'total_amount' => $payment ? (float) $payment['total_amount'] : 0,
        'amount_paid' => $payment ? (float) $payment['amount_paid'] : 0,
        'amount_pending' => $payment ? (float) $payment['amount_pending'] : 0,
        'payment_status' => $payment ? ($payment['payment_status'] ?? 'Pending') : 'Pending'
    ];

    $payment = [
        'total_sale_amount' => $paymentPart1['total_sale_amount'],
        'transaction_id' => $paymentPart1['transaction_id'],
        'payment_mode' => $paymentPart1['payment_mode'],
        'device_charge' => $paymentPart2['device_charge'],
        'software_charge' => $paymentPart2['software_charge'],
        'technician_charge' => $paymentPart2['technician_charge'],
        'sim_charge' => $paymentPart2['sim_charge'],
        'courier_charge' => $paymentPart2['courier_charge'],
        'total_amount' => $paymentPart2['total_amount'],
        'amount_paid' => $paymentPart2['amount_paid'],
        'amount_pending' => $paymentPart2['amount_pending'],
        'payment_status' => $paymentPart2['payment_status']
    ];

    $conn->close();

    sendResponse(
        true,
        'Customer details fetched successfully.',
        [
            'customer' => $customer,
            'vehicle' => $vehicle,
            'installation' => $installation,
            'payment' => $payment,
            'payment_part1' => $paymentPart1,
            'payment_part2' => $paymentPart2
            , 'cash_collection' => $cashCollection
        ]
    );
} catch (Throwable $e) {
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
}
