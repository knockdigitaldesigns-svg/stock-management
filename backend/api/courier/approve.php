<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/transaction_ids.php';
require_once '../../utils/courier_payment_validation.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
ensurePermissionDefinitions(['courier.approve', 'courier.view']);

// Check if user is authorized Admin
if (!isSuperAdminUser((int)($currentUser['user_id'] ?? 0))) {
    $userPermissions = getUserPermissions((int)($currentUser['user_id'] ?? 0));
    if (!in_array('courier.approve', $userPermissions, true)) {
        sendResponse(false, "Unauthorized: Only authorized Admin users can approve courier requests.", [], [], 403);
    }
}

$data = json_decode(file_get_contents("php://input"));
$id = (int)($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, "Courier Request ID is required", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Begin the transaction before locking the request and its assets.
$conn->begin_transaction();

// 1. Fetch courier request with FOR UPDATE lock
$fetchStmt = $conn->prepare("SELECT * FROM courier_requests WHERE id = ? FOR UPDATE");
$fetchStmt->bind_param("i", $id);
$fetchStmt->execute();
$request = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$request) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, "Courier request not found", [], [], 404);
}

if ($request['approval_status'] !== 'Pending Approval') {
    $conn->rollback();
    $conn->close();
    sendResponse(false, "Courier request is already {$request['approval_status']}.", [], [], 400);
}

$courierTo = $request['courier_to_person'];
$owner_type = strtolower($courierTo);
$isNewCustomer = !empty($request['is_new_customer']);
$owner_id = 0;

if ($owner_type === 'technician') {
    $owner_id = (int)$request['technician_id'];
} elseif ($owner_type === 'dealer') {
    $owner_id = (int)$request['dealer_id'];
} elseif ($owner_type === 'customer' && !$isNewCustomer) {
    $owner_id = (int)$request['customer_id'];
}

if (!$isNewCustomer && $owner_id <= 0) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, "Recipient owner ID not found for this request.", [], [], 400);
}

$device_id = !empty($request['device_id']) ? (int)$request['device_id'] : null;
$sim_id = !empty($request['sim_id']) ? (int)$request['sim_id'] : null;
$allocation_date = !empty($request['courier_date']) ? $request['courier_date'] : $request['request_date'];
$software = $request['software'] ?? '';
$notes = $request['notes'] ?? '';
$allocationPayment = [
    'enabled' => false,
    'sale_amount' => 0.0,
    'device_charge' => 0.0,
    'sim_charge' => 0.0,
    'shared_charges' => 0.0,
    'amount_paid' => 0.0,
    'payment_mode' => null,
    'transaction_id' => null
];

try {
    validateCourierPaymentWorkflow(
        !empty($request['new_customer_data']) ? json_decode($request['new_customer_data'], true) : null,
        (string)$request['courier_to_person']
    );

    // 2. Verify selected Device is available or reserved by this request.
    if ($device_id) {
        $devStmt = $conn->prepare("SELECT id, status FROM devices WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $devStmt->bind_param("i", $device_id);
        $devStmt->execute();
        $devRow = $devStmt->get_result()->fetch_assoc();
        $devStmt->close();

        if (!$devRow) {
            throw new Exception("Device associated with this request was not found.");
        }

        $devOwnerStmt = $conn->prepare("SELECT id FROM courier_requests WHERE id = ? AND device_id = ? AND approval_status = 'Pending Approval' LIMIT 1");
        $devOwnerStmt->bind_param("ii", $id, $device_id);
        $devOwnerStmt->execute();
        $deviceReservationOwner = $devOwnerStmt->get_result()->fetch_assoc();
        $devOwnerStmt->close();

        $otherDevOwnerStmt = $conn->prepare("SELECT id FROM courier_requests WHERE device_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $otherDevOwnerStmt->bind_param("ii", $device_id, $id);
        $otherDevOwnerStmt->execute();
        $otherDeviceReservation = $otherDevOwnerStmt->get_result()->fetch_assoc();
        $otherDevOwnerStmt->close();

        if ($devRow['status'] === 'reserved' && (!$deviceReservationOwner || $otherDeviceReservation)) {
            throw new Exception("Device is no longer available in stock (reserved by another request or transaction).");
        }
        if ($devRow['status'] !== 'available' && $devRow['status'] !== 'reserved') {
            throw new Exception("Device is no longer available in stock (current status: {$devRow['status']}).");
        }
        if ($devRow['status'] === 'available' && $otherDeviceReservation) {
            throw new Exception("Device is reserved by another courier request.");
        }
    }

    // 3. Verify selected SIM is available or reserved by this request.
    if ($sim_id) {
        $simStmt = $conn->prepare("SELECT id, status, sim_validity_id FROM sims WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $simStmt->bind_param("i", $sim_id);
        $simStmt->execute();
        $simRow = $simStmt->get_result()->fetch_assoc();
        $simStmt->close();

        if (!$simRow) {
            throw new Exception("SIM associated with this request was not found.");
        }

        $simOwnerStmt = $conn->prepare("SELECT id FROM courier_requests WHERE id = ? AND sim_id = ? AND approval_status = 'Pending Approval' LIMIT 1");
        $simOwnerStmt->bind_param("ii", $id, $sim_id);
        $simOwnerStmt->execute();
        $simReservationOwner = $simOwnerStmt->get_result()->fetch_assoc();
        $simOwnerStmt->close();

        $otherSimOwnerStmt = $conn->prepare("SELECT id FROM courier_requests WHERE sim_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $otherSimOwnerStmt->bind_param("ii", $sim_id, $id);
        $otherSimOwnerStmt->execute();
        $otherSimReservation = $otherSimOwnerStmt->get_result()->fetch_assoc();
        $otherSimOwnerStmt->close();

        if ($simRow['status'] === 'reserved' && (!$simReservationOwner || $otherSimReservation)) {
            throw new Exception("SIM is no longer available in stock (reserved by another request or transaction).");
        }
        if ($simRow['status'] !== 'available' && $simRow['status'] !== 'reserved') {
            throw new Exception("SIM is no longer available in stock (current status: {$simRow['status']}).");
        }
        if ($simRow['status'] === 'available' && $otherSimReservation) {
            throw new Exception("SIM is reserved by another courier request.");
        }
    }

    // Apply submitted workflow details for both new and existing customers.
    $hasCustomerWorkflow = $owner_type === 'customer' && !empty($request['new_customer_data']);
    if ($isNewCustomer || $hasCustomerWorkflow) {
        $newCustomerData = !empty($request['new_customer_data']) ? json_decode($request['new_customer_data'], true) : [];
        $step1 = $newCustomerData['step1'] ?? [];
        if ($isNewCustomer) {
        $username = trim((string)($step1['username'] ?? ''));
        $primaryMobile = trim((string)($step1['primary_mobile_no'] ?? ''));

        if ($username === '' || $primaryMobile === '') {
            throw new Exception("New customer username and primary mobile number are required for approval.");
        }

        // Check if customer already exists (same username + same mobile)
        $chkCust = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(username)) = LOWER(?) AND TRIM(primary_mobile_no) = ? ORDER BY id ASC LIMIT 1");
        $chkCust->bind_param("ss", $username, $primaryMobile);
        $chkCust->execute();
        $existingCust = $chkCust->get_result()->fetch_assoc();
        $chkCust->close();

        if ($existingCust) {
            $owner_id = (int)$existingCust['id'];
        } else {
            // Check username conflict with different mobile
            $chkDiff = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(username)) = LOWER(?) AND TRIM(primary_mobile_no) != ? LIMIT 1");
            $chkDiff->bind_param("ss", $username, $primaryMobile);
            $chkDiff->execute();
            if ($chkDiff->get_result()->num_rows > 0) {
                $chkDiff->close();
                throw new Exception("Username already exists with a different mobile number in Customer records.");
            }
            $chkDiff->close();

            // Insert Customer
            $platformId = (int)($step1['platform_id'] ?? 0);
            $platformStmt = $conn->prepare("SELECT id FROM platforms WHERE id = ? AND status = 'Active' LIMIT 1");
            $platformStmt->bind_param('i', $platformId);
            $platformStmt->execute();
            $validPlatform = $platformStmt->get_result()->fetch_assoc();
            $platformStmt->close();
            if (!$validPlatform) {
                throw new Exception('Select an active Platform by editing this courier request before approving the new customer.');
            }
            $secMobile = !empty($step1['secondary_mobile_no']) ? trim($step1['secondary_mobile_no']) : null;
            $email = !empty($step1['email']) ? trim($step1['email']) : null;
            $location = !empty($step1['location']) ? trim($step1['location']) : '';
            $pincode = !empty($step1['pincode']) ? trim($step1['pincode']) : '';

            $insCust = $conn->prepare("
                INSERT INTO customers (platform_id, username, primary_mobile_no, secondary_mobile_no, email, location, pincode, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')
            ");
            $insCust->bind_param("issssss", $platformId, $username, $primaryMobile, $secMobile, $email, $location, $pincode);
            if (!$insCust->execute()) {
                throw new Exception("Failed to create customer: " . $insCust->error);
            }
            $owner_id = $insCust->insert_id;
            $insCust->close();

            writeCreatedFields($conn, $owner_id, 'Customer', [
                'platform_id' => $platformId,
                'username' => $username,
                'primary_mobile_no' => $primaryMobile,
                'status' => 'Active'
            ], $currentUser);
        }
        }

        // Insert Vehicle Details if present
        $step2 = $newCustomerData['step2'] ?? [];
        $vehicleNo = trim((string)($step2['vehicle_no'] ?? ''));
        $vTypeId = !empty($step2['vehicle_type_id']) ? (int)$step2['vehicle_type_id'] : null;
        $vDevModelId = !empty($step2['device_model_id']) ? (int)$step2['device_model_id'] : (int)($request['device_model_id'] ?? 0);
        $vImeiNo = trim((string)($step2['imei_no'] ?? ''));
        $vSim1 = trim((string)($step2['sim_no_1'] ?? ''));
        $vSim2 = trim((string)($step2['sim_no_2'] ?? ''));
        $vSim2 = $vSim2 !== '' ? $vSim2 : null;
        $vValidityId = !empty($step2['validity_id']) ? (int)$step2['validity_id'] : null;
        $vValidityMonths = 0;
        $customerVehicleId = null;
        if ($vValidityId) {
            $validityStmt = $conn->prepare('SELECT months FROM sim_validities WHERE id = ? LIMIT 1');
            $validityStmt->bind_param('i', $vValidityId);
            $validityStmt->execute();
            $validityRow = $validityStmt->get_result()->fetch_assoc();
            $validityStmt->close();
            $vValidityMonths = (int) ($validityRow['months'] ?? 0);
        }

        if ($vehicleNo !== '' || $vImeiNo !== '') {
            if ($vSim1 === '') {
                throw new Exception('SIM No 1 is required for customer vehicle details.');
            }
            if (!preg_match('/^(?:\d{10}|\d{13})$/', $vSim1)) {
                throw new Exception('SIM No 1 must contain exactly 10 or 13 digits.');
            }
            if ($vSim2 !== null && !preg_match('/^(?:\d{10}|\d{13})$/', $vSim2)) {
                throw new Exception('SIM No 2 must contain exactly 10 or 13 digits.');
            }
            if ($vSim2 !== null && $vSim1 === $vSim2) {
                throw new Exception('SIM No 1 and SIM No 2 cannot be the same.');
            }
            $chkVehTable = $conn->query("SHOW TABLES LIKE 'customer_vehicle_details'");
            if ($chkVehTable && $chkVehTable->num_rows > 0) {
                $existingVehicleStmt = $conn->prepare("
                    SELECT id
                    FROM customer_vehicle_details
                    WHERE customer_id = ?
                      AND ((? <> '' AND vehicle_no = ?) OR (? <> '' AND imei_no = ?))
                    FOR UPDATE
                ");
                $existingVehicleStmt->bind_param("issss", $owner_id, $vehicleNo, $vehicleNo, $vImeiNo, $vImeiNo);
                $existingVehicleStmt->execute();
                $existingVehicles = $existingVehicleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $existingVehicleStmt->close();
                if (count($existingVehicles) > 1) {
                    throw new Exception('The submitted vehicle or IMEI matches multiple customer vehicle records. Verify the customer data before approval.');
                }
                if ($existingVehicles) {
                    $customerVehicleId = (int)$existingVehicles[0]['id'];
                } else {
                    $insVeh = $conn->prepare("
                        INSERT INTO customer_vehicle_details (
                            customer_id, vehicle_no, vehicle_type_id, device_model_id, imei_no, sim_no_1, sim_no_2, validity_months
                        ) VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0))
                    ");
                    $insVeh->bind_param("isiisssi", $owner_id, $vehicleNo, $vTypeId, $vDevModelId, $vImeiNo, $vSim1, $vSim2, $vValidityMonths);
                    if (!$insVeh->execute()) {
                        throw new Exception('Failed to create customer vehicle details: ' . $insVeh->error);
                    }
                    $customerVehicleId = (int) $insVeh->insert_id;
                    $insVeh->close();
                }
            }
        }

        // Insert Installation Details if present
        $step3 = $newCustomerData['step3'] ?? [];
        $requestedInstPersonType = !empty($step3['installation_person_type']) ? trim($step3['installation_person_type']) : null;
        $instPersonId = !empty($step3['installation_person_id']) ? (int)$step3['installation_person_id'] : null;
        $instPersonType = null;
        if ($instPersonId) {
            if ($requestedInstPersonType === 'Technician') {
                $instPersonStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
                $instPersonStmt->bind_param('i', $instPersonId);
                $instPersonStmt->execute();
                $validInstPerson = $instPersonStmt->get_result()->fetch_assoc();
                $instPersonStmt->close();
                $instPersonType = 'Technician';
            } elseif (in_array($requestedInstPersonType, ['Onsite Dealer', 'Offsite Dealer'], true)) {
                $expectedInstallationStatus = $requestedInstPersonType === 'Onsite Dealer' ? 'Onsite' : 'Offsite';
                $instPersonStmt = $conn->prepare('SELECT id FROM dealers WHERE id = ? AND LOWER(TRIM(installation_status)) = LOWER(?) LIMIT 1');
                $instPersonStmt->bind_param('is', $instPersonId, $expectedInstallationStatus);
                $instPersonStmt->execute();
                $validInstPerson = $instPersonStmt->get_result()->fetch_assoc();
                $instPersonStmt->close();
                $instPersonType = 'Dealer';
            } else {
                throw new Exception('Invalid installation person type for the selected person.');
            }

            if (!$validInstPerson) {
                throw new Exception('Selected installation person does not exist or does not match the selected type.');
            }
        } elseif ($requestedInstPersonType === 'Direct') {
            $instPersonType = 'Direct';
        } elseif ($requestedInstPersonType === 'Technician') {
            $instPersonType = 'Technician';
        } elseif (in_array($requestedInstPersonType, ['Onsite Dealer', 'Offsite Dealer'], true)) {
            $instPersonType = 'Dealer';
        }
        $instDate = !empty($step3['installation_date']) ? $step3['installation_date'] : null;
        $leadClosureId = !empty($step3['lead_closure_id']) ? (int)$step3['lead_closure_id'] : null;
        if ($leadClosureId) {
            $leadClosureStmt = $conn->prepare("SELECT id FROM lead_closures WHERE id = ? AND (status IS NULL OR status = '' OR LOWER(status) = 'active') LIMIT 1");
            $leadClosureStmt->bind_param('i', $leadClosureId);
            $leadClosureStmt->execute();
            $validLeadClosure = $leadClosureStmt->get_result()->fetch_assoc();
            $leadClosureStmt->close();
            if (!$validLeadClosure) {
                throw new Exception('Selected lead closure is inactive or does not exist.');
            }
        }

        if ($instPersonType !== null && ($instPersonId || $instDate || $leadClosureId)) {
            $chkInstTable = $conn->query("SHOW TABLES LIKE 'customer_installations'");
            if ($chkInstTable && $chkInstTable->num_rows > 0) {
                $existingInstallationStmt = $conn->prepare("
                    SELECT id
                    FROM customer_installations
                    WHERE customer_id = ?
                      AND installation_person_type = ?
                      AND installation_person_id <=> NULLIF(?, 0)
                      AND installation_date <=> ?
                      AND lead_closure_id <=> NULLIF(?, 0)
                    LIMIT 1
                    FOR UPDATE
                ");
                $existingInstallationStmt->bind_param("isisi", $owner_id, $instPersonType, $instPersonId, $instDate, $leadClosureId);
                $existingInstallationStmt->execute();
                $existingInstallation = $existingInstallationStmt->get_result()->fetch_assoc();
                $existingInstallationStmt->close();
                if (!$existingInstallation) {
                    $insInst = $conn->prepare("
                        INSERT INTO customer_installations (
                            customer_id, installation_person_type, installation_person_id, installation_date, lead_closure_id
                        ) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0))
                    ");
                    $insInst->bind_param("isisi", $owner_id, $instPersonType, $instPersonId, $instDate, $leadClosureId);
                    if (!$insInst->execute()) {
                        throw new Exception('Failed to create customer installation details: ' . $insInst->error);
                    }
                    $insInst->close();
                }
            }
        }

        // Insert Payment Details if present
        $step4 = $newCustomerData['step4'] ?? [];
        $step5 = $newCustomerData['step5'] ?? [];
        $totalSaleAmount = isset($step4['totalSaleAmount'])
            ? (float)$step4['totalSaleAmount']
            : (float)($step5['totalAmount'] ?? 0);
        $pMode = !empty($step4['paymentMode'])
            ? trim($step4['paymentMode'])
            : (!empty($step5['paymentMode']) ? trim($step5['paymentMode']) : null);
        $txId = normalizeTransactionId(
            !empty($step4['transactionId'])
                ? $step4['transactionId']
                : ($step5['transactionId'] ?? '')
        );
        if ($pMode && !in_array($pMode, getPaymentModes(), true)) throw new Exception('Invalid payment mode.');
        if ($txId !== '' && !$pMode) throw new Exception('Payment Mode is required when Transaction ID is entered.');
        if ($pMode && $pMode !== 'Cash' && $txId === '') throw new Exception('Transaction ID is required for the selected Payment Mode.');
        $amountPaid = isset($step5['amountPaid']) && $step5['amountPaid'] !== ''
            ? (float)$step5['amountPaid']
            : 0.0;
        $deviceCharge = (float)($step5['deviceCharge'] ?? 0);
        $softwareCharge = (float)($step5['softwareCharge'] ?? 0);
        $technicianCharge = (float)($step5['technicianCharge'] ?? 0);
        $simCharge = (float)($step5['simCharge'] ?? 0);
        $courierCharge = (float)($step5['courierCharge'] ?? 0);
        if ($totalSaleAmount < 0 || $amountPaid < 0 ||
            $deviceCharge < 0 || $softwareCharge < 0 || $technicianCharge < 0 ||
            $simCharge < 0 || $courierCharge < 0) {
            throw new Exception('Payment amounts and charges cannot be negative.');
        }
        $totalCharges = round(
            $deviceCharge + $softwareCharge + $technicianCharge + $simCharge + $courierCharge,
            2
        );
        if ($totalCharges > 0 && ($totalSaleAmount <= 0 || abs($totalCharges - $totalSaleAmount) > 0.005)) {
            throw new Exception('Total charges must equal Total Sale Amount.');
        }
        foreach ([
            'Device Charge' => $deviceCharge,
            'Software Charge' => $softwareCharge,
            'Technician Charge' => $technicianCharge,
            'SIM Charge' => $simCharge,
            'Courier Charge' => $courierCharge
        ] as $chargeName => $chargeValue) {
            if ($chargeValue > $totalSaleAmount) {
                throw new Exception($chargeName . ' cannot exceed Total Sale Amount.');
            }
        }
        $totalAmount = $totalCharges > 0
            ? $totalCharges
            : ($totalSaleAmount > 0 ? $totalSaleAmount : 0.0);
        if ($amountPaid > $totalAmount) {
            throw new Exception('Amount Paid cannot exceed Total Amount.');
        }
        $amountPending = $totalAmount > 0 ? round(max(0.0, $totalAmount - $amountPaid), 2) : 0.0;
        $requestedPaymentStatus = trim((string)($step5['paymentStatus'] ?? ''));
        $pendingOnly = $totalCharges <= 0 && $totalSaleAmount > 0 &&
            $amountPaid <= 0 && !$pMode && $txId === '';
        $pStatus = $pendingOnly
            ? 'Pending'
            : ($totalAmount <= 0
                ? 'Pending'
                : ($amountPaid <= 0
                ? ($requestedPaymentStatus === 'Pending' ? 'Pending' : 'Not Paid')
                : ($amountPaid < $totalAmount ? 'Partially Paid' : 'Paid')));

        if ($totalSaleAmount > 0 || $totalAmount > 0 || $amountPaid > 0) {
            $chkPayTable = $conn->query("SHOW TABLES LIKE 'customer_payments'");
            if ($chkPayTable && $chkPayTable->num_rows > 0) {
                $paymentVehicleId = (int)($customerVehicleId ?? 0);
                $paymentTransactionId = $txId !== '' ? $txId : null;
                $paymentMode = $pMode ?? '';
                $paymentWhere = $paymentVehicleId > 0 ? 'vehicle_id = ?' : 'customer_id = ?';
                $paymentTargetId = $paymentVehicleId > 0 ? $paymentVehicleId : $owner_id;
                $existingPaymentStmt = $conn->prepare("
                    SELECT *
                    FROM customer_payments
                    WHERE {$paymentWhere}
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $existingPaymentStmt->bind_param('i', $paymentTargetId);
                $existingPaymentStmt->execute();
                $existingPayment = $existingPaymentStmt->get_result()->fetch_assoc();
                $existingPaymentStmt->close();

                if ($existingPayment) {
                    $paymentId = (int) $existingPayment['id'];
                    $paymentChanges = [
                        'total_sale_amount' => $totalSaleAmount,
                        'transaction_id' => $paymentTransactionId,
                        'payment_mode' => $pMode
                    ];
                    if ($totalAmount > 0) {
                        $paymentChanges += [
                            'device_charge' => $deviceCharge,
                            'software_charge' => $softwareCharge,
                            'technician_charge' => $technicianCharge,
                            'sim_charge' => $simCharge,
                            'courier_charge' => $courierCharge,
                            'total_amount' => $totalAmount,
                            'amount_paid' => $amountPaid,
                            'amount_pending' => $amountPending,
                            'payment_status' => $pStatus
                        ];
                    }
                    writeChangedFields($conn, $paymentId, 'Customer Payment', $existingPayment, $paymentChanges, $currentUser);
                    if ($totalAmount > 0) {
                        $updatePaymentStmt = $conn->prepare("
                            UPDATE customer_payments
                            SET total_sale_amount = ?, transaction_id = NULLIF(?, ''),
                                payment_mode = NULLIF(?, ''), device_charge = ?,
                                software_charge = ?, technician_charge = ?, sim_charge = ?,
                                courier_charge = ?, total_amount = ?, amount_paid = ?,
                                amount_pending = ?, payment_status = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $updatePaymentStmt->bind_param(
                            'dssddddddddsi',
                            $totalSaleAmount,
                            $paymentTransactionId,
                            $paymentMode,
                            $deviceCharge,
                            $softwareCharge,
                            $technicianCharge,
                            $simCharge,
                            $courierCharge,
                            $totalAmount,
                            $amountPaid,
                            $amountPending,
                            $pStatus,
                            $paymentId
                        );
                    } else {
                        $updatePaymentStmt = $conn->prepare("
                            UPDATE customer_payments
                            SET total_sale_amount = ?, transaction_id = NULLIF(?, ''),
                                payment_mode = NULLIF(?, ''), updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $updatePaymentStmt->bind_param(
                            'dssi',
                            $totalSaleAmount,
                            $paymentTransactionId,
                            $paymentMode,
                            $paymentId
                        );
                    }
                    if (!$updatePaymentStmt->execute()) {
                        throw new Exception('Failed to update customer payment details: ' . $updatePaymentStmt->error);
                    }
                    $updatePaymentStmt->close();
                } else {
                    $insPay = $conn->prepare("
                        INSERT INTO customer_payments (
                            customer_id, vehicle_id, total_sale_amount, transaction_id, payment_mode,
                            device_charge, software_charge, technician_charge, sim_charge, courier_charge,
                            total_amount, amount_paid, amount_pending, payment_status
                        ) VALUES (?, NULLIF(?, 0), ?, ?, NULLIF(?, ''),
                            ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insPay->bind_param(
                        "iidssdddddddds",
                        $owner_id,
                        $paymentVehicleId,
                        $totalSaleAmount,
                        $paymentTransactionId,
                        $paymentMode,
                        $deviceCharge,
                        $softwareCharge,
                        $technicianCharge,
                        $simCharge,
                        $courierCharge,
                        $totalAmount,
                        $amountPaid,
                        $amountPending,
                        $pStatus
                    );
                    if (!$insPay->execute()) {
                        throw new Exception('Failed to create customer payment details: ' . $insPay->error);
                    }
                    $paymentId = (int) $insPay->insert_id;
                    $insPay->close();
                }
                reserveTransactionId($conn, (string) ($paymentTransactionId ?? ''), 'customer_payments', (string) $paymentId);
            }
        }
    }

    if (in_array($owner_type, ['dealer', 'technician'], true) && !empty($request['new_customer_data'])) {
        $requestWorkflowData = json_decode($request['new_customer_data'], true) ?: [];
        $paymentWorkflow = $requestWorkflowData['payment_workflow'] ?? [];
        $paymentStep1 = $paymentWorkflow['step4'] ?? [];
        $paymentStep2 = $paymentWorkflow['step5'] ?? [];
        $saleAmount = (float)($paymentStep1['totalSaleAmount'] ?? 0);
        $deviceCharge = (float)($paymentStep2['deviceCharge'] ?? 0);
        $softwareCharge = (float)($paymentStep2['softwareCharge'] ?? 0);
        $technicianCharge = (float)($paymentStep2['technicianCharge'] ?? 0);
        $simCharge = (float)($paymentStep2['simCharge'] ?? 0);
        $courierCharge = (float)($paymentStep2['courierCharge'] ?? 0);
        $amountPaid = (float)($paymentStep2['amountPaid'] ?? 0);
        $paymentMode = trim((string)($paymentStep1['paymentMode'] ?? ''));
        $transactionId = normalizeTransactionId((string)($paymentStep1['transactionId'] ?? ''));
        $chargesTotal = round(
            $deviceCharge + $softwareCharge + $technicianCharge + $simCharge + $courierCharge,
            2
        );
        $hasPaymentData = $saleAmount > 0 || $chargesTotal > 0 || $amountPaid > 0 || $paymentMode !== '' || $transactionId !== '';

        if ($hasPaymentData) {
            if ($saleAmount <= 0 || min($deviceCharge, $softwareCharge, $technicianCharge, $simCharge, $courierCharge, $amountPaid) < 0) {
                throw new Exception('Courier payment amounts are invalid.');
            }
            if ($chargesTotal > 0 && abs($chargesTotal - $saleAmount) > 0.005) {
                throw new Exception('Total charges must equal Total Sale Amount.');
            }
            if ($amountPaid > ($chargesTotal > 0 ? $chargesTotal : $saleAmount)) {
                throw new Exception('Amount Paid cannot exceed Total Amount.');
            }
            if ($paymentMode !== '' && !in_array($paymentMode, getPaymentModes(), true)) {
                throw new Exception('Invalid payment mode.');
            }
            if ($transactionId !== '' && $paymentMode === '') {
                throw new Exception('Payment Mode is required when Transaction ID is entered.');
            }
            if ($paymentMode !== '' && $paymentMode !== 'Cash' && $transactionId === '') {
                throw new Exception('Transaction ID is required for the selected Payment Mode.');
            }

            $allocationPayment = [
                'enabled' => true,
                'sale_amount' => $saleAmount,
                'device_charge' => $deviceCharge,
                'sim_charge' => $simCharge,
                'shared_charges' => $softwareCharge + $technicianCharge + $courierCharge,
                'charges_total' => $chargesTotal,
                'total_amount' => $chargesTotal > 0 ? $chargesTotal : $saleAmount,
                'amount_paid' => $amountPaid,
                'payment_mode' => $paymentMode !== '' ? $paymentMode : null,
                'transaction_id' => $transactionId !== '' ? $transactionId : null
            ];
        }
    }

    // 4. Change Approval Status to Approved in courier_requests
    $requestCustomerId = $owner_type === 'customer' ? $owner_id : 0;
    $updReq = $conn->prepare("UPDATE courier_requests SET customer_id = NULLIF(?, 0), approval_status = 'Approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $updReq->bind_param("ii", $requestCustomerId, $id);
    if (!$updReq->execute()) {
        throw new Exception("Failed to update approval status: " . $updReq->error);
    }
    $updReq->close();

    $groupDeviceCount = $device_id ? 1 : 0;
    $groupSimCount = $sim_id ? 1 : 0;
    if (!empty($request['request_code'])) {
        $groupCountStmt = $conn->prepare("
            SELECT COUNT(device_id) AS device_count, COUNT(sim_id) AS sim_count
            FROM courier_requests
            WHERE request_code = ?
        ");
        $groupCountStmt->bind_param('s', $request['request_code']);
        $groupCountStmt->execute();
        $groupCounts = $groupCountStmt->get_result()->fetch_assoc();
        $groupCountStmt->close();
        $groupDeviceCount = max(0, (int)($groupCounts['device_count'] ?? 0));
        $groupSimCount = max(0, (int)($groupCounts['sim_count'] ?? 0));
    }
    $groupAssetCount = max(1, $groupDeviceCount + $groupSimCount);
    $getAllocationPayment = static function (string $assetType) use (
        $allocationPayment,
        $groupDeviceCount,
        $groupSimCount,
        $groupAssetCount
    ): array {
        if (!$allocationPayment['enabled']) {
            return ['asset_amount' => 0.0, 'total_amount' => 0.0, 'amount_paid' => 0.0, 'pending_amount' => 0.0, 'payment_status' => 'Not Paid'];
        }

        $hasChargeBreakdown = $allocationPayment['charges_total'] > 0;
        if ($assetType === 'device') {
            $assetAmount = $groupDeviceCount > 0
                ? $allocationPayment['device_charge'] / $groupDeviceCount
                    + ($groupSimCount === 0 ? $allocationPayment['sim_charge'] / $groupDeviceCount : 0.0)
                : 0.0;
        } else {
            $assetAmount = $groupSimCount > 0
                ? $allocationPayment['sim_charge'] / $groupSimCount
                    + ($groupDeviceCount === 0 ? $allocationPayment['device_charge'] / $groupSimCount : 0.0)
                : 0.0;
        }
        $sharedAmount = $allocationPayment['shared_charges'] / $groupAssetCount;
        $lineTotal = $hasChargeBreakdown
            ? $assetAmount + $sharedAmount
            : $allocationPayment['total_amount'] / $groupAssetCount;
        $lineTotal = round($lineTotal, 2);
        $paidAmount = $allocationPayment['total_amount'] > 0
            ? round(min($lineTotal, $allocationPayment['amount_paid'] * $lineTotal / $allocationPayment['total_amount']), 2)
            : 0.0;
        $pendingAmount = max(0.0, $lineTotal - $paidAmount);
        $status = $lineTotal <= 0
            ? 'Not Paid'
            : ($paidAmount <= 0 ? 'Not Paid' : ($paidAmount < $lineTotal ? 'Partially Paid' : 'Paid'));

        return [
            'asset_amount' => round($assetAmount, 2),
            'total_amount' => $lineTotal,
            'amount_paid' => $paidAmount,
            'pending_amount' => round($pendingAmount, 2),
            'payment_status' => $status
        ];
    };

    // 5. Allocate Device to Owner (Dealer, Technician, or Customer)
    if ($device_id) {
        $devicePayment = $getAllocationPayment('device');
        $deviceAmount = $devicePayment['asset_amount'];
        $totalAllocationAmount = $devicePayment['total_amount'];
        $paidAmount = $devicePayment['amount_paid'];
        $pendingAmount = $devicePayment['pending_amount'];
        $allocationStatus = $devicePayment['payment_status'];
        $paymentMode = $allocationPayment['payment_mode'];
        $transactionId = $allocationPayment['transaction_id'];
        $allocDevStmt = $conn->prepare("
            INSERT INTO stock_allocations (
                owner_type, owner_id, device_id, allocation_type, allocation_date,
                device_amount, total_amount, amount_paid, pending_amount,
                payment_status, payment_mode, transaction_id, software, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $allocDevStmt->bind_param(
            "siissddddsssss",
            $owner_type,
            $owner_id,
            $device_id,
            $owner_type,
            $allocation_date,
            $deviceAmount,
            $totalAllocationAmount,
            $paidAmount,
            $pendingAmount,
            $allocationStatus,
            $paymentMode,
            $transactionId,
            $software,
            $notes
        );
        if (!$allocDevStmt->execute()) {
            throw new Exception("Failed to create device stock allocation: " . $allocDevStmt->error);
        }
        $allocDevStmt->close();

        // Update device status to 'allocated'
        $updDevStatus = $conn->prepare("UPDATE devices SET status = 'allocated' WHERE id = ?");
        $updDevStatus->bind_param("i", $device_id);
        if (!$updDevStatus->execute()) {
            throw new Exception("Failed to update device status to allocated: " . $updDevStatus->error);
        }
        $updDevStatus->close();
    }

    // 6. Allocate SIM to Owner (Dealer, Technician, or Customer)
    if ($sim_id) {
        $sim_validity_id = (int)($simRow['sim_validity_id'] ?? 0);
        $simPayment = $getAllocationPayment('sim');
        $simAmount = $simPayment['asset_amount'];
        $totalAllocationAmount = $simPayment['total_amount'];
        $paidAmount = $simPayment['amount_paid'];
        $pendingAmount = $simPayment['pending_amount'];
        $allocationStatus = $simPayment['payment_status'];
        $paymentMode = $allocationPayment['payment_mode'];
        $transactionId = $allocationPayment['transaction_id'];
        $allocSimStmt = $conn->prepare("
            INSERT INTO stock_allocations (
                owner_type, owner_id, sim_id, allocation_type, allocation_date, 
                sim_given_date, sim_validity_id, sim_status, sim_amount,
                total_amount, amount_paid, pending_amount, payment_status,
                payment_mode, transaction_id, software, notes
            ) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), 'Available', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $allocSimStmt->bind_param(
            "siisssiddddsssss",
            $owner_type,
            $owner_id,
            $sim_id,
            $owner_type,
            $allocation_date,
            $allocation_date,
            $sim_validity_id,
            $simAmount,
            $totalAllocationAmount,
            $paidAmount,
            $pendingAmount,
            $allocationStatus,
            $paymentMode,
            $transactionId,
            $software,
            $notes
        );
        if (!$allocSimStmt->execute()) {
            throw new Exception("Failed to create SIM stock allocation: " . $allocSimStmt->error);
        }
        $allocSimStmt->close();

        // Update SIM status to 'allocated'
        $updSimStatus = $conn->prepare("UPDATE sims SET status = 'allocated' WHERE id = ?");
        $updSimStatus->bind_param("i", $sim_id);
        if (!$updSimStatus->execute()) {
            throw new Exception("Failed to update SIM status to allocated: " . $updSimStatus->error);
        }
        $updSimStatus->close();
    }

    // 7. Generic History Audit
    $auditMsg = $isNewCustomer ? 'Admin Approved (New Customer Created)' : 'Admin Approved';
    writeAudit($conn, $id, 'Courier', $auditMsg, 'approval_status', 'Pending Approval', 'Approved', $currentUser);

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier request approved successfully and asset allocated to " . ucfirst($owner_type) . ".");

} catch (InvalidArgumentException $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
