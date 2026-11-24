<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/installation_personnel.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
$userId = (int) ($currentUser['user_id'] ?? 0);
ensurePermissionDefinitions(['dealers.view', 'technicians.view']);
$allowedTypes = [];
foreach (['dealer', 'technician'] as $type) {
    if (installationPersonnelCanViewType($userId, $type)) {
        $allowedTypes[] = $type;
    }
}
if (!$allowedTypes) {
    sendResponse(false, 'You do not have permission to view installation persons.', [], [], 403);
}

$requestedType = strtolower(trim((string) ($_GET['type'] ?? '')));
if ($requestedType !== '') {
    if (!in_array($requestedType, ['dealer', 'technician'], true)) {
        sendResponse(false, 'Invalid installation person type.', [], [], 400);
    }
    if (!in_array($requestedType, $allowedTypes, true)) {
        sendResponse(false, 'You do not have permission to view this installation person type.', [], [], 403);
    }
    $allowedTypes = [$requestedType];
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    sendResponse(false, 'Invalid account status filter.', [], [], 400);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;

$roleSql = installationPersonnelRoleSqlExpression();
$personnelSql = "
    SELECT 'dealer' AS personnel_type, d.id, d.dealer_name AS name, u.username,
           COALESCE(NULLIF(u.mobile_no, ''), d.mobile_no) AS mobile_no,
           'Dealer' AS role_name, LOWER(COALESCE(u.status, 'active')) AS status, u.created_at
    FROM dealers d
    INNER JOIN users u ON u.id = d.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE d.user_id IS NOT NULL AND u.username IS NOT NULL AND TRIM(u.username) <> ''
      AND {$roleSql} = 'dealer'
    UNION ALL
    SELECT 'technician' AS personnel_type, t.id, t.technician_name AS name, u.username,
           COALESCE(NULLIF(u.mobile_no, ''), t.mobile_no) AS mobile_no,
           'Technician' AS role_name, LOWER(COALESCE(u.status, 'active')) AS status, u.created_at
    FROM technicians t
    INNER JOIN users u ON u.id = t.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE t.user_id IS NOT NULL AND u.username IS NOT NULL AND TRIM(u.username) <> ''
      AND {$roleSql} = 'technician'
";

$conditions = [];
$params = $allowedTypes;
$paramTypes = str_repeat('s', count($allowedTypes));
$typePlaceholders = implode(', ', array_fill(0, count($allowedTypes), '?'));
$conditions[] = "personnel_type IN ({$typePlaceholders})";

if ($search !== '') {
    $conditions[] = '(name LIKE ? OR username LIKE ? OR mobile_no LIKE ?)';
    $searchValue = '%' . $search . '%';
    array_push($params, $searchValue, $searchValue, $searchValue);
    $paramTypes .= 'sss';
}
if ($status !== 'all') {
    $conditions[] = 'status = ?';
    $params[] = $status;
    $paramTypes .= 's';
}

$whereSql = 'WHERE ' . implode(' AND ', $conditions);
$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ({$personnelSql}) AS personnel {$whereSql}");
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare installation persons query.', [], [], 500);
}
if ($params && !$countStmt->bind_param($paramTypes, ...$params)) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind installation persons filters.', [], [], 500);
}
if (!$countStmt->execute()) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load installation persons.', [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$listParams = $params;
$listParams[] = $pageSize;
$listParams[] = $offset;
$listStmt = $conn->prepare(
    "SELECT personnel_type, id, name, username, mobile_no, role_name, status, created_at
     FROM ({$personnelSql}) AS personnel
     {$whereSql}
     ORDER BY created_at DESC, name ASC, id DESC
     LIMIT ? OFFSET ?"
);
if (!$listStmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare installation persons list.', [], [], 500);
}
if (!$listStmt->bind_param($paramTypes . 'ii', ...$listParams)) {
    $listStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to bind installation persons list filters.', [], [], 500);
}
if (!$listStmt->execute()) {
    $listStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load installation persons.', [], [], 500);
}
$records = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();
$conn->close();

sendResponse(true, 'Installation persons loaded successfully.', [
    'persons' => $records,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
