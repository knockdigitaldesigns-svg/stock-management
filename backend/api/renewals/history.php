<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.history');
$renewalId = (int)($_GET['renewal_id'] ?? 0);
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
$conn = (new Database())->getConnection();
$stmt = $conn->prepare('SELECT rh.*, u.username AS changed_by_name FROM renewal_history rh LEFT JOIN users u ON u.id = rh.changed_by WHERE rh.renewal_id = ? ORDER BY rh.id DESC');
$stmt->bind_param('i', $renewalId); $stmt->execute(); $result = $stmt->get_result(); $history = []; while ($row = $result->fetch_assoc()) $history[] = $row; $stmt->close(); $conn->close();
sendResponse(true, 'Renewal history fetched successfully.', ['history' => $history]);
?>