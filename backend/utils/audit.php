<?php

function auditActor(array $user): array
{
    return [
        'id' => (int) ($user['user_id'] ?? 0),
        'name' => (string) ($user['username'] ?? 'Unknown user')
    ];
}

function auditValue($value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return (string) $value;
}

function writeAudit(mysqli $conn, int $recordId, string $module, string $action, ?string $field, $oldValue, $newValue, array $user): void
{
    $actor = auditActor($user);
    if ($actor['id'] > 0) {
        $actorStmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        if ($actorStmt) {
            $actorStmt->bind_param('i', $actor['id']);
            $actorStmt->execute();
            $actorRow = $actorStmt->get_result()->fetch_assoc();
            $actorStmt->close();
            if (!empty($actorRow['username'])) {
                $actor['name'] = $actorRow['username'];
            }
        }
    }
    $old = auditValue($oldValue);
    $new = auditValue($newValue);
    $stmt = $conn->prepare('INSERT INTO history (customer_id, module, action, field_changed, old_value, new_value, changed_by_user_id, changed_by_name, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare audit history insert: ' . $conn->error);
    }
    $stmt->bind_param('isssssis', $recordId, $module, $action, $field, $old, $new, $actor['id'], $actor['name']);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to write audit history: ' . $error);
    }
    $stmt->close();
}

function writeChangedFields(mysqli $conn, int $recordId, string $module, array $old, array $new, array $user): void
{
    foreach ($new as $field => $newValue) {
        $oldValue = $old[$field] ?? null;
        if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
            continue;
        }
        writeAudit($conn, $recordId, $module, 'Edit', $field, $oldValue, $newValue, $user);
    }
}

function writeDeleteSnapshot(mysqli $conn, int $recordId, string $module, array $old, array $user): void
{
    writeAudit($conn, $recordId, $module, 'Delete', 'record_snapshot', $old, null, $user);
}

function writeCreatedFields(mysqli $conn, int $recordId, string $module, array $created, array $user): void
{
    foreach ($created as $field => $value) {
        writeAudit($conn, $recordId, $module, 'Create', $field, null, $value, $user);
    }
}