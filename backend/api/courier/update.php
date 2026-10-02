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
ensurePermissionDefinitions(['courier.edit', 'courier.view']);

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

require_once __DIR__ . '/setup_table.php';

// Fetch existing request
$conn->begin_transaction();
$fetchStmt = $conn->prepare("SELECT * FROM courier_requests WHERE id = ? FOR UPDATE");
$fetchStmt->bind_param("i", $id);
$fetchStmt->execute();
$existing = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$existing) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, "Courier request not found", [], [], 404);
}

$currentApprovalStatus = $existing['approval_status'];
$newTrackingId = trim((string)($data->tracking_id ?? $existing['tracking_id']));
$newCourierSendVia = trim((string)($data->courier_send_via ?? $existing['courier_send_via']));
$validSendVias = ['St Courier', 'ST Courier', 'Professional', 'Delhivery', 'DTDC', 'Trackon'];
if (!empty($newCourierSendVia) && !in_array($newCourierSendVia, $validSendVias, true)) {
    $conn->close();
    sendResponse(false, "Courier Send Via must be St Courier, Professional, Delhivery, DTDC, or Trackon.", [], [], 400);
}

$newDeviceCount = (int)($data->device_count ?? $existing['device_count'] ?? 1);
if ($newDeviceCount < 1) $newDeviceCount = 1;

$newSimCount = (int)($data->sim_count ?? $existing['sim_count'] ?? 1);
if ($newSimCount < 1) $newSimCount = 1;

$newCourierStatus = trim((string)($data->courier_status ?? $existing['courier_status']));
if (!in_array($newCourierStatus, ['Pending', 'Reached', 'Not Reached'], true)) {
    $newCourierStatus = $existing['courier_status'] ?: 'Pending';
}
$newCourierReason = trim((string)($data->courier_reason ?? $existing['courier_reason']));
$newNotes = trim((string)($data->notes ?? $existing['notes']));
$newSoftware = trim((string)($data->software ?? $existing['software']));
$newRequestDate = trim((string)($data->request_date ?? $data->date ?? $existing['request_date']));
$newCourierDate = trim((string)($data->courier_date ?? $existing['courier_date']));

if (isFutureDate($newRequestDate) || isFutureDate($newCourierDate)) {
    $conn->close();
    sendResponse(false, "Future dates are not allowed", [], [], 400);
}

// Check if request is Approved
if ($currentApprovalStatus === 'Approved') {
    // Section 14: Approved request - Do not allow changing the approved Device/SIM to another asset.
    $newDeviceId = (int)($data->device_id ?? $existing['device_id']);
    $newSimId = (int)($data->sim_id ?? $existing['sim_id']);
    if ($newDeviceId !== (int)$existing['device_id'] || $newSimId !== (int)$existing['sim_id']) {
        $conn->close();
        sendResponse(false, "Approved requests cannot change the allocated Device or SIM.", [], [], 400);
    }

    // Only update non-asset fields
    try {
        $updStmt = $conn->prepare("
            UPDATE courier_requests 
            SET tracking_id = ?, courier_send_via = ?, courier_status = ?, courier_reason = ?, notes = ?, software = ?, courier_date = ?, request_date = ?
            WHERE id = ?
        ");
        $updStmt->bind_param("ssssssssi", $newTrackingId, $newCourierSendVia, $newCourierStatus, $newCourierReason, $newNotes, $newSoftware, $newCourierDate, $newRequestDate, $id);
        $updStmt->execute();
        $updStmt->close();

        // Audit Log
        writeAudit($conn, $id, 'Courier', 'Courier Request Edited', 'courier_info', $existing, [
            'tracking_id' => $newTrackingId,
            'courier_send_via' => $newCourierSendVia,
            'courier_status' => $newCourierStatus,
            'courier_reason' => $newCourierReason,
            'notes' => $newNotes,
            'software' => $newSoftware,
            'courier_date' => $newCourierDate,
            'request_date' => $newRequestDate
        ], $currentUser);

        $conn->commit();
        $conn->close();
        sendResponse(true, "Courier request updated successfully.");
    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, $e->getMessage(), [], [], 500);
    }
    exit;
}

// For Pending Approval or Rejected requests
$newCourierTo = trim((string)($data->courier_to_person ?? $existing['courier_to_person']));
if (!in_array($newCourierTo, ['Dealer', 'Technician', 'Customer'], true)) {
    $newCourierTo = 'Dealer';
}

$newDealerId = $newCourierTo === 'Dealer' ? (int)($data->dealer_id ?? $existing['dealer_id']) : null;
$newTechnicianId = $newCourierTo === 'Technician' ? (int)($data->technician_id ?? $existing['technician_id']) : null;
$newCustomerId = $newCourierTo === 'Customer' ? (int)($data->customer_id ?? $existing['customer_id']) : null;
$newCustomerDataJson = $existing['new_customer_data'];
if (!empty($existing['is_new_customer']) && isset($data->new_customer_data)) {
    $newCustomerData = json_decode(json_encode($data->new_customer_data), true) ?: [];
    $step1 = $newCustomerData['step1'] ?? [];
    $platformId = (int)($step1['platform_id'] ?? 0);
    $platformStmt = $conn->prepare("SELECT id FROM platforms WHERE id = ? AND status = 'Active' LIMIT 1");
    $platformStmt->bind_param('i', $platformId);
    $platformStmt->execute();
    $validPlatform = $platformStmt->get_result()->fetch_assoc();
    $platformStmt->close();
    if (!$validPlatform) {
        $conn->close();
        sendResponse(false, 'Select an active Platform for the new customer.', [], [], 400);
    }
    $newCustomerDataJson = json_encode($newCustomerData);
}

$newAssetType = trim((string)($data->asset_type ?? $existing['asset_type']));
$newDeviceModelId = (int)($data->device_model_id ?? 0);
$newDeviceId = (int)($data->device_id ?? 0);
$newSimType = trim((string)($data->sim_type ?? ''));
$newSimId = (int)($data->sim_id ?? 0);

if ($newAssetType === 'device' || $newAssetType === 'both') {
    if ($newDeviceModelId <= 0 || $newDeviceId <= 0) {
        $conn->close();
        sendResponse(false, "Device Model and IMEI No are required for Device asset type.", [], [], 400);
    }
} else {
    $newDeviceModelId = null;
    $newDeviceId = null;
}

if ($newAssetType === 'sim' || $newAssetType === 'both') {
    if ($newSimType === '' || $newSimId <= 0) {
        $conn->close();
        sendResponse(false, "SIM Type and SIM No are required for SIM asset type.", [], [], 400);
    }
} else {
    $newSimType = null;
    $newSimId = null;
}

try {
    // 1. Release old device reservation if device changed
    if ((int)$existing['device_id'] > 0 && (int)$existing['device_id'] !== $newDeviceId) {
        if ($existing['approval_status'] === 'Pending Approval') {
            $otherDevOwner = $conn->prepare("SELECT id FROM courier_requests WHERE device_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
            $otherDevOwner->bind_param("ii", $existing['device_id'], $id);
            $otherDevOwner->execute();
            $hasOtherDevOwner = $otherDevOwner->get_result()->num_rows > 0;
            $otherDevOwner->close();
            if (!$hasOtherDevOwner) {
                $relDev = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ? AND status = 'reserved'");
                $relDev->bind_param("i", $existing['device_id']);
                if (!$relDev->execute()) {
                    throw new Exception("Failed to release Device reservation: " . $relDev->error);
                }
                $relDev->close();
            }
        }
    }

    // 2. Release old SIM reservation if SIM changed
    if ((int)$existing['sim_id'] > 0 && (int)$existing['sim_id'] !== $newSimId) {
        if ($existing['approval_status'] === 'Pending Approval') {
            $otherSimOwner = $conn->prepare("SELECT id FROM courier_requests WHERE sim_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
            $otherSimOwner->bind_param("ii", $existing['sim_id'], $id);
            $otherSimOwner->execute();
            $hasOtherSimOwner = $otherSimOwner->get_result()->num_rows > 0;
            $otherSimOwner->close();
            if (!$hasOtherSimOwner) {
                $relSim = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ? AND status = 'reserved'");
                $relSim->bind_param("i", $existing['sim_id']);
                if (!$relSim->execute()) {
                    throw new Exception("Failed to release SIM reservation: " . $relSim->error);
                }
                $relSim->close();
            }
        }
    }

    // 3. Verify and Reserve new Device if changed or resubmitting
    if ($newDeviceId && ((int)$existing['device_id'] !== $newDeviceId || $currentApprovalStatus === 'Rejected')) {
        $devCheck = $conn->prepare("SELECT status FROM devices WHERE id = ? FOR UPDATE");
        $devCheck->bind_param("i", $newDeviceId);
        $devCheck->execute();
        $devRes = $devCheck->get_result()->fetch_assoc();
        $devCheck->close();

        if (!$devRes) throw new Exception("Selected Device not found");

        $pendingCheck = $conn->prepare("SELECT id FROM courier_requests WHERE device_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $pendingCheck->bind_param("ii", $newDeviceId, $id);
        $pendingCheck->execute();
        $otherDeviceOwner = $pendingCheck->get_result()->fetch_assoc();
        $pendingCheck->close();

        if ($otherDeviceOwner) {
            throw new Exception("This Device/SIM is already reserved in a pending Courier request.");
        }

        $samePendingReservation = $currentApprovalStatus === 'Pending Approval'
            && (int)$existing['device_id'] === $newDeviceId
            && $devRes['status'] === 'reserved';
        if ($devRes['status'] !== 'available' && !$samePendingReservation) {
            throw new Exception("Selected Device is already {$devRes['status']}");
        }

        if ($devRes['status'] === 'available') {
            $updDev = $conn->prepare("UPDATE devices SET status = 'reserved' WHERE id = ? AND status = 'available'");
            $updDev->bind_param("i", $newDeviceId);
            if (!$updDev->execute() || $updDev->affected_rows !== 1) {
                throw new Exception("Selected Device is no longer available to reserve.");
            }
            $updDev->close();
        }
    }

    // 4. Verify and Reserve new SIM if changed or resubmitting
    if ($newSimId && ((int)$existing['sim_id'] !== $newSimId || $currentApprovalStatus === 'Rejected')) {
        $simCheck = $conn->prepare("SELECT status FROM sims WHERE id = ? FOR UPDATE");
        $simCheck->bind_param("i", $newSimId);
        $simCheck->execute();
        $simRes = $simCheck->get_result()->fetch_assoc();
        $simCheck->close();

        if (!$simRes) throw new Exception("Selected SIM not found");

        $pendingSimCheck = $conn->prepare("SELECT id FROM courier_requests WHERE sim_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $pendingSimCheck->bind_param("ii", $newSimId, $id);
        $pendingSimCheck->execute();
        $otherSimOwner = $pendingSimCheck->get_result()->fetch_assoc();
        $pendingSimCheck->close();

        if ($otherSimOwner) {
            throw new Exception("This Device/SIM is already reserved in a pending Courier request.");
        }

        $samePendingReservation = $currentApprovalStatus === 'Pending Approval'
            && (int)$existing['sim_id'] === $newSimId
            && $simRes['status'] === 'reserved';
        if ($simRes['status'] !== 'available' && !$samePendingReservation) {
            throw new Exception("Selected SIM is already {$simRes['status']}");
        }

        if ($simRes['status'] === 'available') {
            $updSim = $conn->prepare("UPDATE sims SET status = 'reserved' WHERE id = ? AND status = 'available'");
            $updSim->bind_param("i", $newSimId);
            if (!$updSim->execute() || $updSim->affected_rows !== 1) {
                throw new Exception("Selected SIM is no longer available to reserve.");
            }
            $updSim->close();
        }
    }

    // If rejected request is edited, reset approval_status to 'Pending Approval'
    $newApprovalStatus = $currentApprovalStatus === 'Rejected' ? 'Pending Approval' : $currentApprovalStatus;

    $updSql = "
        UPDATE courier_requests SET
            courier_to_person = ?,
            dealer_id = NULLIF(?, 0),
            technician_id = NULLIF(?, 0),
            customer_id = NULLIF(?, 0),
            new_customer_data = ?,
            asset_type = ?,
            device_count = ?,
            device_model_id = NULLIF(?, 0),
            device_id = NULLIF(?, 0),
            sim_count = ?,
            sim_type = ?,
            sim_id = NULLIF(?, 0),
            software = ?,
            request_date = ?,
            notes = ?,
            courier_date = ?,
            tracking_id = ?,
            courier_send_via = ?,
            courier_status = ?,
            courier_reason = ?,
            approval_status = ?
        WHERE id = ?
    ";
    $updStmt = $conn->prepare($updSql);
    $updStmt->bind_param(
        "siiissiiiisisssssssssi",
        $newCourierTo,
        $newDealerId,
        $newTechnicianId,
        $newCustomerId,
        $newCustomerDataJson,
        $newAssetType,
        $newDeviceCount,
        $newDeviceModelId,
        $newDeviceId,
        $newSimCount,
        $newSimType,
        $newSimId,
        $newSoftware,
        $newRequestDate,
        $newNotes,
        $newCourierDate,
        $newTrackingId,
        $newCourierSendVia,
        $newCourierStatus,
        $newCourierReason,
        $newApprovalStatus,
        $id
    );
    if (!$updStmt->execute()) {
        throw new Exception("Failed to update courier request: " . $updStmt->error);
    }
    $updStmt->close();

    // Audit Log
    $actionName = ($existing['courier_status'] !== $newCourierStatus) ? 'Courier Status Changed' : 'Courier Request Edited';
    writeChangedFields($conn, $id, 'Courier', $existing, [
        'courier_to_person' => $newCourierTo,
        'dealer_id' => $newDealerId,
        'technician_id' => $newTechnicianId,
        'customer_id' => $newCustomerId,
        'asset_type' => $newAssetType,
        'device_count' => $newDeviceCount,
        'device_model_id' => $newDeviceModelId,
        'device_id' => $newDeviceId,
        'sim_count' => $newSimCount,
        'sim_type' => $newSimType,
        'sim_id' => $newSimId,
        'software' => $newSoftware,
        'request_date' => $newRequestDate,
        'notes' => $newNotes,
        'courier_date' => $newCourierDate,
        'tracking_id' => $newTrackingId,
        'courier_send_via' => $newCourierSendVia,
        'courier_status' => $newCourierStatus,
        'courier_reason' => $newCourierReason,
        'approval_status' => $newApprovalStatus
    ], $currentUser);

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier request updated successfully.");

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
