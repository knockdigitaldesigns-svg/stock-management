<?php

function crmLeadTransferBatch(
    mysqli $conn,
    array $leadIds,
    int $employeeId,
    int $transferredByUserId,
    string $reason,
    string $transferType,
    bool $canViewAll
): array {
    if (!in_array($transferType, ['Single', 'Bulk'], true)) {
        throw new InvalidArgumentException('Invalid lead transfer type.');
    }
    sort($leadIds, SORT_NUMERIC);

    $employeeStmt = $conn->prepare(
        "SELECT u.id
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
           AND LOWER(COALESCE(u.status, 'active')) = 'active'
           AND LOWER(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, ''))) = 'employee'
         LIMIT 1
         FOR UPDATE"
    );
    if (!$employeeStmt) {
        throw new RuntimeException('Unable to prepare transfer employee validation: ' . $conn->error);
    }
    $employeeStmt->bind_param('i', $employeeId);
    if (!$employeeStmt->execute()) {
        $error = $employeeStmt->error;
        $employeeStmt->close();
        throw new RuntimeException('Unable to validate transfer employee: ' . $error);
    }
    $employeeExists = $employeeStmt->get_result()->num_rows === 1;
    $employeeStmt->close();
    if (!$employeeExists) {
        throw new DomainException('Select an active Employee account as the transfer destination.');
    }

    $hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);
    $activeLeadCondition = $hasSoftDeleteSchema ? ' AND deleted_at IS NULL' : '';
    $leadStmt = $conn->prepare(
        'SELECT id, lead_name, assigned_user_id
         FROM crm_leads
         WHERE id = ?' . $activeLeadCondition . '
         LIMIT 1
         FOR UPDATE'
    );
    $updateStmt = $conn->prepare(
        'UPDATE crm_leads
         SET assigned_user_id = ?, assigned_by_user_id = ?, assigned_at = NOW(), updated_at = NOW()
         WHERE id = ? AND assigned_user_id = ?'
    );
    $historyStmt = $conn->prepare(
        'INSERT INTO crm_lead_transfer_history
            (lead_id, previous_employee_id, new_employee_id, transferred_by_user_id,
             transfer_reason, transfer_type)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$leadStmt || !$updateStmt || !$historyStmt) {
        $error = $conn->error;
        if ($leadStmt) {
            $leadStmt->close();
        }
        if ($updateStmt) {
            $updateStmt->close();
        }
        if ($historyStmt) {
            $historyStmt->close();
        }
        throw new RuntimeException('Unable to prepare lead transfer: ' . $error);
    }

    $validatedLeads = [];
    $failures = [];
    foreach ($leadIds as $leadId) {
        $leadStmt->bind_param('i', $leadId);
        if (!$leadStmt->execute()) {
            $error = $leadStmt->error;
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            throw new RuntimeException('Unable to validate lead #' . $leadId . ': ' . $error);
        }
        $lead = $leadStmt->get_result()->fetch_assoc();
        if (!$lead) {
            $failures[] = [
                'lead_id' => $leadId,
                'lead_name' => null,
                'reason' => 'Lead not found or archived.'
            ];
            continue;
        }

        $previousEmployeeId = (int) ($lead['assigned_user_id'] ?? 0);
        if (!$canViewAll && $previousEmployeeId !== $transferredByUserId) {
            $failures[] = [
                'lead_id' => $leadId,
                'lead_name' => null,
                'reason' => 'Lead is outside your authorized assignment scope.'
            ];
            continue;
        }
        if ($previousEmployeeId <= 0) {
            $failures[] = [
                'lead_id' => $leadId,
                'lead_name' => (string) $lead['lead_name'],
                'reason' => 'Unassigned leads cannot be transferred.'
            ];
            continue;
        }
        if ($previousEmployeeId === $employeeId) {
            $failures[] = [
                'lead_id' => $leadId,
                'lead_name' => (string) $lead['lead_name'],
                'reason' => 'The lead is already assigned to the selected Employee.'
            ];
            continue;
        }
        $lead['previous_employee_id'] = $previousEmployeeId;
        $validatedLeads[] = $lead;
    }

    if ($failures) {
        $leadStmt->close();
        $updateStmt->close();
        $historyStmt->close();
        return ['success' => false, 'failures' => $failures];
    }

    foreach ($validatedLeads as $lead) {
        $leadId = (int) $lead['id'];
        $previousEmployeeId = (int) $lead['previous_employee_id'];
        $updateStmt->bind_param('iiii', $employeeId, $transferredByUserId, $leadId, $previousEmployeeId);
        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
            $error = $updateStmt->error ?: 'The current lead assignment changed during transfer.';
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            throw new RuntimeException('Unable to transfer lead #' . $leadId . ': ' . $error);
        }
        $historyStmt->bind_param(
            'iiiiss',
            $leadId,
            $previousEmployeeId,
            $employeeId,
            $transferredByUserId,
            $reason,
            $transferType
        );
        if (!$historyStmt->execute()) {
            $error = $historyStmt->error;
            $leadStmt->close();
            $updateStmt->close();
            $historyStmt->close();
            throw new RuntimeException('Unable to record transfer history for lead #' . $leadId . ': ' . $error);
        }
    }

    $leadStmt->close();
    $updateStmt->close();
    $historyStmt->close();
    return [
        'success' => true,
        'transferred' => count($validatedLeads),
        'lead_ids' => array_map(static fn (array $lead): int => (int) $lead['id'], $validatedLeads)
    ];
}
