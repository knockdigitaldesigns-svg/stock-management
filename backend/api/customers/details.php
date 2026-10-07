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
$requestedVehicleId = isset($_GET['vehicle_id'])
    ? (int) $_GET['vehicle_id']
    : 0;

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
            vci.id AS vehicle_installation_id,
            vci.installation_person_type AS vehicle_installation_person_type,
            vci.installation_person_id AS vehicle_installation_person_id,
            vci.lead_closure_id AS vehicle_lead_closure_id,
            CASE
                WHEN vci.installation_person_type = 'Dealer' THEN vdlr.dealer_name
                ELSE vtech.technician_name
            END AS vehicle_installation_person,
            CASE
                WHEN vci.installation_person_type = 'Dealer' THEN CONCAT(COALESCE(vdlr.installation_status, 'Dealer'), ' Dealer')
                ELSE vci.installation_person_type
            END AS vehicle_installation_type,
            vlc.lead_closure_name AS vehicle_lead_closure,
            vci.installation_date AS vehicle_installation_date,
            vcp.id AS vehicle_payment_id,
            vcp.total_sale_amount AS vehicle_total_sale_amount,
            vcp.transaction_id AS vehicle_transaction_id,
            vcp.payment_mode AS vehicle_payment_mode,
            vcp.device_charge AS vehicle_device_charge,
            vcp.software_charge AS vehicle_software_charge,
            vcp.technician_charge AS vehicle_technician_charge,
            vcp.sim_charge AS vehicle_sim_charge,
            vcp.courier_charge AS vehicle_courier_charge,
            vcp.total_amount AS vehicle_total_amount,
            vcp.amount_paid AS vehicle_amount_paid,
            vcp.amount_pending AS vehicle_amount_pending,
            vcp.payment_status AS vehicle_payment_status,
            vcc.payment_mode AS vehicle_cash_payment_mode,
            vcc.transaction_id AS vehicle_cash_transaction_id,
            vcc.id AS vehicle_cash_collection_id,
            vcc.recipient_type AS vehicle_cash_recipient_type,
            CASE
                WHEN vcc.recipient_type = 'Dealer' THEN vcc_dealer.dealer_name
                WHEN vcc.recipient_type = 'Technician' THEN vcc_technician.technician_name
                ELSE NULL
            END AS vehicle_cash_recipient_name,
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
         LEFT JOIN customer_installations vci
            ON vci.id = (
                SELECT latest_vci.id
                FROM customer_installations latest_vci
                WHERE latest_vci.vehicle_id = cvd.id
                ORDER BY latest_vci.created_at DESC, latest_vci.id DESC
                LIMIT 1
            )
         LEFT JOIN technicians vtech
            ON vtech.id = vci.installation_person_id AND vci.installation_person_type = 'Technician'
         LEFT JOIN dealers vdlr
            ON vdlr.id = vci.installation_person_id AND vci.installation_person_type = 'Dealer'
         LEFT JOIN lead_closures vlc
            ON vlc.id = vci.lead_closure_id
         LEFT JOIN customer_payments vcp
            ON vcp.id = (
                SELECT latest_vcp.id
                FROM customer_payments latest_vcp
                WHERE latest_vcp.vehicle_id = cvd.id
                ORDER BY latest_vcp.created_at DESC, latest_vcp.id DESC
                LIMIT 1
            )
         LEFT JOIN customer_cash_collections vcc
            ON vcc.id = (
                SELECT latest_vcc.id
                FROM customer_cash_collections latest_vcc
                WHERE latest_vcc.payment_id = vcp.id
                ORDER BY latest_vcc.created_at DESC, latest_vcc.id DESC
                LIMIT 1
            )
         LEFT JOIN dealers vcc_dealer
            ON vcc_dealer.id = vcc.recipient_id AND vcc.recipient_type = 'Dealer'
         LEFT JOIN technicians vcc_technician
            ON vcc_technician.id = vcc.recipient_id AND vcc.recipient_type = 'Technician'
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
         ORDER BY cvd.id ASC"
    );

    if (!$vehicleStmt) {
        throw new Exception('Failed to prepare vehicle query.');
    }

    $vehicleStmt->bind_param('i', $customerId);
    $vehicleStmt->execute();
    $vehicleResult = $vehicleStmt->get_result();
    $vehicles = [];
    while ($vehicleRow = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $vehicleRow;
    }
    $vehicleStmt->close();

    $legacyInstallationStmt = $conn->prepare(
        "SELECT
            ci.id,
            ci.customer_id,
            ci.installation_person_type,
            ci.installation_person_id,
            CASE
                WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name
                ELSE t.technician_name
            END AS installation_person,
            CASE
                WHEN ci.installation_person_type = 'Dealer' THEN CONCAT(COALESCE(d.installation_status, 'Dealer'), ' Dealer')
                ELSE ci.installation_person_type
            END AS installation_type,
            ci.lead_closure_id,
            lc.lead_closure_name AS lead_closure,
            ci.installation_date
         FROM customer_installations ci
         LEFT JOIN technicians t
            ON t.id = ci.installation_person_id AND ci.installation_person_type = 'Technician'
         LEFT JOIN dealers d
            ON d.id = ci.installation_person_id AND ci.installation_person_type = 'Dealer'
         LEFT JOIN lead_closures lc
            ON lc.id = ci.lead_closure_id
         WHERE ci.customer_id = ? AND ci.vehicle_id IS NULL
         ORDER BY ci.id ASC"
    );
    if (!$legacyInstallationStmt) {
        throw new Exception('Failed to prepare legacy vehicle installation query.');
    }
    $legacyInstallationStmt->bind_param('i', $customerId);
    $legacyInstallationStmt->execute();
    $legacyInstallationResult = $legacyInstallationStmt->get_result();
    $legacyInstallations = [];
    while ($legacyInstallation = $legacyInstallationResult->fetch_assoc()) {
        $legacyInstallations[] = $legacyInstallation;
    }
    $legacyInstallationStmt->close();

    $vehiclesMissingInstallation = [];
    foreach ($vehicles as $index => $vehicleRow) {
        if (empty($vehicleRow['vehicle_installation_id'])) {
            $vehiclesMissingInstallation[] = $index;
        }
    }
    // Excel import writes one unlinked child row per vehicle in vehicle insertion order.
    if (count($legacyInstallations) === count($vehiclesMissingInstallation)) {
        foreach ($legacyInstallations as $index => $legacyInstallation) {
            $vehicleIndex = $vehiclesMissingInstallation[$index];
            $vehicles[$vehicleIndex] = array_merge($vehicles[$vehicleIndex], [
                'vehicle_installation_id' => $legacyInstallation['id'],
                'vehicle_installation_person_type' => $legacyInstallation['installation_person_type'],
                'vehicle_installation_person_id' => $legacyInstallation['installation_person_id'],
                'vehicle_installation_person' => $legacyInstallation['installation_person'],
                'vehicle_installation_type' => $legacyInstallation['installation_type'],
                'vehicle_lead_closure_id' => $legacyInstallation['lead_closure_id'],
                'vehicle_lead_closure' => $legacyInstallation['lead_closure'],
                'vehicle_installation_date' => $legacyInstallation['installation_date']
            ]);
        }
    }

    $legacyPaymentStmt = $conn->prepare(
        "SELECT
            cp.id,
            cp.vehicle_id,
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
            ccc.id AS cash_collection_id,
            ccc.recipient_type AS cash_recipient_type,
            CASE
                WHEN ccc.recipient_type = 'Dealer' THEN d.dealer_name
                WHEN ccc.recipient_type = 'Technician' THEN t.technician_name
                ELSE NULL
            END AS cash_recipient_name,
            ccc.payment_mode AS cash_payment_mode,
            ccc.transaction_id AS cash_transaction_id
         FROM customer_payments cp
         LEFT JOIN customer_cash_collections ccc
            ON ccc.id = (
                SELECT latest_ccc.id
                FROM customer_cash_collections latest_ccc
                WHERE latest_ccc.payment_id = cp.id
                ORDER BY latest_ccc.created_at DESC, latest_ccc.id DESC
                LIMIT 1
            )
         LEFT JOIN dealers d
            ON d.id = ccc.recipient_id AND ccc.recipient_type = 'Dealer'
         LEFT JOIN technicians t
            ON t.id = ccc.recipient_id AND ccc.recipient_type = 'Technician'
         WHERE cp.customer_id = ? AND cp.vehicle_id IS NULL
         ORDER BY cp.id ASC"
    );
    if (!$legacyPaymentStmt) {
        throw new Exception('Failed to prepare legacy vehicle payment query.');
    }
    $legacyPaymentStmt->bind_param('i', $customerId);
    $legacyPaymentStmt->execute();
    $legacyPaymentResult = $legacyPaymentStmt->get_result();
    $legacyPayments = [];
    while ($legacyPayment = $legacyPaymentResult->fetch_assoc()) {
        $legacyPayments[] = $legacyPayment;
    }
    $legacyPaymentStmt->close();

    $vehiclesMissingPayment = [];
    foreach ($vehicles as $index => $vehicleRow) {
        if (empty($vehicleRow['vehicle_payment_id'])) {
            $vehiclesMissingPayment[] = $index;
        }
    }
    if (count($legacyPayments) === count($vehiclesMissingPayment)) {
        foreach ($legacyPayments as $index => $legacyPayment) {
            $vehicleIndex = $vehiclesMissingPayment[$index];
            $vehicles[$vehicleIndex] = array_merge($vehicles[$vehicleIndex], [
                'vehicle_payment_id' => $legacyPayment['id'],
                'vehicle_total_sale_amount' => $legacyPayment['total_sale_amount'],
                'vehicle_transaction_id' => $legacyPayment['transaction_id'],
                'vehicle_payment_mode' => $legacyPayment['payment_mode'],
                'vehicle_device_charge' => $legacyPayment['device_charge'],
                'vehicle_software_charge' => $legacyPayment['software_charge'],
                'vehicle_technician_charge' => $legacyPayment['technician_charge'],
                'vehicle_sim_charge' => $legacyPayment['sim_charge'],
                'vehicle_courier_charge' => $legacyPayment['courier_charge'],
                'vehicle_total_amount' => $legacyPayment['total_amount'],
                'vehicle_amount_paid' => $legacyPayment['amount_paid'],
                'vehicle_amount_pending' => $legacyPayment['amount_pending'],
                'vehicle_payment_status' => $legacyPayment['payment_status'],
                'vehicle_cash_collection_id' => $legacyPayment['cash_collection_id'],
                'vehicle_cash_recipient_type' => $legacyPayment['cash_recipient_type'],
                'vehicle_cash_recipient_name' => $legacyPayment['cash_recipient_name'],
                'vehicle_cash_payment_mode' => $legacyPayment['cash_payment_mode'],
                'vehicle_cash_transaction_id' => $legacyPayment['cash_transaction_id']
            ]);
        }
    }

    $vehicle = null;
    foreach ($vehicles as $vehicleRow) {
        if ($requestedVehicleId > 0 && (int) $vehicleRow['id'] !== $requestedVehicleId) {
            continue;
        }
        $vehicle = $vehicleRow;
        break;
    }

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
    WHERE ci.id = ?
    LIMIT 1"
);


    if (!$installationStmt) {
        throw new Exception('Failed to prepare installation query.');
    }

    $installationId = (int) ($vehicle['vehicle_installation_id'] ?? 0);
    $installationStmt->bind_param('i', $installationId);
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
         WHERE id = ?
         LIMIT 1'
    );

    if (!$paymentStmt) {
        throw new Exception('Failed to prepare payment query.');
    }

    $selectedPaymentId = (int) ($vehicle['vehicle_payment_id'] ?? 0);
    $paymentStmt->bind_param('i', $selectedPaymentId);
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
         WHERE ccc.payment_id = ?
         ORDER BY ccc.created_at DESC, ccc.id DESC
         LIMIT 1'
    );
    $cashPaymentId = (int) ($vehicle['vehicle_payment_id'] ?? 0);
    $collectionStmt->bind_param('i', $cashPaymentId);
    $collectionStmt->execute();
    $cashCollection = $collectionStmt->get_result()->fetch_assoc();
    $collectionStmt->close();

    foreach ($vehicles as &$vehicleRow) {
        if (empty($vehicleRow['validity_months'])) {
            $vehicleRow['validity_id'] = null;
            continue;
        }

        $validityStmt = $conn->prepare(
            'SELECT id
             FROM sim_validities
             WHERE months = ?
             LIMIT 1'
        );

        if ($validityStmt) {
            $validityMonths = (int) $vehicleRow['validity_months'];
            $validityStmt->bind_param('i', $validityMonths);
            $validityStmt->execute();
            $validityResult = $validityStmt->get_result();
            $validityRow = $validityResult->fetch_assoc();
            $validityStmt->close();

            $vehicleRow['validity_id'] = $validityRow ? (int) $validityRow['id'] : null;
        }
    }
    unset($vehicleRow);

    if ($vehicle) {
        foreach ($vehicles as $vehicleRow) {
            if ((int) $vehicleRow['id'] === (int) $vehicle['id']) {
                $vehicle = $vehicleRow;
                break;
            }
        }

        $installation = !empty($vehicle['vehicle_installation_id'])
            ? [
                'id' => $vehicle['vehicle_installation_id'],
                'customer_id' => $customerId,
                'vehicle_id' => $vehicle['id'],
                'installation_person_type' => $vehicle['vehicle_installation_person_type'],
                'installation_person_id' => $vehicle['vehicle_installation_person_id'],
                'installation_person' => $vehicle['vehicle_installation_person'],
                'installation_type' => $vehicle['vehicle_installation_type'],
                'lead_closure_id' => $vehicle['vehicle_lead_closure_id'] ?? null,
                'lead_closure' => $vehicle['vehicle_lead_closure'],
                'installation_date' => $vehicle['vehicle_installation_date']
            ]
            : null;

        $payment = !empty($vehicle['vehicle_payment_id'])
            ? [
                'id' => $vehicle['vehicle_payment_id'],
                'customer_id' => $customerId,
                'vehicle_id' => $vehicle['id'],
                'total_sale_amount' => $vehicle['vehicle_total_sale_amount'],
                'transaction_id' => $vehicle['vehicle_transaction_id'],
                'payment_mode' => $vehicle['vehicle_payment_mode'],
                'device_charge' => $vehicle['vehicle_device_charge'],
                'software_charge' => $vehicle['vehicle_software_charge'],
                'technician_charge' => $vehicle['vehicle_technician_charge'],
                'sim_charge' => $vehicle['vehicle_sim_charge'],
                'courier_charge' => $vehicle['vehicle_courier_charge'],
                'total_amount' => $vehicle['vehicle_total_amount'],
                'amount_paid' => $vehicle['vehicle_amount_paid'],
                'amount_pending' => $vehicle['vehicle_amount_pending'],
                'payment_status' => $vehicle['vehicle_payment_status']
            ]
            : null;

        $cashCollection = !empty($vehicle['vehicle_cash_collection_id'])
            ? [
                'id' => $vehicle['vehicle_cash_collection_id'],
                'recipient_type' => $vehicle['vehicle_cash_recipient_type'],
                'recipient_name' => $vehicle['vehicle_cash_recipient_name'],
                'payment_mode' => $vehicle['vehicle_cash_payment_mode'],
                'transaction_id' => $vehicle['vehicle_cash_transaction_id']
            ]
            : null;
    }

    $customer['vehicles'] = $vehicles;

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
            'vehicles' => $vehicles,
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
