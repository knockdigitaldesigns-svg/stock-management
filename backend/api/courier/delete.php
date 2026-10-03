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
ensurePermissionDefinitions(['courier.delete', 'courier.view']);

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

// Fetch request
$fetchStmt = $conn->prepare("SELECT * FROM courier_requests WHERE id = ? FOR UPDATE");
$fetchStmt->bind_param("i", $id);
$fetchStmt->execute();
$request = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$request) {
    $conn->close();
    sendResponse(false, "Courier request not found", [], [], 404);
}

$approvalStatus = $request['approval_status'];
$device_id = !empty($request['device_id']) ? (int)$request['device_id'] : null;
$sim_id = !empty($request['sim_id']) ? (int)$request['sim_id'] : null;

$conn->begin_transaction();

try {
    if ($approvalStatus === 'Pending Approval') {
        // Pending Courier Request: Delete request -> release reservation -> Device/SIM becomes Available again
        if ($device_id) {
            $updDev = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ? AND status = 'reserved'");
            $updDev->bind_param("i", $device_id);
            $updDev->execute();
            $updDev->close();
        }
        if ($sim_id) {
            $updSim = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ? AND status = 'reserved'");
            $updSim->bind_param("i", $sim_id);
            $updSim->execute();
            $updSim->close();
        }
    } elseif ($approvalStatus === 'Rejected') {
        // Rejected Request: Delete request -> no stock impact
    } elseif ($approvalStatus === 'Approved') {
        // Approved Request: Do NOT blindly delete the actual Dealer allocation.
        // Keep actual allocation intact, but record deletion of courier tracking record if authorized.
    }

    // Write Generic History Audit (Section 17 & 15)
    writeDeleteSnapshot($conn, $id, 'Courier', $request, $currentUser);

    // Delete record from courier_requests
    $delStmt = $conn->prepare("DELETE FROM courier_requests WHERE id = ?");
    $delStmt->bind_param("i", $id);
    if (!$delStmt->execute()) {
        throw new Exception("Failed to delete courier request: " . $delStmt->error);
    }
    $delStmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier request deleted successfully.");

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
