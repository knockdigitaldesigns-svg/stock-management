<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
ensurePermissionDefinitions(['courier.add', 'courier.view']);

$data = json_decode(file_get_contents("php://input"));

$courier_to_person = trim((string)($data->courier_to_person ?? 'Dealer'));
if (!in_array($courier_to_person, ['Dealer', 'Technician', 'Customer'], true)) {
    sendResponse(false, "Invalid Courier To Person. Must be Dealer, Technician, or Customer.", [], [], 400);
}

$dealer_id = null;
$technician_id = null;
$customer_id = null;
$is_new_customer = false;
$newCustomerData = null;
$personName = '';
$personStatus = '';

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

if ($courier_to_person === 'Dealer') {
    $dealer_id = (int)($data->dealer_id ?? 0);
    if ($dealer_id <= 0) {
        $conn->close();
        sendResponse(false, "Select Dealer is required", [], [], 400);
    }
    // Verify Dealer Exists
    $dealerStmt = $conn->prepare("SELECT id, dealer_name, installation_status FROM dealers WHERE id = ?");
    $dealerStmt->bind_param("i", $dealer_id);
    $dealerStmt->execute();
    $dealerRow = $dealerStmt->get_result()->fetch_assoc();
    $dealerStmt->close();

    if (!$dealerRow) {
        $conn->close();
        sendResponse(false, "Selected Dealer not found", [], [], 404);
    }
    $personName = $dealerRow['dealer_name'];
    $personStatus = $dealerRow['installation_status'];

} elseif ($courier_to_person === 'Technician') {
    $technician_id = (int)($data->technician_id ?? 0);
    if ($technician_id <= 0) {
        $conn->close();
        sendResponse(false, "Select Technician is required", [], [], 400);
    }
    // Verify Technician Exists
    $techStmt = $conn->prepare("SELECT id, technician_name FROM technicians WHERE id = ?");
    $techStmt->bind_param("i", $technician_id);
    $techStmt->execute();
    $techRow = $techStmt->get_result()->fetch_assoc();
    $techStmt->close();

    if (!$techRow) {
        $conn->close();
        sendResponse(false, "Selected Technician not found", [], [], 404);
    }
    $personName = $techRow['technician_name'];
    $personName = $techRow['technician_name'];
    $personStatus = 'Technician';
} elseif ($courier_to_person === 'Customer') {
    $is_new_customer = !empty($data->is_new_customer) || (string)($data->customer_id ?? '') === '__create_new__' || !empty($data->new_customer_data);
    
    if ($is_new_customer) {
        $newCustomerData = $data->new_customer_data ?? (object)[];
        $step1 = $newCustomerData->step1 ?? (object)[];
        $username = trim((string)($step1->username ?? $data->username ?? ''));
        $primaryMobile = trim((string)($step1->primary_mobile_no ?? $data->primary_mobile_no ?? ''));

        if ($username === '') {
            $conn->close();
            sendResponse(false, "Username is required for new customer.", [], [], 400);
        }
        if ($primaryMobile === '') {
            $conn->close();
            sendResponse(false, "Primary mobile number is required for new customer.", [], [], 400);
        }
        if (!preg_match('/^[0-9]{10}$/', $primaryMobile)) {
            $conn->close();
            sendResponse(false, "Primary mobile number must contain exactly 10 digits.", [], [], 400);
        }

        // Check if username already exists with different mobile
        $stmt = $conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(username)) = LOWER(?) AND TRIM(primary_mobile_no) != ? LIMIT 1");
        $stmt->bind_param("ss", $username, $primaryMobile);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            $conn->close();
            sendResponse(false, "Username already exists with a different mobile number.", [], [], 409);
        }
        $stmt->close();

        $personName = $username;
        $personStatus = 'New Customer';
        $customer_id = null;
    } else {
        $customer_id = (int)($data->customer_id ?? 0);
        if ($customer_id <= 0) {
            $conn->close();
            sendResponse(false, "Select Customer is required", [], [], 400);
        }
        // Verify Customer Exists
        $custStmt = $conn->prepare("SELECT id, username, status FROM customers WHERE id = ?");
        $custStmt->bind_param("i", $customer_id);
        $custStmt->execute();
        $custRow = $custStmt->get_result()->fetch_assoc();
        $custStmt->close();

        if (!$custRow) {
            $conn->close();
            sendResponse(false, "Selected Customer not found", [], [], 404);
        }
        $personName = $custRow['username'];
        $personStatus = $custRow['status'];
    }
}

$asset_type = trim((string)($data->asset_type ?? ''));
if (!in_array($asset_type, ['device', 'sim', 'both'], true)) {
    $conn->close();
    sendResponse(false, "Valid Asset Type (Device, SIM, or Both Devices & SIMs) is required", [], [], 400);
}

// Build $deviceItems list
$deviceItems = [];
if ($asset_type === 'device' || $asset_type === 'both') {
    if (isset($data->devices) && is_array($data->devices) && count($data->devices) > 0) {
        foreach ($data->devices as $devObj) {
            $mId = (int)($devObj->device_model_id ?? 0);
            $dId = (int)($devObj->device_id ?? 0);
            if ($mId > 0 && $dId > 0) {
                $deviceItems[] = ['device_model_id' => $mId, 'device_id' => $dId];
            }
        }
    } elseif (!empty($data->device_id) && !empty($data->device_model_id)) {
        $deviceItems[] = ['device_model_id' => (int)$data->device_model_id, 'device_id' => (int)$data->device_id];
    }

    if (empty($deviceItems)) {
        $conn->close();
        sendResponse(false, "Device Model and IMEI No are required for all Device rows.", [], [], 400);
    }
}

// Build $simItems list
$simItems = [];
if ($asset_type === 'sim' || $asset_type === 'both') {
    if (isset($data->sims) && is_array($data->sims) && count($data->sims) > 0) {
        foreach ($data->sims as $simObj) {
            $sType = trim((string)($simObj->sim_type ?? ''));
            $sId = (int)($simObj->sim_id ?? 0);
            if (!empty($sType) && $sId > 0) {
                $simItems[] = ['sim_type' => $sType, 'sim_id' => $sId];
            }
        }
    } elseif (!empty($data->sim_id) && !empty($data->sim_type)) {
        $simItems[] = ['sim_type' => trim((string)$data->sim_type), 'sim_id' => (int)$data->sim_id];
    }

    if (empty($simItems)) {
        $conn->close();
        sendResponse(false, "SIM Type and SIM No are required for all SIM rows.", [], [], 400);
    }
}

require_once __DIR__ . '/setup_table.php';

$courier_send_via = !empty($data->courier_send_via) ? trim((string)$data->courier_send_via) : null;
$validSendVias = ['St Courier', 'ST Courier', 'Professional', 'Delhivery', 'DTDC', 'Trackon'];
if ($courier_send_via !== null && !in_array($courier_send_via, $validSendVias, true)) {
    $courier_send_via = null;
}

$software = trim((string)($data->software ?? ''));
$courier_date = trim((string)($data->courier_date ?? getTodayDate()));
$request_date = trim((string)($data->request_date ?? $data->date ?? $courier_date));

if (isFutureDate($request_date) || isFutureDate($courier_date)) {
    $conn->close();
    sendResponse(false, "Future dates are not allowed", [], [], 400);
}

$notes = trim((string)($data->notes ?? ''));
$tracking_id = !empty($data->tracking_id) ? trim((string)$data->tracking_id) : null;
$courier_status = !empty($data->courier_status) ? trim((string)$data->courier_status) : 'Pending';
if (!in_array($courier_status, ['Pending', 'Reached', 'Not Reached'], true)) {
    $courier_status = 'Pending';
}
$courier_reason = !empty($data->courier_reason) ? trim((string)$data->courier_reason) : null;

// Validate Device Availability & Duplicate Protection
$validatedDevices = [];
foreach ($deviceItems as $idx => $dItem) {
    $dId = $dItem['device_id'];
    $mId = $dItem['device_model_id'];

    $devCheck = $conn->prepare("
        SELECT d.id, d.status, d.device_model_id, dt.device_type AS model_name, d.imei_no 
        FROM devices d 
        JOIN device_types dt ON dt.id = d.device_model_id 
        WHERE d.id = ? 
        FOR UPDATE
    ");
    $devCheck->bind_param("i", $dId);
    $devCheck->execute();
    $devResult = $devCheck->get_result()->fetch_assoc();
    $devCheck->close();

    if (!$devResult) {
        $conn->close();
        sendResponse(false, "Selected Device #" . ($idx + 1) . " not found", [], [], 404);
    }

    if ((int)$devResult['device_model_id'] !== $mId) {
        $conn->close();
        sendResponse(false, "IMEI for Device #" . ($idx + 1) . " does not match the selected Device Model", [], [], 400);
    }

    $pendingCheck = $conn->prepare("SELECT id FROM courier_requests WHERE device_id = ? AND approval_status = 'Pending Approval'");
    $pendingCheck->bind_param("i", $dId);
    $pendingCheck->execute();
    $isPending = $pendingCheck->get_result()->num_rows > 0;
    $pendingCheck->close();

    if ($isPending || $devResult['status'] === 'reserved') {
        $conn->close();
        sendResponse(false, "Device #" . ($idx + 1) . " (IMEI: {$devResult['imei_no']}) is already reserved in a pending Courier request.", [], [], 400);
    }

    if ($devResult['status'] !== 'available') {
        $conn->close();
        sendResponse(false, "Device #" . ($idx + 1) . " (IMEI: {$devResult['imei_no']}) is already {$devResult['status']}.", [], [], 400);
    }

    $validatedDevices[] = [
        'device_model_id' => $mId,
        'device_id' => $dId,
        'model_name' => $devResult['model_name'],
        'imei_no' => $devResult['imei_no']
    ];
}

// Validate SIM Availability & Duplicate Protection
$validatedSims = [];
foreach ($simItems as $idx => $sItem) {
    $sId = $sItem['sim_id'];
    $sType = $sItem['sim_type'];

    $simCheck = $conn->prepare("
        SELECT id, status, sim_no, sim_type 
        FROM sims 
        WHERE id = ? 
        FOR UPDATE
    ");
    $simCheck->bind_param("i", $sId);
    $simCheck->execute();
    $simResult = $simCheck->get_result()->fetch_assoc();
    $simCheck->close();

    if (!$simResult) {
        $conn->close();
        sendResponse(false, "Selected SIM #" . ($idx + 1) . " not found", [], [], 404);
    }

    if (!empty($simResult['sim_type']) && strtolower(trim($simResult['sim_type'])) !== strtolower(trim($sType))) {
        $conn->close();
        sendResponse(false, "SIM No for SIM #" . ($idx + 1) . " does not match the selected SIM Type", [], [], 400);
    }

    $pendingSimCheck = $conn->prepare("SELECT id FROM courier_requests WHERE sim_id = ? AND approval_status = 'Pending Approval'");
    $pendingSimCheck->bind_param("i", $sId);
    $pendingSimCheck->execute();
    $isSimPending = $pendingSimCheck->get_result()->num_rows > 0;
    $pendingSimCheck->close();

    if ($isSimPending || $simResult['status'] === 'reserved') {
        $conn->close();
        sendResponse(false, "SIM #" . ($idx + 1) . " (No: {$simResult['sim_no']}) is already reserved in a pending Courier request.", [], [], 400);
    }

    if ($simResult['status'] !== 'available') {
        $conn->close();
        sendResponse(false, "SIM #" . ($idx + 1) . " (No: {$simResult['sim_no']}) is already {$simResult['status']}.", [], [], 400);
    }

    $validatedSims[] = [
        'sim_type' => $sType,
        'sim_id' => $sId,
        'sim_no' => $simResult['sim_no']
    ];
}

// Begin DB Transaction
$conn->begin_transaction();

try {
    $requested_by_user_id = (int)($currentUser['user_id'] ?? 0);
    $requested_by_name = (string)($currentUser['username'] ?? 'Admin');

    $totalDevices = count($validatedDevices);
    $totalSims = count($validatedSims);
    $totalItems = 1;
    if ($asset_type === 'device') $totalItems = max(1, $totalDevices);
    elseif ($asset_type === 'sim') $totalItems = max(1, $totalSims);
    elseif ($asset_type === 'both') $totalItems = max(1, max($totalDevices, $totalSims));

    $firstRequestId = null;
    $requestCode = '';

    for ($i = 0; $i < $totalItems; $i++) {
        $curDev = $validatedDevices[$i] ?? ($totalDevices > 0 ? $validatedDevices[0] : null);
        $curSim = $validatedSims[$i] ?? ($totalSims > 0 ? $validatedSims[0] : null);

        $curDevModelId = $curDev ? $curDev['device_model_id'] : null;
        $curDeviceId = $curDev ? $curDev['device_id'] : null;

        $curSimType = $curSim ? $curSim['sim_type'] : null;
        $curSimId = $curSim ? $curSim['sim_id'] : null;

        $isNewCustInt = $is_new_customer ? 1 : 0;
        $newCustDataStr = $is_new_customer ? json_encode($newCustomerData) : null;

        $insertSql = "
            INSERT INTO courier_requests (
                courier_to_person, dealer_id, technician_id, customer_id, is_new_customer, new_customer_data,
                asset_type, device_count, device_model_id, device_id, 
                sim_count, sim_type, sim_id, software, request_date, notes, courier_date, 
                tracking_id, courier_send_via, courier_status, courier_reason, approval_status, 
                requested_by_user_id, requested_by_name
            ) VALUES (
                ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?,
                ?, ?, NULLIF(?, 0), NULLIF(?, 0), 
                ?, ?, NULLIF(?, 0), ?, ?, ?, ?, 
                ?, ?, ?, ?, 'Pending Approval', 
                ?, ?
            )
        ";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            throw new Exception("Failed to prepare courier request insert: " . $conn->error);
        }

        $stmt->bind_param(
            "siiiissiiiisissssssssis",
            $courier_to_person,
            $dealer_id,
            $technician_id,
            $customer_id,
            $isNewCustInt,
            $newCustDataStr,
            $asset_type,
            $totalDevices,
            $curDevModelId,
            $curDeviceId,
            $totalSims,
            $curSimType,
            $curSimId,
            $software,
            $request_date,
            $notes,
            $courier_date,
            $tracking_id,
            $courier_send_via,
            $courier_status,
            $courier_reason,
            $requested_by_user_id,
            $requested_by_name
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save courier request: " . $stmt->error);
        }

        $rowId = $stmt->insert_id;
        $stmt->close();

        if ($i === 0) {
            $firstRequestId = $rowId;
            $requestCode = 'CR-' . str_pad((string)$rowId, 4, '0', STR_PAD_LEFT);
        }

        // Update request_code for all inserted rows in this batch
        $updCode = $conn->prepare("UPDATE courier_requests SET request_code = ? WHERE id = ?");
        $updCode->bind_param("si", $requestCode, $rowId);
        $updCode->execute();
        $updCode->close();

        // Mark device reserved (Only for existing Dealer, Technician, or Existing Customer)
        if ($curDeviceId && !$is_new_customer) {
            $updDev = $conn->prepare("UPDATE devices SET status = 'reserved' WHERE id = ?");
            $updDev->bind_param("i", $curDeviceId);
            $updDev->execute();
            $updDev->close();
        }

        // Mark SIM reserved (Only for existing Dealer, Technician, or Existing Customer)
        if ($curSimId && !$is_new_customer) {
            $updSim = $conn->prepare("UPDATE sims SET status = 'reserved' WHERE id = ?");
            $updSim->bind_param("i", $curSimId);
            $updSim->execute();
            $updSim->close();
        }
    }

    // Write Generic History Audit
    $allImeisStr = implode(', ', array_column($validatedDevices, 'imei_no'));
    $allModelsStr = implode(', ', array_unique(array_column($validatedDevices, 'model_name')));
    $allSimsStr = implode(', ', array_column($validatedSims, 'sim_no'));
    $allSimTypesStr = implode(', ', array_unique(array_column($validatedSims, 'sim_type')));

    $auditData = [
        'request_id' => $requestCode,
        'courier_to_person' => $courier_to_person,
        'recipient' => $personName,
        'recipient_status' => $personStatus,
        'asset_type' => $asset_type,
        'device_count' => $totalDevices,
        'device_model' => $allModelsStr,
        'imei_no' => $allImeisStr,
        'sim_count' => $totalSims,
        'sim_type' => $allSimTypesStr,
        'sim_no' => $allSimsStr,
        'software' => $software,
        'request_date' => $request_date,
        'courier_date' => $courier_date,
        'tracking_id' => $tracking_id,
        'courier_send_via' => $courier_send_via,
        'courier_status' => $courier_status,
        'courier_reason' => $courier_reason,
        'approval_status' => 'Pending Approval',
        'notes' => $notes
    ];

    writeAudit($conn, $firstRequestId, 'Courier', 'Courier Request Created', 'record_snapshot', null, $auditData, $currentUser);

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier request created successfully and asset reserved.", [
        "id" => $firstRequestId,
        "request_code" => $requestCode
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
