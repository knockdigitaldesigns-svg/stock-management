<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once 'distribution_common.php';
require_once __DIR__ . '/../../utils/notifications.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.assign');

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data) || !isset($data->lead_ids) || !is_array($data->lead_ids)) {
    sendResponse(false, 'A list of lead IDs and an employee are required.', [], [], 400);
}

$employeeId = filter_var($data->employee_id ?? null, FILTER_VALIDATE_INT);
if ($employeeId === false || $employeeId <= 0) {
    sendResponse(false, 'Select an eligible employee.', [], [], 400);
}

$requestedFollowupDate = trim((string) ($data->next_followup_date ?? ''));
if ($requestedFollowupDate !== '' &&
    (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedFollowupDate) ||
     !checkdate((int) substr($requestedFollowupDate, 5, 2), (int) substr($requestedFollowupDate, 8, 2), (int) substr($requestedFollowupDate, 0, 4)))) {
    sendResponse(false, 'Select a valid next follow-up date.', [], [], 400);
}

$leadIds = [];
foreach ($data->lead_ids as $leadId) {
    $validatedId = filter_var($leadId, FILTER_VALIDATE_INT);
    if ($validatedId === false || $validatedId <= 0) {
        sendResponse(false, 'Lead IDs must be positive integers.', [], [], 400);
    }
    $leadIds[] = $validatedId;
}
$leadIds = array_values(array_unique($leadIds));
if (count($leadIds) === 0) {
    sendResponse(false, 'Select at least one lead.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$assignedByUserId = requireLeadDistributionAccess($conn);
$hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);
$hasCompletionColumn = leadDistributionColumnExists($conn, 'crm_lead_followups', 'completed_at');
$hasCancelledAtColumn = leadDistributionColumnExists($conn, 'crm_lead_followups', 'cancelled_at');
$hasCancellationReasonColumn = leadDistributionColumnExists($conn, 'crm_lead_followups', 'cancellation_reason');
if (!$hasCompletionColumn) {
    $conn->close();
    sendResponse(
        false,
        'Assignment follow-up scheduling requires crm_lead_followups.completed_at. Apply backend/migrations/crm_followups_completion.sql before assigning leads.',
        [],
        [],
        503
    );
}
if ($hasCancelledAtColumn !== $hasCancellationReasonColumn) {
    $conn->close();
    sendResponse(false, 'Follow-up cancellation schema is incomplete. Apply backend/migrations/crm_followup_assignment_schedule.sql.', [], [], 503);
}
$hasCancellationColumns = $hasCancelledAtColumn && $hasCancellationReasonColumn;

if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to start lead assignment transaction: ' . $error, [], [], 500);
}

$assignmentDateResult = $conn->query('SELECT CURDATE() AS assignment_date');
if (!$assignmentDateResult) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to determine the assignment date: ' . $error, [], [], 500);
}
$assignmentDate = (string) ($assignmentDateResult->fetch_assoc()['assignment_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignmentDate)) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'The database returned an invalid assignment date.', [], [], 500);
}
$followupDate = $requestedFollowupDate !== '' ? $requestedFollowupDate : $assignmentDate;

$employeeStmt = $conn->prepare(
    "SELECT u.id
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?
       AND LOWER(COALESCE(u.status, 'active')) = 'active'
     LIMIT 1
     FOR UPDATE"
);
if (!$employeeStmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate employee: ' . $error, [], [], 500);
}
$employeeStmt->bind_param('i', $employeeId);
if (!$employeeStmt->execute()) {
    $error = $employeeStmt->error;
    $employeeStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to validate employee: ' . $error, [], [], 500);
}
if ($employeeStmt->get_result()->num_rows === 0) {
    $employeeStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'The selected user does not exist or is not active.', [], [], 400);
}
$employeeStmt->close();

$leadStmt = $conn->prepare(
    "SELECT l.assigned_user_id, l.lead_name,
            l.lead_status_id,
            COALESCE(NULLIF(u.employee_name, ''), u.username) AS previous_employee
     FROM crm_leads l
     LEFT JOIN users u ON u.id = l.assigned_user_id
     WHERE l.id = ?" . ($hasSoftDeleteSchema ? ' AND l.deleted_at IS NULL' : '') . "
     FOR UPDATE"
);
$updateStmt = $conn->prepare(
    'UPDATE crm_leads
     SET assigned_user_id = ?, assigned_by_user_id = ?, assigned_at = NOW(), updated_at = NOW()
     WHERE id = ?'
);
$historyStmt = $conn->prepare(
    "INSERT INTO crm_lead_assignments
        (lead_id, assigned_to_user_id, assigned_by_user_id, assignment_type, assignment_notes)
     VALUES (?, ?, ?, ?, NULLIF(?, ''))"
);
$scheduledStmt = $conn->prepare(
    $hasCancellationColumns
        ? "SELECT id, next_followup_date
           FROM crm_lead_followups
           WHERE lead_id = ? AND completed_at IS NULL AND cancelled_at IS NULL
             AND next_followup_date IS NOT NULL
           ORDER BY created_at DESC, id DESC
           FOR UPDATE"
        : "SELECT id, next_followup_date
           FROM crm_lead_followups
           WHERE lead_id = ? AND completed_at IS NULL
             AND next_followup_date IS NOT NULL
           ORDER BY created_at DESC, id DESC
           FOR UPDATE"
);
$cancelStmt = $hasCancellationColumns
    ? $conn->prepare(
        "UPDATE crm_lead_followups
         SET cancelled_at = NOW(), cancellation_reason = 'Replaced by lead assignment schedule'
         WHERE lead_id = ? AND completed_at IS NULL AND cancelled_at IS NULL
           AND next_followup_date IS NOT NULL AND next_followup_date <> ?"
    )
    : null;
$insertFollowupStmt = $conn->prepare(
    "INSERT INTO crm_lead_followups
        (lead_id, current_status_id, call_outcome, remarks,
         next_followup_date, next_followup_time, completed_at, updated_by_user_id)
     VALUES (?, ?, NULL, 'Scheduled during lead assignment',
             ?, NULL, NULL, ?)"
);
if (!$leadStmt || !$updateStmt || !$historyStmt || !$scheduledStmt || !$insertFollowupStmt ||
    ($hasCancellationColumns && !$cancelStmt)) {
    $error = $conn->error;
    if ($leadStmt) $leadStmt->close();
    if ($updateStmt) $updateStmt->close();
    if ($historyStmt) $historyStmt->close();
    if ($scheduledStmt) $scheduledStmt->close();
    if ($cancelStmt) $cancelStmt->close();
    if ($insertFollowupStmt) $insertFollowupStmt->close();
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to prepare lead assignment: ' . $error, [], [], 500);
}

$assignedCount = 0;
$reassignedCount = 0;
$unchangedCount = 0;
$scheduledCount = 0;
$existingScheduleCount = 0;
foreach ($leadIds as $leadId) {
    $leadStmt->bind_param('i', $leadId);
    if (!$leadStmt->execute()) {
        $error = $leadStmt->error;
        $leadStmt->close();
        $updateStmt->close();
        $historyStmt->close();
        $scheduledStmt->close();
        if ($cancelStmt) $cancelStmt->close();
        $insertFollowupStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to validate selected leads: ' . $error, [], [], 500);
    }

    $lead = $leadStmt->get_result()->fetch_assoc();
    if (!$lead) {
        $leadStmt->close();
        $updateStmt->close();
        $historyStmt->close();
        $scheduledStmt->close();
        if ($cancelStmt) $cancelStmt->close();
        $insertFollowupStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'One or more selected leads no longer exist. No assignments were applied.', [], [], 404);
    }

    $previousUserId = $lead['assigned_user_id'] === null ? null : (int) $lead['assigned_user_id'];
    $scheduledStmt->bind_param('i', $leadId);
    if (!$scheduledStmt->execute()) {
        $error = $scheduledStmt->error;
        $leadStmt->close();
        $updateStmt->close();
        $historyStmt->close();
        $scheduledStmt->close();
        if ($cancelStmt) $cancelStmt->close();
        $insertFollowupStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to check the current follow-up schedule: ' . $error . ' No assignments were saved.', [], [], 500);
    }

    $scheduledResult = $scheduledStmt->get_result();
    $hasSameDateSchedule = false;
    $hasOtherSchedule = false;
    while ($scheduled = $scheduledResult->fetch_assoc()) {
        if ((string) $scheduled['next_followup_date'] === $followupDate) {
            $hasSameDateSchedule = true;
        } else {
            $hasOtherSchedule = true;
        }
    }
    if (!$hasCancellationColumns && $hasOtherSchedule) {
        $leadStmt->close();
        $updateStmt->close();
        $historyStmt->close();
        $scheduledStmt->close();
        $insertFollowupStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(
            false,
            'A different pending follow-up already exists for a selected lead. Apply backend/migrations/crm_followup_assignment_schedule.sql before replacing an existing schedule. No assignments were saved.',
            [],
            [],
            503
        );
    }

    if ($hasCancellationColumns && $hasOtherSchedule) {
        $cancelStmt->bind_param('is', $leadId, $followupDate);
        if (!$cancelStmt->execute()) {
            $error = $cancelStmt->error;
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            $scheduledStmt->close();
            $cancelStmt->close();
            $insertFollowupStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to preserve the replaced follow-up schedule: ' . $error . ' No assignments were saved.', [], [], 500);
        }
    }

    if ($previousUserId !== $employeeId) {
        $assignmentType = $previousUserId === null ? 'Distribution' : 'Reassign';
        $assignmentNotes = $previousUserId === null
            ? ''
            : sprintf(
                'Previous assignee: %s (user ID %d)',
                $lead['previous_employee'] ?: 'Unknown user',
                $previousUserId
            );

        $updateStmt->bind_param('iii', $employeeId, $assignedByUserId, $leadId);
        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
            $error = $updateStmt->error ?: 'The lead assignment did not update exactly one row.';
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            $scheduledStmt->close();
            if ($cancelStmt) $cancelStmt->close();
            $insertFollowupStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to apply lead assignments: ' . $error . ' No assignments were saved.', [], [], 500);
        }

        $historyStmt->bind_param('iiiss', $leadId, $employeeId, $assignedByUserId, $assignmentType, $assignmentNotes);
        if (!$historyStmt->execute()) {
            $error = $historyStmt->error;
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            $scheduledStmt->close();
            if ($cancelStmt) $cancelStmt->close();
            $insertFollowupStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to record assignment history: ' . $error . ' No assignments were saved.', [], [], 500);
        }

        $leadName = trim((string) ($lead['lead_name'] ?? 'Lead #' . $leadId));
        if ($previousUserId === null) {
            crmCreateNotification(
                $conn,
                $employeeId,
                'new_lead_assigned',
                'New Lead Assigned',
                'A new lead has been assigned to you: ' . $leadName . '.',
                'High',
                'lead',
                $leadId,
                'new_lead_assigned:' . $leadId . ':' . $employeeId,
                $leadId
            );
        } else {
            crmCreateNotification(
                $conn,
                $previousUserId,
                'lead_transferred_from_you',
                'Lead Transferred from You',
                'Lead ' . $leadName . ' was transferred to another employee.',
                'Normal',
                'lead',
                $leadId,
                'lead_transferred_from:' . $leadId . ':' . $previousUserId . ':' . $employeeId,
                $leadId
            );
            crmCreateNotification(
                $conn,
                $employeeId,
                'lead_transferred_to_you',
                'Lead Transferred to You',
                'Lead ' . $leadName . ' was transferred to you.',
                'High',
                'lead',
                $leadId,
                'lead_transferred_to:' . $leadId . ':' . $previousUserId . ':' . $employeeId,
                $leadId
            );
        }
        if ($previousUserId !== null) {
            crmCreateNotification(
                $conn,
                $employeeId,
                'lead_reassigned_returned',
                'Lead Reassigned / Returned',
                'Lead ' . $leadName . ' has been assigned to you.',
                'High',
                'lead',
                $leadId,
                'lead_reassigned_returned:' . $leadId . ':' . $employeeId . ':' . $historyStmt->insert_id,
                $leadId
            );
        }

        if ($assignmentType === 'Distribution') {
            $assignedCount++;
        } else {
            $reassignedCount++;
        }
    } else {
        $unchangedCount++;
    }

    if (!$hasSameDateSchedule) {
        $statusId = $lead['lead_status_id'] === null ? null : (int) $lead['lead_status_id'];
        $insertFollowupStmt->bind_param('iisi', $leadId, $statusId, $followupDate, $assignedByUserId);
        if (!$insertFollowupStmt->execute()) {
            $error = $insertFollowupStmt->error;
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            $scheduledStmt->close();
            if ($cancelStmt) $cancelStmt->close();
            $insertFollowupStmt->close();
            $conn->rollback();
            $conn->close();
            sendResponse(false, 'Unable to schedule follow-up: ' . $error . ' No assignments were saved.', [], [], 500);
        }
        $scheduledCount++;
    } else {
        $existingScheduleCount++;
    }
}

$leadStmt->close();
$updateStmt->close();
$historyStmt->close();
$scheduledStmt->close();
if ($cancelStmt) $cancelStmt->close();
$insertFollowupStmt->close();
if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to commit lead assignments: ' . $error, [], [], 500);
}
$conn->close();

sendResponse(true, 'Lead assignments saved successfully.', [
    'assigned' => $assignedCount,
    'reassigned' => $reassignedCount,
    'unchanged' => $unchangedCount,
    'followups_scheduled' => $scheduledCount,
    'followups_already_scheduled' => $existingScheduleCount,
    'total' => count($leadIds)
]);
?>
