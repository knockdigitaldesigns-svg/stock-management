<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/installation_personnel.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$id = (int) ($_GET['id'] ?? 0);
$definition = installationPersonnelDefinition($type);
if ($definition === null || $id <= 0) {
    sendResponse(false, 'A valid installation person type and ID are required.', [], [], 400);
}

authenticate();
requirePermission($definition['permission_prefix'] . '.view');

$roleSql = installationPersonnelRoleSqlExpression();
$table = $definition['table'];
$nameColumn = $definition['name_column'];
$roleName = strtolower($definition['role_name']);
$extraFields = $type === 'dealer'
    ? 'p.installation_status, p.software, p.threshold_amount, p.installation_service_areas'
    : 'NULL AS installation_status, NULL AS software, NULL AS threshold_amount, p.installation_service_areas';
$query = "
    SELECT p.id, p.{$nameColumn} AS name, u.username,
           COALESCE(NULLIF(u.mobile_no, ''), p.mobile_no) AS mobile_no,
           p.alternate_mobile_no, p.location, p.enrolled_date, NULL AS profile_status,
           LOWER(COALESCE(u.status, 'active')) AS status, u.created_at,
           '{$definition['role_name']}' AS role_name, p.notes, {$extraFields}
    FROM {$table} p
    INNER JOIN users u ON u.id = p.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE p.id = ? AND p.user_id IS NOT NULL AND {$roleSql} = ?
    LIMIT 1
";

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$stmt = $conn->prepare($query);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare installation person details.', [], [], 500);
}
$stmt->bind_param('is', $id, $roleName);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load installation person details.', [], [], 500);
}
$person = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$person) {
    sendResponse(false, 'Installation person or linked login account not found.', [], [], 404);
}
sendResponse(true, 'Installation person details loaded successfully.', ['person' => $person]);
?>
