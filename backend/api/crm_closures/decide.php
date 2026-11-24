<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../utils/audit.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_closures.approve');
$userId = (int) ($currentUser['user_id'] ?? 0);
crmClosureRequireAdminRole($userId);
$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$closureId = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$decision = trim((string) ($data->decision ?? ''));
$rejectionReason = trim((string) ($data->rejection_reason ?? ''));
$installerType = trim((string) ($data->installation_person_type ?? ''));
$installerId = filter_var($data->installation_person_id ?? null, FILTER_VALIDATE_INT);
$installerUserId = filter_var($data->installation_person_user_id ?? null, FILTER_VALIDATE_INT);
if ($closureId === false || $closureId <= 0 || !in_array($decision, ['Approve', 'Reject'], true)) {
    sendResponse(false, 'A valid closure submission and decision are required.', [], [], 400);
}
if ($decision === 'Reject' && ($rejectionReason === '' || mb_strlen($rejectionReason) > 2000)) {
    sendResponse(false, 'A rejection reason of up to 2,000 characters is required.', [], [], 400);
}
if ($decision === 'Approve' && $rejectionReason !== '') {
    sendResponse(false, 'A rejection reason is only accepted when rejecting a submission.', [], [], 400);
}
if ($decision === 'Approve') {
    requirePermission('crm_installations.assign');
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
    sendResponse(false, 'Unable to begin approval transaction: ' . $error, [], [], 500);
}

try {
    $stmt = $conn->prepare(
        'SELECT cf.*, l.lead_name, l.mobile_no, l.alternate_mobile_no,
                l.email, l.location AS lead_location, l.pincode AS lead_pincode,
                l.assigned_user_id,
                s.status_type AS lead_status_type
         FROM crm_lead_closure_forms cf
         INNER JOIN crm_leads l ON l.id = cf.lead_id
         INNER JOIN crm_lead_status_master s ON s.id = l.lead_status_id
         WHERE cf.id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare closure approval lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $closureId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load closure submission: ' . $error);
    }
    $closure = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closure) {
        throw new DomainException('Closure submission not found.');
    }
    if ($closure['submission_status'] !== 'Pending Approval') {
        throw new DomainException('Only pending closure submissions can be reviewed.');
    }
    if (!empty($closure['customer_id'])) {
        throw new DomainException('This closure already has a customer record.');
    }

    $installer = null;
    if ($decision === 'Approve') {
        if ($installerId === false || $installerUserId === false) {
            throw new DomainException('Select an installation person and their authorized login account before approval.');
        }
        $installer = crmClosureValidateInstaller(
            $conn,
            $installerType,
            (int) $installerId,
            (int) $installerUserId
        );
    }

    $oldSnapshot = crmClosureSnapshot($closure);
    if ($decision === 'Approve') {
        if ($closure['lead_status_type'] !== 'Resolved') {
            throw new DomainException('Only a conversion-ready lead can be approved.');
        }
        $update = $conn->prepare(
            "UPDATE crm_lead_closure_forms
             SET submission_status = 'Approved',
                 approved_by_user_id = ?, reviewed_by_user_id = ?,
                 installation_person_type = ?, installation_person_id = ?,
                 installation_person_user_id = ?, installation_assigned_by_user_id = ?,
                 installation_assigned_at = CURRENT_TIMESTAMP,
                 approved_at = CURRENT_TIMESTAMP, reviewed_at = CURRENT_TIMESTAMP,
                 rejection_reason = NULL
             WHERE id = ? AND submission_status = 'Pending Approval' AND customer_id IS NULL"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare approval update: ' . $conn->error);
        }
        $assignedPersonType = $installerType;
        $assignedPersonId = (int) $installerId;
        $assignedPersonUserId = (int) $installerUserId;
        $update->bind_param(
            'iisiiii',
            $userId,
            $userId,
            $assignedPersonType,
            $assignedPersonId,
            $assignedPersonUserId,
            $userId,
            $closureId
        );
    } else {
        $update = $conn->prepare(
            "UPDATE crm_lead_closure_forms
             SET submission_status = 'Rejected', rejection_reason = ?,
                 reviewed_by_user_id = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND submission_status = 'Pending Approval' AND customer_id IS NULL"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare rejection update: ' . $conn->error);
        }
        $update->bind_param('sii', $rejectionReason, $userId, $closureId);
    }
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to save review decision: ' . $error);
    }
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new DomainException('The submission was reviewed by another user. Refresh the approval queue.');
    }
    $update->close();

    $newSnapshot = $oldSnapshot;
    $newSnapshot['submission_status'] = $decision === 'Approve' ? 'Approved' : 'Rejected';
    $newSnapshot['customer_id'] = null;
    $newSnapshot['rejection_reason'] = $decision === 'Reject' ? $rejectionReason : null;
    if ($decision === 'Approve') {
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
        $newSnapshot['installation_person_type'] = $installerType;
        $newSnapshot['installation_person_id'] = (int) $installerId;
        $newSnapshot['installation_person_user_id'] = (int) $installerUserId;
        $newSnapshot['installation_assigned_by_user_id'] = $userId;
    }
    crmClosureWriteHistory(
        $conn,
        (int) $closure['lead_id'],
        $decision === 'Approve' ? 'Closure Approved' : 'Closure Rejected',
        $oldSnapshot,
        ['closure_id' => $closureId] + $newSnapshot,
        $currentUser
    );

    $submitterId = (int) ($closure['submitted_by_user_id'] ?? 0);
    if ($submitterId > 0) {
        $title = $decision === 'Approve' ? 'Lead closure approved' : 'Lead closure rejected';
        $message = $decision === 'Approve'
            ? 'Your closure for ' . $closure['lead_name'] . ' was approved and installation assigned.'
            : 'Your closure for ' . $closure['lead_name'] . ' was rejected: ' . $rejectionReason;
        crmCreateNotification(
            $conn,
            $submitterId,
            $decision === 'Approve' ? 'lead_approved' : 'lead_rejected',
            $title,
            $message,
            $decision === 'Approve' ? 'Normal' : 'High',
            'lead_closure',
            $closureId,
            ($decision === 'Approve' ? 'lead_approved:' : 'lead_rejected:') . $closureId,
            (int) $closure['lead_id'],
            null,
            $closureId
        );
    }
    if ($decision === 'Approve') {
        crmClosureNotify(
            $conn,
            (int) $installerUserId,
            'Installation assigned',
            'Installation work for ' . $closure['lead_name'] . ' has been assigned to you.',
            'crm_installation',
            $closureId
        );
        $responsibleUserId = (int) ($closure['assigned_user_id'] ?? 0);
        if ($responsibleUserId > 0) {
            crmCreateNotification(
                $conn,
                $responsibleUserId,
                'lead_converted',
                'Lead Converted',
                'Lead ' . $closure['lead_name'] . ' was converted successfully.',
                'Normal',
                'lead',
                (int) $closure['lead_id'],
                'lead_converted:' . (int) $closure['lead_id'],
                (int) $closure['lead_id'],
                null,
                $closureId
            );
        }
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit approval decision: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, $decision === 'Approve' ? 'Closure approved and installation assigned.' : 'Closure rejected.', [
        'customer_id' => null,
        'lead_id' => (int) $closure['lead_id'],
        'installation_person_type' => $decision === 'Approve' ? $installerType : null,
        'installation_person_id' => $decision === 'Approve' ? (int) $installerId : null,
        'installation_person_user_id' => $decision === 'Approve' ? (int) $installerUserId : null
    ]);
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
