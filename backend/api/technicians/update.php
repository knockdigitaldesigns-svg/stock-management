<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/technician_validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('technicians.edit');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    sendResponse(false, 'Technician ID is required', [], [], 400);
}

$id = (int) $data['id'];
$validation = validateTechnicianFields($data);
if (!empty($validation['errors'])) {
    sendResponse(false, $validation['errors'][0], ['errors' => $validation['errors']], [], 400);
}

$validData = $validation['data'];

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$dbErrors = checkTechnicianDuplicatesInDb($conn, $validData['technician_name'], $validData['mobile_no'], $id);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$oldStmt = $conn->prepare('SELECT technician_name, mobile_no, location, enrolled_date, notes FROM technicians WHERE id = ? LIMIT 1');
$oldStmt->bind_param('i', $id);
$oldStmt->execute();
$oldTechnician = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();
if (!$oldTechnician) sendResponse(false, 'Technician not found', [], [], 404);

$conn->begin_transaction();
try {
writeChangedFields($conn, $id, 'Technician', $oldTechnician, $validData, $currentUser);

$stmt = $conn->prepare('UPDATE technicians SET technician_name = ?, mobile_no = ?, location = ?, enrolled_date = ?, notes = ? WHERE id = ?');
$stmt->bind_param('sssssi', $validData['technician_name'], $validData['mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['notes'], $id);

if ($stmt->execute()) {
    $stmt->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Technician updated successfully');
}

if ($stmt->errno === 1062) sendResponse(false, 'Technician mobile number already exists.', [], [], 400);

throw new RuntimeException('Failed to update technician');
} catch (Throwable $e) {
    $conn->rollback();
    $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
