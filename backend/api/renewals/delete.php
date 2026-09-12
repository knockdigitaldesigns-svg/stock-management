<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.edit');
$renewalId = (int)($_GET['id'] ?? $_GET['renewal_id'] ?? 0);
if ($renewalId <= 0) {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $renewalId = (int)($payload['id'] ?? $payload['renewal_id'] ?? 0);
}
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
$stmt = $conn->prepare('SELECT id FROM customer_renewals WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $renewalId); $stmt->execute();
if ($stmt->get_result()->num_rows === 0) { $stmt->close(); $conn->close(); sendResponse(false, 'Renewal not found.', [], [], 404); }
$stmt->close();
$delete = $conn->prepare('DELETE FROM customer_renewals WHERE id = ?');
$delete->bind_param('i', $renewalId);
if (!$delete->execute()) { $delete->close(); $conn->close(); sendResponse(false, 'Failed to delete renewal.', [], [], 400); }
$delete->close(); $conn->close();
sendResponse(true, 'Renewal deleted successfully.', ['renewal_id' => $renewalId]);
?>