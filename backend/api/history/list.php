<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('history.view');

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

function historyLookup(mysqli $conn, string $table, string $idColumn, string $nameColumn): array
{
    $values = [];
    $result = $conn->query("SELECT {$idColumn} AS lookup_id, {$nameColumn} AS lookup_name FROM {$table}");
    while ($result && ($row = $result->fetch_assoc())) {
        $values[(string) $row['lookup_id']] = $row['lookup_name'];
    }
    return $values;
}

function historyDisplayValue($value, string $field, array $lookups, ?string $ownerType = null): ?string
{
    if ($value === null || $value === '') return null;
    $raw = (string) $value;
    $parsed = json_decode($raw, true);
    if ($field === 'record_snapshot' && is_array($parsed)) {
        return json_encode(historyDisplaySnapshot($parsed, $lookups), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_array($parsed) && str_ends_with($field, '_id')) return $raw;
    if ($field === 'role_id') return $lookups['roles'][$raw] ?? $raw;
    if ($field === 'platform_id') return $lookups['platforms'][$raw] ?? $raw;
    if ($field === 'vehicle_type_id') return $lookups['vehicle_types'][$raw] ?? $raw;
    if ($field === 'device_model_id') return $lookups['device_types'][$raw] ?? $raw;
    if ($field === 'lead_closure_id') return $lookups['lead_closures'][$raw] ?? $raw;
    if ($field === 'customer_id') return $lookups['customers'][$raw] ?? $raw;
    if ($field === 'device_id') return $lookups['devices'][$raw] ?? $raw;
    if ($field === 'sim_id') return $lookups['sims'][$raw] ?? $raw;
    if (in_array($field, ['assigned_to_user_id', 'created_by_user_id', 'closed_by_user_id'], true)) return $lookups['users'][$raw] ?? $raw;
    if (in_array($field, ['owner_id', 'installation_person_id'], true)) {
        if (strtolower((string) $ownerType) === 'technician') return $lookups['technicians'][$raw] ?? $raw;
        if (strtolower((string) $ownerType) === 'dealer') return $lookups['dealers'][$raw] ?? $raw;
        return $lookups['dealers'][$raw] ?? $lookups['technicians'][$raw] ?? $raw;
    }
    if ($field === 'permissions') {
        $permissions = is_array($parsed) ? $parsed : [$raw];
        return json_encode(array_map(static fn($permission) => $lookups['permissions'][$permission] ?? $permission, $permissions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $raw;
}

function historyDisplaySnapshot(array $snapshot, array $lookups): array
{
    $ownerType = $snapshot['owner_type'] ?? $snapshot['installation_person_type'] ?? null;
    $display = [];
    foreach ($snapshot as $field => $value) {
        if ($field === 'id') continue;
        if (str_ends_with($field, '_id')) {
            $displayField = [
                'owner_id' => 'owner',
                'role_id' => 'role',
                'platform_id' => 'platform',
                'vehicle_type_id' => 'vehicle_type',
                'device_model_id' => 'device_model',
                'lead_closure_id' => 'lead_closure',
                'customer_id' => 'customer',
                'device_id' => 'device',
                'sim_id' => 'sim',
                'assigned_to_user_id' => 'assigned_to',
                'created_by_user_id' => 'created_by',
                'closed_by_user_id' => 'closed_by',
                'installation_person_id' => 'installation_person'
            ][$field] ?? null;
            if ($displayField === null) continue;
            $display[$displayField] = historyDisplayValue($value, $field, $lookups, $ownerType);
            continue;
        }
        $display[$field] = historyDisplayValue($value, $field, $lookups, $ownerType);
    }
    return $display;
}

$lookups = [
    'roles' => historyLookup($conn, 'roles', 'id', 'role_name'),
    'platforms' => historyLookup($conn, 'platforms', 'id', 'platform_name'),
    'vehicle_types' => historyLookup($conn, 'vehicle_types', 'id', 'vehicle_type'),
    'device_types' => historyLookup($conn, 'device_types', 'id', 'device_type'),
    'lead_closures' => historyLookup($conn, 'lead_closures', 'id', 'lead_closure_name'),
    'dealers' => historyLookup($conn, 'dealers', 'id', 'dealer_name'),
    'technicians' => historyLookup($conn, 'technicians', 'id', 'technician_name'),
    'customers' => historyLookup($conn, 'customers', 'id', 'username'),
    'devices' => historyLookup($conn, 'devices', 'id', 'imei_no'),
    'sims' => historyLookup($conn, 'sims', 'id', 'sim_no'),
    'users' => historyLookup($conn, 'users', 'id', "COALESCE(employee_name, username)"),
    'permissions' => historyLookup($conn, 'permissions', 'permission_key', 'permission_name')
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 25)));
$where = [];
$params = [];
$types = '';

$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(h.module LIKE ? OR h.action LIKE ? OR h.field_changed LIKE ? OR h.old_value LIKE ? OR h.new_value LIKE ? OR h.changed_by_name LIKE ? OR u.username LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
    $types .= 'sssssss';
}
foreach (['module', 'action'] as $filter) {
    if (trim((string) ($_GET[$filter] ?? '')) !== '') {
        $where[] = "h.{$filter} = ?";
        $params[] = trim((string) $_GET[$filter]);
        $types .= 's';
    }
}
if (!empty($_GET['date_from'])) {
    $where[] = 'DATE(h.changed_at) >= ?'; $params[] = $_GET['date_from']; $types .= 's';
}
if (!empty($_GET['date_to'])) {
    $where[] = 'DATE(h.changed_at) <= ?'; $params[] = $_GET['date_to']; $types .= 's';
}
if (!empty($_GET['changed_by'])) {
    $where[] = 'h.changed_by_user_id = ?'; $params[] = (int) $_GET['changed_by']; $types .= 'i';
}

$condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$from = ' FROM history h LEFT JOIN users u ON u.id = h.changed_by_user_id';
$stmt = $conn->prepare('SELECT h.id, h.customer_id, h.module, h.action, h.field_changed, h.old_value, h.new_value, h.changed_by_user_id, h.changed_by_name, u.username AS changed_by_username, h.changed_at' . $from . $condition . ' ORDER BY h.changed_at DESC, h.id DESC');
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$groups = [];
while ($row = $result->fetch_assoc()) {
    $key = implode('|', [
        $row['changed_at'],
        $row['module'],
        $row['action'],
        $row['customer_id'] ?? '',
        $row['changed_by_user_id'] ?? ''
    ]);
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'id' => $row['id'],
            'customer_id' => $row['customer_id'],
            'module' => $row['module'],
            'action' => $row['action'],
            'changed_by_user_id' => $row['changed_by_user_id'],
            'changed_by_name' => $row['changed_by_name'],
            'changed_by_username' => $row['changed_by_username'],
            'changed_at' => $row['changed_at'],
            'records' => []
        ];
    }
    $ownerType = null;
    if ($row['field_changed'] === 'owner_id' || $row['field_changed'] === 'installation_person_id') {
        $ownerType = $row['field_changed'] === 'owner_id' ? null : 'dealer';
    }
    $row['old_display_value'] = historyDisplayValue($row['old_value'], $row['field_changed'], $lookups, $ownerType);
    $row['new_display_value'] = historyDisplayValue($row['new_value'], $row['field_changed'], $lookups, $ownerType);
    if ($row['field_changed'] === 'record_snapshot') {
        $snapshot = json_decode((string) $row['old_value'], true);
        $row['old_display_snapshot'] = is_array($snapshot) ? historyDisplaySnapshot($snapshot, $lookups) : null;
    }
    $groups[$key]['records'][] = $row;
}
$stmt->close();
$ownerTypeGroups = [];
foreach ($groups as $groupKey => $group) {
    $ownerType = null;
    foreach ($group['records'] as $record) {
        if ($record['field_changed'] === 'owner_type' || $record['field_changed'] === 'installation_person_type') {
            $ownerType = $record['new_value'] ?: $record['old_value'];
            break;
        }
        $snapshot = json_decode((string) $record['old_value'], true);
        if (is_array($snapshot) && !empty($snapshot['owner_type'])) {
            $ownerType = $snapshot['owner_type'];
            break;
        }
    }
    foreach ($group['records'] as &$record) {
        if (in_array($record['field_changed'], ['owner_id', 'installation_person_id'], true)) {
            $record['old_display_value'] = historyDisplayValue($record['old_value'], $record['field_changed'], $lookups, $ownerType);
            $record['new_display_value'] = historyDisplayValue($record['new_value'], $record['field_changed'], $lookups, $ownerType);
        }
    }
    unset($record);
    $groups[$groupKey] = $group;
}
$allGroups = array_values($groups);
$total = count($allGroups);
$offset = ($page - 1) * $pageSize;
$history = array_slice($allGroups, $offset, $pageSize);

$modules = [];
$moduleResult = $conn->query('SELECT DISTINCT module FROM history ORDER BY module');
while ($moduleResult && ($row = $moduleResult->fetch_assoc())) $modules[] = $row['module'];
$users = [];
$userResult = $conn->query("SELECT id, username FROM users WHERE status = 'active' ORDER BY username ASC");
while ($userResult && ($row = $userResult->fetch_assoc())) $users[] = ['id' => (int) $row['id'], 'username' => $row['username']];
$conn->close();

sendResponse(true, 'History fetched successfully.', [
    'history' => $history,
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ],
    'modules' => $modules,
    'users' => $users
]);
?>