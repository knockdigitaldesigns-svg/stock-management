<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('lead_closures.delete');

$data = json_decode(file_get_contents('php://input'));

if (!$data) {
    sendResponse(false, 'Invalid payload', [], [], 400);
}

$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(false, 'Invalid lead closure ID.', [], [], 400);
}

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$oldStmt = $conn->prepare('SELECT * FROM lead_closures WHERE id = ? LIMIT 1'); $oldStmt->bind_param('i', $id); $oldStmt->execute(); $oldRecord = $oldStmt->get_result()->fetch_assoc(); $oldStmt->close();
if (!$oldRecord) sendResponse(false, 'Lead closure not found.', [], [], 404);
$conn->begin_transaction();
try {
writeDeleteSnapshot($conn, $id, 'Lead Closure', $oldRecord, $currentUser);
$stmt = $conn->prepare(
    'DELETE FROM lead_closures WHERE id = ?'
);

$stmt->bind_param('i', $id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    throw new RuntimeException('Lead closure not found.');
}

$stmt->close();
$conn->commit();
$conn->close();

sendResponse(
    true,
    'Lead closure deleted successfully.'
);
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>