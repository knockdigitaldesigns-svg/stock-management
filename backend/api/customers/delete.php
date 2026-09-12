<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' &&
    $_SERVER['REQUEST_METHOD'] !== 'POST') {

    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customers.delete');

$data = json_decode(file_get_contents('php://input'));

$customerId = isset($data->id)
    ? (int) $data->id
    : 0;

if ($customerId <= 0) {
    sendResponse(
        false,
        'Customer ID is required.',
        [],
        [],
        400
    );
}


$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}


/*
|--------------------------------------------------------------------------
| Check Customer
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id
     FROM customers
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param('i', $customerId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Customer not found.',
        [],
        [],
        404
    );
}

$stmt->close();

$conn->begin_transaction();

try {
    $vehicleStmt = $conn->prepare('SELECT device_id, sim_id_1, sim_id_2 FROM customer_vehicle_details WHERE customer_id = ? FOR UPDATE');
    $vehicleStmt->bind_param('i', $customerId);
    $vehicleStmt->execute();
    $vehicles = $vehicleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $vehicleStmt->close();

    foreach ($vehicles as $vehicle) {
        foreach (['device_id' => 'devices', 'sim_id_1' => 'sims', 'sim_id_2' => 'sims'] as $column => $table) {
            $assetId = (int) ($vehicle[$column] ?? 0);
            if (!$assetId) {
                continue;
            }
            $allocationColumn = $column === 'device_id' ? 'device_id' : 'sim_id';
            $allocationStmt = $conn->prepare("SELECT owner_type, owner_id FROM stock_allocations WHERE {$allocationColumn} = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE");
            $allocationStmt->bind_param('i', $assetId);
            $allocationStmt->execute();
            $allocation = $allocationStmt->get_result()->fetch_assoc();
            $allocationStmt->close();

            if ($allocation) {
                $restoreStmt = $conn->prepare("UPDATE {$table} SET status = 'allocated', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $restoreStmt->bind_param('i', $assetId);
                $restoreStmt->execute();
                $restoreStmt->close();
                $ownerType = (string) $allocation['owner_type'];
                $ownerId = (int) $allocation['owner_id'];
                $transactionStmt = $conn->prepare("DELETE FROM stock_transactions WHERE {$allocationColumn} = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET'");
                $transactionStmt->bind_param('isi', $assetId, $ownerType, $ownerId);
                $transactionStmt->execute();
                $transactionStmt->close();
            } else {
                $restoreStmt = $conn->prepare("UPDATE {$table} SET status = 'available', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $restoreStmt->bind_param('i', $assetId);
                $restoreStmt->execute();
                $restoreStmt->close();
            }
        }
    }


/*
|--------------------------------------------------------------------------
| Delete Customer
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "DELETE FROM customers
     WHERE id = ?"
);

$stmt->bind_param('i', $customerId);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();
    throw new Exception('Failed to delete customer.');
}

$stmt->close();
$conn->commit();
$conn->close();


sendResponse(
    true,
    'Customer deleted successfully.',
    [
        'customer_id' => $customerId
    ]
);

} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

?>