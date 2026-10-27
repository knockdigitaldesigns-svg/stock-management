<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/renewal_undo.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed.', [], [], 405);

$user = authenticate();
$userId = (int)($user['user_id'] ?? 0);
if (!isSuperAdminUser($userId)) {
    sendResponse(false, 'Only Super Admin can undo a renewal.', [], [], 403);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$allocationId = (int)($payload['allocation_id'] ?? 0);
$historyId = (int)($payload['history_id'] ?? 0);
$reason = trim((string)($payload['reason'] ?? ''));
if ($allocationId <= 0 || $historyId <= 0) {
    sendResponse(false, 'A valid dealer SIM renewal transaction is required.', [], [], 400);
}
if ($reason === '' || strlen($reason) > 500) {
    sendResponse(false, 'An undo reason of up to 500 characters is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
try {
    requireRenewalUndoSchema($conn);
    $conn->begin_transaction();

    $state = loadDealerSimUndoState($conn, $allocationId, true);
    $historyStmt = $conn->prepare(
        "SELECT * FROM renewal_history
         WHERE id = ? AND allocation_id = ?
           AND action_type IN ('Renew SIM', 'Reactivate SIM')
         FOR UPDATE"
    );
    if (!$historyStmt) throw new RuntimeException('Unable to prepare dealer SIM renewal transaction lookup.');
    $historyStmt->bind_param('ii', $historyId, $allocationId);
    if (!$historyStmt->execute()) {
        $error = $historyStmt->error;
        $historyStmt->close();
        throw new RuntimeException('Unable to load dealer SIM renewal transaction: ' . $error);
    }
    $history = $historyStmt->get_result()->fetch_assoc();
    $historyStmt->close();
    if (!$history) throw new RuntimeException('The selected transaction does not belong to this dealer SIM allocation.');
    if (!empty($history['undone_at'])) throw new RuntimeException('This renewal transaction has already been undone.');

    $previousState = json_decode((string)($history['previous_state_json'] ?? ''), true);
    $resultState = json_decode((string)($history['result_state_json'] ?? ''), true);
    if (is_array($previousState)) {
        $previousState = normalizeDealerSimUndoSnapshot($conn, $previousState);
    }
    if (is_array($resultState)) {
        $resultState = normalizeDealerSimUndoSnapshot($conn, $resultState);
    }
    if (!is_array($previousState) || !is_array($resultState)
        || ($previousState['entity'] ?? '') !== 'dealer'
        || ($resultState['entity'] ?? '') !== 'dealer'
        || !renewalUndoSnapshotHasFields($previousState, dealerSimUndoStateFields())
        || !renewalUndoSnapshotHasFields($resultState, dealerSimUndoStateFields())
        || (int)$previousState['allocation_id'] !== $allocationId
        || (int)$resultState['allocation_id'] !== $allocationId
        || (int)$previousState['sim_id'] !== (int)$state['sim_id']
        || (int)$resultState['sim_id'] !== (int)$state['sim_id']
        || (int)$previousState['dealer_id'] !== (int)$state['dealer_id']
        || (int)$resultState['dealer_id'] !== (int)$state['dealer_id']) {
        throw new RuntimeException('This renewal has no complete before-and-after snapshot and cannot be safely undone.');
    }
    $laterStmt = $conn->prepare(
        "SELECT id, action_type FROM renewal_history
         WHERE allocation_id = ? AND id > ?
           AND NOT (action_type = 'Renewal Payment' AND source_history_id = ?)
         ORDER BY id ASC LIMIT 1 FOR UPDATE"
    );
    if (!$laterStmt) throw new RuntimeException('Unable to verify later dealer SIM operations.');
    $laterStmt->bind_param('iii', $allocationId, $historyId, $historyId);
    if (!$laterStmt->execute()) {
        $error = $laterStmt->error;
        $laterStmt->close();
        throw new RuntimeException('Unable to verify later dealer SIM operations: ' . $error);
    }
    $laterOperation = $laterStmt->get_result()->fetch_assoc();
    $laterStmt->close();
    if ($laterOperation) {
        throw new RuntimeException('A later SIM lifecycle or payment operation depends on this renewal. Undo the latest operation first.');
    }

    if (!renewalUndoStateMatches($state, $resultState, dealerSimUndoStateFields())) {
        throw new RuntimeException('The SIM activation state changed after this transaction. It cannot be safely undone.');
    }

    $update = $conn->prepare(
        'UPDATE stock_allocations
         SET sim_activation_date = ?, sim_validity_id = ?, sim_expiry_date = ?,
             sim_deactivation_date = ?, sim_reactivation_date = ?, sim_status = ?
         WHERE id = ? AND owner_type = ? AND sim_id = ?'
    );
    if (!$update) throw new RuntimeException('Unable to prepare dealer SIM state restoration.');
    $activationDate = $previousState['activation_date'];
    $validityId = $previousState['validity_id'] === null ? null : (int)$previousState['validity_id'];
    $expiryDate = $previousState['expiry_date'];
    $deactivationDate = $previousState['deactivation_date'];
    $reactivationDate = $previousState['reactivation_date'];
    $status = (string)$previousState['status'];
    $ownerType = 'dealer';
    $simId = (int)$state['sim_id'];
    $update->bind_param('sissssisi', $activationDate, $validityId, $expiryDate, $deactivationDate, $reactivationDate, $status, $allocationId, $ownerType, $simId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to restore the exact previous SIM activation state: ' . $error);
    }
    $update->close();

    $undoStmt = $conn->prepare(
        'UPDATE renewal_history
         SET undone_at = CURRENT_TIMESTAMP, undone_by = ?, undo_reason = ?
         WHERE id = ? AND allocation_id = ? AND undone_at IS NULL'
    );
    if (!$undoStmt) throw new RuntimeException('Unable to prepare dealer SIM undo audit record.');
    $undoStmt->bind_param('isii', $userId, $reason, $historyId, $allocationId);
    if (!$undoStmt->execute() || $undoStmt->affected_rows !== 1) {
        $error = $undoStmt->error;
        $undoStmt->close();
        throw new RuntimeException('Unable to mark the SIM renewal transaction as undone: ' . $error);
    }
    $undoStmt->close();

    $undoHistoryStmt = $conn->prepare(
        "INSERT INTO renewal_history
            (renewal_id, allocation_id, customer_id, action_type, action_date,
             old_status, new_status, old_validity_months, new_validity_months,
             old_renewal_date, new_renewal_date, payment_amount, amount_paid,
             amount_pending, changed_by, notes, previous_state_json,
             result_state_json, source_history_id)
         VALUES (0, ?, 0, 'Undo Renewal', ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?)"
    );
    if (!$undoHistoryStmt) throw new RuntimeException('Unable to prepare dealer SIM reversal history.');
    $actionDate = date('Y-m-d');
    $newValidity = $previousState['effective_validity_months'] === null
        ? null
        : (int)$previousState['effective_validity_months'];
    $oldValidity = $resultState['effective_validity_months'] === null
        ? null
        : (int)$resultState['effective_validity_months'];
    $oldRenewalDate = $resultState['expiry_date'];
    $newRenewalDate = $previousState['expiry_date'];
    $previousSnapshot = encodeRenewalUndoState($resultState);
    $restoredSnapshot = encodeRenewalUndoState($previousState);
    $undoHistoryStmt->bind_param(
        'isssiississsi',
        $allocationId,
        $actionDate,
        $resultState['status'],
        $previousState['status'],
        $oldValidity,
        $newValidity,
        $oldRenewalDate,
        $newRenewalDate,
        $userId,
        $reason,
        $previousSnapshot,
        $restoredSnapshot,
        $historyId
    );
    if (!$undoHistoryStmt->execute()) {
        $error = $undoHistoryStmt->error;
        $undoHistoryStmt->close();
        throw new RuntimeException('Unable to record dealer SIM reversal history: ' . $error);
    }
    $undoHistoryStmt->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Dealer SIM renewal undone successfully.', [
        'allocation_id' => $allocationId,
        'history_id' => $historyId,
        'validity_months' => $newValidity,
        'expiry_date' => $newRenewalDate
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>
