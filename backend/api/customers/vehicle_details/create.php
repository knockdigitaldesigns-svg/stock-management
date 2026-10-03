<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/audit.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(
        false,
        'Method not allowed',
        [],
        [],
        405
    );
}

$currentUser = authenticate();

/*
|--------------------------------------------------------------------------
| READ REQUEST
|--------------------------------------------------------------------------
*/

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

$customerId = isset($data->customer_id) ? (int) $data->customer_id : 0;
$vehicleNo = trim((string) ($data->vehicle_no ?? ''));
$vehicleTypeId = isset($data->vehicle_type_id) ? (int) $data->vehicle_type_id : 0;
$deviceModelId = isset($data->device_model_id) ? (int) $data->device_model_id : 0;
$imeiNo = trim((string) ($data->imei_no ?? ''));
$simNo1 = trim((string) ($data->sim_no_1 ?? ''));
$simNo2 = trim((string) ($data->sim_no_2 ?? ''));
$validityId = isset($data->validity_id) ? (int) $data->validity_id : 0;
$validityMonthsParam = isset($data->validity_months) ? (int) $data->validity_months : 0;
$appendVehicle = filter_var($data->append_vehicle ?? false, FILTER_VALIDATE_BOOLEAN);

/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if ($customerId <= 0) {
    sendResponse(false, 'Customer ID is required.', [], [], 400);
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

if ($imeiNo === '') {
    sendResponse(false, 'IMEI No is required.', [], [], 400);
}

if (!preg_match('/^\d{15}$/', $imeiNo)) {
    sendResponse(false, 'IMEI No must contain exactly 15 digits.', [], [], 400);
}

if ($simNo1 === '') {
    sendResponse(false, 'SIM No 1 is required.', [], [], 400);
}

if (
    !preg_match('/^\d{10}$/', $simNo1) &&
    !preg_match('/^\d{13}$/', $simNo1)
) {
    sendResponse(false, 'SIM No 1 must contain exactly 10 or 13 digits.', [], [], 400);
}

if ($simNo2 !== '') {
    if (
        !preg_match('/^\d{10}$/', $simNo2) &&
        !preg_match('/^\d{13}$/', $simNo2)
    ) {
        sendResponse(false, 'SIM No 2 must contain exactly 10 or 13 digits.', [], [], 400);
    }

    if ($simNo1 === $simNo2) {
        sendResponse(false, 'SIM No 1 and SIM No 2 cannot be the same.', [], [], 400);
    }
}

if ($validityId <= 0 && $validityMonthsParam <= 0) {
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
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();

try {
    /*
    |--------------------------------------------------------------------------
    | CUSTOMER CHECK
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Failed to prepare customer query.');
    }
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customerResult = $stmt->get_result();
    if ($customerResult->num_rows === 0) {
        $stmt->close();
        throw new Exception('Customer not found.');
    }
    $stmt->close();

    $existingRow = null;
    if (!$appendVehicle) {
        $existingVehicleStmt = $conn->prepare('SELECT * FROM customer_vehicle_details WHERE customer_id = ? LIMIT 1 FOR UPDATE');
        $existingVehicleStmt->bind_param('i', $customerId);
        $existingVehicleStmt->execute();
        $existingRow = $existingVehicleStmt->get_result()->fetch_assoc() ?: null;
        $existingVehicleStmt->close();
    }
    $existingVehicleId = (int) ($existingRow['id'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | VEHICLE TYPE CHECK
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare('SELECT id FROM vehicle_types WHERE id = ? AND (status = "Active" OR status IS NULL) LIMIT 1');
    if (!$stmt) {
        throw new Exception('Failed to prepare vehicle type query.');
    }
    $stmt->bind_param('i', $vehicleTypeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        throw new Exception('Selected vehicle type is not available.');
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | DEVICE MODEL CHECK
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Failed to prepare device model query.');
    }
    $stmt->bind_param('i', $deviceModelId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        throw new Exception('Selected device model does not exist.');
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | VALIDITY
    |--------------------------------------------------------------------------
    */
    $validityMonths = 0;
    if ($validityId > 0) {
        $stmt = $conn->prepare('SELECT id, months, status FROM sim_validities WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new Exception('Failed to prepare validity query.');
        }
        $stmt->bind_param('i', $validityId);
        $stmt->execute();
        $validityResult = $stmt->get_result();
        if ($validityResult->num_rows === 0) {
            $stmt->close();
            throw new Exception('Selected validity does not exist.');
        }
        $validity = $validityResult->fetch_assoc();
        $stmt->close();

        if (isset($validity['status']) && strtolower(trim((string) $validity['status'])) !== 'active') {
            throw new Exception('Selected validity is inactive.');
        }
        $validityMonths = (int) $validity['months'];
    } elseif ($validityMonthsParam > 0) {
        $validityMonths = $validityMonthsParam;
    }

    if ($validityMonths <= 0) {
        throw new Exception('Invalid validity months.');
    }

    /*
    |--------------------------------------------------------------------------
    | VEHICLE NUMBER DUPLICATE (Excluding current customer)
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT id FROM customer_vehicle_details
         WHERE UPPER(TRIM(vehicle_no)) = UPPER(TRIM(?))
           AND customer_id != ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new Exception('Failed to check vehicle number.');
    }
    $stmt->bind_param('si', $vehicleNo, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        throw new Exception('Vehicle No already exists.');
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | FIND DEVICE FROM INVENTORY
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        "SELECT d.id, d.device_model_id,
                CASE
                    WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = d.id)
                        OR EXISTS (
                            SELECT 1 FROM stock_transactions st
                            WHERE st.device_id = d.id
                              AND st.from_owner_type = sa.owner_type
                              AND st.from_owner_id = sa.owner_id
                              AND st.id = (
                                  SELECT MAX(st_latest.id) FROM stock_transactions st_latest
                                  WHERE st_latest.device_id = d.id
                                    AND st_latest.from_owner_type = sa.owner_type
                                    AND st_latest.from_owner_id = sa.owner_id
                              )
                              AND st.transaction_type = 'USE'
                        ) THEN 'used'
                    ELSE d.status
                END AS status,
                sa.owner_type, sa.owner_id
         FROM devices d
         LEFT JOIN stock_allocations sa
            ON sa.id = (
                SELECT latest_sa.id
                FROM stock_allocations latest_sa
                WHERE latest_sa.device_id = d.id
                ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                LIMIT 1
            )
         WHERE d.imei_no = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare device query.');
    }
    $stmt->bind_param('s', $imeiNo);
    $stmt->execute();
    $deviceResult = $stmt->get_result();
    if ($deviceResult->num_rows === 0) {
        $stmt->close();
        throw new Exception('IMEI not found in device stock.');
    }
    $device = $deviceResult->fetch_assoc();
    $stmt->close();

    $deviceId = (int) $device['id'];
    $inventoryDeviceModelId = (int) $device['device_model_id'];
    $deviceStatus = strtolower(trim((string) $device['status']));
    $deviceOwnerType = strtolower(trim((string) ($device['owner_type'] ?? '')));
    $deviceOwnerId = (int) ($device['owner_id'] ?? 0);
    $assertNoOtherCustomerAssignment = static function ($assetColumn, $assetId, $message) use ($conn, $existingVehicleId) {
        $stmt = $conn->prepare("SELECT id FROM customer_vehicle_details WHERE {$assetColumn} = ? AND id <> ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii', $assetId, $existingVehicleId);
        $stmt->execute();
        $assignedToAnotherCustomer = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($assignedToAnotherCustomer) {
            throw new Exception($message);
        }
    };
    $sameExistingDevice = (int) ($existingRow['device_id'] ?? 0) === $deviceId;
    if ($deviceStatus === 'used' && !$sameExistingDevice) {
        throw new Exception('IMEI is already used and cannot be assigned again.');
    }
    $assertNoOtherCustomerAssignment('device_id', $deviceId, 'IMEI is already assigned to another customer.');

    /*
    |--------------------------------------------------------------------------
    | DEVICE MODEL MATCH
    |--------------------------------------------------------------------------
    */
    if ($inventoryDeviceModelId !== $deviceModelId) {
        throw new Exception('Device Model does not match the IMEI device.');
    }

    /*
    |--------------------------------------------------------------------------
    | DEVICE STATUS & USAGE CHECK
    |--------------------------------------------------------------------------
    */
    $deviceOwnerEligible = false;
    if ($deviceOwnerType === 'dealer' && $deviceOwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT installation_status FROM dealers WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $deviceOwnerId);
        $ownerStmt->execute();
        $deviceOwner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        if (!$deviceOwner) {
            throw new Exception('The device owner dealer was not found.');
        }
        $deviceOwnerEligible = in_array(strtolower(trim((string) $deviceOwner['installation_status'])), ['onsite', 'offsite'], true);
    } elseif ($deviceOwnerType === 'technician' && $deviceOwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $deviceOwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The device owner technician was not found.');
        }
        $deviceOwnerEligible = true;
    }

    if ($sameExistingDevice || $deviceStatus === 'available') {
        // OK
    } elseif ($deviceStatus === 'allocated' && $deviceOwnerEligible) {
        // OK, preserve owner relationship
    } else {
        throw new Exception('This IMEI is not available for allocation.');
    }

    /*
    |--------------------------------------------------------------------------
    | FIND SIM 1 FROM INVENTORY
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT s.id,
                CASE
                    WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id)
                        OR EXISTS (
                            SELECT 1 FROM stock_transactions st
                            WHERE st.sim_id = s.id
                              AND st.from_owner_type = sa.owner_type
                              AND st.from_owner_id = sa.owner_id
                              AND st.id = (
                                  SELECT MAX(st_latest.id) FROM stock_transactions st_latest
                                  WHERE st_latest.sim_id = s.id
                                    AND st_latest.from_owner_type = sa.owner_type
                                    AND st_latest.from_owner_id = sa.owner_id
                              )
                              AND st.transaction_type = "USE"
                        ) THEN "used"
                    ELSE s.status
                END AS status,
                sa.owner_type, sa.owner_id
         FROM sims s
         LEFT JOIN stock_allocations sa
            ON sa.id = (
                SELECT latest_sa.id
                FROM stock_allocations latest_sa
                WHERE latest_sa.sim_id = s.id
                ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                LIMIT 1
            )
         WHERE s.sim_no = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare SIM query.');
    }
    $stmt->bind_param('s', $simNo1);
    $stmt->execute();
    $simResult = $stmt->get_result();
    if ($simResult->num_rows === 0) {
        $stmt->close();
        throw new Exception('SIM No 1 was not found in SIM Maintenance.');
    }
    $sim1 = $simResult->fetch_assoc();
    $stmt->close();

    $simId1 = (int) $sim1['id'];
    $sim1Status = strtolower(trim((string) $sim1['status']));
    $sim1OwnerType = strtolower(trim((string) ($sim1['owner_type'] ?? '')));
    $sim1OwnerId = (int) ($sim1['owner_id'] ?? 0);
    $sameExistingSim1 = (int) ($existingRow['sim_id_1'] ?? 0) === $simId1;
    if ($sim1Status === 'used' && !$sameExistingSim1) {
        throw new Exception('SIM is already used and cannot be assigned again.');
    }
    $assertNoOtherCustomerAssignment('sim_id_1', $simId1, 'SIM is already assigned to another customer.');

    $sim1OwnerEligible = false;
    if ($sim1OwnerType === 'dealer' && $sim1OwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT installation_status FROM dealers WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $sim1OwnerId);
        $ownerStmt->execute();
        $sim1Owner = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        if (!$sim1Owner) {
            throw new Exception('The SIM No 1 owner dealer was not found.');
        }
        $sim1OwnerEligible = in_array(strtolower(trim((string) $sim1Owner['installation_status'])), ['onsite', 'offsite'], true);
    } elseif ($sim1OwnerType === 'technician' && $sim1OwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $sim1OwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The SIM No 1 owner technician was not found.');
        }
        $sim1OwnerEligible = true;
    }

    if ($sameExistingSim1 || $sim1Status === 'available') {
        // OK
    } elseif ($sim1Status === 'allocated' && $sim1OwnerEligible) {
        // OK
    } else {
        throw new Exception('SIM No 1 is not available for allocation.');
    }

    /*
    |--------------------------------------------------------------------------
    | FIND SIM 2 IF PROVIDED
    |--------------------------------------------------------------------------
    */
    $simId2 = null;
    $sim2OwnerType = '';
    $sim2OwnerId = 0;

    if ($simNo2 !== '') {
        $stmt = $conn->prepare(
            'SELECT s.id,
                    CASE
                        WHEN EXISTS (SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id)
                            OR EXISTS (
                                SELECT 1 FROM stock_transactions st
                                WHERE st.sim_id = s.id
                                  AND st.from_owner_type = sa.owner_type
                                  AND st.from_owner_id = sa.owner_id
                                  AND st.id = (
                                      SELECT MAX(st_latest.id) FROM stock_transactions st_latest
                                      WHERE st_latest.sim_id = s.id
                                        AND st_latest.from_owner_type = sa.owner_type
                                        AND st_latest.from_owner_id = sa.owner_id
                                  )
                                  AND st.transaction_type = "USE"
                            ) THEN "used"
                        ELSE s.status
                    END AS status,
                    sa.owner_type, sa.owner_id
             FROM sims s
             LEFT JOIN stock_allocations sa
                ON sa.id = (
                    SELECT latest_sa.id
                    FROM stock_allocations latest_sa
                    WHERE latest_sa.sim_id = s.id
                    ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                    LIMIT 1
                )
             WHERE s.sim_no = ?
             LIMIT 1
             FOR UPDATE'
        );
        if (!$stmt) {
            throw new Exception('Failed to prepare SIM 2 query.');
        }
        $stmt->bind_param('s', $simNo2);
        $stmt->execute();
        $sim2Result = $stmt->get_result();
        if ($sim2Result->num_rows === 0) {
            $stmt->close();
            throw new Exception('SIM No 2 was not found in SIM Maintenance.');
        }
        $sim2 = $sim2Result->fetch_assoc();
        $stmt->close();

        $simId2 = (int) $sim2['id'];
        $sim2Status = strtolower(trim((string) $sim2['status']));
        $sim2OwnerType = strtolower(trim((string) ($sim2['owner_type'] ?? '')));
        $sim2OwnerId = (int) ($sim2['owner_id'] ?? 0);
        $sameExistingSim2 = (int) ($existingRow['sim_id_2'] ?? 0) === $simId2;
        if ($sim2Status === 'used' && !$sameExistingSim2) {
            throw new Exception('SIM is already used and cannot be assigned again.');
        }
        $assertNoOtherCustomerAssignment('sim_id_2', $simId2, 'SIM is already assigned to another customer.');

        $sim2OwnerEligible = false;
        if ($sim2OwnerType === 'dealer' && $sim2OwnerId > 0) {
            $ownerStmt = $conn->prepare('SELECT installation_status FROM dealers WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $sim2OwnerId);
            $ownerStmt->execute();
            $sim2Owner = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            if (!$sim2Owner) {
                throw new Exception('The SIM No 2 owner dealer was not found.');
            }
            $sim2OwnerEligible = in_array(strtolower(trim((string) $sim2Owner['installation_status'])), ['onsite', 'offsite'], true);
        } elseif ($sim2OwnerType === 'technician' && $sim2OwnerId > 0) {
            $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $sim2OwnerId);
            $ownerStmt->execute();
            $ownerExists = $ownerStmt->get_result()->num_rows > 0;
            $ownerStmt->close();
            if (!$ownerExists) {
                throw new Exception('The SIM No 2 owner technician was not found.');
            }
            $sim2OwnerEligible = true;
        }

        if ($sameExistingSim2 || $sim2Status === 'available') {
            // OK
        } elseif ($sim2Status === 'allocated' && $sim2OwnerEligible) {
            // OK
        } else {
            throw new Exception('SIM No 2 is not available for allocation.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | OWNER LOOKUP & COMPATIBILITY CHECK
    |--------------------------------------------------------------------------
    */
    $assetOwnerKey = static function ($type, $id) {
        return ($type !== '' && (int) $id > 0)
            ? strtolower((string) $type) . ':' . (int) $id
            : null;
    };

    $deviceOwnerKey = $assetOwnerKey($deviceOwnerType, $deviceOwnerId);
    $sim1OwnerKey = $assetOwnerKey($sim1OwnerType, $sim1OwnerId);
    $sim2OwnerKey = ($simNo2 !== '' && $sim2OwnerType !== '') ? $assetOwnerKey($sim2OwnerType, $sim2OwnerId) : null;

    if ($deviceOwnerKey && $sim1OwnerKey && $deviceOwnerKey !== $sim1OwnerKey) {
        throw new Exception('The selected IMEI and SIM No 1 must belong to the same current owner.');
    }
    if ($deviceOwnerKey && $sim2OwnerKey && $deviceOwnerKey !== $sim2OwnerKey) {
        throw new Exception('The selected IMEI and SIM No 2 must belong to the same current owner.');
    }
    if ($sim1OwnerKey && $sim2OwnerKey && $sim1OwnerKey !== $sim2OwnerKey) {
        throw new Exception('SIM No 1 and SIM No 2 must belong to the same current owner.');
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT OR UPDATE CUSTOMER VEHICLE DETAILS
    |--------------------------------------------------------------------------
    */
    $cleanSimNo2 = $simNo2 !== '' ? $simNo2 : null;

    if ($existingRow && !$appendVehicle) {
        $vehicleDetailsId = (int) $existingRow['id'];
        writeChangedFields($conn, $vehicleDetailsId, 'Customer Vehicle Details', $existingRow, [
            'vehicle_no' => $vehicleNo, 'vehicle_type_id' => $vehicleTypeId, 'device_id' => $deviceId,
            'device_model_id' => $deviceModelId, 'imei_no' => $imeiNo, 'sim_id_1' => $simId1,
            'sim_no_1' => $simNo1, 'sim_id_2' => $simId2, 'sim_no_2' => $cleanSimNo2,
            'validity_months' => $validityMonths
        ], $currentUser);
        $stmt = $conn->prepare(
            'UPDATE customer_vehicle_details
             SET vehicle_no = ?,
                 vehicle_type_id = ?,
                 device_id = ?,
                 device_model_id = ?,
                 imei_no = ?,
                 sim_id_1 = ?,
                 sim_no_1 = ?,
                 sim_id_2 = ?,
                 sim_no_2 = ?,
                 validity_months = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new Exception('Failed to prepare update customer vehicle details query.');
        }
        $stmt->bind_param(
            'siiisisssii',
            $vehicleNo,
            $vehicleTypeId,
            $deviceId,
            $deviceModelId,
            $imeiNo,
            $simId1,
            $simNo1,
            $simId2,
            $cleanSimNo2,
            $validityMonths,
            $vehicleDetailsId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update customer vehicle details.');
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO customer_vehicle_details
            (
                customer_id,
                vehicle_no,
                vehicle_type_id,
                device_id,
                device_model_id,
                imei_no,
                sim_id_1,
                sim_no_1,
                sim_id_2,
                sim_no_2,
                validity_months
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new Exception('Failed to prepare customer vehicle insert.');
        }
        $stmt->bind_param(
            'isiiisisssi',
            $customerId,
            $vehicleNo,
            $vehicleTypeId,
            $deviceId,
            $deviceModelId,
            $imeiNo,
            $simId1,
            $simNo1,
            $simId2,
            $cleanSimNo2,
            $validityMonths
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to save customer vehicle details.');
        }
        $vehicleDetailsId = (int) $conn->insert_id;
        $stmt->close();
        writeCreatedFields($conn, $vehicleDetailsId, 'Customer Vehicle Details', [
            'customer_id' => $customerId, 'vehicle_no' => $vehicleNo, 'vehicle_type_id' => $vehicleTypeId,
            'device_id' => $deviceId, 'device_model_id' => $deviceModelId, 'imei_no' => $imeiNo,
            'sim_id_1' => $simId1, 'sim_no_1' => $simNo1, 'sim_id_2' => $simId2,
            'sim_no_2' => $cleanSimNo2, 'validity_months' => $validityMonths
        ], $currentUser);
    }

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'Vehicle details saved successfully.',
        [
            'id' => $vehicleDetailsId,
            'customer_id' => $customerId,
            'device_id' => $deviceId,
            'sim_id_1' => $simId1,
            'sim_id_2' => $simId2,
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