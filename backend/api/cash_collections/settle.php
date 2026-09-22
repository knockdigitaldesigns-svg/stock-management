<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/payment_modes.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'));
if (!$data) sendResponse(false, 'Invalid request payload', [], [], 400);

$recipientType = trim((string) ($data->recipient_type ?? ''));
$recipientId = (int) ($data->recipient_id ?? 0);
$collectionId = (int) ($data->collection_id ?? 0);
$settlementAmountRaw = $data->settlement_amount ?? ($data->amount_remitted ?? null);
$settlementDate = trim((string) ($data->settlement_date ?? date('Y-m-d')));
$paymentMode = trim((string) ($data->payment_mode ?? 'Cash'));
$transactionId = trim((string) ($data->transaction_id ?? ''));
$notes = trim((string) ($data->notes ?? ''));
$allowedPaymentModes = getPaymentModes();

if ($settlementDate === '' || !in_array($paymentMode, $allowedPaymentModes, true)) sendResponse(false, 'Valid settlement date and payment mode are required.', [], [], 400);
if (isFutureDate($settlementDate)) sendResponse(false, 'Future settlement dates are not allowed.', [], [], 400);
if ($paymentMode !== 'Cash' && $transactionId === '') sendResponse(false, 'Transaction ID is required for non-cash payment modes.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

try {
    if ($collectionId > 0 && ($recipientType === '' || $recipientId <= 0)) {
        $lookup = $conn->prepare('SELECT recipient_type, recipient_id FROM customer_cash_collections WHERE id = ?');
        $lookup->bind_param('i', $collectionId);
        $lookup->execute();
        $owner = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$owner) throw new Exception('Customer cash collection record not found.');
        $recipientType = $owner['recipient_type'];
        $recipientId = (int) $owner['recipient_id'];
    }
    if (!in_array($recipientType, ['Technician', 'Dealer'], true) || $recipientId <= 0) throw new Exception('Recipient type and recipient ID are required.');
    requirePermission($recipientType === 'Dealer' ? 'dealers.edit' : 'technicians.edit');

    $settlementAmount = round((float) $settlementAmountRaw, 2);
    if ($settlementAmount <= 0) throw new Exception('Settlement Amount must be greater than 0.');

    $conn->begin_transaction();
    $sql = 'SELECT ccc.*, ci.installation_date FROM customer_cash_collections ccc INNER JOIN customer_installations ci ON ci.id = ccc.installation_id WHERE ccc.recipient_type = ? AND ccc.recipient_id = ? AND ccc.pending_amount > 0';
    if ($collectionId > 0) $sql .= ' AND ccc.id = ?';
    $sql .= ' ORDER BY ci.installation_date ASC, ccc.created_at ASC, ccc.id ASC FOR UPDATE';
    $stmt = $conn->prepare($sql);
    if ($collectionId > 0) $stmt->bind_param('sii', $recipientType, $recipientId, $collectionId);
    else $stmt->bind_param('si', $recipientType, $recipientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $pendingRows = [];
    $totalPending = 0.0;
    while ($row = $result->fetch_assoc()) {
        $totalPending = round($totalPending + (float) $row['pending_amount'], 2);
        $pendingRows[] = $row;
    }
    $stmt->close();
    if (!$pendingRows) throw new Exception('There are no pending customer cash collections to settle for this ' . strtolower($recipientType) . '.');
    if ($settlementAmount > $totalPending) throw new Exception('Settlement Amount cannot exceed the outstanding amount of ₹' . number_format($totalPending, 2) . '.');

    $actor = auditActor($currentUser);
    $outstandingAfter = round($totalPending - $settlementAmount, 2);
    $header = $conn->prepare("INSERT INTO cash_collection_settlements (recipient_type, recipient_id, settlement_amount, outstanding_before, outstanding_after, settlement_date, payment_mode, transaction_id, notes, settled_by_user_id, settled_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)");
    $header->bind_param('sidddssssis', $recipientType, $recipientId, $settlementAmount, $totalPending, $outstandingAfter, $settlementDate, $paymentMode, $transactionId, $notes, $actor['id'], $actor['name']);
    if (!$header->execute()) throw new Exception('Failed to create settlement history record.');
    $settlementId = $conn->insert_id;
    $header->close();

    $update = $conn->prepare("UPDATE customer_cash_collections SET amount_remitted = ?, pending_amount = ?, settlement_status = ?, settlement_date = ?, payment_mode = ?, transaction_id = NULLIF(?, ''), notes = NULLIF(?, ''), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $allocation = $conn->prepare('INSERT INTO cash_collection_settlement_allocations (settlement_id, collection_id, customer_id, amount_allocated, outstanding_before, outstanding_after) VALUES (?, ?, ?, ?, ?, ?)');
    $remaining = $settlementAmount;
    $details = [];
    foreach ($pendingRows as $row) {
        if ($remaining <= 0) break;
        $rowId = (int) $row['id'];
        $before = round((float) $row['pending_amount'], 2);
        $apply = round(min($remaining, $before), 2);
        $newRemitted = round((float) $row['amount_remitted'] + $apply, 2);
        $after = round($before - $apply, 2);
        $status = $after <= 0 ? 'Paid' : 'Partially Paid';
        $update->bind_param('ddsssssi', $newRemitted, $after, $status, $settlementDate, $paymentMode, $transactionId, $notes, $rowId);
        if (!$update->execute()) throw new Exception('Failed to update customer cash collection.');
        $customerId = (int) $row['customer_id'];
        $allocation->bind_param('iiiddd', $settlementId, $rowId, $customerId, $apply, $before, $after);
        if (!$allocation->execute()) throw new Exception('Failed to create settlement allocation.');
        $remaining = round($remaining - $apply, 2);
        $details[] = ['collection_id' => $rowId, 'customer_id' => $customerId, 'applied_amount' => $apply, 'outstanding_before' => $before, 'outstanding_after' => $after];
    }
    $update->close();
    $allocation->close();
    writeAudit($conn, $settlementId, 'Cash Collections', 'Settlement', 'settlement_details', null, [
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'settlement_amount' => $settlementAmount,
        'payment_mode' => $paymentMode,
        'transaction_id' => $transactionId ?: null,
        'allocation_count' => count($details)
    ], $currentUser);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Settlement of ₹' . number_format($settlementAmount, 2) . ' processed successfully.', [
        'settlement_id' => $settlementId,
        'settlement_amount' => $settlementAmount,
        'remaining_total_pending' => $outstandingAfter,
        'details' => $details
    ]);
} catch (Throwable $error) {
    if ($conn->connect_errno === 0) $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}