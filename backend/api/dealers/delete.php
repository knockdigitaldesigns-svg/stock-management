<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();
requirePermission('dealers.delete');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    sendResponse(false, "Dealer ID is required", [], [], 400);
}

$id = $data->id;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Prompt: For dealers/technicians with existing allocations: Do not blindly delete.
$checkStmt = $conn->prepare("SELECT COUNT(id) as allocations FROM stock_allocations WHERE owner_type='dealer' AND owner_id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$result = $checkStmt->get_result()->fetch_assoc();

if ($result['allocations'] > 0) {
    sendResponse(false, "Cannot delete dealer. There are existing stock allocations tied to this dealer.", [], [], 400);
}
$checkStmt->close();

$stmt = $conn->prepare("DELETE FROM dealers WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    sendResponse(true, "Dealer deleted successfully");
} else {
    sendResponse(false, "Failed to delete dealer", [], [], 500);
}

$stmt->close();
$conn->close();
?>
