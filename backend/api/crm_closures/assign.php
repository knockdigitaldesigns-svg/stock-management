<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../utils/audit.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_installations.assign');
$userId = (int) ($currentUser['user_id'] ?? 0);
crmClosureRequireAdminRole($userId);
$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$closureId = filter_var($data->closure_id ?? null, FILTER_VALIDATE_INT);
$personType = trim((string) ($data->installation_person_type ?? ''));
$personId = filter_var($data->installation_person_id ?? null, FILTER_VALIDATE_INT);
$personUserId = filter_var($data->installation_person_user_id ?? null, FILTER_VALIDATE_INT);
if ($closureId === false || $closureId <= 0 || $personId === false || $personUserId === false) {
    sendResponse(false, 'Select a valid approved installation and installer account.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);
crmClosureRequireInstallationAssignmentSchema($conn);
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin installation reassignment: ' . $error, [], [], 500);
}

try {
    $stmt = $conn->prepare(
        'SELECT cf.*, l.lead_name, l.assigned_user_id
         FROM crm_lead_closure_forms cf
         INNER JOIN crm_leads l ON l.id = cf.lead_id
         WHERE cf.id = ? AND cf.submission_status = \'Approved\' AND cf.customer_id IS NULL
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare approved installation lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $closureId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load approved installation: ' . $error);
    }
    $closure = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closure) {
        throw new DomainException('Approved installation assignment was not found.');
    }
    if ($closure['installation_person_type'] === $personType &&
        (int) $closure['installation_person_id'] === (int) $personId &&
        (int) $closure['installation_person_user_id'] === (int) $personUserId) {
        throw new DomainException('This installation is already assigned to the selected person.');
    }

    $installer = crmClosureValidateInstaller(
        $conn,
        $personType,
        (int) $personId,
        (int) $personUserId
    );

    $oldSnapshot = crmClosureSnapshot($closure);
    $update = $conn->prepare(
        'UPDATE crm_lead_closure_forms
         SET installation_person_type = ?, installation_person_id = ?,
             installation_person_user_id = ?, installation_assigned_by_user_id = ?,
             installation_assigned_at = CURRENT_TIMESTAMP
         WHERE id = ? AND submission_status = \'Approved\''
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare installation reassignment: ' . $conn->error);
    }
    $update->bind_param(
        'siiii',
        $personType,
        $personId,
        $personUserId,
        $userId,
        $closureId
    );
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to save installation reassignment: ' . $error);
    }
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new DomainException('The installation assignment changed. Refresh and try again.');
    }
    $update->close();

    $newSnapshot = $oldSnapshot;
    $newSnapshot['installation_person_type'] = $personType;
    $newSnapshot['installation_person_id'] = (int) $personId;
    $newSnapshot['installation_person_user_id'] = (int) $personUserId;
    $newSnapshot['installation_assigned_by_user_id'] = $userId;
    $assignedAtStmt = $conn->prepare('SELECT installation_assigned_at FROM crm_lead_closure_forms WHERE id = ?');
    if (!$assignedAtStmt) {
        throw new RuntimeException('Unable to prepare installation assignment timestamp lookup: ' . $conn->error);
    }
    $assignedAtStmt->bind_param('i', $closureId);
    if (!$assignedAtStmt->execute()) {
        $error = $assignedAtStmt->error;
        $assignedAtStmt->close();
        throw new RuntimeException('Unable to load installation assignment timestamp: ' . $error);
    }
    $newSnapshot['installation_assigned_at'] =
        $assignedAtStmt->get_result()->fetch_assoc()['installation_assigned_at'] ?? null;
    $assignedAtStmt->close();
    crmClosureWriteHistory(
        $conn,
        (int) $closure['lead_id'],
        'Installation Reassigned',
        $oldSnapshot,
        ['closure_id' => $closureId] + $newSnapshot,
        $currentUser
    );

    $newUserId = (int) $personUserId;
    crmClosureNotify(
        $conn,
        $newUserId,
        'Installation assigned',
        'Installation work for ' . $closure['lead_name'] . ' has been assigned to you.',
        'crm_installation',
        $closureId
    );
    $oldUserId = (int) ($closure['installation_person_user_id'] ?? 0);
    if ($oldUserId > 0 && $oldUserId !== $newUserId) {
        crmClosureNotify(
            $conn,
            $oldUserId,
            'Installation reassigned',
            'Installation work for ' . $closure['lead_name'] . ' has been reassigned.',
            'crm_installation',
            $closureId
        );
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit installation reassignment: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'Installation reassigned successfully.');
} catch (DomainException $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 409);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
