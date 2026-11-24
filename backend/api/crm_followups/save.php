<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_followups.add');
$userId = (int) ($currentUser['user_id'] ?? 0);
if ($userId <= 0) {
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$leadId = filter_var($data->lead_id ?? null, FILTER_VALIDATE_INT);
$statusId = filter_var($data->current_status_id ?? null, FILTER_VALIDATE_INT);
$mode = trim((string) ($data->mode ?? ''));
$connectionType = trim((string) ($data->connection_type ?? ''));
$followupIdValue = $data->followup_id ?? null;
$followupId = null;
$scheduledFollowupValue = $data->scheduled_followup_id ?? null;
$scheduledFollowupId = null;
$customerNeed = trim((string) ($data->customer_need ?? ''));
$callOutcome = trim((string) ($data->call_outcome ?? ''));
$closureReason = trim((string) ($data->closure_reason ?? ''));
$remarks = trim((string) ($data->remarks ?? ''));
$dateValue = trim((string) ($data->next_followup_date ?? ''));
$timeValue = trim((string) ($data->next_followup_time ?? ''));
$notConnectedOutcomes = [
    'Did not pick',
    'Busy in another call',
    'User disconnected the call',
    'Switch off',
    'Out of coverage area / Network issue',
    'Call not connected / Cannot be completed',
    'Incorrect / Invalid number',
    'Incoming calls not available',
    'Number not in use / Does not exist / Out of service',
    'Other reason'
];
$scheduleFieldsProvided = property_exists($data, 'next_followup_date') ||
    property_exists($data, 'next_followup_time');

if ($leadId === false || $leadId <= 0 || $statusId === false || $statusId <= 0) {
    sendResponse(false, 'A valid lead and active lead status are required.', [], [], 400);
}
if (!in_array($mode, ['record', 'update'], true)) {
    sendResponse(false, 'Choose whether to record a follow-up or update an existing one.', [], [], 400);
}
if (!in_array($connectionType, ['connected', 'not-connected'], true)) {
    sendResponse(false, 'Select whether the call connected.', [], [], 400);
}
if ($mode === 'update') {
    $followupId = filter_var($followupIdValue, FILTER_VALIDATE_INT);
    if ($followupId === false || $followupId <= 0) {
        sendResponse(false, 'A valid follow-up ID is required when updating an existing follow-up.', [], [], 400);
    }
} elseif ($followupIdValue !== null && $followupIdValue !== '') {
    sendResponse(false, 'Use a scheduled follow-up ID when recording a new activity.', [], [], 400);
}
if ($mode === 'record' && $scheduledFollowupValue !== null && $scheduledFollowupValue !== '') {
    $scheduledFollowupId = filter_var($scheduledFollowupValue, FILTER_VALIDATE_INT);
    if ($scheduledFollowupId === false || $scheduledFollowupId <= 0) {
        sendResponse(false, 'A valid scheduled follow-up is required.', [], [], 400);
    }
} elseif ($mode === 'update' && $scheduledFollowupValue !== null && $scheduledFollowupValue !== '') {
    sendResponse(false, 'Updating an existing follow-up cannot include a scheduled follow-up ID.', [], [], 400);
}
if ($dateValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
    sendResponse(false, 'Enter a valid next follow-up date.', [], [], 400);
}
if ($dateValue !== '' && !checkdate((int) substr($dateValue, 5, 2), (int) substr($dateValue, 8, 2), (int) substr($dateValue, 0, 4))) {
    sendResponse(false, 'Enter a valid next follow-up date.', [], [], 400);
}
if ($timeValue !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeValue)) {
    sendResponse(false, 'Enter a valid next follow-up time.', [], [], 400);
}
if ($dateValue === '' && $timeValue !== '') {
    sendResponse(false, 'A next follow-up time requires a date.', [], [], 400);
}
if ($mode === 'record' && $remarks === '') {
    sendResponse(false, 'Remarks are required to record a follow-up activity.', [], [], 400);
}
if ($mode === 'record' && $connectionType === 'not-connected' &&
    !in_array($callOutcome, $notConnectedOutcomes, true)) {
    sendResponse(false, 'Select a valid reason for the call not connecting.', [], [], 400);
}
if ($mode === 'record' && $connectionType === 'connected' &&
    in_array($callOutcome, $notConnectedOutcomes, true)) {
    sendResponse(false, 'Use the not-connected call reason selection for this outcome.', [], [], 400);
}
if (strlen($customerNeed) > 65535 || strlen($remarks) > 65535 || mb_strlen($callOutcome) > 100) {
    sendResponse(false, 'One or more follow-up fields exceed the allowed length.', [], [], 400);
}
$canViewAll = crmFollowupsCanViewAllLeads($userId);
$isEmployee = crmLeadsIsEmployee($userId);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmFollowupsRequireCompletionSchema($conn);
if ($callOutcome === 'Not Interested') {
    crmClosureRequireWorkflowSchema($conn);
}

if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin follow-up transaction: ' . $error, [], [], 500);
}

$lead = crmFollowupsLockLead($conn, $leadId);
if (!$lead) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$canViewAll && (int) ($lead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $isEmployee ? 'Lead not found.' : 'You are not authorized to update this lead.', [], [], $isEmployee ? 404 : 403);
}
if (strtolower((string) ($lead['lead_status_type'] ?? '')) === 'lost' ||
    in_array(strtolower(trim((string) ($lead['lead_stage_name'] ?? ''))), ['converted lead', 'lost lead'], true)) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Converted and Lost leads are closed and cannot receive additional follow-ups.', [], [], 409);
}

$priorCompletedFollowupCount = 0;
if ($mode === 'record') {
    $completedCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS completed_count
         FROM crm_lead_followups f
         INNER JOIN crm_leads l ON l.id = f.lead_id
         WHERE f.lead_id = ? AND f.completed_at IS NOT NULL
           AND l.assigned_user_id IS NOT NULL
           AND (l.assigned_at IS NULL OR f.completed_at >= l.assigned_at)'
    );
    if (!$completedCountStmt) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare completed follow-up lookup: ' . $error, [], [], 500);
    }
    $completedCountStmt->bind_param('i', $leadId);
    if (!$completedCountStmt->execute()) {
        $error = $completedCountStmt->error;
        $completedCountStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to check completed follow-ups: ' . $error, [], [], 500);
    }
    $priorCompletedFollowupCount = (int) (
        $completedCountStmt->get_result()->fetch_assoc()['completed_count'] ?? 0
    );
    $completedCountStmt->close();
}

$statusStmt = $conn->prepare(
    "SELECT id, status_type FROM crm_lead_status_master
     WHERE id = ? AND status = 'Active'
     LIMIT 1"
);
if (!$statusStmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate lead status: ' . $error, [], [], 500);
}
$statusStmt->bind_param('i', $statusId);
if (!$statusStmt->execute()) {
    $error = $statusStmt->error;
    $statusStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate lead status: ' . $error, [], [], 500);
}
$activeStatus = $statusStmt->get_result()->fetch_assoc();
if (!$activeStatus) {
    $statusStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Select an active lead status.', [], [], 400);
}
$statusStmt->close();
$hasCancellationColumn = crmFollowupsColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
$hasClosureReasonColumns =
    crmFollowupsColumnExists($conn, 'crm_lead_followups', 'closure_reason') &&
    crmFollowupsColumnExists($conn, 'crm_leads', 'closure_reason');

if ($mode === 'update') {
    $closureReasonSelect = $hasClosureReasonColumns ? 'closure_reason' : 'NULL AS closure_reason';
    $cancellationSelect = $hasCancellationColumn ? ', cancelled_at' : '';
    $existingStmt = $conn->prepare(
        "SELECT current_status_id, customer_need, call_outcome, remarks,
                next_followup_date, next_followup_time, completed_at,
                {$closureReasonSelect}{$cancellationSelect}
         FROM crm_lead_followups
         WHERE id = ? AND lead_id = ?
           AND NOT (
               call_outcome IS NULL
               AND remarks = 'Scheduled during lead assignment'
           )
         LIMIT 1 FOR UPDATE"
    );
    if (!$existingStmt) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare follow-up update validation: ' . $error, [], [], 500);
    }
    $existingStmt->bind_param('ii', $followupId, $leadId);
    if (!$existingStmt->execute()) {
        $error = $existingStmt->error;
        $existingStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to load the selected follow-up: ' . $error, [], [], 500);
    }
    $existingFollowup = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if (!$existingFollowup) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'The selected follow-up does not belong to this lead.', [], [], 404);
    }
    if ($hasCancellationColumn && $existingFollowup['cancelled_at'] !== null) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'A cancelled follow-up schedule cannot be edited.', [], [], 409);
    }

    $customerNeed = property_exists($data, 'customer_need')
        ? $customerNeed
        : (string) ($existingFollowup['customer_need'] ?? '');
    $callOutcome = property_exists($data, 'call_outcome')
        ? $callOutcome
        : (string) ($existingFollowup['call_outcome'] ?? '');
    $remarks = property_exists($data, 'remarks')
        ? $remarks
        : (string) ($existingFollowup['remarks'] ?? '');
    $dateValue = property_exists($data, 'next_followup_date')
        ? $dateValue
        : '';
    $timeValue = property_exists($data, 'next_followup_time')
        ? $timeValue
        : '';
    $closureReason = property_exists($data, 'closure_reason')
        ? $closureReason
        : (string) ($existingFollowup['closure_reason'] ?? '');

    if (($connectionType === 'not-connected' && $callOutcome === '') || $remarks === '') {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'A not-connected reason and remarks are required for a follow-up activity.', [], [], 400);
    }
    if (($connectionType === 'not-connected' && !in_array($callOutcome, $notConnectedOutcomes, true)) ||
        ($connectionType === 'connected' && in_array($callOutcome, $notConnectedOutcomes, true))) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'The selected call connection does not match its outcome.', [], [], 400);
    }

    if ($callOutcome === 'Not Interested') {
        crmClosureRequireWorkflowSchema($conn);
    }

    $closureReasons = ['Not Interested', 'Price Too High', 'Already Purchased', 'Other'];
    if ($callOutcome === 'Not Interested') {
        if (!in_array($closureReason, $closureReasons, true) || $dateValue !== '' || $remarks === '') {
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Lost leads require a closure reason and remarks, and cannot have another follow-up scheduled.', [], [], 400);
        }
    } elseif ($closureReason !== '') {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'A closure reason is only valid when the call outcome is Not Interested.', [], [], 400);
    }

    $selectedStatusType = (string) $activeStatus['status_type'];
    if (($callOutcome === 'Not Interested' && $selectedStatusType !== 'Lost') ||
        ($callOutcome !== 'Not Interested' && $selectedStatusType === 'Lost')) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'The Not Interested outcome must use an active Lost status, and Lost statuses require that outcome.', [], [], 400);
    }

    $updateSql = $hasClosureReasonColumns
        ? "UPDATE crm_lead_followups
           SET current_status_id = ?, customer_need = NULLIF(?, ''),
               call_outcome = NULLIF(?, ''), remarks = NULLIF(?, ''),
               next_followup_date = NULL, next_followup_time = NULL,
               closure_reason = NULLIF(?, ''), updated_by_user_id = ?
           WHERE id = ? AND lead_id = ?"
        : "UPDATE crm_lead_followups
           SET current_status_id = ?, customer_need = NULLIF(?, ''),
               call_outcome = NULLIF(?, ''), remarks = NULLIF(?, ''),
               next_followup_date = NULL, next_followup_time = NULL,
               updated_by_user_id = ?
           WHERE id = ? AND lead_id = ?";
    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare follow-up update: ' . $error, [], [], 500);
    }
    if ($hasClosureReasonColumns) {
        $updateStmt->bind_param(
            'issssiii',
            $statusId,
            $customerNeed,
            $callOutcome,
            $remarks,
            $closureReason,
            $userId,
            $followupId,
            $leadId
        );
    } else {
        $updateStmt->bind_param(
            'isssiii',
            $statusId,
            $customerNeed,
            $callOutcome,
            $remarks,
            $userId,
            $followupId,
            $leadId
        );
    }
    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to update follow-up: ' . $error, [], [], 500);
    }
    $updateStmt->close();

    $leadUpdateSql = $hasClosureReasonColumns
        ? 'UPDATE crm_leads SET lead_status_id = ?, customer_need = NULLIF(?, \'\'), closure_reason = NULLIF(?, \'\') WHERE id = ?'
        : 'UPDATE crm_leads SET lead_status_id = ?, customer_need = NULLIF(?, \'\') WHERE id = ?';
    $leadUpdate = $conn->prepare($leadUpdateSql);
    if (!$leadUpdate) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare lead status update: ' . $error, [], [], 500);
    }
    if ($hasClosureReasonColumns) {
        $leadUpdate->bind_param('issi', $statusId, $customerNeed, $closureReason, $leadId);
    } else {
        $leadUpdate->bind_param('isi', $statusId, $customerNeed, $leadId);
    }
    if (!$leadUpdate->execute()) {
        $error = $leadUpdate->error;
        $leadUpdate->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
    }
    $leadUpdate->close();

    if ($selectedStatusType === 'Lost') {
        try {
            crmLeadSetLifecycleStage($conn, $leadId, 'Lost Lead', $currentUser);
        } catch (RuntimeException $error) {
            $conn->rollback();
            $conn->close();
            sendResponse(false, $error->getMessage(), [], [], 503);
        }
    }

    if ($scheduleFieldsProvided) {
        $scheduleError = crmFollowupsReplacePendingSchedule(
            $conn,
            $leadId,
            $dateValue,
            $timeValue,
            $userId,
            $hasCancellationColumn
        );
        if ($scheduleError !== null) {
            $conn->rollback();
            $conn->close();
            sendResponse(false, $scheduleError, [], [], 500);
        }
    }

    if ($callOutcome === 'Not Interested') {
        $reason = $closureReason !== '' ? $closureReason : 'Not specified';
        crmCreateNotification(
            $conn,
            (int) $lead['assigned_user_id'],
            'lead_marked_not_done',
            'Lead Marked Not Done',
            'Lead ' . $lead['lead_name'] . ' was marked Not Done. Reason: ' . $reason,
            'Normal',
            'lead',
            $leadId,
            'lead_marked_not_done:' . $leadId . ':' . $followupId,
            $leadId
        );
    }

    if (!$conn->commit()) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to commit follow-up update: ' . $error, [], [], 500);
    }
    $conn->close();
    sendResponse(true, 'Follow-up updated successfully.', ['id' => $followupId]);
}

$closureReasons = ['Not Interested', 'Price Too High', 'Already Purchased', 'Other'];
if ($callOutcome === 'Not Interested') {
    if (!in_array($closureReason, $closureReasons, true) || $dateValue !== '' || $remarks === '') {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Lost leads require a closure reason and remarks, and cannot have another follow-up scheduled.', [], [], 400);
    }
} elseif ($closureReason !== '') {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'A closure reason is only valid when the call outcome is Not Interested.', [], [], 400);
}
$selectedStatusType = (string) $activeStatus['status_type'];
if (($callOutcome === 'Not Interested' && $selectedStatusType !== 'Lost') ||
    ($callOutcome !== 'Not Interested' && $selectedStatusType === 'Lost')) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'The Not Interested outcome must use an active Lost status, and Lost statuses require that outcome.', [], [], 400);
}

$scheduledFollowup = null;
if ($scheduledFollowupId !== null) {
    $scheduleLookupSql =
        'SELECT id, call_outcome, remarks, completed_at' .
        ($hasCancellationColumn ? ', cancelled_at' : '') .
        ' FROM crm_lead_followups
          WHERE id = ? AND lead_id = ?
          LIMIT 1 FOR UPDATE';
    $scheduleLookup = $conn->prepare($scheduleLookupSql);
    if (!$scheduleLookup) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare scheduled follow-up lookup: ' . $error, [], [], 500);
    }
    $scheduleLookup->bind_param('ii', $scheduledFollowupId, $leadId);
    if (!$scheduleLookup->execute()) {
        $error = $scheduleLookup->error;
        $scheduleLookup->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to load the selected follow-up schedule: ' . $error, [], [], 500);
    }
    $scheduledFollowup = $scheduleLookup->get_result()->fetch_assoc();
    $scheduleLookup->close();
    if (!$scheduledFollowup || $scheduledFollowup['completed_at'] !== null ||
        ($hasCancellationColumn && $scheduledFollowup['cancelled_at'] !== null)) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'This scheduled follow-up is no longer pending. Refresh the follow-up list and try again.', [], [], 409);
    }
    if ($scheduledFollowup['call_outcome'] !== null ||
        $scheduledFollowup['remarks'] !== 'Scheduled during lead assignment') {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'The selected record is an existing follow-up activity. Use Edit Follow-up to update it.', [], [], 409);
    }
}

$completedScheduledFollowupId = null;
if ($scheduledFollowup) {
    $isAssignmentSchedule =
        $scheduledFollowup['call_outcome'] === null &&
        $scheduledFollowup['remarks'] === 'Scheduled during lead assignment';
    if ($isAssignmentSchedule) {
        $updateScheduleSql = $hasClosureReasonColumns
            ? "UPDATE crm_lead_followups
               SET current_status_id = ?,
                   customer_need = NULLIF(?, ''),
                   call_outcome = NULLIF(?, ''),
                   remarks = NULLIF(?, ''),
                   next_followup_date = NULL,
                   next_followup_time = NULL,
                   completed_at = NOW(), updated_by_user_id = ?, created_at = NOW(),
                   closure_reason = NULLIF(?, '')
               WHERE id = ? AND completed_at IS NULL"
            : "UPDATE crm_lead_followups
               SET current_status_id = ?,
                   customer_need = NULLIF(?, ''),
                   call_outcome = NULLIF(?, ''),
                   remarks = NULLIF(?, ''),
                   next_followup_date = NULL,
                   next_followup_time = NULL,
                   completed_at = NOW(), updated_by_user_id = ?, created_at = NOW()
               WHERE id = ? AND completed_at IS NULL";
        $updateSchedule = $conn->prepare($updateScheduleSql);
        if (!$updateSchedule) {
            $error = $conn->error;
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to prepare assignment schedule activity update: ' . $error, [], [], 500);
        }
        if ($hasClosureReasonColumns) {
            $updateSchedule->bind_param(
                'isssisi',
                $statusId,
                $customerNeed,
                $callOutcome,
                $remarks,
                $userId,
                $closureReason,
                $scheduledFollowup['id']
            );
        } else {
            $updateSchedule->bind_param(
                'isssii',
                $statusId,
                $customerNeed,
                $callOutcome,
                $remarks,
                $userId,
                $scheduledFollowup['id']
            );
        }
        if (!$updateSchedule->execute()) {
            $error = $updateSchedule->error;
            $updateSchedule->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to save the follow-up activity: ' . $error, [], [], 500);
        }
        if ($updateSchedule->affected_rows !== 1) {
            $updateSchedule->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'This scheduled follow-up was already updated. Refresh and try again.', [], [], 409);
        }
        $updateSchedule->close();
        $followupId = (int) $scheduledFollowup['id'];
        $completedScheduledFollowupId = $followupId;
    } else {
        $completeSql =
            'UPDATE crm_lead_followups
             SET completed_at = NOW(), updated_by_user_id = ?
             WHERE id = ? AND lead_id = ? AND completed_at IS NULL';
        if ($hasCancellationColumn) {
            $completeSql .= ' AND cancelled_at IS NULL';
        }
        $completeStmt = $conn->prepare($completeSql);
        if (!$completeStmt) {
            $error = $conn->error;
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to prepare scheduled follow-up completion: ' . $error, [], [], 500);
        }
        $completeStmt->bind_param('iii', $userId, $scheduledFollowup['id'], $leadId);
        if (!$completeStmt->execute()) {
            $error = $completeStmt->error;
            $completeStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to complete scheduled follow-up: ' . $error, [], [], 500);
        }
        if ($completeStmt->affected_rows !== 1) {
            $completeStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'This scheduled follow-up is no longer pending. Refresh the follow-up list and try again.', [], [], 409);
        }
        $completeStmt->close();
        $completedScheduledFollowupId = (int) $scheduledFollowup['id'];
    }
}

$isAssignmentScheduleActivity =
    $scheduledFollowup &&
    $scheduledFollowup['call_outcome'] === null &&
    $scheduledFollowup['remarks'] === 'Scheduled during lead assignment';
if (!$isAssignmentScheduleActivity) {
    $insertSql = $hasClosureReasonColumns
        ? "INSERT INTO crm_lead_followups
            (lead_id, current_status_id, customer_need, call_outcome, remarks,
             next_followup_date, next_followup_time, completed_at, closure_reason,
             updated_by_user_id)
           VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                   NULL, NULL, NOW(), NULLIF(?, ''), ?)"
        : "INSERT INTO crm_lead_followups
            (lead_id, current_status_id, customer_need, call_outcome, remarks,
             next_followup_date, next_followup_time, completed_at, updated_by_user_id)
           VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                   NULL, NULL, NOW(), ?)";
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to prepare follow-up save: ' . $error, [], [], 500);
    }
    if ($hasClosureReasonColumns) {
        $insertStmt->bind_param(
            'iissssi',
            $leadId,
            $statusId,
            $customerNeed,
            $callOutcome,
            $remarks,
            $closureReason,
            $userId
        );
    } else {
        $insertStmt->bind_param(
            'iisssi',
            $leadId,
            $statusId,
            $customerNeed,
            $callOutcome,
            $remarks,
            $userId
        );
    }
    if (!$insertStmt->execute()) {
        $error = $insertStmt->error;
        $insertStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to save follow-up: ' . $error, [], [], 500);
    }
    $followupId = $conn->insert_id;
    $insertStmt->close();
}

$scheduleError = crmFollowupsReplacePendingSchedule(
    $conn,
    $leadId,
    $dateValue,
    $timeValue,
    $userId,
    $hasCancellationColumn
);
if ($scheduleError !== null) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $scheduleError, [], [], 500);
}

$leadUpdate = $conn->prepare(
    $hasClosureReasonColumns
        ? 'UPDATE crm_leads
           SET lead_status_id = ?, customer_need = NULLIF(?, \'\'),
               closure_reason = NULLIF(?, \'\')
           WHERE id = ?'
        : 'UPDATE crm_leads
           SET lead_status_id = ?, customer_need = NULLIF(?, \'\')
           WHERE id = ?'
);
if (!$leadUpdate) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to prepare lead status update: ' . $error, [], [], 500);
}
if ($hasClosureReasonColumns) {
    $leadUpdate->bind_param('issi', $statusId, $customerNeed, $closureReason, $leadId);
} else {
    $leadUpdate->bind_param('isi', $statusId, $customerNeed, $leadId);
}
if (!$leadUpdate->execute()) {
    $error = $leadUpdate->error;
    $leadUpdate->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to update lead status: ' . $error, [], [], 500);
}
$leadUpdate->close();

try {
    if ($selectedStatusType === 'Lost') {
        crmLeadSetLifecycleStage($conn, $leadId, 'Lost Lead', $currentUser);
    } elseif ($priorCompletedFollowupCount === 0 &&
        (int) ($lead['assigned_user_id'] ?? 0) > 0) {
        crmLeadSetLifecycleStage($conn, $leadId, 'Existing Customer', $currentUser);
    }
} catch (RuntimeException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 503);
}

if ($callOutcome === 'Not Interested') {
    $reason = $closureReason !== '' ? $closureReason : 'Not specified';
    $eventFollowupId = $followupId > 0 ? (int) $followupId : (int) $completedScheduledFollowupId;
    crmCreateNotification(
        $conn,
        (int) $lead['assigned_user_id'],
        'lead_marked_not_done',
        'Lead Marked Not Done',
        'Lead ' . $lead['lead_name'] . ' was marked Not Done. Reason: ' . $reason,
        'Normal',
        'lead',
        $leadId,
        'lead_marked_not_done:' . $leadId . ':' . $eventFollowupId,
        $leadId
    );
}

if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to commit follow-up: ' . $error, [], [], 500);
}

$conn->close();
sendResponse(true, 'Follow-up saved successfully.', [
    'id' => $followupId,
    'completed_scheduled_followup_id' => $completedScheduledFollowupId
]);
?>
