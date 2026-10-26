<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed.', [], [], 405);

$user = authenticate();
$userId = (int)($user['user_id'] ?? 0);
if (!isSuperAdminUser($userId)) {
    sendResponse(false, 'Only Super Admin can undo a renewal.', [], [], 403);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$renewalId = (int)($payload['renewal_id'] ?? 0);
$historyId = (int)($payload['history_id'] ?? 0);
$reason = trim((string)($payload['reason'] ?? ''));
if ($renewalId <= 0 || $historyId <= 0) {
    sendResponse(false, 'A valid renewal transaction is required.', [], [], 400);
}
if ($reason === '' || strlen($reason) > 500) {
    sendResponse(false, 'An undo reason of up to 500 characters is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
try {
    requireRenewalUndoSchema($conn);
    $conn->begin_transaction();

    $state = loadCustomerRenewalUndoState($conn, $renewalId, true);
    $historyStmt = $conn->prepare(
        "SELECT * FROM renewal_history
         WHERE id = ? AND renewal_id = ?
           AND action_type IN ('Renew SIM', 'Reactivate SIM')
         FOR UPDATE"
    );
    if (!$historyStmt) throw new RuntimeException('Unable to prepare renewal transaction lookup.');
    $historyStmt->bind_param('ii', $historyId, $renewalId);
    if (!$historyStmt->execute()) {
        $error = $historyStmt->error;
        $historyStmt->close();
        throw new RuntimeException('Unable to load renewal transaction: ' . $error);
    }
    $history = $historyStmt->get_result()->fetch_assoc();
    $historyStmt->close();
    if (!$history) throw new RuntimeException('The selected renewal transaction does not belong to this renewal.');
    if (!empty($history['undone_at'])) throw new RuntimeException('This renewal transaction has already been undone.');

    $previousState = json_decode((string)($history['previous_state_json'] ?? ''), true);
    $resultState = json_decode((string)($history['result_state_json'] ?? ''), true);
    if (!is_array($previousState) || !is_array($resultState)
        || ($previousState['entity'] ?? '') !== 'customer'
        || ($resultState['entity'] ?? '') !== 'customer'
        || !renewalUndoSnapshotHasFields($previousState, customerRenewalUndoStateFields())
        || !renewalUndoSnapshotHasFields($resultState, customerRenewalUndoStateFields())
        || (int)$previousState['renewal_id'] !== $renewalId
        || (int)$resultState['renewal_id'] !== $renewalId
        || (int)$previousState['customer_id'] !== (int)$history['customer_id']
        || (int)$resultState['customer_id'] !== (int)$history['customer_id']) {
        throw new RuntimeException('This renewal has no complete before-and-after snapshot and cannot be safely undone.');
    }
    $laterStmt = $conn->prepare(
        "SELECT id, action_type FROM renewal_history
         WHERE renewal_id = ? AND id > ?
           AND NOT (action_type = 'Renewal Payment' AND source_history_id = ?)
         ORDER BY id ASC LIMIT 1 FOR UPDATE"
    );
    if (!$laterStmt) throw new RuntimeException('Unable to verify later renewal operations.');
    $laterStmt->bind_param('iii', $renewalId, $historyId, $historyId);
    if (!$laterStmt->execute()) {
        $error = $laterStmt->error;
        $laterStmt->close();
        throw new RuntimeException('Unable to verify later renewal operations: ' . $error);
    }
    $laterOperation = $laterStmt->get_result()->fetch_assoc();
    $laterStmt->close();
    if ($laterOperation) {
        throw new RuntimeException('A later renewal operation depends on this transaction. Undo the latest operation first.');
    }

    if (!renewalUndoStateMatches($state, $resultState, customerRenewalUndoStateFields())) {
        throw new RuntimeException('The renewal or installation state changed after this transaction. It cannot be safely undone.');
    }
    if ((int)$state['customer_id'] !== (int)$history['customer_id']) {
        throw new RuntimeException('The renewal customer does not match the selected transaction.');
    }

    $renewalUpdate = $conn->prepare(
        'UPDATE customer_renewals
         SET installation_date = ?, next_renewal_date = ?, validity_months = ?, sim_status = ?,
             expired_to_safe_days = ?, safe_to_deactive_days = ?, last_renewed_date = ?,
             safe_custody_date = ?
         WHERE id = ? AND customer_id = ?'
    );
    if (!$renewalUpdate) throw new RuntimeException('Unable to prepare renewal state restoration.');
    $previousInstallationDate = $previousState['installation_date'];
    $previousNextDate = $previousState['next_renewal_date'];
    $previousValidity = (int)$previousState['validity_months'];
    $previousStatus = (string)$previousState['sim_status'];
    $previousExpiredDays = (int)$previousState['expired_to_safe_days'];
    $previousDeactiveDays = (int)$previousState['safe_to_deactive_days'];
    $previousLastRenewed = $previousState['last_renewed_date'];
    $previousSafeDate = $previousState['safe_custody_date'];
    $customerId = (int)$state['customer_id'];
    $renewalUpdate->bind_param(
        'ssisiissii',
        $previousInstallationDate,
        $previousNextDate,
        $previousValidity,
        $previousStatus,
        $previousExpiredDays,
        $previousDeactiveDays,
        $previousLastRenewed,
        $previousSafeDate,
        $renewalId,
        $customerId
    );
    if (!$renewalUpdate->execute() || $renewalUpdate->affected_rows !== 1) {
        $error = $renewalUpdate->error;
        $renewalUpdate->close();
        throw new RuntimeException('Unable to restore the exact previous renewal state: ' . $error);
    }
    $renewalUpdate->close();

    $installationId = (int)($previousState['installation_record_id'] ?? 0);
    if ($installationId > 0) {
        $installationUpdate = $conn->prepare(
            'UPDATE customer_installations
             SET installation_date = ?
             WHERE id = ? AND vehicle_id = ? AND customer_id = ?'
        );
        if (!$installationUpdate) throw new RuntimeException('Unable to prepare installation date restoration.');
        $previousLinkedDate = $previousState['linked_installation_date'];
        $vehicleId = (int)$previousState['vehicle_id'];
        $installationUpdate->bind_param('siii', $previousLinkedDate, $installationId, $vehicleId, $customerId);
        if (!$installationUpdate->execute() || $installationUpdate->affected_rows > 1) {
            $error = $installationUpdate->error;
            $installationUpdate->close();
            throw new RuntimeException('Unable to restore the linked installation date: ' . $error);
        }
        $installationUpdate->close();
    }

    $undoStmt = $conn->prepare(
        'UPDATE renewal_history
         SET undone_at = CURRENT_TIMESTAMP, undone_by = ?, undo_reason = ?
         WHERE id = ? AND renewal_id = ? AND undone_at IS NULL'
    );
    if (!$undoStmt) throw new RuntimeException('Unable to prepare renewal undo audit record.');
    $undoStmt->bind_param('isii', $userId, $reason, $historyId, $renewalId);
    if (!$undoStmt->execute() || $undoStmt->affected_rows !== 1) {
        $error = $undoStmt->error;
        $undoStmt->close();
        throw new RuntimeException('Unable to mark the renewal transaction as undone: ' . $error);
    }
    $undoStmt->close();

    $undoHistoryStmt = $conn->prepare(
        "INSERT INTO renewal_history
            (renewal_id, allocation_id, customer_id, action_type, action_date,
             old_status, new_status, old_validity_months, new_validity_months,
             old_renewal_date, new_renewal_date, payment_amount, amount_paid,
             amount_pending, changed_by, notes, previous_state_json,
             result_state_json, source_history_id)
         VALUES (?, NULL, ?, 'Undo Renewal', ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?)"
    );
    if (!$undoHistoryStmt) throw new RuntimeException('Unable to prepare renewal reversal history.');
    $actionDate = date('Y-m-d');
    $oldValidity = (int)$history['new_validity_months'];
    $newValidity = (int)$previousState['validity_months'];
    $oldRenewalDate = $resultState['next_renewal_date'];
    $newRenewalDate = $previousState['next_renewal_date'];
    $previousSnapshot = encodeRenewalUndoState($resultState);
    $restoredSnapshot = encodeRenewalUndoState($previousState);
    $undoHistoryStmt->bind_param(
        'iisssiississsi',
        $renewalId,
        $customerId,
        $actionDate,
        $resultState['sim_status'],
        $previousState['sim_status'],
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
        throw new RuntimeException('Unable to record renewal reversal history: ' . $error);
    }
    $undoHistoryStmt->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Renewal transaction undone successfully.', [
        'renewal_id' => $renewalId,
        'history_id' => $historyId,
        'validity_months' => $newValidity,
        'next_renewal_date' => $newRenewalDate
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>
