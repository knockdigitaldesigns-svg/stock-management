<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$payload = authenticate();
$userId = (int) ($payload['user_id'] ?? 0);
if (!$userId) {
    sendResponse(false, 'Invalid user session', [], [], 401);
}

$permissions = getUserPermissions($userId);
$user = getCurrentUserDetails($userId);

sendResponse(true, 'Permissions fetched successfully', [
    'user' => $user,
    'permissions' => $permissions,
    'role' => [
        'id' => (int) ($user['role_id'] ?? 0),
        'name' => $user['role_name'] ?? 'No Role'
    ]
]);
