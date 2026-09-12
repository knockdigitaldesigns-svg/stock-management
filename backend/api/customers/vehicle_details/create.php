<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
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

authenticate();

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
    | IMEI DUPLICATE (Excluding current customer)
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT id FROM customer_vehicle_details
         WHERE imei_no = ?
           AND customer_id != ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new Exception('Failed to check IMEI.');
    }
    $stmt->bind_param('si', $imeiNo, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        throw new Exception('This IMEI is already used for another customer.');
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | SIM 1 DUPLICATE (Excluding current customer)
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT id FROM customer_vehicle_details
         WHERE (sim_no_1 = ? OR sim_no_2 = ?)
           AND customer_id != ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new Exception('Failed to check SIM No 1.');
    }
    $stmt->bind_param('ssi', $simNo1, $simNo1, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        throw new Exception('SIM No 1 is already used by another customer.');
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | SIM 2 DUPLICATE (Excluding current customer)
    |--------------------------------------------------------------------------
    */
    if ($simNo2 !== '') {
        $stmt = $conn->prepare(
            'SELECT id FROM customer_vehicle_details
             WHERE (sim_no_1 = ? OR sim_no_2 = ?)
               AND customer_id != ?
             LIMIT 1'
        );
        if (!$stmt) {
            throw new Exception('Failed to check SIM No 2.');
        }
        $stmt->bind_param('ssi', $simNo2, $simNo2, $customerId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new Exception('SIM No 2 is already used by another customer.');
        }
        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | FIND DEVICE FROM INVENTORY
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT d.id, d.device_model_id, d.status, sa.owner_type, sa.owner_id
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
         FOR UPDATE'
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
    if ($deviceOwnerType === 'dealer' && $deviceOwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM dealers WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $deviceOwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The device owner dealer was not found.');
        }
    } elseif ($deviceOwnerType === 'technician' && $deviceOwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $deviceOwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The device owner technician was not found.');
        }
    }

    $allowedAllocatedOwners = ['technician', 'dealer'];
    if ($deviceStatus === 'available') {
        // OK
    } elseif ($deviceStatus === 'allocated' && in_array($deviceOwnerType, $allowedAllocatedOwners, true)) {
        // OK, preserve owner relationship
    } else {
        $sameCustomerCheck = $conn->prepare('SELECT id FROM customer_vehicle_details WHERE device_id = ? AND customer_id = ? LIMIT 1');
        $sameCustomerCheck->bind_param('ii', $deviceId, $customerId);
        $sameCustomerCheck->execute();
        $isSameCustomerDevice = $sameCustomerCheck->get_result()->num_rows > 0;
        $sameCustomerCheck->close();

        if (!$isSameCustomerDevice) {
            throw new Exception('This IMEI is not available for allocation.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND SIM 1 FROM INVENTORY
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare(
        'SELECT s.id, s.status, sa.owner_type, sa.owner_id
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

    if ($sim1OwnerType === 'dealer' && $sim1OwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM dealers WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $sim1OwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The SIM No 1 owner dealer was not found.');
        }
    } elseif ($sim1OwnerType === 'technician' && $sim1OwnerId > 0) {
        $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
        $ownerStmt->bind_param('i', $sim1OwnerId);
        $ownerStmt->execute();
        $ownerExists = $ownerStmt->get_result()->num_rows > 0;
        $ownerStmt->close();
        if (!$ownerExists) {
            throw new Exception('The SIM No 1 owner technician was not found.');
        }
    }

    if ($sim1Status === 'available') {
        // OK
    } elseif ($sim1Status === 'allocated' && in_array($sim1OwnerType, $allowedAllocatedOwners, true)) {
        // OK
    } else {
        $sameCustomerCheck = $conn->prepare('SELECT id FROM customer_vehicle_details WHERE sim_id_1 = ? AND customer_id = ? LIMIT 1');
        $sameCustomerCheck->bind_param('ii', $simId1, $customerId);
        $sameCustomerCheck->execute();
        $isSameCustomerSim = $sameCustomerCheck->get_result()->num_rows > 0;
        $sameCustomerCheck->close();

        if (!$isSameCustomerSim) {
            throw new Exception('SIM No 1 is not available for allocation.');
        }
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
            'SELECT s.id, s.status, sa.owner_type, sa.owner_id
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

        if ($sim2OwnerType === 'dealer' && $sim2OwnerId > 0) {
            $ownerStmt = $conn->prepare('SELECT id FROM dealers WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $sim2OwnerId);
            $ownerStmt->execute();
            $ownerExists = $ownerStmt->get_result()->num_rows > 0;
            $ownerStmt->close();
            if (!$ownerExists) {
                throw new Exception('The SIM No 2 owner dealer was not found.');
            }
        } elseif ($sim2OwnerType === 'technician' && $sim2OwnerId > 0) {
            $ownerStmt = $conn->prepare('SELECT id FROM technicians WHERE id = ? LIMIT 1');
            $ownerStmt->bind_param('i', $sim2OwnerId);
            $ownerStmt->execute();
            $ownerExists = $ownerStmt->get_result()->num_rows > 0;
            $ownerStmt->close();
            if (!$ownerExists) {
                throw new Exception('The SIM No 2 owner technician was not found.');
            }
        }

        if ($sim2Status === 'available') {
            // OK
        } elseif ($sim2Status === 'allocated' && in_array($sim2OwnerType, $allowedAllocatedOwners, true)) {
            // OK
        } else {
            $sameCustomerCheck = $conn->prepare('SELECT id FROM customer_vehicle_details WHERE sim_id_2 = ? AND customer_id = ? LIMIT 1');
            $sameCustomerCheck->bind_param('ii', $simId2, $customerId);
            $sameCustomerCheck->execute();
            $isSameCustomerSim2 = $sameCustomerCheck->get_result()->num_rows > 0;
            $sameCustomerCheck->close();

            if (!$isSameCustomerSim2) {
                throw new Exception('SIM No 2 is not available for allocation.');
            }
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
    $checkCustomerStmt = $conn->prepare('SELECT id FROM customer_vehicle_details WHERE customer_id = ? LIMIT 1');
    $checkCustomerStmt->bind_param('i', $customerId);
    $checkCustomerStmt->execute();
    $existingRow = $checkCustomerStmt->get_result()->fetch_assoc();
    $checkCustomerStmt->close();

    $cleanSimNo2 = $simNo2 !== '' ? $simNo2 : null;

    if ($existingRow) {
        $vehicleDetailsId = (int) $existingRow['id'];
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