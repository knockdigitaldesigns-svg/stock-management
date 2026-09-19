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

function auditSanitize(array $data): array
{
    foreach (['password', 'password_hash', 'token', 'jwt', 'access_token', 'refresh_token'] as $sensitive) {
        unset($data[$sensitive]);
    }
    return $data;
}

function customerAuditSnapshot(mysqli $conn, int $customerId): array
{
    $snapshot = [];
    $stmt = $conn->prepare('SELECT c.*, p.platform_name FROM customers c LEFT JOIN platforms p ON p.id = c.platform_id WHERE c.id = ? LIMIT 1');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $snapshot['customer'] = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $fetch = static function (mysqli $conn, string $sql, int $customerId): array {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    };
    $snapshot['vehicles'] = $fetch($conn, 'SELECT cv.*, vt.vehicle_type, dt.device_type AS device_model FROM customer_vehicle_details cv LEFT JOIN vehicle_types vt ON vt.id = cv.vehicle_type_id LEFT JOIN device_types dt ON dt.id = cv.device_model_id WHERE cv.customer_id = ? ORDER BY cv.id', $customerId);
    $snapshot['installations'] = $fetch($conn, 'SELECT ci.*, COALESCE(t.technician_name, d.dealer_name) AS installation_person, lc.lead_closure_name FROM customer_installations ci LEFT JOIN technicians t ON t.id = ci.installation_person_id AND ci.installation_person_type = "Technician" LEFT JOIN dealers d ON d.id = ci.installation_person_id AND ci.installation_person_type = "Dealer" LEFT JOIN lead_closures lc ON lc.id = ci.lead_closure_id WHERE ci.customer_id = ? ORDER BY ci.id', $customerId);
    $snapshot['payments'] = $fetch($conn, 'SELECT * FROM customer_payments WHERE customer_id = ? ORDER BY id', $customerId);
    $snapshot['cash_collections'] = $fetch($conn, 'SELECT * FROM customer_cash_collections WHERE customer_id = ? ORDER BY id', $customerId);
    return auditSanitize($snapshot);
}

function writeAuditSnapshot(mysqli $conn, int $recordId, string $module, string $action, ?array $old, ?array $new, array $user): void
{
    writeAudit($conn, $recordId, $module, $action, 'record_snapshot', $old, $new, $user);
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
    writeAuditSnapshot($conn, $recordId, $module, 'Edit', auditSanitize($old), auditSanitize($new), $user);
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
    writeAuditSnapshot($conn, $recordId, $module, 'Delete', auditSanitize($old), null, $user);
}

function writeCreatedFields(mysqli $conn, int $recordId, string $module, array $created, array $user): void
{
    writeAuditSnapshot($conn, $recordId, $module, 'Create', null, auditSanitize($created), $user);
    foreach ($created as $field => $value) {
        writeAudit($conn, $recordId, $module, 'Create', $field, null, $value, $user);
    }
}