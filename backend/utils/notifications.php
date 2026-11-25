<?php

function crmNotificationLeadVisibilityCondition(string $alias, bool $canViewAll, bool $hasSoftDeleteSchema): string
{
    if ($canViewAll) {
        return '';
    }

    $activeLeadCondition = $hasSoftDeleteSchema ? ' AND visible_lead.deleted_at IS NULL' : '';
    return " AND (
        (
            COALESCE({$alias}.related_lead_id, 0) = 0
            AND COALESCE({$alias}.related_entity_type, '') NOT IN ('lead', 'lead_closure')
        )
        OR EXISTS (
            SELECT 1
            FROM crm_leads visible_lead
            LEFT JOIN crm_lead_closure_forms visible_closure
              ON visible_closure.id = COALESCE(
                    NULLIF({$alias}.related_closure_id, 0),
                    CASE
                        WHEN {$alias}.related_entity_type = 'lead_closure'
                        THEN {$alias}.related_entity_id
                        ELSE NULL
                    END
                 )
            WHERE visible_lead.assigned_user_id = ?
              {$activeLeadCondition}
              AND (
                    visible_lead.id = {$alias}.related_lead_id
                    OR (
                        {$alias}.related_lead_id IS NULL
                        AND {$alias}.related_entity_type = 'lead'
                        AND visible_lead.id = {$alias}.related_entity_id
                    )
                    OR visible_closure.lead_id = visible_lead.id
              )
        )
    )";
}

function crmCreateNotification(
    $conn,
    $recipientUserId,
    $notificationType,
    $title,
    $message,
    $priority = 'Normal',
    $relatedEntityType = null,
    $relatedEntityId = null,
    $eventKey = null,
    $leadId = null,
    $customerId = null,
    $closureId = null
) {
    $recipientUserId = (int) $recipientUserId;
    if ($recipientUserId <= 0) {
        return false;
    }
    $priority = $priority === 'High' ? 'High' : 'Normal';
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO crm_notifications
            (user_id, title, message, notification_type, priority, related_entity_type,
             related_entity_id, event_key, related_lead_id, related_customer_id, related_closure_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare CRM notification: ' . $conn->error);
    }
    $leadId = $leadId !== null ? (int) $leadId : null;
    $customerId = $customerId !== null ? (int) $customerId : null;
    $closureId = $closureId !== null ? (int) $closureId : null;
    $stmt->bind_param(
        'isssssisiii',
        $recipientUserId,
        $title,
        $message,
        $notificationType,
        $priority,
        $relatedEntityType,
        $relatedEntityId,
        $eventKey,
        $leadId,
        $customerId,
        $closureId
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create CRM notification: ' . $error);
    }
    $inserted = $stmt->affected_rows === 1;
    $stmt->close();
    return $inserted;
}
?>
