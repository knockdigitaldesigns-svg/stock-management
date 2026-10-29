<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/validation_error_response.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
requirePermission('devices.add');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->devices) || !is_array($data->devices) || count($data->devices) === 0) {
    sendResponse(false, "No devices provided", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

try {
    $conn->begin_transaction();
    $validationErrors = [];
    $validatedDevices = [];
    $seenImeis = [];
    foreach ($data->devices as $index => $device) {
        $rowNum = max(1, (int) ($device->row_number ?? ($index + 1)));
        $purchaseDateRaw = $device->purchase_date ?? '';
        $purchaseDate = parseAndNormalizeDate($purchaseDateRaw);
        $deviceModelId = (int) ($device->device_model_id ?? 0);
        $deviceModelName = trim((string) ($device->device_model_name ?? ''));
        $imeiNo = trim((string) ($device->imei_no ?? ''));
        $notes = trim((string) ($device->notes ?? ''));
        $rowErrors = [];

        if ($purchaseDateRaw === '' || $purchaseDateRaw === null) {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Purchase Date is required.";
        } elseif ($purchaseDate === false || $purchaseDate === '') {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Date value '{$purchaseDateRaw}' is invalid.";
        } elseif (isFutureDate($purchaseDate)) {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Future date '{$purchaseDateRaw}' is not allowed.";
        }
        if ($deviceModelId <= 0) {
            if ($deviceModelName === '') {
                $rowErrors[] = "Row {$rowNum}: Device Model - Required field is missing.";
            } else {
                $rowErrors[] = "Row {$rowNum}: Device Model - Device model '{$deviceModelName}' does not exist in Device Types.";
            }
        }
        if ($imeiNo === '') {
            $rowErrors[] = "Row {$rowNum}: IMEI No - IMEI No is required.";
        } elseif (!preg_match('/^[0-9]{15}$/', $imeiNo)) {
            $rowErrors[] = "Row {$rowNum}: IMEI No - Value '{$imeiNo}' must contain exactly 15 digits.";
        } elseif (isset($seenImeis[$imeiNo])) {
            $rowErrors[] = "Row {$rowNum}: IMEI No - Duplicate IMEI '{$imeiNo}' in this file.";
        } else {
            $seenImeis[$imeiNo] = true;
        }

        if ($deviceModelId > 0) {
            $modelCheck = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
            if (!$modelCheck) {
                throw new RuntimeException('Failed to prepare device model check.');
            }
            $modelCheck->bind_param('i', $deviceModelId);
            $modelCheck->execute();
            if ($modelCheck->get_result()->num_rows === 0) {
                $rowErrors[] = "Row {$rowNum}: Device Model - Device model '{$deviceModelName}' (ID {$deviceModelId}) does not exist in Device Types.";
            }
            $modelCheck->close();
        }
        if ($imeiNo !== '' && preg_match('/^[0-9]{15}$/', $imeiNo)) {
            $checkStmt = $conn->prepare('SELECT id FROM devices WHERE imei_no = ? LIMIT 1 FOR UPDATE');
            if (!$checkStmt) {
                throw new RuntimeException('Failed to prepare duplicate IMEI check.');
            }
            $checkStmt->bind_param('s', $imeiNo);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $rowErrors[] = "Row {$rowNum}: IMEI No - IMEI '{$imeiNo}' already exists.";
            }
            $checkStmt->close();
        }

        if ($rowErrors) {
            $validationErrors = array_merge($validationErrors, $rowErrors);
        } else {
            $validatedDevices[] = [
                'row_number' => $rowNum,
                'purchase_date' => $purchaseDate,
                'device_model_id' => $deviceModelId,
                'imei_no' => $imeiNo,
                'notes' => $notes
            ];
        }
    }

    if ($validationErrors) {
        $conn->rollback();
        $conn->close();
        sendValidationErrorResponse('Excel validation failed; no devices were added.', $validationErrors);
    }

    $stmt = $conn->prepare('INSERT INTO devices (purchase_date, device_model_id, imei_no, notes) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare device insert.');
    }
    foreach ($validatedDevices as $device) {
        $stmt->bind_param('siss', $device['purchase_date'], $device['device_model_id'], $device['imei_no'], $device['notes']);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) {
                throw new RuntimeException("IMEI '{$device['imei_no']}' already exists.");
            }
            throw new RuntimeException('Failed to add device: ' . $stmt->error);
        }
        $deviceId = (int) $conn->insert_id;
        $snapshotStmt = $conn->prepare('SELECT d.*, dt.device_type AS device_model FROM devices d LEFT JOIN device_types dt ON dt.id = d.device_model_id WHERE d.id = ? LIMIT 1');
        $snapshotStmt->bind_param('i', $deviceId);
        $snapshotStmt->execute();
        $snapshot = $snapshotStmt->get_result()->fetch_assoc() ?: [];
        $snapshotStmt->close();
        writeAuditSnapshot($conn, $deviceId, 'Device', 'Create', null, $snapshot, $currentUser);
    }
    
    $conn->commit();
    sendResponse(true, "Devices added successfully");

} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

$stmt->close();
$conn->close();
?>
