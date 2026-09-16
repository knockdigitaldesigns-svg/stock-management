<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

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

// Transaction start
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO devices (purchase_date, device_model_id, imei_no, notes) VALUES (?, ?, ?, ?)");
    
    $seenImeis = []; // To check duplicates within the request

    foreach ($data->devices as $index => $device) {
        $rowNum = $index + 1;
        
        if (empty($device->purchase_date)) throw new Exception("Row $rowNum: Purchase date is required.");
        if (isFutureDate($device->purchase_date)) throw new Exception("Row $rowNum: Future dates are not allowed.");
        if (empty($device->device_model_id)) throw new Exception("Row $rowNum: Device model is required.");
        if (empty($device->imei_no)) throw new Exception("Row $rowNum: IMEI number is required.");
        
        if (!preg_match('/^[0-9]{15}$/', $device->imei_no)) {
            throw new Exception("Row $rowNum: IMEI number must contain exactly 15 digits.");
        }
        
        if (in_array($device->imei_no, $seenImeis)) {
            throw new Exception("Row $rowNum: Duplicate IMEI number ({$device->imei_no}) found in the request.");
        }
        $seenImeis[] = $device->imei_no;

        $modelCheck = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
        $deviceModelId = (int) $device->device_model_id;
        $modelCheck->bind_param('i', $deviceModelId);
        $modelCheck->execute();
        if ($modelCheck->get_result()->num_rows === 0) {
            $modelCheck->close();
            throw new Exception("Row $rowNum: Selected device model does not exist in Device Types.");
        }
        $modelCheck->close();
        
        // Check uniqueness in DB
        $checkStmt = $conn->prepare("SELECT id FROM devices WHERE imei_no = ?");
        $checkStmt->bind_param("s", $device->imei_no);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception("Row $rowNum: IMEI number already exists.");
        }
        $checkStmt->close();
        
        $notes = trim((string) ($device->notes ?? ''));
        $stmt->bind_param("siss", $device->purchase_date, $deviceModelId, $device->imei_no, $notes);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) throw new Exception("Row $rowNum: IMEI number already exists.");
            throw new Exception("Row $rowNum: Database error - " . $stmt->error);
        }
        writeCreatedFields($conn, $conn->insert_id, 'Device', [
            'purchase_date' => $device->purchase_date,
            'device_model_id' => $deviceModelId,
            'imei_no' => $device->imei_no,
            'notes' => $notes
        ], $currentUser);
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
