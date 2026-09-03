<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/dealer_validation.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

authenticate();
requirePermission('dealers.edit');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    sendResponse(false, 'Dealer ID is required', [], [], 400);
}

$id = (int) $data['id'];
$validation = validateDealerFields($data);
if (!empty($validation['errors'])) {
    sendResponse(false, $validation['errors'][0], ['errors' => $validation['errors']], [], 400);
}

$validData = $validation['data'];

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$dbErrors = checkDealerDuplicatesInDb($conn, $validData['dealer_name'], $validData['mobile_no'], $id);
if (!empty($dbErrors)) {
    $conn->close();
    sendResponse(false, $dbErrors[0], ['errors' => $dbErrors], [], 400);
}

$stmt = $conn->prepare('UPDATE dealers SET dealer_name = ?, mobile_no = ?, location = ?, enrolled_date = ?, installation_status = ?, software = ?, notes = ? WHERE id = ?');
$stmt->bind_param('sssssssi', $validData['dealer_name'], $validData['mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['installation_status'], $validData['software'], $validData['notes'], $id);

if ($stmt->execute()) {
    sendResponse(true, 'Dealer updated successfully');
}

if ($stmt->errno === 1062) sendResponse(false, 'Dealer mobile number already exists.', [], [], 400);

sendResponse(false, 'Failed to update dealer', [], [], 500);
