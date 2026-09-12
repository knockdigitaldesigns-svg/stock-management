<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customers.delete');

$data = json_decode(
    file_get_contents('php://input')
);

$id = isset($data->id)
    ? (int) $data->id
    : (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    sendResponse(
        false,
        'Vehicle detail ID is required.',
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

$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | GET VEHICLE RECORD
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'SELECT
            id,
            device_id,
            sim_id_1,
            sim_id_2
         FROM customer_vehicle_details
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare vehicle detail query.'
        );
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $stmt->close();

        throw new Exception(
            'Vehicle details not found.'
        );
    }

    $vehicle = $result->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | DELETE VEHICLE DETAIL
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'DELETE FROM customer_vehicle_details
         WHERE id = ?'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare delete query.'
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {

        $stmt->close();

        throw new Exception(
            'Failed to delete vehicle details.'
        );
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | RESTORE DEVICE TO AVAILABLE
    |--------------------------------------------------------------------------
    */

    if (!empty($vehicle['device_id'])) {

        $deviceId = (int) $vehicle['device_id'];

        $ownerStmt = $conn->prepare('SELECT owner_type, owner_id FROM stock_allocations WHERE device_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $ownerStmt->bind_param('i', $deviceId);
        $ownerStmt->execute();
        $owner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        $restoreStatus = $owner ? 'allocated' : 'available';

        $stmt = $conn->prepare(
            "UPDATE devices
             SET status = '{$restoreStatus}',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        if (!$stmt) {
            throw new Exception(
                'Failed to prepare device restore query.'
            );
        }

        $stmt->bind_param(
            'i',
            $deviceId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                'Failed to restore device stock.'
            );
        }

        $stmt->close();
        if ($owner) {
            $ownerType = (string) $owner['owner_type'];
            $ownerId = (int) $owner['owner_id'];
            $historyStmt = $conn->prepare("DELETE FROM stock_transactions WHERE device_id = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET'");
            $historyStmt->bind_param('isi', $deviceId, $ownerType, $ownerId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESTORE SIM 1 TO AVAILABLE
    |--------------------------------------------------------------------------
    */

    if (!empty($vehicle['sim_id_1'])) {

        $simId1 = (int) $vehicle['sim_id_1'];

        $ownerStmt = $conn->prepare('SELECT owner_type, owner_id FROM stock_allocations WHERE sim_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $ownerStmt->bind_param('i', $simId1);
        $ownerStmt->execute();
        $owner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        $restoreStatus = $owner ? 'allocated' : 'available';

        $stmt = $conn->prepare(
            "UPDATE sims
             SET status = '{$restoreStatus}',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        if (!$stmt) {
            throw new Exception(
                'Failed to prepare SIM restore query.'
            );
        }

        $stmt->bind_param(
            'i',
            $simId1
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                'Failed to restore SIM 1 stock.'
            );
        }

        $stmt->close();
        if ($owner) {
            $ownerType = (string) $owner['owner_type'];
            $ownerId = (int) $owner['owner_id'];
            $historyStmt = $conn->prepare("DELETE FROM stock_transactions WHERE sim_id = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET'");
            $historyStmt->bind_param('isi', $simId1, $ownerType, $ownerId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESTORE SIM 2 TO AVAILABLE
    |--------------------------------------------------------------------------
    */

    if (!empty($vehicle['sim_id_2'])) {

        $simId2 = (int) $vehicle['sim_id_2'];

        $ownerStmt = $conn->prepare('SELECT owner_type, owner_id FROM stock_allocations WHERE sim_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $ownerStmt->bind_param('i', $simId2);
        $ownerStmt->execute();
        $owner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        $restoreStatus = $owner ? 'allocated' : 'available';

        $stmt = $conn->prepare(
            "UPDATE sims
             SET status = '{$restoreStatus}',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        if (!$stmt) {
            throw new Exception(
                'Failed to prepare SIM 2 restore query.'
            );
        }

        $stmt->bind_param(
            'i',
            $simId2
        );

        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                'Failed to restore SIM 2 stock.'
            );
        }

        $stmt->close();
        if ($owner) {
            $ownerType = (string) $owner['owner_type'];
            $ownerId = (int) $owner['owner_id'];
            $historyStmt = $conn->prepare("DELETE FROM stock_transactions WHERE sim_id = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET'");
            $historyStmt->bind_param('isi', $simId2, $ownerType, $ownerId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();
    $conn->close();


    sendResponse(
        true,
        'Vehicle details deleted successfully.',
        [
            'id' => $id
        ]
    );


} catch (Throwable $e) {

    $conn->rollback();
    $conn->close();

    sendResponse(
        false,
        $e->getMessage(),
        [],
        [],
        400
    );
}

?>