<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/audit.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();

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
$verificationSubmissionId = max(0, (int) ($data->verification_submission_id ?? 0));
if ($verificationSubmissionId > 0) {
    requireAnyPermission(['customers.edit', CUSTOMER_PAYMENT_VERIFICATION_PERMISSION]);
} else {
    requirePermission('customers.edit');
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
    $courierReservedDeviceIds = [];
    $courierReservedSimIds = [];
    if ($verificationSubmissionId > 0) {
        assertCustomerVerificationCommitAccess($conn, $verificationSubmissionId, (int) ($currentUser['user_id'] ?? 0));
        $submissionStmt = $conn->prepare(
            'SELECT submission_data FROM customer_payment_verification_submissions
             WHERE id = ? AND status = \'Applying\' FOR UPDATE'
        );
        if (!$submissionStmt) {
            throw new RuntimeException('Unable to verify the customer submission.');
        }
        $submissionStmt->bind_param('i', $verificationSubmissionId);
        if (!$submissionStmt->execute()) {
            $submissionStmt->close();
            throw new RuntimeException('Unable to load the customer submission.');
        }
        $submissionData = json_decode(
            (string) ($submissionStmt->get_result()->fetch_assoc()['submission_data'] ?? ''),
            true
        );
        $submissionStmt->close();
        if (is_array($submissionData) && ($submissionData['source'] ?? '') === 'courier') {
            $requestIds = array_values(array_unique(array_filter(
                array_map('intval', is_array($submissionData['courier_request_ids'] ?? null)
                    ? $submissionData['courier_request_ids']
                    : []),
                static function (int $requestId): bool {
                    return $requestId > 0;
                }
            )));
            if ($requestIds) {
                $requestIdList = implode(',', $requestIds);
                $courierStmt = $conn->prepare(
                    "SELECT id, device_id, sim_id, approval_status, new_customer_data
                     FROM courier_requests WHERE id IN ({$requestIdList}) FOR UPDATE"
                );
                if (!$courierStmt || !$courierStmt->execute()) {
                    throw new RuntimeException('Unable to verify courier stock reservations.');
                }
                $courierRows = $courierStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $courierStmt->close();
                if (count($courierRows) !== count($requestIds)) {
                    throw new RuntimeException('A linked courier reservation could not be found.');
                }
                foreach ($courierRows as $courierRow) {
                    $linkedWorkflow = json_decode((string) $courierRow['new_customer_data'], true);
                    if ($courierRow['approval_status'] !== 'Approved'
                        || !is_array($linkedWorkflow)
                        || (int) ($linkedWorkflow['_verification']['submission_id'] ?? 0) !== $verificationSubmissionId) {
                        throw new RuntimeException('Courier stock is not reserved for this verification request.');
                    }
                    if ((int) ($courierRow['device_id'] ?? 0) > 0) {
                        $courierReservedDeviceIds[] = (int) $courierRow['device_id'];
                    }
                    if ((int) ($courierRow['sim_id'] ?? 0) > 0) {
                        $courierReservedSimIds[] = (int) $courierRow['sim_id'];
                    }
                }
            }
        }
    }

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

    $findAsset = static function ($conn, $table, $numberColumn, $number, $allowedArchivedId) {
        $allocationColumn = $numberColumn === 'imei_no' ? 'device_id' : 'sim_id';
        $statusExpression = $table === 'devices'
            ? "CASE WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = a.id OR BINARY cvd.imei_no = BINARY a.imei_no) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.device_id = a.id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.id = (SELECT MAX(st_latest.id) FROM stock_transactions st_latest WHERE st_latest.device_id = a.id AND st_latest.from_owner_type = sa.owner_type AND st_latest.from_owner_id = sa.owner_id) AND st.transaction_type = 'USE') THEN 'used' ELSE a.status END"
            : "CASE WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = a.id OR cvd.sim_id_2 = a.id OR BINARY TRIM(cvd.sim_no_1) = BINARY a.sim_no OR (cvd.sim_no_2 IS NOT NULL AND BINARY TRIM(cvd.sim_no_2) = BINARY a.sim_no)) OR EXISTS (SELECT 1 FROM stock_transactions st WHERE st.sim_id = a.id AND st.from_owner_type = sa.owner_type AND st.from_owner_id = sa.owner_id AND st.id = (SELECT MAX(st_latest.id) FROM stock_transactions st_latest WHERE st_latest.sim_id = a.id AND st_latest.from_owner_type = sa.owner_type AND st_latest.from_owner_id = sa.owner_id) AND st.transaction_type = 'USE') THEN 'used' ELSE a.status END";
        $stmt = $conn->prepare("SELECT a.id, {$statusExpression} AS status, sa.owner_type, sa.owner_id, (SELECT installation_status FROM dealers WHERE id = sa.owner_id AND sa.owner_type = 'dealer' LIMIT 1) AS owner_installation_status FROM {$table} a LEFT JOIN stock_allocations sa ON sa.id = (SELECT latest_sa.id FROM stock_allocations latest_sa WHERE latest_sa.{$allocationColumn} = a.id ORDER BY latest_sa.created_at DESC, latest_sa.id DESC LIMIT 1) WHERE a.{$numberColumn} = ? AND (a.deleted_at IS NULL OR a.id = ?) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('si', $number, $allowedArchivedId);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $asset;
    };
    $device = $findAsset($conn, 'devices', 'imei_no', $imeiNo, (int) ($old['device_id'] ?? 0));
    $sim1 = $findAsset($conn, 'sims', 'sim_no', $simNo1, (int) ($old['sim_id_1'] ?? 0));
    $sim2 = $simNo2 === '' ? null : $findAsset($conn, 'sims', 'sim_no', $simNo2, (int) ($old['sim_id_2'] ?? 0));
    if (!$device || !$sim1 || ($simNo2 !== '' && !$sim2)) {
        throw new Exception('Selected device or SIM was not found.');
    }
    $newDeviceId = (int) $device['id'];
    $newSimId1 = (int) $sim1['id'];
    $newSimId2 = $sim2 ? (int) $sim2['id'] : null;
    $assetChecks = [
        [$device, 'device_id', 'IMEI is already assigned to another customer.', 'This IMEI is not available for allocation.'],
        [$sim1, 'sim_id_1', 'SIM is already assigned to another customer.', 'This SIM is not available for allocation.'],
        [$sim2, 'sim_id_2', 'SIM is already assigned to another customer.', 'This SIM is not available for allocation.']
    ];
    foreach ($assetChecks as [$asset, $customerColumn, $assignedMessage, $unavailableMessage]) {
        if (!$asset) continue;
        $assetId = (int) $asset['id'];
        $oldNumberColumn = $customerColumn === 'device_id'
            ? 'imei_no'
            : ($customerColumn === 'sim_id_1' ? 'sim_no_1' : 'sim_no_2');
        $requestedNumber = $customerColumn === 'device_id'
            ? $imeiNo
            : ($customerColumn === 'sim_id_1' ? $simNo1 : $simNo2);
        $sameExistingAsset = (int) ($old[$customerColumn] ?? 0) === $assetId
            || ($requestedNumber !== '' && trim((string) ($old[$oldNumberColumn] ?? '')) === $requestedNumber);
        $assetStatus = strtolower(trim((string) ($asset['status'] ?? '')));
        if ($assetStatus === 'used' && !$sameExistingAsset) {
            throw new Exception($customerColumn === 'device_id'
                ? 'IMEI is already used and cannot be assigned again.'
                : 'SIM is already used and cannot be assigned again.');
        }
        if ($customerColumn === 'device_id') {
            $customerCheck = $conn->prepare(
                'SELECT id FROM customer_vehicle_details
                 WHERE (device_id = ? OR BINARY imei_no = BINARY ?)
                   AND id <> ? LIMIT 1 FOR UPDATE'
            );
            $customerCheck->bind_param('isi', $assetId, $requestedNumber, $id);
        } else {
            $customerCheck = $conn->prepare("SELECT id FROM customer_vehicle_details WHERE {$customerColumn} = ? AND id <> ? LIMIT 1 FOR UPDATE");
            $customerCheck->bind_param('ii', $assetId, $id);
        }
        $customerCheck->execute();
        $alreadyUsed = $customerCheck->get_result()->num_rows > 0;
        $customerCheck->close();
        if ($alreadyUsed) throw new Exception($assignedMessage);

        $assetOwnerType = strtolower(trim((string) ($asset['owner_type'] ?? '')));
        $assetOwnerId = (int) ($asset['owner_id'] ?? 0);
        $ownerInstallationStatus = strtolower(trim((string) ($asset['owner_installation_status'] ?? '')));
        $ownerEligible = $assetOwnerId > 0 && (
            $assetOwnerType === 'technician' ||
            ($assetOwnerType === 'dealer' && in_array($ownerInstallationStatus, ['onsite', 'offsite'], true))
        );
        $reservedForVerification = $customerColumn === 'device_id'
            ? in_array($assetId, $courierReservedDeviceIds, true)
            : in_array($assetId, $courierReservedSimIds, true);
        if (!$sameExistingAsset
            && $assetStatus !== 'available'
            && !($assetStatus === 'allocated' && $ownerEligible)
            && !($assetStatus === 'reserved' && $reservedForVerification)) {
            throw new Exception($unavailableMessage);
        }
    }
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
            'IMEI is already assigned to another customer.'
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
            'SIM is already assigned to another customer.'
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
                'SIM is already assigned to another customer.'
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
        $oldNumberColumn = $oldColumn === 'device_id'
            ? 'imei_no'
            : ($oldColumn === 'sim_id_1' ? 'sim_no_1' : 'sim_no_2');
        $oldNumber = trim((string) ($old[$oldNumberColumn] ?? ''));
        if (!$oldAssetId && $oldNumber !== '') {
            $stockNumberColumn = $table === 'devices' ? 'imei_no' : 'sim_no';
            $legacyAssetStmt = $conn->prepare(
                "SELECT id FROM {$table} WHERE {$stockNumberColumn} = ? LIMIT 1 FOR UPDATE"
            );
            $legacyAssetStmt->bind_param('s', $oldNumber);
            $legacyAssetStmt->execute();
            $legacyAssetId = $legacyAssetStmt->get_result()->fetch_assoc();
            $legacyAssetStmt->close();
            $oldAssetId = (int) ($legacyAssetId['id'] ?? 0);
        }
        if (!$oldAssetId || $oldAssetId === $newAssetId) continue;
        $allocationColumn = $oldColumn === 'device_id' ? 'device_id' : 'sim_id';
        if ($oldColumn === 'device_id') {
            $assignmentCheck = $conn->prepare(
                'SELECT id FROM customer_vehicle_details
                 WHERE device_id = ? OR imei_no = ?
                 LIMIT 1 FOR UPDATE'
            );
            $assignmentCheck->bind_param('is', $oldAssetId, $oldNumber);
        } else {
            $assignmentCheck = $conn->prepare(
                'SELECT id FROM customer_vehicle_details
                 WHERE sim_id_1 = ? OR sim_id_2 = ? OR sim_no_1 = ? OR sim_no_2 = ?
                 LIMIT 1 FOR UPDATE'
            );
            $assignmentCheck->bind_param('iiss', $oldAssetId, $oldAssetId, $oldNumber, $oldNumber);
        }
        $assignmentCheck->execute();
        $stillAssigned = $assignmentCheck->get_result()->num_rows > 0;
        $assignmentCheck->close();
        if ($stillAssigned) continue;

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
        $deviceTransactionId = $table === 'devices' ? $oldAssetId : 0;
        $simTransactionId = $table === 'sims' ? $oldAssetId : 0;
        $ownerType = (string) ($allocation['owner_type'] ?? '');
        $ownerId = (int) ($allocation['owner_id'] ?? 0);
        $returnNote = 'Returned to stock after customer vehicle assignment changed.';
        $historyStmt = $conn->prepare(
            "INSERT INTO stock_transactions
                (device_id, sim_id, from_owner_type, from_owner_id, transaction_type, transaction_date, notes)
             VALUES (NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, 0), 'RETURN', CURDATE(), ?)"
        );
        $historyStmt->bind_param('iisis', $deviceTransactionId, $simTransactionId, $ownerType, $ownerId, $returnNote);
        if (!$historyStmt->execute()) {
            $historyStmt->close();
            throw new Exception('Failed to record the returned customer asset.');
        }
        $historyStmt->close();
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