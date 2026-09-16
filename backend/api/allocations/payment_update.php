<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['allocation_id'] ?? 0);
$totalAmount = $data['total_amount'] ?? null;
$paid = $data['amount_paid'] ?? null;
$paymentMode = trim((string)($data['payment_mode'] ?? ''));
$transactionId = trim((string)($data['transaction_id'] ?? ''));
$status = trim((string)($data['payment_status'] ?? ''));
if ($id <= 0 || !is_numeric($totalAmount) || !is_numeric($paid)) sendResponse(false, 'Allocation, total price and paid amount are required.', [], [], 400);
$validModes = ['Cash', 'UPI', 'Bank Transfer', 'Card', 'Other'];
if ($paymentMode !== '' && !in_array($paymentMode, $validModes, true)) sendResponse(false, 'Invalid payment mode.', [], [], 400);
$paymentMode = $paymentMode !== '' ? $paymentMode : null;
if ($paymentMode !== null && $paymentMode !== 'Cash' && $transactionId === '') sendResponse(false, 'Transaction ID is required for the selected Payment Mode.', [], [], 400);
if ($paymentMode === 'Cash') $transactionId = '';
$totalAmount = round((float)$totalAmount, 2);
if ($totalAmount < 0) sendResponse(false, 'Total Price cannot be negative.', [], [], 400);
$paid = round((float)$paid, 2);
if ($paid < 0) sendResponse(false, 'Amount Paid cannot be negative.', [], [], 400);
$db = new Database(); $conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$conn->begin_transaction();
$stmt = $conn->prepare('SELECT * FROM stock_allocations WHERE id = ? FOR UPDATE'); $stmt->bind_param('i', $id); $stmt->execute(); $allocation = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$allocation) { $conn->rollback(); $conn->close(); sendResponse(false, 'Allocation not found.', [], [], 404); }
$total = $totalAmount;
if ($paid > $total) sendResponse(false, 'Amount Paid cannot exceed Total Amount.', [], [], 400);
$pending = max(0, round($total - $paid, 2));
$expected = $total <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Not Paid'));
if ($status !== '' && $status !== $expected) sendResponse(false, "Payment status must be $expected for the entered amount.", [], [], 400);
$conn->begin_transaction(); try { writeChangedFields($conn, $id, 'Stock Allocation Payment', $allocation, ['total_amount' => $total, 'amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $expected, 'payment_mode' => $paymentMode, 'transaction_id' => $transactionId ?: null], $currentUser); $stmt = $conn->prepare('UPDATE stock_allocations SET total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, payment_mode = ?, transaction_id = NULLIF(?, \'\') WHERE id = ?'); $stmt->bind_param('dddsssi', $total, $paid, $pending, $expected, $paymentMode, $transactionId, $id); if (!$stmt->execute()) throw new RuntimeException('Unable to update payment.'); $stmt->close(); $conn->commit(); $conn->close(); } catch (Throwable $e) { $conn->rollback(); if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close(); $conn->close(); sendResponse(false, $e->getMessage(), [], [], 500); }
sendResponse(true, 'Payment updated successfully', ['total_amount' => $total, 'amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $expected, 'payment_mode' => $paymentMode, 'transaction_id' => $transactionId ?: null]);
?>
