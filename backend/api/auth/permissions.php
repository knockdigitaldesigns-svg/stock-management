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
if (!$user) {
    sendResponse(false, 'Unable to load authenticated user details.', [], [], 500);
}
$user['impersonation'] = null;
if (!empty($payload['impersonating'])) {
    $actingUser = getCurrentUserDetails((int) ($payload['auth_user_id'] ?? 0));
    $user['impersonation'] = [
        'active' => true,
        'acting_user' => [
            'id' => (int) ($actingUser['id'] ?? 0),
            'username' => (string) ($actingUser['username'] ?? ''),
            'employee_name' => (string) ($actingUser['employee_name'] ?? $actingUser['username'] ?? '')
        ],
        'started_at' => (string) ($payload['switch_started_at'] ?? '')
    ];
}

sendResponse(true, 'Permissions fetched successfully', [
    'user' => $user,
    'permissions' => $permissions,
    'role' => [
        'id' => (int) ($user['role_id'] ?? 0),
        'name' => $user['role_name'] ?? 'No Role'
    ]
]);
