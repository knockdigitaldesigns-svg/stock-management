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
        sendResponse(false, "Unauthorized: Only authorized Admin users can reject courier requests.", [], [], 403);
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

// Start the transaction before taking the request lock so reject/approve cannot race.
$conn->begin_transaction();

// 1. Fetch request with FOR UPDATE lock
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

$device_id = !empty($request['device_id']) ? (int)$request['device_id'] : null;
$sim_id = !empty($request['sim_id']) ? (int)$request['sim_id'] : null;

try {
    // Release only reservations still linked to this request, preserving any
    // reservation another pending request may now own.
    if ($device_id) {
        $otherDevOwner = $conn->prepare("SELECT id FROM courier_requests WHERE device_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $otherDevOwner->bind_param("ii", $device_id, $id);
        $otherDevOwner->execute();
        $hasOtherDevOwner = $otherDevOwner->get_result()->num_rows > 0;
        $otherDevOwner->close();

        if (!$hasOtherDevOwner) {
            $updDev = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ? AND status = 'reserved'");
            $updDev->bind_param("i", $device_id);
            if (!$updDev->execute()) {
                throw new Exception("Failed to release Device reservation: " . $updDev->error);
            }
            $updDev->close();
        }
    }

    if ($sim_id) {
        $otherSimOwner = $conn->prepare("SELECT id FROM courier_requests WHERE sim_id = ? AND approval_status = 'Pending Approval' AND id != ? LIMIT 1");
        $otherSimOwner->bind_param("ii", $sim_id, $id);
        $otherSimOwner->execute();
        $hasOtherSimOwner = $otherSimOwner->get_result()->num_rows > 0;
        $otherSimOwner->close();

        if (!$hasOtherSimOwner) {
            $updSim = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ? AND status = 'reserved'");
            $updSim->bind_param("i", $sim_id);
            if (!$updSim->execute()) {
                throw new Exception("Failed to release SIM reservation: " . $updSim->error);
            }
            $updSim->close();
        }
    }

    // Change the request state only after its owned reservations are released.
    $updReq = $conn->prepare("UPDATE courier_requests SET approval_status = 'Rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $updReq->bind_param("i", $id);
    if (!$updReq->execute()) {
        throw new Exception("Failed to update approval status to Rejected: " . $updReq->error);
    }
    $updReq->close();

    // Generic History Audit (Section 17)
    writeAudit($conn, $id, 'Courier', 'Admin Reject', 'approval_status', 'Pending Approval', 'Rejected', $currentUser);

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier request rejected and reservation released.");

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
