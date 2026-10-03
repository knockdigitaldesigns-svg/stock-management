<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/setup_table.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
ensurePermissionDefinitions(['courier.edit', 'courier.approve', 'courier.view']);

$userId = (int)($currentUser['user_id'] ?? 0);
$isSuperAdmin = isSuperAdminUser($userId);
$userPermissions = getUserPermissions($userId);

if (!$isSuperAdmin && !in_array('courier.edit', $userPermissions, true) && !in_array('courier.approve', $userPermissions, true)) {
    sendResponse(false, "Unauthorized: You do not have permission to update courier tracking details.", [], [], 403);
}

$data = json_decode(file_get_contents("php://input"));

$id = (int)($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, "Courier Request ID is required", [], [], 400);
}

$courier_send_via = trim((string)($data->courier_send_via ?? ''));
$validSendVias = ['St Courier', 'ST Courier', 'Professional', 'Delhivery', 'DTDC', 'Trackon'];
if (empty($courier_send_via) || !in_array($courier_send_via, $validSendVias, true)) {
    sendResponse(false, "Courier Send Via is required and must be St Courier, Professional, Delhivery, DTDC, or Trackon.", [], [], 400);
}

$tracking_id = trim((string)($data->tracking_id ?? ''));

$courier_status = trim((string)($data->courier_status ?? ''));
$validStatuses = ['Pending', 'Reached', 'Not Reached'];
if (empty($courier_status) || !in_array($courier_status, $validStatuses, true)) {
    sendResponse(false, "Courier Status is required and must be Pending, Reached, or Not Reached.", [], [], 400);
}

$courier_reason = trim((string)($data->courier_reason ?? ''));
if ($courier_status === 'Not Reached' && empty($courier_reason)) {
    sendResponse(false, "Reason is required when Courier Status is Not Reached.", [], [], 400);
}

if ($courier_status !== 'Not Reached') {
    $courier_reason = '';
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Fetch existing record
$fetchStmt = $conn->prepare("SELECT * FROM courier_requests WHERE id = ? FOR UPDATE");
$fetchStmt->bind_param("i", $id);
$fetchStmt->execute();
$existing = $fetchStmt->get_result()->fetch_assoc();
$fetchStmt->close();

if (!$existing) {
    $conn->close();
    sendResponse(false, "Courier request not found", [], [], 404);
}

if ($existing['approval_status'] === 'Rejected') {
    $conn->close();
    sendResponse(false, "Courier tracking details cannot be updated for rejected requests.", [], [], 400);
}

$conn->begin_transaction();
try {
    $updStmt = $conn->prepare("
        UPDATE courier_requests 
        SET courier_send_via = ?, tracking_id = ?, courier_status = ?, courier_reason = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $updStmt->bind_param("ssssi", $courier_send_via, $tracking_id, $courier_status, $courier_reason, $id);
    if (!$updStmt->execute()) {
        throw new Exception("Failed to update courier tracking information: " . $updStmt->error);
    }
    $updStmt->close();

    // Audit Log
    $actionName = ($existing['courier_status'] !== $courier_status) ? 'Courier Status Changed' : 'Courier Tracking Updated';
    writeChangedFields($conn, $id, 'Courier', $existing, [
        'courier_send_via' => $courier_send_via,
        'tracking_id' => $tracking_id,
        'courier_status' => $courier_status,
        'courier_reason' => $courier_reason
    ], $currentUser);

    $conn->commit();
    $conn->close();

    sendResponse(true, "Courier tracking information updated successfully.", [
        "id" => $id,
        "courier_send_via" => $courier_send_via,
        "tracking_id" => $tracking_id,
        "courier_status" => $courier_status,
        "courier_reason" => $courier_reason
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
