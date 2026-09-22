<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/excel_reader.php';
require_once '../../utils/dealer_validation.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate();
requirePermission('technicians.import');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) sendResponse(false, 'Please upload a valid Excel file.', [], [], 400);
$file = $_FILES['file'];
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') sendResponse(false, 'Only .xlsx files are allowed.', [], [], 400);
if (empty($file['size'])) sendResponse(false, 'Excel file is empty.', [], [], 400);

$requiredHeaders = ['Technician Name', 'Allocation Type'];
$optionalHeaders = ['Device Date', 'IMEI No', 'Device Notes', 'SIM Date', 'SIM Number', 'SIM Notes', 'Software'];
$parsed = parseXlsxRows($file['tmp_name'], $requiredHeaders, $optionalHeaders);
if (isset($parsed['error'])) sendResponse(false, $parsed['error'], [], [], 400);
$rows = $parsed['rows'] ?? [];
if (!$rows) sendResponse(false, 'Excel file contains no data rows.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

$errors = [];
$validRows = [];
$seenAssets = [];
$allowedSoftware = ['Tracoo', 'Tracco', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;
    $rowErrors = [];
    $technicianName = trim((string)($row['Technician Name'] ?? ''));
    $allocationType = strtolower(trim((string)($row['Allocation Type'] ?? '')));
    $hasDevice = in_array($allocationType, ['device', 'both'], true);
    $hasSim = in_array($allocationType, ['sim', 'both'], true);
    $software = trim((string)($row['Software'] ?? ''));
    $deviceDateRaw = $row['Device Date'] ?? '';
    $simDateRaw = $row['SIM Date'] ?? '';
    $deviceDate = parseAndNormalizeDate($deviceDateRaw);
    $simDate = parseAndNormalizeDate($simDateRaw);
    $imei = trim((string)($row['IMEI No'] ?? ''));
    $simNumber = trim((string)($row['SIM Number'] ?? ''));

    if ($technicianName === '') $rowErrors[] = "Row {$rowNumber}: Technician Name is required.";
    if (!in_array($allocationType, ['device', 'sim', 'both'], true)) $rowErrors[] = "Row {$rowNumber}: Allocation Type must be device, sim, or both.";
    if ($software !== '' && !in_array($software, $allowedSoftware, true)) $rowErrors[] = "Row {$rowNumber}: Invalid Software.";

    $technician = null;
    if ($technicianName !== '') {
        $stmt = $conn->prepare('SELECT id FROM technicians WHERE LOWER(TRIM(technician_name)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->bind_param('s', $technicianName);
        $stmt->execute();
        $technician = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$technician) $rowErrors[] = "Row {$rowNumber}: Technician '{$technicianName}' not found.";
    }

    $device = null;
    if ($hasDevice) {
        if ($deviceDateRaw === '' || $deviceDate === '') $rowErrors[] = "Row {$rowNumber}: Device Date is required.";
        elseif ($deviceDate === false || isFutureDate($deviceDate)) $rowErrors[] = "Row {$rowNumber}: Invalid or future Device Date.";
        if ($imei === '') $rowErrors[] = "Row {$rowNumber}: IMEI No is required.";
        elseif (!preg_match('/^[0-9]{15}$/', $imei)) $rowErrors[] = "Row {$rowNumber}: IMEI No must contain exactly 15 digits.";
        else {
            $stmt = $conn->prepare("SELECT id, status FROM devices WHERE imei_no = ? LIMIT 1");
            $stmt->bind_param('s', $imei);
            $stmt->execute();
            $device = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$device) $rowErrors[] = "Row {$rowNumber}: Device IMEI '{$imei}' not found.";
            elseif (strtolower((string)$device['status']) !== 'available') $rowErrors[] = "Row {$rowNumber}: Device IMEI '{$imei}' is not available.";
            elseif (isset($seenAssets['device:' . $device['id']])) $rowErrors[] = "Row {$rowNumber}: Duplicate device in uploaded Excel.";
            else $seenAssets['device:' . $device['id']] = true;
        }
    }

    $sim = null;
    if ($hasSim) {
        if ($simDateRaw === '' || $simDate === '') $rowErrors[] = "Row {$rowNumber}: SIM Date is required.";
        elseif ($simDate === false || isFutureDate($simDate)) $rowErrors[] = "Row {$rowNumber}: Invalid or future SIM Date.";
        if ($simNumber === '') $rowErrors[] = "Row {$rowNumber}: SIM Number is required.";
        else {
            $stmt = $conn->prepare("SELECT id, status, sim_validity_id FROM sims WHERE sim_no = ? LIMIT 1");
            $stmt->bind_param('s', $simNumber);
            $stmt->execute();
            $sim = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$sim) $rowErrors[] = "Row {$rowNumber}: SIM Number '{$simNumber}' not found.";
            elseif (strtolower((string)$sim['status']) !== 'available') $rowErrors[] = "Row {$rowNumber}: SIM Number '{$simNumber}' is not available.";
            elseif (isset($seenAssets['sim:' . $sim['id']])) $rowErrors[] = "Row {$rowNumber}: Duplicate SIM in uploaded Excel.";
            else $seenAssets['sim:' . $sim['id']] = true;
        }
    }

    if ($rowErrors) $errors = array_merge($errors, $rowErrors);
    else $validRows[] = [
        'technician_id' => (int)$technician['id'],
        'allocation_type' => $allocationType,
        'device_id' => $device['id'] ?? null,
        'device_date' => $deviceDate,
        'device_notes' => trim((string)($row['Device Notes'] ?? '')),
        'sim_id' => $sim['id'] ?? null,
        'sim_date' => $simDate,
        'sim_validity_id' => $sim['sim_validity_id'] ?? null,
        'sim_notes' => trim((string)($row['SIM Notes'] ?? '')),
        'software' => $software
    ];
}

if ($errors) {
    $conn->close();
    sendResponse(false, 'Excel validation failed.', ['errors' => array_values(array_unique($errors))], [], 400);
}

$conn->begin_transaction();
try {
    $deviceInsert = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, device_id, allocation_type, allocation_date, software, notes) VALUES ('technician', ?, ?, 'technician', ?, ?, ?)");
    $simInsert = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, sim_id, allocation_type, allocation_date, sim_given_date, sim_validity_id, sim_status, software, notes) VALUES ('technician', ?, ?, 'technician', ?, ?, NULLIF(?, 0), 'Available', ?, ?)");
    $deviceUpdate = $conn->prepare("UPDATE devices SET status = 'allocated' WHERE id = ?");
    $simUpdate = $conn->prepare("UPDATE sims SET status = 'allocated' WHERE id = ?");

    foreach ($validRows as $item) {
        if ($item['device_id']) {
            $deviceInsert->bind_param('iisss', $item['technician_id'], $item['device_id'], $item['device_date'], $item['software'], $item['device_notes']);
            if (!$deviceInsert->execute()) throw new Exception('Failed to allocate device: ' . $deviceInsert->error);
            $allocationId = (int)$conn->insert_id;
            $snapshotStmt = $conn->prepare('SELECT sa.*, d.imei_no, dt.device_type AS device_model, t.technician_name AS owner_name FROM stock_allocations sa LEFT JOIN devices d ON d.id = sa.device_id LEFT JOIN device_types dt ON dt.id = d.device_model_id LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician" WHERE sa.id = ? LIMIT 1');
            $snapshotStmt->bind_param('i', $allocationId); $snapshotStmt->execute();
            $snapshot = $snapshotStmt->get_result()->fetch_assoc() ?: []; $snapshotStmt->close();
            $snapshot['_audit'] = ['source' => 'Technician Excel Allocation', 'file' => $file['name']];
            writeAuditSnapshot($conn, $allocationId, 'Stock Allocation', 'Create', null, $snapshot, $currentUser);
            $deviceUpdate->bind_param('i', $item['device_id']);
            if (!$deviceUpdate->execute()) throw new Exception('Failed to update device status: ' . $deviceUpdate->error);
        }
        if ($item['sim_id']) {
            $simInsert->bind_param('iississ', $item['technician_id'], $item['sim_id'], $item['sim_date'], $item['sim_date'], $item['sim_validity_id'], $item['software'], $item['sim_notes']);
            if (!$simInsert->execute()) throw new Exception('Failed to allocate SIM: ' . $simInsert->error);
            $allocationId = (int)$conn->insert_id;
            $snapshotStmt = $conn->prepare('SELECT sa.*, s.sim_no, s.sim_type, t.technician_name AS owner_name FROM stock_allocations sa LEFT JOIN sims s ON s.id = sa.sim_id LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician" WHERE sa.id = ? LIMIT 1');
            $snapshotStmt->bind_param('i', $allocationId); $snapshotStmt->execute();
            $snapshot = $snapshotStmt->get_result()->fetch_assoc() ?: []; $snapshotStmt->close();
            $snapshot['_audit'] = ['source' => 'Technician Excel Allocation', 'file' => $file['name']];
            writeAuditSnapshot($conn, $allocationId, 'Stock Allocation', 'Create', null, $snapshot, $currentUser);
            $simUpdate->bind_param('i', $item['sim_id']);
            if (!$simUpdate->execute()) throw new Exception('Failed to update SIM status: ' . $simUpdate->error);
        }
    }

    $deviceInsert->close(); $simInsert->close(); $deviceUpdate->close(); $simUpdate->close();
    $conn->commit();
    sendResponse(true, 'Bulk stock allocation completed successfully for ' . count($validRows) . ' rows.', ['count' => count($validRows)]);
} catch (Throwable $ex) {
    $conn->rollback();
    sendResponse(false, 'Failed to import stock allocations: ' . $ex->getMessage(), [], [], 500);
}
$conn->close();
?>
