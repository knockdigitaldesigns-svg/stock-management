<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();
requirePermission('sims.delete');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    sendResponse(false, "SIM ID is required", [], [], 400);
}

$id = $data->id;

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Check status first
$checkStmt = $conn->prepare("SELECT status FROM sims WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    sendResponse(false, "SIM not found", [], [], 404);
}

$sim = $result->fetch_assoc();

if ($sim['status'] === 'allocated' || $sim['status'] === 'used') {
    sendResponse(false, "This SIM is currently " . $sim['status'] . " and cannot be deleted.", [], [], 400);
}

$checkStmt->close();

// Delete sim
$stmt = $conn->prepare("DELETE FROM sims WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    sendResponse(true, "SIM deleted successfully");
} else {
    sendResponse(false, "Failed to delete SIM", [], [], 500);
}

$stmt->close();
$conn->close();
?>
