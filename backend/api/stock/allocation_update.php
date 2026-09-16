<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/date.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate();
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
$activationDate = trim((string) ($data['activation_date'] ?? ''));
$validityId = (int) ($data['sim_validity_id'] ?? 0);
$deactivationDate = trim((string) ($data['deactivation_date'] ?? ''));
$paymentMode = trim((string) ($data['payment_mode'] ?? ''));
$transactionId = trim((string) ($data['transaction_id'] ?? ''));
$activationDate = trim((string) ($data['activation_date'] ?? ''));
$validityId = (int) ($data['sim_validity_id'] ?? 0);
$deactivationDate = trim((string) ($data['deactivation_date'] ?? ''));
if (!in_array($ownerType, ['dealer', 'technician'], true) || $ownerId <= 0) sendResponse(false, 'Valid owner is required', [], [], 400);
if ($software !== '' && !in_array($software, $allowedSoftware, true)) sendResponse(false, 'Invalid software selection', [], [], 400);
if ($allocationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $allocationDate)) sendResponse(false, 'Valid allocation date is required', [], [], 400);
if ($paid < 0) sendResponse(false, 'Amount Paid cannot be negative.', [], [], 400);
if ($paid > $total) sendResponse(false, 'Amount Paid cannot exceed Total Amount.', [], [], 400);
$pending = max(0, $total - $paid);
$status = $total <= 0 ? 'Not Paid' : ($pending <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Not Paid'));
$validModes = ['Cash', 'UPI', 'Bank Transfer', 'Card', 'Other'];
$paymentMode = in_array($paymentMode, $validModes, true) ? $paymentMode : null;
if ($paymentMode !== null && $paymentMode !== 'Cash' && $transactionId === '') sendResponse(false, 'Transaction ID is required for the selected Payment Mode.', [], [], 400);
if ($paymentMode === 'Cash') $transactionId = '';

$db = new Database();
$conn = $db->getConnection();
$conn->begin_transaction();
$stmt = $conn->prepare('SELECT * FROM stock_allocations WHERE id = ? FOR UPDATE');
$stmt->bind_param('i', $id); $stmt->execute();
$oldAllocation = $stmt->get_result()->fetch_assoc();
if (!$oldAllocation) { $conn->rollback(); $conn->close(); sendResponse(false, 'Stock allocation not found', [], [], 404); }
$stmt->close();

$isSimAllocation = !empty($oldAllocation['sim_id']);
$calculatedExpiryDate = $oldAllocation['sim_expiry_date'] ?? null;
$lifecycleStatus = $oldAllocation['sim_status'] ?: 'Available';
if ($isSimAllocation) {
	if ($activationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) sendResponse(false, 'Valid activation date is required', [], [], 400);
	if ($deactivationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deactivationDate)) sendResponse(false, 'Valid deactivation date is required', [], [], 400);
	if ($activationDate !== '' && isFutureDate($activationDate)) sendResponse(false, 'Activation date cannot be in the future', [], [], 400);
	if ($deactivationDate !== '' && isFutureDate($deactivationDate)) sendResponse(false, 'Deactivation date cannot be in the future', [], [], 400);
	if ($deactivationDate !== '' && $activationDate === '') sendResponse(false, 'Activation date is required before deactivation', [], [], 400);
	if ($deactivationDate !== '' && $deactivationDate < $activationDate) sendResponse(false, 'Deactivation date cannot be before activation date', [], [], 400);
	$validityId = $validityId > 0 ? $validityId : (int) ($oldAllocation['sim_validity_id'] ?? 0);
	if ($validityId <= 0) sendResponse(false, 'SIM validity is required', [], [], 400);
	$validityStmt = $conn->prepare("SELECT months FROM sim_validities WHERE id = ? AND LOWER(status) = 'active' LIMIT 1");
	$validityStmt->bind_param('i', $validityId);
	$validityStmt->execute();
	$validityRow = $validityStmt->get_result()->fetch_assoc();
	$validityStmt->close();
	if (!$validityRow) sendResponse(false, 'Selected SIM validity is invalid', [], [], 400);
	$calculatedExpiryDate = $activationDate !== ''
		? (new DateTimeImmutable($activationDate))->modify('+' . (int) $validityRow['months'] . ' months')->format('Y-m-d')
		: null;
	$lifecycleStatus = $deactivationDate !== '' ? 'Deactive' : ($activationDate !== '' ? 'Active' : 'Available');
}

try {
	$auditFields = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'allocation_type' => $allocationType, 'software' => $software ?: null, 'total_amount' => $total, 'amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $status, 'payment_mode' => $paymentMode, 'transaction_id' => $transactionId ?: null, 'notes' => $notes];
	if (!$isSimAllocation) $auditFields['allocation_date'] = $allocationDate;
	if ($isSimAllocation) {
		$auditFields['sim_activation_date'] = $activationDate ?: null;
		$auditFields['sim_validity_id'] = $validityId;
		$auditFields['sim_expiry_date'] = $calculatedExpiryDate;
		$auditFields['sim_deactivation_date'] = $deactivationDate ?: null;
		$auditFields['sim_status'] = $lifecycleStatus;
	}
	writeChangedFields($conn, $id, 'Stock Allocation', $oldAllocation, $auditFields, $currentUser);
	if ($isSimAllocation) {
		$sql = "UPDATE stock_allocations SET owner_type = ?, owner_id = ?, allocation_type = ?, software = NULLIF(?, ''), total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, payment_mode = NULLIF(?, ''), transaction_id = NULLIF(?, ''), notes = ?, sim_activation_date = NULLIF(?, ''), sim_validity_id = ?, sim_expiry_date = NULLIF(?, ''), sim_deactivation_date = NULLIF(?, ''), sim_status = ? WHERE id = ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('sissdddsssssisssi', $ownerType, $ownerId, $allocationType, $software, $total, $paid, $pending, $status, $paymentMode, $transactionId, $notes, $activationDate, $validityId, $calculatedExpiryDate, $deactivationDate, $lifecycleStatus, $id);
	} else {
		$sql = "UPDATE stock_allocations SET owner_type = ?, owner_id = ?, allocation_type = ?, allocation_date = ?, software = NULLIF(?, ''), total_amount = ?, amount_paid = ?, pending_amount = ?, payment_status = ?, payment_mode = NULLIF(?, ''), transaction_id = NULLIF(?, ''), notes = ? WHERE id = ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param('sisssdddssssi', $ownerType, $ownerId, $allocationType, $allocationDate, $software, $total, $paid, $pending, $status, $paymentMode, $transactionId, $notes, $id);
	}
if (!$stmt->execute()) throw new RuntimeException('Unable to update stock allocation');
$stmt->close(); $conn->commit(); $conn->close();
sendResponse(true, 'Stock allocation updated', ['allocation_id' => $id, 'amount_paid' => $paid, 'pending_amount' => $pending, 'payment_status' => $status]);
} catch (Throwable $e) {
	$conn->rollback();
	if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
	$conn->close();
	sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
