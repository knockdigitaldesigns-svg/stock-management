<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../utils/inventory_status.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('devices.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data || !isset($data->id)) {
    sendResponse(false, 'Device ID is required', [], [], 400);
}

$id = (int) $data->id;
$purchase_date = trim((string) ($data->purchase_date ?? ''));
$device_model_id = (int) ($data->device_model_id ?? 0);
$imei_no = trim((string) ($data->imei_no ?? ''));
$notes = trim((string) ($data->notes ?? ''));
$statusProvided = property_exists($data, 'status');
$requestedStatus = $statusProvided
    ? strtolower(trim((string) $data->status))
    : null;

if ($purchase_date === '') {
    sendResponse(false, 'Purchase date is required', [], [], 400);
}
if (isFutureDate($purchase_date)) sendResponse(false, 'Future dates are not allowed', [], [], 400);

if ($device_model_id <= 0) {
    sendResponse(false, 'Device model is required', [], [], 400);
}

if (!preg_match('/^[0-9]{15}$/', $imei_no)) {
    sendResponse(false, 'IMEI must contain exactly 15 digits', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$modelCheck = $conn->prepare('SELECT id FROM device_types WHERE id = ? LIMIT 1');
$modelCheck->bind_param('i', $device_model_id);
$modelCheck->execute();
if ($modelCheck->get_result()->num_rows === 0) {
    $modelCheck->close();
    sendResponse(false, 'Selected device model does not exist in Device Types.', [], [], 400);
}
$modelCheck->close();

$checkStmt = $conn->prepare('SELECT id FROM devices WHERE imei_no = ? AND id != ?');
$checkStmt->bind_param('si', $imei_no, $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    sendResponse(false, 'IMEI number already exists.', [], [], 400);
}
$checkStmt->close();

$conn->begin_transaction();
$stmt = null;
try {
    $oldStmt = $conn->prepare('SELECT purchase_date, device_model_id, imei_no, notes, status FROM devices WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    if (!$oldStmt) {
        throw new RuntimeException('Failed to prepare device lookup.');
    }
    $oldStmt->bind_param('i', $id);
    if (!$oldStmt->execute()) {
        $error = $oldStmt->error;
        $oldStmt->close();
        throw new RuntimeException('Failed to load device: ' . $error);
    }
    $oldDevice = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    if (!$oldDevice) {
        throw new OutOfBoundsException('Device not found.');
    }

    $statusContext = lockInventoryStatusContext($conn, 'device', $id);
    if (!$statusContext) {
        throw new OutOfBoundsException('Device not found.');
    }
    $currentStatus = $statusContext['effective_status'];
    if ($statusProvided
        && !in_array($requestedStatus, ['available', 'allocated', 'used'], true)
        && !($requestedStatus === 'reserved' && $currentStatus === 'reserved')) {
        throw new InvalidArgumentException('Status must be Available, Allocated, or Used.');
    }

    $statusChanged = $statusProvided && $requestedStatus !== $currentStatus;
    if ($statusChanged) {
        $statusBlocker = inventoryStatusChangeBlocker(
            $conn,
            'device',
            $id,
            (string) $oldDevice['imei_no'],
            $statusContext['stored_status']
        );
        if ($statusBlocker !== null) {
            throw new DomainException($statusBlocker);
        }
    }

    $newDevice = [
        'purchase_date' => $purchase_date,
        'device_model_id' => $device_model_id,
        'imei_no' => $imei_no,
        'notes' => $notes,
        'status' => $statusChanged ? $requestedStatus : $statusContext['stored_status']
    ];
    writeChangedFields($conn, $id, 'Device', $oldDevice, $newDevice, $currentUser);

    $stmt = $conn->prepare('UPDATE devices SET purchase_date = ?, device_model_id = ?, imei_no = ?, notes = ?, status = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare device update.');
    }
    $stmt->bind_param('sisssi', $purchase_date, $device_model_id, $imei_no, $notes, $newDevice['status'], $id);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to update device: ' . $stmt->error);
    }
    $stmt->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Device updated successfully', ['status' => $newDevice['status']]);
} catch (OutOfBoundsException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 404);
} catch (InvalidArgumentException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
} catch (DomainException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 409);
} catch (Throwable $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
