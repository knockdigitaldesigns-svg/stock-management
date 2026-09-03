<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/technician_validation.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

authenticate();
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

$stmt = $conn->prepare('UPDATE technicians SET technician_name = ?, mobile_no = ?, location = ?, enrolled_date = ?, notes = ? WHERE id = ?');
$stmt->bind_param('sssssi', $validData['technician_name'], $validData['mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['notes'], $id);

if ($stmt->execute()) {
    sendResponse(true, 'Technician updated successfully');
}

if ($stmt->errno === 1062) sendResponse(false, 'Technician mobile number already exists.', [], [], 400);

sendResponse(false, 'Failed to update technician', [], [], 500);
