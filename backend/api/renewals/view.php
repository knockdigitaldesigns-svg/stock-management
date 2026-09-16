<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.view');
$renewalId = (int)($_GET['renewal_id'] ?? 0);
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
$conn = (new Database())->getConnection();
$stmt = $conn->prepare(renewalRowQuery() . ' WHERE cr.id = ?');
$stmt->bind_param('i', $renewalId); $stmt->execute(); $renewal = $stmt->get_result()->fetch_assoc(); $stmt->close(); $conn->close();
if (!$renewal) sendResponse(false, 'Renewal not found.', [], [], 404);
sendResponse(true, 'Renewal fetched successfully.', ['renewal' => $renewal]);
?>