<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('vehicle_types.delete');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload.', [], [], 400);
}

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(
        false,
        'Vehicle type ID is required.',
        [],
        [],
        400
    );
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}

$oldStmt = $conn->prepare('SELECT * FROM vehicle_types WHERE id = ? LIMIT 1');
$oldStmt->bind_param('i', $id); $oldStmt->execute(); $oldVehicleType = $oldStmt->get_result()->fetch_assoc(); $oldStmt->close();
if (!$oldVehicleType) sendResponse(false, 'Vehicle type not found.', [], [], 404);
$conn->begin_transaction();
try {
writeDeleteSnapshot($conn, $id, 'Vehicle Type', $oldVehicleType, $currentUser);
// Check whether this vehicle type is being used
// by customer records before deleting.
//
// IMPORTANT:
// Add the actual customer table reference here
// once Customer Details table is created.
//
// For now, only delete the master record.

$stmt = $conn->prepare(
    'DELETE FROM vehicle_types WHERE id = ?'
);

$stmt->bind_param('i', $id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    throw new RuntimeException('Vehicle type not found.');
}

$stmt->close();
$conn->commit();
$conn->close();

sendResponse(
    true,
    'Vehicle type deleted successfully.'
);
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>