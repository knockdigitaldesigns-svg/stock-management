<?php

function accountImpersonationSessionHash(string $sessionId): string
{
    return hash('sha256', $sessionId);
}

function accountImpersonationFindActive(mysqli $conn, int $actingUserId, string $sessionId): ?array
{
    $sessionHash = accountImpersonationSessionHash($sessionId);
    $stmt = $conn->prepare(
        'SELECT id, acting_user_id, represented_user_id, started_at
         FROM user_account_switches
         WHERE session_id_hash = ? AND acting_user_id = ? AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare account-switch context lookup: ' . $conn->error);
    }
    $stmt->bind_param('si', $sessionHash, $actingUserId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load account-switch context: ' . $error);
    }
    $context = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $context;
}

function accountImpersonationLoadUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT u.id, u.username, u.employee_name, u.status, u.role_id, u.role,
                COALESCE(NULLIF(r.role_name, ''), u.role, 'No Role') AS role_name,
                COALESCE(r.status, 'active') AS role_status,
                EXISTS(SELECT 1 FROM dealers d WHERE d.user_id = u.id) AS is_dealer,
                EXISTS(SELECT 1 FROM technicians t WHERE t.user_id = u.id) AS is_technician
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare account-switch user lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load account-switch user: ' . $error);
    }
    $user = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $user;
}

function accountImpersonationIsSuperAdminRole(string $roleName): bool
{
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($roleName))) === 'superadmin';
}

function accountImpersonationUserIsEligible(array $user): bool
{
    $roleName = preg_replace(
        '/[^a-z0-9]/',
        '',
        strtolower(trim((string) ($user['role_name'] ?? $user['role'] ?? '')))
    );
    return strtolower(trim((string) ($user['status'] ?? 'active'))) === 'active'
        && strtolower(trim((string) ($user['role_status'] ?? 'active'))) === 'active'
        && !in_array($roleName, ['superadmin', 'dealer', 'technician'], true)
        && empty($user['is_dealer'])
        && empty($user['is_technician']);
}

function accountImpersonationEnd(
    mysqli $conn,
    int $actingUserId,
    string $sessionId,
    string $reason
): bool {
    $sessionHash = accountImpersonationSessionHash($sessionId);
    $stmt = $conn->prepare(
        'UPDATE user_account_switches
         SET ended_at = CURRENT_TIMESTAMP, end_reason = ?
         WHERE session_id_hash = ? AND acting_user_id = ? AND ended_at IS NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare account-switch termination: ' . $conn->error);
    }
    $stmt->bind_param('ssi', $reason, $sessionHash, $actingUserId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to terminate account-switch context: ' . $error);
    }
    $ended = $stmt->affected_rows > 0;
    $stmt->close();
    return $ended;
}
