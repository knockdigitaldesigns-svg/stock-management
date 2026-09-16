<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('support.view');
$conn = (new Database())->getConnection();
$result = $conn->query("SELECT id, employee_name, username FROM users WHERE status = 'active' ORDER BY employee_name ASC, username ASC");
$users = [];
while ($result && ($row = $result->fetch_assoc())) $users[] = ['employee_name' => $row['employee_name'] ?: $row['username'], 'username' => $row['username'], 'user_id' => (int) $row['id']];
$conn->close();
sendResponse(true, 'Support employees fetched successfully.', ['users' => $users]);
?>
