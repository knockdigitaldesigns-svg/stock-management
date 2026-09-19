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
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
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

$oldStmt = $conn->prepare('SELECT dealer_name, mobile_no, location, enrolled_date, installation_status, software, threshold_amount, notes FROM dealers WHERE id = ? LIMIT 1');
$oldStmt->bind_param('i', $id);
$oldStmt->execute();
$oldDealer = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();
if (!$oldDealer) {
    $conn->close();
    sendResponse(false, 'Dealer not found', [], [], 404);
}

$newDealer = [
    'dealer_name' => $validData['dealer_name'],
    'mobile_no' => $validData['mobile_no'],
    'location' => $validData['location'],
    'enrolled_date' => $validData['enrolled_date'],
    'installation_status' => $validData['installation_status'],
    'software' => $validData['software'],
    'threshold_amount' => $validData['threshold_amount'],
    'notes' => $validData['notes']
];
$conn->begin_transaction();
try {
    writeChangedFields($conn, $id, 'Dealer', $oldDealer, $newDealer, $currentUser);

    $stmt = $conn->prepare('UPDATE dealers SET dealer_name = ?, mobile_no = ?, location = ?, enrolled_date = ?, installation_status = ?, software = ?, threshold_amount = ?, notes = ? WHERE id = ?');
    $stmt->bind_param('ssssssdsi', $validData['dealer_name'], $validData['mobile_no'], $validData['location'], $validData['enrolled_date'], $validData['installation_status'], $validData['software'], $validData['threshold_amount'], $validData['notes'], $id);

    if ($stmt->execute()) {
        $stmt->close();

        // Sync dealer_software table
        $delStmt = $conn->prepare('DELETE FROM dealer_software WHERE dealer_id = ?');
        $delStmt->bind_param('i', $id);
        $delStmt->execute();
        $delStmt->close();

        if (!empty($validData['software_list'])) {
            $swStmt = $conn->prepare('INSERT IGNORE INTO dealer_software (dealer_id, software) VALUES (?, ?)');
            foreach ($validData['software_list'] as $sw) {
                $swStmt->bind_param('is', $id, $sw);
                $swStmt->execute();
            }
            $swStmt->close();
        }

        $conn->commit();
        $conn->close();
        sendResponse(true, 'Dealer updated successfully');
    }

    if ($stmt->errno === 1062) sendResponse(false, 'Dealer mobile number already exists.', [], [], 400);

    throw new RuntimeException('Failed to update dealer');
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
