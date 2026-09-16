<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('sims.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data || !isset($data->id)) {
    sendResponse(false, 'SIM ID is required', [], [], 400);
}

$id = (int) $data->id;
$purchase_date = trim((string) ($data->purchase_date ?? ''));
$sim_no = trim((string) ($data->sim_no ?? ''));
$sim_type = trim((string) ($data->sim_type ?? ''));
$sim_validity_id = (int) ($data->sim_validity_id ?? 0);
$notes = trim((string) ($data->notes ?? ''));

if ($purchase_date === '') {
    sendResponse(false, 'Purchase date is required', [], [], 400);
}
if (isFutureDate($purchase_date)) sendResponse(false, 'Future dates are not allowed', [], [], 400);

if (!preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $sim_no)) {
    sendResponse(false, 'SIM number must contain exactly 10 or 13 digits', [], [], 400);
}
if (!in_array($sim_type, ['Voice', 'Non Voice'], true)) sendResponse(false, 'SIM type must be Voice or Non Voice', [], [], 400);
if ($sim_validity_id <= 0) sendResponse(false, 'SIM validity is required', [], [], 400);

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}
$validityCheck = $conn->prepare('SELECT id FROM sim_validities WHERE id = ? LIMIT 1');
$validityCheck->bind_param('i', $sim_validity_id); $validityCheck->execute();
if (!$validityCheck->get_result()->num_rows) { $validityCheck->close(); sendResponse(false, 'SIM validity does not exist', [], [], 400); }
$validityCheck->close();

$checkStmt = $conn->prepare('SELECT id FROM sims WHERE sim_no = ? AND id != ?');
$checkStmt->bind_param('si', $sim_no, $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    sendResponse(false, 'SIM number already exists.', [], [], 400);
}
$checkStmt->close();

$oldStmt = $conn->prepare('SELECT purchase_date, sim_no, sim_type, sim_validity_id, notes FROM sims WHERE id = ? LIMIT 1');
$oldStmt->bind_param('i', $id);
$oldStmt->execute();
$oldSim = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();
if (!$oldSim) sendResponse(false, 'SIM not found', [], [], 404);

$newSim = ['purchase_date' => $purchase_date, 'sim_no' => $sim_no, 'sim_type' => $sim_type, 'sim_validity_id' => $sim_validity_id, 'notes' => $notes];
$conn->begin_transaction();
try {
writeChangedFields($conn, $id, 'SIM', $oldSim, $newSim, $currentUser);

$stmt = $conn->prepare('UPDATE sims SET purchase_date = ?, sim_no = ?, sim_type = ?, sim_validity_id = ?, notes = ? WHERE id = ?');
$stmt->bind_param('sssisi', $purchase_date, $sim_no, $sim_type, $sim_validity_id, $notes, $id);

if ($stmt->execute()) {
    $stmt->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, 'SIM updated successfully');
}

throw new RuntimeException('Failed to update SIM');
} catch (Throwable $e) {
    $conn->rollback();
    $stmt->close();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
