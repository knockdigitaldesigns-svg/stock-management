<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/dealer_validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
requirePermission('dealers.add');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    sendResponse(false, "Invalid request payload", [], [], 400);
}

$validation = validateDealerFields($data);
if (!empty($validation['errors'])) {
    sendResponse(false, $validation['errors'][0], ['errors' => $validation['errors']], [], 400);
}

$validData = $validation['data'];

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$dbErrors = checkDealerDuplicatesInDb($conn, $validData['dealer_name'], $validData['mobile_no']);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$conn->begin_transaction();
$stmt = $conn->prepare("INSERT INTO dealers (dealer_name, mobile_no, location, enrolled_date, installation_status, software, threshold_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssds", $validData['dealer_name'], $validData['mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['installation_status'], $validData['software'], $validData['threshold_amount'], $validData['notes']);

if ($stmt->execute()) {
    $dealerId = $conn->insert_id;
    try {
        if (!empty($validData['software_list'])) {
            $swStmt = $conn->prepare("INSERT IGNORE INTO dealer_software (dealer_id, software) VALUES (?, ?)");
            foreach ($validData['software_list'] as $sw) {
                $swStmt->bind_param("is", $dealerId, $sw);
                $swStmt->execute();
            }
            $swStmt->close();
        }
        writeCreatedFields($conn, $dealerId, 'Dealer', $validData, $currentUser);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmt->close();
        $conn->close();
        sendResponse(false, $e->getMessage(), [], [], 500);
    }
    $stmt->close();
    $conn->close();
    sendResponse(true, "Dealer created successfully", ["id" => $dealerId]);
} else {
    $conn->rollback();
    sendResponse(false, "Failed to create dealer: " . $stmt->error, [], [], 500);
}


if ($stmt->errno === 1062) sendResponse(false, 'Dealer mobile number already exists.', [], [], 400);
$stmt->close();
$conn->close();
?>
