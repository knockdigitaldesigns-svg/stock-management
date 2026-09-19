<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/technician_validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/excel_reader.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('technicians.import');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    sendResponse(false, 'Please upload a valid Excel file.', [], [], 400);
}

$file = $_FILES['file'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    sendResponse(false, 'Only .xlsx files are allowed.', [], [], 400);
}

if (!isset($file['size']) || $file['size'] === 0) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

$parsed = parseXlsxRows($file['tmp_name'], ['Technician Name', 'Mobile No', 'Location', 'Enrolled Date'], ['Notes']);
if (isset($parsed['error'])) {
    sendResponse(false, $parsed['error'], [], [], 400);
}

$rows = $parsed['rows'];
if (count($rows) === 0) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

$errors = [];
$validRows = [];
$seenMobiles = [];
$seenNames = [];
$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;
    $fieldValidation = validateTechnicianFields($row, $rowNumber);
    $rowErrors = $fieldValidation['errors'];
    $data = $fieldValidation['data'];

    // In-file duplicate detection
    $normName = normalizeName($data['technician_name']);
    if ($normName !== '') {
        if (isset($seenNames[$normName])) {
            $rowErrors[] = "Row {$rowNumber}: Duplicate Technician Name in uploaded Excel.";
        }
        $seenNames[$normName] = true;
    }

    $normMobile = $data['mobile_no'];
    if ($normMobile !== '') {
        if (isset($seenMobiles[$normMobile])) {
            $rowErrors[] = "Row {$rowNumber}: Duplicate Mobile No in uploaded Excel.";
        }
        $seenMobiles[$normMobile] = true;
    }

    // Database duplicate checks
    if ($normName !== '' || $normMobile !== '') {
        $dbErrors = checkTechnicianDuplicatesInDb($conn, $data['technician_name'], $normMobile, null, $rowNumber);
        $rowErrors = array_merge($rowErrors, $dbErrors);
    }

    if (!empty($rowErrors)) {
        $errors = array_merge($errors, $rowErrors);
    } else {
        $validRows[] = $data;
    }
}

if (!empty($errors)) {
    $conn->close();
    sendResponse(false, 'Excel validation failed.', ['errors' => array_values(array_unique($errors))], [], 400);
}

$conn->begin_transaction();
try {
    $insertStmt = $conn->prepare('INSERT INTO technicians (technician_name, mobile_no, location, enrolled_date, notes) VALUES (?, ?, ?, ?, ?)');
    foreach ($validRows as $technician) {
        $insertStmt->bind_param('sssss', $technician['technician_name'], $technician['mobile_no'], $technician['location'], $technician['enrolled_date'], $technician['notes']);
        if (!$insertStmt->execute()) {
            throw new Exception($insertStmt->error);
        }
        $technicianId = (int) $conn->insert_id;
        $snapshotStmt = $conn->prepare('SELECT * FROM technicians WHERE id = ? LIMIT 1');
        $snapshotStmt->bind_param('i', $technicianId);
        $snapshotStmt->execute();
        $snapshot = $snapshotStmt->get_result()->fetch_assoc() ?: $technician;
        $snapshotStmt->close();
        $snapshot['_audit'] = ['source' => 'Excel Import', 'file' => $file['name']];
        writeAuditSnapshot($conn, $technicianId, 'Technician', 'Create', null, $snapshot, $currentUser);
    }
    $insertStmt->close();
    $conn->commit();
    sendResponse(true, count($validRows) . ' technicians imported successfully.', ['count' => count($validRows)]);
} catch (Throwable $ex) {
    $conn->rollback();
    if (strpos($ex->getMessage(), 'Duplicate entry') !== false) {
        sendResponse(false, 'A technician with the same name or mobile number already exists.', [], [], 400);
    }
    sendResponse(false, 'Failed to import technicians: ' . $ex->getMessage(), [], [], 500);
}

$conn->close();
