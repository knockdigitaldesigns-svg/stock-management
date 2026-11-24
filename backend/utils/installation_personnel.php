<?php

function installationPersonnelDefinition(string $type): ?array
{
    $definitions = [
        'dealer' => [
            'table' => 'dealers',
            'name_column' => 'dealer_name',
            'role_name' => 'Dealer',
            'permission_prefix' => 'dealers'
        ],
        'technician' => [
            'table' => 'technicians',
            'name_column' => 'technician_name',
            'role_name' => 'Technician',
            'permission_prefix' => 'technicians'
        ]
    ];

    return $definitions[strtolower(trim($type))] ?? null;
}

function installationPersonnelRoleSqlExpression(): string
{
    return "LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(r.role_name, ''), u.role, '')), ' ', ''), '_', ''), '-', ''))";
}

function isInstallationPersonnelRoleName(string $roleName): bool
{
    $normalized = strtolower(trim($roleName));
    $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);
    return in_array($normalized, ['dealer', 'technician'], true);
}

function installationPersonnelCanViewType(int $userId, string $type): bool
{
    $definition = installationPersonnelDefinition($type);
    return $definition !== null && userHasPermission(
        $userId,
        $definition['permission_prefix'] . '.view'
    );
}

function isInstallationPersonnelAccount(mysqli $conn, int $userId): bool
{
    $roleSql = installationPersonnelRoleSqlExpression();
    $stmt = $conn->prepare(
        "SELECT 1
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND {$roleSql} IN ('dealer', 'technician')
         UNION ALL
         SELECT 1 FROM dealers WHERE user_id = ?
         UNION ALL
         SELECT 1 FROM technicians WHERE user_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to verify installation person account.');
    }
    $stmt->bind_param('iii', $userId, $userId, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to verify installation person account: ' . $error);
    }
    $isLinked = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $isLinked;
}

?>
