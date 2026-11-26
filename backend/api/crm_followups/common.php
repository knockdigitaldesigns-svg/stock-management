<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../crm_leads/access.php';
require_once __DIR__ . '/../../utils/notifications.php';
require_once __DIR__ . '/../../utils/crm_lead_stages.php';

function crmFollowupsRequireCompletionSchema($conn) {
    $result = $conn->query("SHOW COLUMNS FROM crm_lead_followups LIKE 'completed_at'");
    if (!$result) {
        $error = $conn->error;
        sendResponse(false, 'Unable to verify follow-up schema: ' . $error, [], [], 500);
    }
    if ($result->num_rows === 0) {
        sendResponse(
            false,
            'Follow-ups require the completed_at column. Review and apply backend/migrations/crm_followups_completion.sql.',
            [],
            [],
            503
        );
    }
}

function crmFollowupsColumnExists($conn, $table, $column) {
    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
    );
    if (!$result) {
        $error = $conn->error;
        sendResponse(false, 'Unable to verify follow-up schema: ' . $error, [], [], 500);
    }
    return $result->num_rows > 0;
}

function crmFollowupsCanViewAllLeads($userId) {
    return crmLeadsCanViewAll($userId);
}

function crmFollowupsLockLead($conn, $leadId) {
    $activeLeadCondition = crmLeadsHasSoftDeleteSchema($conn)
        ? ' AND l.deleted_at IS NULL'
        : '';
    $sql = 'SELECT l.id, l.assigned_user_id, l.assigned_at, l.lead_name, l.lead_status_id,
                   l.lead_stage_id, l.customer_need, s.status_type AS lead_status_type,
                   st.stage_name AS lead_stage_name
            FROM crm_leads l
            LEFT JOIN crm_lead_status_master s ON s.id = l.lead_status_id
            LEFT JOIN crm_lead_stage_master st ON st.id = l.lead_stage_id
            WHERE l.id = ?
            ' . $activeLeadCondition . '
            LIMIT 1 FOR UPDATE';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $error = $conn->error;
        sendResponse(false, 'Unable to validate lead access: ' . $error, [], [], 500);
    }
    $stmt->bind_param('i', $leadId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(false, 'Unable to validate lead access: ' . $error, [], [], 500);
    }
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $lead;
}

function crmFollowupsReplacePendingSchedule($conn, $leadId, $dateValue, $timeValue, $userId, $hasCancellationColumn) {
    $replaceScheduleSql =
        'UPDATE crm_lead_followups
         SET ' . ($hasCancellationColumn
            ? "cancelled_at = NOW(), cancellation_reason = 'Replaced by recorded follow-up'"
            : 'next_followup_date = NULL, next_followup_time = NULL') . "
         WHERE lead_id = ? AND completed_at IS NULL
           AND call_outcome IS NULL
           AND remarks = 'Scheduled during lead assignment'
           AND next_followup_date IS NOT NULL";
    if ($hasCancellationColumn) {
        $replaceScheduleSql .= ' AND cancelled_at IS NULL';
    }

    $replaceSchedules = $conn->prepare($replaceScheduleSql);
    if (!$replaceSchedules) {
        return 'Unable to prepare assignment schedule replacement: ' . $conn->error;
    }
    $replaceSchedules->bind_param('i', $leadId);
    if (!$replaceSchedules->execute()) {
        $error = $replaceSchedules->error;
        $replaceSchedules->close();
        return 'Unable to replace the assignment schedule: ' . $error;
    }
    $replaceSchedules->close();

    if ($dateValue === '') {
        return null;
    }

    $scheduleInsert = $conn->prepare(
        "INSERT INTO crm_lead_followups
            (lead_id, current_status_id, call_outcome, remarks,
             next_followup_date, next_followup_time, completed_at, updated_by_user_id)
         VALUES (?, NULL, NULL, 'Scheduled during lead assignment', ?, NULLIF(?, ''), NULL, ?)"
    );
    if (!$scheduleInsert) {
        return 'Unable to prepare the next follow-up schedule: ' . $conn->error;
    }
    $scheduleInsert->bind_param('issi', $leadId, $dateValue, $timeValue, $userId);
    if (!$scheduleInsert->execute()) {
        $error = $scheduleInsert->error;
        $scheduleInsert->close();
        return 'Unable to schedule the next follow-up: ' . $error;
    }
    $scheduleInsert->close();

    return null;
}

function crmClosureRequireWorkflowSchema($conn) {
    $requirements = [
        'crm_leads' => ['closure_reason'],
        'crm_lead_followups' => ['closure_reason', 'completed_at'],
        'crm_lead_closure_forms' => ['platform_id', 'customer_id', 'submitted_at', 'reviewed_by_user_id', 'reviewed_at', 'username']
    ];
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            $result = $conn->query(
                "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
            );
            if (!$result) {
                $error = $conn->error;
                sendResponse(false, 'Unable to verify CRM closure schema: ' . $error, [], [], 500);
            }
            if ($result->num_rows === 0) {
                $migration = $table === 'crm_lead_closure_forms' && $column === 'username'
                    ? 'backend/migrations/crm_lead_converter_customer_number.sql'
                    : 'backend/migrations/crm_lead_outcome_workflow.sql';
                sendResponse(
                    false,
                    "Lead outcome workflow requires {$table}.{$column}. Review and apply {$migration}.",
                    [],
                    [],
                    503
                );
            }
        }
    }
}

function crmClosureCanViewAllLeads($userId) {
    return crmLeadsCanViewAll($userId);
}

function crmClosureWriteHistory($conn, $leadId, $action, $oldValue, $newValue, $currentUser) {
    $actorId = (int) ($currentUser['auth_user_id'] ?? $currentUser['user_id'] ?? 0);
    $representedUserId = !empty($currentUser['impersonating'])
        ? (int) ($currentUser['represented_user_id'] ?? $currentUser['user_id'] ?? 0)
        : null;
    $actorName = trim((string) ($currentUser['auth_username'] ?? $currentUser['username'] ?? 'Unknown user'));
    $oldJson = $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_array($newValue)) {
        $newValue['lead_id'] = $leadId;
    }
    $newJson = $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare(
        "INSERT INTO history
            (customer_id, module, action, field_changed, old_value, new_value,
             changed_by_user_id, represented_by_user_id, changed_by_name, changed_at)
         VALUES (NULL, 'CRM Lead Workflow', ?, 'lead_id', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare CRM workflow history: ' . $conn->error);
    }
    $newJson = $newJson ?? json_encode(['lead_id' => $leadId]);
    $stmt->bind_param('sssiis', $action, $oldJson, $newJson, $actorId, $representedUserId, $actorName);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to write CRM workflow history: ' . $error);
    }
    $stmt->close();
}

function crmClosureNotify($conn, $userId, $title, $message, $entityType, $entityId) {
    $stmt = $conn->prepare(
        'INSERT INTO crm_notifications
            (user_id, title, message, notification_type, related_entity_type, related_entity_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare CRM notification: ' . $conn->error);
    }
    $type = 'crm_closure';
    $stmt->bind_param('issssi', $userId, $title, $message, $type, $entityType, $entityId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create CRM notification: ' . $error);
    }
    $stmt->close();
}
?>
