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
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
requirePermission('technicians.add');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    sendResponse(false, "Invalid request payload", [], [], 400);
}

$validation = validateTechnicianFields($data);
if (!empty($validation['errors'])) {
    sendResponse(false, $validation['errors'][0], ['errors' => $validation['errors']], [], 400);
}

$validData = $validation['data'];

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$dbErrors = checkTechnicianDuplicatesInDb($conn, $validData['technician_name'], $validData['mobile_no']);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$conn->begin_transaction();
$stmt = $conn->prepare("INSERT INTO technicians (technician_name, mobile_no, alternate_mobile_no, location, enrolled_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssss", $validData['technician_name'], $validData['mobile_no'], $validData['alternate_mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['notes']);

if ($stmt->execute()) {
    $technicianId = $conn->insert_id;
    try {
        writeCreatedFields($conn, $technicianId, 'Technician', $validData, $currentUser);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmt->close();
        $conn->close();
        sendResponse(false, $e->getMessage(), [], [], 500);
    }
    sendResponse(true, "Technician created successfully", ["id" => $technicianId]);
} else {
    $conn->rollback();
    sendResponse(false, "Failed to create technician: " . $stmt->error, [], [], 500);
}


if ($stmt->errno === 1062) sendResponse(false, 'Technician mobile number already exists.', [], [], 400);
$stmt->close();
$conn->close();
?>
