<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate();

$data = json_decode(file_get_contents('php://input'));
$collectionId = (int) ($data->collection_id ?? 0);
$amountRemitted = (float) ($data->amount_remitted ?? -1);
$settlementDate = trim((string) ($data->settlement_date ?? ''));
$paymentMode = trim((string) ($data->payment_mode ?? ''));
$transactionId = trim((string) ($data->transaction_id ?? ''));
$notes = trim((string) ($data->notes ?? ''));
if ($collectionId <= 0 || $amountRemitted < 0 || $settlementDate === '' || !in_array($paymentMode, ['Cash', 'UPI', 'Card', 'Bank Transfer', 'Other'], true)) {
    sendResponse(false, 'Collection, remitted amount, settlement date and payment mode are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
try {
    $conn->begin_transaction();
    $select = $conn->prepare('SELECT * FROM customer_cash_collections WHERE id = ? FOR UPDATE');
    $select->bind_param('i', $collectionId);
    $select->execute();
    $old = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$old) throw new Exception('Customer cash collection not found.');
    $collected = round((float) $old['amount_collected'], 2);
    $amountRemitted = round($amountRemitted, 2);
    if ($amountRemitted > $collected) throw new Exception('Amount Remitted cannot exceed Overall Amount Collected.');
    $pending = round($collected - $amountRemitted, 2);
    $status = $pending <= 0 ? 'Paid' : ($amountRemitted > 0 ? 'Partially Paid' : 'Pending');
    $update = $conn->prepare('UPDATE customer_cash_collections SET amount_remitted = ?, pending_amount = ?, settlement_status = ?, settlement_date = ?, payment_mode = ?, transaction_id = NULLIF(?, \'\'), notes = NULLIF(?, \'\'), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->bind_param('ddsssssi', $amountRemitted, $pending, $status, $settlementDate, $paymentMode, $transactionId, $notes, $collectionId);
    if (!$update->execute()) throw new Exception('Failed to save settlement.');
    $update->close();
    writeChangedFields($conn, $collectionId, 'Customer Cash Collection', $old, ['amount_remitted' => $amountRemitted, 'pending_amount' => $pending, 'settlement_status' => $status, 'settlement_date' => $settlementDate, 'payment_mode' => $paymentMode, 'transaction_id' => $transactionId ?: null, 'notes' => $notes ?: null], $currentUser);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Customer cash settlement updated successfully.', ['amount_remitted' => $amountRemitted, 'pending_amount' => $pending, 'settlement_status' => $status]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>