<?php

function requireLeadDistributionAccess($conn) {
    $currentUser = authenticate();
    requirePermission('crm_leads.assign');

    $userId = (int) ($currentUser['user_id'] ?? 0);
    if ($userId <= 0) {
        sendResponse(false, 'Invalid authenticated user.', [], [], 401);
    }

    $stmt = $conn->prepare(
        'SELECT COALESCE(NULLIF(r.role_name, \'\'), u.role, \'\') AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        sendResponse(false, 'Unable to validate lead distribution access: ' . $conn->error, [], [], 500);
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(false, 'Unable to validate lead distribution access: ' . $error, [], [], 500);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        sendResponse(false, 'Authenticated user was not found.', [], [], 401);
    }

    $role = strtolower(trim((string) $row['role_name']));
    if ($role === 'employee') {
        sendResponse(false, 'Employees are not authorized to distribute or reassign leads.', [], [], 403);
    }
    if (!isSuperAdminUser(null, $role) && in_array($role, ['manager', 'rsm', 'tl', 'team lead', 'team_lead'], true)) {
        sendResponse(
            false,
            'Manager/team-lead distribution is unavailable because no manager-to-employee team relationship is configured.',
            [],
            [],
            403
        );
    }

    return $userId;
}

function getLeadDistributionEmployees($conn) {
    $stmt = $conn->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.employee_name, ''), u.username) AS employee_name,
                COALESCE(NULLIF(r.role_name, ''), u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE LOWER(COALESCE(u.status, 'active')) = 'active'
         ORDER BY employee_name ASC, role_name ASC, u.id ASC"
    );
    if (!$stmt) {
        sendResponse(false, 'Unable to prepare employee list: ' . $conn->error, [], [], 500);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(false, 'Unable to load employees: ' . $error, [], [], 500);
    }

    $result = $stmt->get_result();
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();

    return $employees;
}

function leadDistributionColumnExists($conn, $table, $column) {
    $allowedColumns = [
        'crm_lead_followups' => ['completed_at', 'cancelled_at', 'cancellation_reason']
    ];
    if (!isset($allowedColumns[$table]) || !in_array($column, $allowedColumns[$table], true)) {
        sendResponse(false, 'Invalid lead distribution schema check.', [], [], 500);
    }
    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'"
    );
    if (!$result) {
        sendResponse(false, 'Unable to verify lead distribution schema: ' . $conn->error, [], [], 500);
    }
    return $result->num_rows > 0;
}

function crmLeadsHasSoftDeleteSchema($conn) {
    $result = $conn->query(
        "SHOW COLUMNS FROM `crm_leads` LIKE 'deleted_at'"
    );

    if (!$result) {
        sendResponse(
            false,
            'Unable to verify CRM leads soft-delete schema: ' . $conn->error,
            [],
            [],
            500
        );
    }

    return $result->num_rows > 0;
}

?>
