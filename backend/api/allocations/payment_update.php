<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();
$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['allocation_id'] ?? 0);
$paid = $data['amount_paid'] ?? null;
$status = trim((string)($data['payment_status'] ?? ''));
if ($id <= 0 || !is_numeric($paid)) sendResponse(false, 'Allocation and paid amount are required.', [], [], 400);
$paid = round((float)$paid, 2);
if ($paid < 0) sendResponse(false, 'Amount paid cannot be negative.', [], [], 400);
$db = new Database(); $conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$stmt = $conn->prepare('SELECT total_amount FROM stock_allocations WHERE id = ?'); $stmt->bind_param('i', $id); $stmt->execute(); $allocation = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$allocation) sendResponse(false, 'Allocation not found.', [], [], 404);
$total = (float)$allocation['total_amount'];
if ($paid > $total) sendResponse(false, 'Amount paid cannot be greater than total price.', [], [], 400);
$pending = round($total - $paid, 2);
$expected = $paid <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : 'Partially Paid');
if ($status !== '' && $status !== $expected) sendResponse(false, "Payment status must be $expected for the entered amount.", [], [], 400);
$stmt = $conn->prepare('UPDATE stock_allocations SET amount_paid = ?, pending_amount = ?, payment_status = ? WHERE id = ?'); $stmt->bind_param('ddsi', $paid, $pending, $expected, $id);
if (!$stmt->execute()) sendResponse(false, 'Unable to update payment.', [], [], 500);
$stmt->close(); $conn->close();
sendResponse(true, 'Payment updated successfully', ['amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $expected]);
?>
