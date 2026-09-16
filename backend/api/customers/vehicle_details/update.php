<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/audit.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('customers.edit');

$data = json_decode(
    file_get_contents('php://input')
);

if (!$data) {
    sendResponse(
        false,
        'Invalid request data.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| INPUTS
|--------------------------------------------------------------------------
*/

$id = isset($data->id)
    ? (int) $data->id
    : 0;

$vehicleNo = trim(
    (string) ($data->vehicle_no ?? '')
);

$vehicleTypeId = isset($data->vehicle_type_id)
    ? (int) $data->vehicle_type_id
    : 0;

$deviceModelId = isset($data->device_model_id)
    ? (int) $data->device_model_id
    : 0;

$imeiNo = trim(
    (string) ($data->imei_no ?? '')
);

$simNo1 = trim(
    (string) ($data->sim_no_1 ?? '')
);

$simNo2 = trim(
    (string) ($data->sim_no_2 ?? '')
);

$validityId = isset($data->validity_id)
    ? (int) $data->validity_id
    : 0;


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if ($id <= 0) {
    sendResponse(false, 'Vehicle detail ID is required.', [], [], 400);
}

if ($vehicleNo === '') {
    sendResponse(false, 'Vehicle No is required.', [], [], 400);
}

if ($vehicleTypeId <= 0) {
    sendResponse(false, 'Vehicle Type is required.', [], [], 400);
}

if ($deviceModelId <= 0) {
    sendResponse(false, 'Device Model is required.', [], [], 400);
}

if (!preg_match('/^\d{15}$/', $imeiNo)) {
    sendResponse(
        false,
        'IMEI No must contain exactly 15 digits.',
        [],
        [],
        400
    );
}

if (
    !preg_match('/^\d{10}$/', $simNo1) &&
    !preg_match('/^\d{13}$/', $simNo1)
) {
    sendResponse(
        false,
        'SIM No 1 must contain exactly 10 or 13 digits.',
        [],
        [],
        400
    );
}

if ($simNo2 !== '') {

    if (
        !preg_match('/^\d{10}$/', $simNo2) &&
        !preg_match('/^\d{13}$/', $simNo2)
    ) {
        sendResponse(
            false,
            'SIM No 2 must contain exactly 10 or 13 digits.',
            [],
            [],
            400
        );
    }

    if ($simNo1 === $simNo2) {
        sendResponse(
            false,
            'SIM No 1 and SIM No 2 cannot be the same.',
            [],
            [],
            400
        );
    }
}

if ($validityId <= 0) {
    sendResponse(false, 'Validity is required.', [], [], 400);
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

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
    | GET EXISTING VEHICLE RECORD
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'SELECT
            id,
            customer_id,
            vehicle_no,
            vehicle_type_id,
            device_id,
            device_model_id,
            sim_id_1,
            sim_id_2,
            imei_no,
            sim_no_1,
            sim_no_2,
            validity_months
         FROM customer_vehicle_details
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare existing vehicle query.'
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

    $old = $result->fetch_assoc();

    $stmt->close();

    $findAsset = static function ($conn, $table, $numberColumn, $number) {
        $allocationColumn = $numberColumn === 'imei_no' ? 'device_id' : 'sim_id';
        $stmt = $conn->prepare("SELECT a.id, a.status, sa.owner_type, sa.owner_id FROM {$table} a LEFT JOIN stock_allocations sa ON sa.id = (SELECT latest_sa.id FROM stock_allocations latest_sa WHERE latest_sa.{$allocationColumn} = a.id ORDER BY latest_sa.created_at DESC, latest_sa.id DESC LIMIT 1) WHERE a.{$numberColumn} = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $asset;
    };
    $device = $findAsset($conn, 'devices', 'imei_no', $imeiNo);
    $sim1 = $findAsset($conn, 'sims', 'sim_no', $simNo1);
    $sim2 = $simNo2 === '' ? null : $findAsset($conn, 'sims', 'sim_no', $simNo2);
    if (!$device || !$sim1 || ($simNo2 !== '' && !$sim2)) {
        throw new Exception('Selected device or SIM was not found.');
    }
    $newDeviceId = (int) $device['id'];
    $newSimId1 = (int) $sim1['id'];
    $newSimId2 = $sim2 ? (int) $sim2['id'] : null;
    $ownerKey = static function ($asset) {
        return !empty($asset['owner_type']) && (int) ($asset['owner_id'] ?? 0) > 0
            ? strtolower((string) $asset['owner_type']) . ':' . (int) $asset['owner_id']
            : null;
    };
    $deviceOwnerKey = $ownerKey($device);
    foreach ([$sim1, $sim2] as $asset) {
        if (!$asset || !$deviceOwnerKey) continue;
        $simOwnerKey = $ownerKey($asset);
        if ($simOwnerKey && $simOwnerKey !== $deviceOwnerKey) {
            throw new Exception('The selected IMEI and SIM must belong to the same current owner.');
        }
    }
    $assetChecks = [
        [$device, 'device_id'],
        [$sim1, 'sim_id_1'],
        [$sim2, 'sim_id_2']
    ];
    foreach ($assetChecks as [$asset, $customerColumn]) {
        if (!$asset) continue;
        $customerCheck = $conn->prepare("SELECT id FROM customer_vehicle_details WHERE {$customerColumn} = ? AND id <> ? LIMIT 1");
        $assetId = (int) $asset['id'];
        $customerCheck->bind_param('ii', $assetId, $id);
        $customerCheck->execute();
        $alreadyUsed = $customerCheck->get_result()->num_rows > 0;
        $customerCheck->close();
        if ($alreadyUsed) throw new Exception('The selected device or SIM is already used by another customer.');

        $allocationColumn = $customerColumn === 'device_id' ? 'device_id' : 'sim_id';
        $useCheck = $conn->prepare("SELECT id FROM stock_transactions WHERE {$allocationColumn} = ? AND transaction_type = 'USE' ORDER BY id DESC LIMIT 1");
        $useCheck->bind_param('i', $assetId);
        $useCheck->execute();
        $hasUse = $useCheck->get_result()->num_rows > 0;
        $useCheck->close();
        $sameExistingAsset = (int) ($old[$customerColumn] ?? 0) === $assetId;
        if ($hasUse && !$sameExistingAsset) throw new Exception('The selected device or SIM is already used and cannot be reassigned.');
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDITY
    |--------------------------------------------------------------------------
    */

    writeChangedFields($conn, $id, 'Customer Vehicle Details', $old, [
        'vehicle_no' => $vehicleNo,
        'vehicle_type_id' => $vehicleTypeId,
        'device_id' => $newDeviceId,
        'device_model_id' => $deviceModelId,
        'imei_no' => $imeiNo,
        'sim_id_1' => $newSimId1,
        'sim_no_1' => $simNo1,
        'sim_id_2' => $newSimId2,
        'sim_no_2' => $simNo2,
        'validity_months' => $validityId
    ], $currentUser);

    $stmt = $conn->prepare(
        'SELECT id, months, status
         FROM sim_validities
         WHERE id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare validity query.'
        );
    }

    $stmt->bind_param('i', $validityId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $stmt->close();

        throw new Exception(
            'Selected validity does not exist.'
        );
    }

    $validity = $result->fetch_assoc();

    $stmt->close();

    if (
        isset($validity['status']) &&
        strtolower(trim((string) $validity['status'])) !== 'active'
    ) {
        throw new Exception(
            'Selected validity is inactive.'
        );
    }

    $validityMonths = (int) $validity['months'];

    if ($validityMonths <= 0) {
        throw new Exception(
            'Invalid validity months.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VEHICLE NUMBER DUPLICATE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'SELECT id
         FROM customer_vehicle_details
         WHERE UPPER(TRIM(vehicle_no)) =
               UPPER(TRIM(?))
           AND id <> ?
         LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to check vehicle number.'
        );
    }

    $stmt->bind_param(
        'si',
        $vehicleNo,
        $id
    );

    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {

        $stmt->close();

        throw new Exception(
            'Vehicle No already exists.'
        );
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | IMEI DUPLICATE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'SELECT id
         FROM customer_vehicle_details
         WHERE imei_no = ?
           AND id <> ?
         LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to check IMEI.'
        );
    }

    $stmt->bind_param(
        'si',
        $imeiNo,
        $id
    );

    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {

        $stmt->close();

        throw new Exception(
            'IMEI No is already used by another customer.'
        );
    }

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | SIM DUPLICATE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'SELECT id
         FROM customer_vehicle_details
         WHERE (
                sim_no_1 = ?
                OR sim_no_2 = ?
               )
           AND id <> ?
         LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to check SIM number.'
        );
    }

    $stmt->bind_param(
        'ssi',
        $simNo1,
        $simNo1,
        $id
    );

    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {

        $stmt->close();

        throw new Exception(
            'SIM No 1 is already used by another customer.'
        );
    }

    $stmt->close();


    if ($simNo2 !== '') {

        $stmt = $conn->prepare(
            'SELECT id
             FROM customer_vehicle_details
             WHERE (
                    sim_no_1 = ?
                    OR sim_no_2 = ?
                   )
               AND id <> ?
             LIMIT 1'
        );

        if (!$stmt) {
            throw new Exception(
                'Failed to check SIM No 2.'
            );
        }

        $stmt->bind_param(
            'ssi',
            $simNo2,
            $simNo2,
            $id
        );

        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {

            $stmt->close();

            throw new Exception(
                'SIM No 2 is already used by another customer.'
            );
        }

        $stmt->close();
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE CUSTOMER VEHICLE DETAILS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        'UPDATE customer_vehicle_details
         SET
            vehicle_no = ?,
            vehicle_type_id = ?,
            device_id = ?,
            device_model_id = ?,
            imei_no = ?,
            sim_id_1 = ?,
            sim_no_1 = ?,
            sim_id_2 = ?,
            sim_no_2 = NULLIF(?, ""),
            validity_months = ?,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );

    if (!$stmt) {
        throw new Exception(
            'Failed to prepare update query.'
        );
    }

    $stmt->bind_param(
        'siiisisisii',
        $vehicleNo,
        $vehicleTypeId,
        $newDeviceId,
        $deviceModelId,
        $imeiNo,
        $newSimId1,
        $simNo1,
        $newSimId2,
        $simNo2,
        $validityMonths,
        $id
    );

    if (!$stmt->execute()) {

        $stmt->close();

        throw new Exception(
            'Failed to update vehicle details.'
        );
    }

    $stmt->close();

    foreach (['device_id' => 'devices', 'sim_id_1' => 'sims', 'sim_id_2' => 'sims'] as $oldColumn => $table) {
        $oldAssetId = (int) ($old[$oldColumn] ?? 0);
        $newAssetId = $oldColumn === 'device_id' ? $newDeviceId : ($oldColumn === 'sim_id_1' ? $newSimId1 : (int) ($newSimId2 ?? 0));
        if (!$oldAssetId || $oldAssetId === $newAssetId) continue;
        $allocationColumn = $oldColumn === 'device_id' ? 'device_id' : 'sim_id';
        $allocationStmt = $conn->prepare("SELECT owner_type, owner_id FROM stock_allocations WHERE {$allocationColumn} = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE");
        $allocationStmt->bind_param('i', $oldAssetId);
        $allocationStmt->execute();
        $allocation = $allocationStmt->get_result()->fetch_assoc();
        $allocationStmt->close();
        $restoreStatus = $allocation ? 'allocated' : 'available';
        $restoreStmt = $conn->prepare("UPDATE {$table} SET status = '{$restoreStatus}', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $restoreStmt->bind_param('i', $oldAssetId);
        $restoreStmt->execute();
        $restoreStmt->close();
        if ($allocation) {
            $ownerType = (string) $allocation['owner_type'];
            $ownerId = (int) $allocation['owner_id'];
            $historyStmt = $conn->prepare("DELETE FROM stock_transactions WHERE {$allocationColumn} = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET'");
            $historyStmt->bind_param('isi', $oldAssetId, $ownerType, $ownerId);
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
        'Vehicle details updated successfully.',
        [
            'id' => $id,
            'validity_months' => $validityMonths
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