<?php

function crmLeadSetLifecycleStage($conn, int $leadId, string $stageName, array $currentUser): bool {
    $automaticStages = ['Existing Customer', 'Converted Lead', 'Lost Lead'];
    if ($leadId <= 0 || !in_array($stageName, $automaticStages, true)) {
        throw new InvalidArgumentException('Invalid automatic CRM lead stage transition.');
    }

    $targetStageStmt = $conn->prepare(
        "SELECT id, stage_name
         FROM crm_lead_stage_master
         WHERE LOWER(TRIM(stage_name)) = LOWER(?) AND status = 'Active'
         LIMIT 1"
    );
    if (!$targetStageStmt) {
        throw new RuntimeException('Unable to prepare automatic lead stage lookup: ' . $conn->error);
    }
    $targetStageStmt->bind_param('s', $stageName);
    if (!$targetStageStmt->execute()) {
        $error = $targetStageStmt->error;
        $targetStageStmt->close();
        throw new RuntimeException('Unable to load automatic lead stage: ' . $error);
    }
    $targetStage = $targetStageStmt->get_result()->fetch_assoc();
    $targetStageStmt->close();
    if (!$targetStage) {
        throw new RuntimeException(
            'The active "' . $stageName . '" stage is missing. Apply backend/migrations/crm_lead_lifecycle_stages.sql.'
        );
    }

    $leadStmt = $conn->prepare(
        'SELECT l.lead_stage_id, s.stage_name AS current_stage_name
         FROM crm_leads l
         LEFT JOIN crm_lead_stage_master s ON s.id = l.lead_stage_id
         WHERE l.id = ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$leadStmt) {
        throw new RuntimeException('Unable to prepare lead stage transition: ' . $conn->error);
    }
    $leadStmt->bind_param('i', $leadId);
    if (!$leadStmt->execute()) {
        $error = $leadStmt->error;
        $leadStmt->close();
        throw new RuntimeException('Unable to load current lead stage: ' . $error);
    }
    $lead = $leadStmt->get_result()->fetch_assoc();
    $leadStmt->close();
    if (!$lead) {
        throw new RuntimeException('The lead for this stage transition no longer exists.');
    }

    $currentStageName = trim((string) ($lead['current_stage_name'] ?? ''));
    $normalizedCurrentStage = strtolower($currentStageName);
    if (in_array($normalizedCurrentStage, ['converted lead', 'lost lead'], true) ||
        (int) ($lead['lead_stage_id'] ?? 0) === (int) $targetStage['id']) {
        return false;
    }

    $updateStmt = $conn->prepare(
        'UPDATE crm_leads SET lead_stage_id = ?, updated_at = NOW() WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare lead stage update: ' . $conn->error);
    }
    $targetStageId = (int) $targetStage['id'];
    $updateStmt->bind_param('ii', $targetStageId, $leadId);
    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        throw new RuntimeException('Unable to update lead stage: ' . $error);
    }
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('The lead stage changed before the automatic transition could be saved.');
    }
    $updateStmt->close();

    $oldValue = json_encode([
        'lead_stage_id' => $lead['lead_stage_id'] === null ? null : (int) $lead['lead_stage_id'],
        'lead_stage_name' => $currentStageName
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newValue = json_encode([
        'lead_id' => $leadId,
        'lead_stage_id' => $targetStageId,
        'lead_stage_name' => (string) $targetStage['stage_name']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $actorId = (int) ($currentUser['auth_user_id'] ?? $currentUser['user_id'] ?? 0);
    $representedUserId = !empty($currentUser['impersonating'])
        ? (int) ($currentUser['represented_user_id'] ?? $currentUser['user_id'] ?? 0)
        : null;
    $actorName = trim((string) ($currentUser['auth_username'] ?? $currentUser['username'] ?? 'Unknown user'));
    $historyStmt = $conn->prepare(
        "INSERT INTO history
            (customer_id, module, action, field_changed, old_value, new_value,
             changed_by_user_id, represented_by_user_id, changed_by_name, changed_at)
         VALUES (NULL, 'CRM Lead Workflow', 'Automatic Lead Stage Transition',
                 'lead_stage_id', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
    if (!$historyStmt) {
        throw new RuntimeException('Unable to prepare lead stage history: ' . $conn->error);
    }
    $historyStmt->bind_param('ssiis', $oldValue, $newValue, $actorId, $representedUserId, $actorName);
    if (!$historyStmt->execute()) {
        $error = $historyStmt->error;
        $historyStmt->close();
        throw new RuntimeException('Unable to write lead stage history: ' . $error);
    }
    $historyStmt->close();

    return true;
}
?>
