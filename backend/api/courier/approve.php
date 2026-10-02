<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

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

try {
    // 2. Verify selected Device is available or reserved by this request.
    if ($device_id) {
        $devStmt = $conn->prepare("SELECT id, status FROM devices WHERE id = ? FOR UPDATE");
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
        $simStmt = $conn->prepare("SELECT id, status, sim_validity_id FROM sims WHERE id = ? FOR UPDATE");
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

    // Handle New Customer Creation if this is a New Customer request
    if ($isNewCustomer) {
        $newCustomerData = !empty($request['new_customer_data']) ? json_decode($request['new_customer_data'], true) : [];
        $step1 = $newCustomerData['step1'] ?? [];
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

        // Insert Vehicle Details if present
        $step2 = $newCustomerData['step2'] ?? [];
        $vehicleNo = trim((string)($step2['vehicle_no'] ?? ''));
        $vTypeId = !empty($step2['vehicle_type_id']) ? (int)$step2['vehicle_type_id'] : null;
        $vDevModelId = !empty($step2['device_model_id']) ? (int)$step2['device_model_id'] : (int)($request['device_model_id'] ?? 0);
        $vImeiNo = trim((string)($step2['imei_no'] ?? ''));
        $vSim1 = trim((string)($step2['sim_no_1'] ?? ''));
        $vSim2 = trim((string)($step2['sim_no_2'] ?? ''));
        $vValidityId = !empty($step2['validity_id']) ? (int)$step2['validity_id'] : null;
        $vValidityMonths = 0;
        if ($vValidityId) {
            $validityStmt = $conn->prepare('SELECT months FROM sim_validities WHERE id = ? LIMIT 1');
            $validityStmt->bind_param('i', $vValidityId);
            $validityStmt->execute();
            $validityRow = $validityStmt->get_result()->fetch_assoc();
            $validityStmt->close();
            $vValidityMonths = (int) ($validityRow['months'] ?? 0);
        }

        if ($vehicleNo !== '' || $vImeiNo !== '') {
            $chkVehTable = $conn->query("SHOW TABLES LIKE 'customer_vehicle_details'");
            if ($chkVehTable && $chkVehTable->num_rows > 0) {
                $insVeh = $conn->prepare("
                    INSERT INTO customer_vehicle_details (
                        customer_id, vehicle_no, vehicle_type_id, device_model_id, imei_no, sim_no_1, sim_no_2, validity_months
                    ) VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0))
                ");
                $insVeh->bind_param("isiisssi", $owner_id, $vehicleNo, $vTypeId, $vDevModelId, $vImeiNo, $vSim1, $vSim2, $vValidityMonths);
                $insVeh->execute();
                $insVeh->close();
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
                $insInst = $conn->prepare("
                    INSERT INTO customer_installations (
                        customer_id, installation_person_type, installation_person_id, installation_date, lead_closure_id
                    ) VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0))
                ");
                $insInst->bind_param("isisi", $owner_id, $instPersonType, $instPersonId, $instDate, $leadClosureId);
                $insInst->execute();
                $insInst->close();
            }
        }

        // Insert Payment Details if present
        $step4 = $newCustomerData['step4'] ?? [];
        $step5 = $newCustomerData['step5'] ?? [];
        $totalSaleAmount = !empty($step4['totalSaleAmount']) ? (float)$step4['totalSaleAmount'] : (!empty($step5['totalAmount']) ? (float)$step5['totalAmount'] : 0.0);
        $pMode = !empty($step5['paymentMode']) ? trim($step5['paymentMode']) : (!empty($step4['paymentMode']) ? trim($step4['paymentMode']) : null);
        $txId = !empty($step5['transactionId']) ? trim($step5['transactionId']) : (!empty($step4['transactionId']) ? trim($step4['transactionId']) : null);
        $amountPaid = !empty($step5['amountPaid']) ? (float)$step5['amountPaid'] : 0.0;
        $amountPending = !empty($step5['amountPending']) ? (float)$step5['amountPending'] : max(0.0, $totalSaleAmount - $amountPaid);
        $pStatus = !empty($step5['paymentStatus']) ? trim($step5['paymentStatus']) : ($amountPaid >= $totalSaleAmount && $totalSaleAmount > 0 ? 'Paid' : ($amountPaid > 0 ? 'Partial' : 'Not Paid'));

        if ($totalSaleAmount > 0 || $amountPaid > 0 || $pMode) {
            $chkPayTable = $conn->query("SHOW TABLES LIKE 'customer_payments'");
            if ($chkPayTable && $chkPayTable->num_rows > 0) {
                $insPay = $conn->prepare("
                    INSERT INTO customer_payments (
                        customer_id, total_sale_amount, amount_paid, pending_amount, payment_status, payment_mode, transaction_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insPay->bind_param("idddsss", $owner_id, $totalSaleAmount, $amountPaid, $amountPending, $pStatus, $pMode, $txId);
                $insPay->execute();
                $insPay->close();
            }
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

    // 5. Allocate Device to Owner (Dealer, Technician, or Customer)
    if ($device_id) {
        $allocDevStmt = $conn->prepare("
            INSERT INTO stock_allocations (
                owner_type, owner_id, device_id, allocation_type, allocation_date, 
                device_amount, total_amount, amount_paid, pending_amount, 
                payment_status, software, notes
            ) VALUES (
                ?, ?, ?, ?, ?, 
                0.00, 0.00, 0.00, 0.00, 
                'Not Paid', ?, ?
            )
        ");
        $allocDevStmt->bind_param("siissss", $owner_type, $owner_id, $device_id, $owner_type, $allocation_date, $software, $notes);
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
        $allocSimStmt = $conn->prepare("
            INSERT INTO stock_allocations (
                owner_type, owner_id, sim_id, allocation_type, allocation_date, 
                sim_given_date, sim_validity_id, sim_status, sim_amount, 
                total_amount, amount_paid, pending_amount, payment_status, 
                software, notes
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, NULLIF(?, 0), 'Available', 0.00, 
                0.00, 0.00, 0.00, 'Not Paid', 
                ?, ?
            )
        ");
        $allocSimStmt->bind_param("siisssiss", $owner_type, $owner_id, $sim_id, $owner_type, $allocation_date, $allocation_date, $sim_validity_id, $software, $notes);
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

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
