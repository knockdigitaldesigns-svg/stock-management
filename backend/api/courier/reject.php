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

// 1. Fetch request with FOR UPDATE lock
$fetchStmt = $conn->prepare("SELECT * FROM courier_requests WHERE id = ? FOR UPDATE");
$fetchStmt->bind_param("i", $id);
$fetchStmt->execute();
$request = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$request) {
    $conn->close();
    sendResponse(false, "Courier request not found", [], [], 404);
}

if ($request['approval_status'] !== 'Pending Approval') {
    $conn->close();
    sendResponse(false, "Courier request is already {$request['approval_status']}.", [], [], 400);
}

$device_id = !empty($request['device_id']) ? (int)$request['device_id'] : null;
$sim_id = !empty($request['sim_id']) ? (int)$request['sim_id'] : null;

$conn->begin_transaction();

try {
    // 2. Change Approval Status to Rejected
    $updReq = $conn->prepare("UPDATE courier_requests SET approval_status = 'Rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $updReq->bind_param("i", $id);
    if (!$updReq->execute()) {
        throw new Exception("Failed to update approval status to Rejected: " . $updReq->error);
    }
    $updReq->close();

    // 3. Release Device Reservation -> status becomes 'available'
    if ($device_id) {
        $updDev = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ? AND status = 'reserved'");
        $updDev->bind_param("i", $device_id);
        $updDev->execute();
        $updDev->close();
    }

    // 4. Release SIM Reservation -> status becomes 'available'
    if ($sim_id) {
        $updSim = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ? AND status = 'reserved'");
        $updSim->bind_param("i", $sim_id);
        $updSim->execute();
        $updSim->close();
    }

    // 5. Generic History Audit (Section 17)
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
