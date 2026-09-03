<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();
requirePermission('stock.update');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($data['allocation_id'] ?? 0);
if ($id <= 0) sendResponse(false, 'Valid allocation ID is required', [], [], 400);

$allowedSoftware = ['Tracoo', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
$ownerType = strtolower(trim((string) ($data['owner_type'] ?? '')));
$ownerId = (int) ($data['owner_id'] ?? 0);
$allocationType = trim((string) ($data['allocation_type'] ?? ''));
$allocationDate = trim((string) ($data['allocation_date'] ?? ''));
$software = trim((string) ($data['software'] ?? ''));
$total = is_numeric($data['total_amount'] ?? null) ? (float) $data['total_amount'] : 0;
$paid = is_numeric($data['amount_paid'] ?? null) ? (float) $data['amount_paid'] : 0;
$notes = trim((string) ($data['notes'] ?? ''));
$paymentMode = trim((string) ($data['payment_mode'] ?? ''));
if (!in_array($ownerType, ['dealer', 'technician'], true) || $ownerId <= 0) sendResponse(false, 'Valid owner is required', [], [], 400);
if ($software !== '' && !in_array($software, $allowedSoftware, true)) sendResponse(false, 'Invalid software selection', [], [], 400);
if ($allocationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $allocationDate)) sendResponse(false, 'Valid allocation date is required', [], [], 400);
if ($total < 0 || $paid < 0 || $paid > $total) sendResponse(false, 'Amount paid cannot exceed total amount', [], [], 400);
$pending = $total - $paid;
$status = $paid <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : 'Partially Paid');
$validModes = ['Cash', 'UPI', 'Bank Transfer', 'Card', 'Other'];
$paymentMode = in_array($paymentMode, $validModes, true) ? $paymentMode : null;

$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare('SELECT id FROM stock_allocations WHERE id = ?');
$stmt->bind_param('i', $id); $stmt->execute();
if ($stmt->get_result()->num_rows === 0) sendResponse(false, 'Stock allocation not found', [], [], 404);
$stmt->close();

$sql = "UPDATE stock_allocations SET owner_type = ?, owner_id = ?, allocation_type = ?, allocation_date = ?, software = NULLIF(?, ''), total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, payment_mode = NULLIF(?, ''), notes = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sisssdddsssi', $ownerType, $ownerId, $allocationType, $allocationDate, $software, $total, $paid, $pending, $status, $paymentMode, $notes, $id);
if (!$stmt->execute()) sendResponse(false, 'Unable to update stock allocation', [], [], 500);
$stmt->close(); $conn->close();
sendResponse(true, 'Stock allocation updated', ['allocation_id' => $id, 'amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $status]);
?>
