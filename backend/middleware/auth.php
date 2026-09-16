<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/response.php';

function generateJWT($payload, $secret) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function verifyJWT($jwt, $secret) {
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) != 3) {
        return false;
    }

    $header = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[0]));
    $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
    $signatureProvided = $tokenParts[2];

    $base64UrlHeader = $tokenParts[0];
    $base64UrlPayload = $tokenParts[1];
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    if ($base64UrlSignature === $signatureProvided) {
        return json_decode($payload, true);
    }

    return false;
}

function getCurrentUserFromToken() {
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    if (!$authHeader) {
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    }

    if (!$authHeader) {
        return null;
    }

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        return null;
    }

    $payload = verifyJWT($matches[1], 'your_super_secret_key_12345');
    if (!$payload) {
        return null;
    }

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function authenticate() {
    $payload = getCurrentUserFromToken();
    if (!$payload) {
        sendResponse(false, 'Invalid or expired token', [], [], 401);
    }

    return $payload;
}

function isSuperAdminUser($userId = null, $roleName = null) {
    if ($userId !== null) {
        $conn = (new Database())->getConnection();
        if (!$conn) {
            return false;
        }

        $stmt = $conn->prepare('SELECT u.role, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        $roleName = $row['role_name'] ?? $row['role'] ?? null;
    }

    if (!$roleName) {
        return false;
    }

    $normalized = strtolower(trim((string) $roleName));
    return $normalized === 'super admin' || $normalized === 'super_admin';
}

function ensurePermissionDefinitions($permissionKeys = []) {
    $conn = (new Database())->getConnection();
    if (!$conn) {
        return;
    }

    $definitions = [
        'dealers.import' => ['permission_name' => 'Dealer Import', 'module' => 'dealers', 'action' => 'IMPORT'],
        'technicians.import' => ['permission_name' => 'Technician Import', 'module' => 'technicians', 'action' => 'IMPORT'],
        'device_types.view' => ['permission_name' => 'Device Types View', 'module' => 'device_types', 'action' => 'VIEW'],
        'device_types.add' => ['permission_name' => 'Device Types Add', 'module' => 'device_types', 'action' => 'ADD'],
        'device_types.edit' => ['permission_name' => 'Device Types Edit', 'module' => 'device_types', 'action' => 'EDIT'],
        'device_types.delete' => ['permission_name' => 'Device Types Delete', 'module' => 'device_types', 'action' => 'DELETE'],
        'history.view' => ['permission_name' => 'History View', 'module' => 'history', 'action' => 'VIEW'],
        'sim_validity.view' => ['permission_name' => 'SIM Validity View', 'module' => 'sim_validity', 'action' => 'VIEW'],
        'sim_validity.add' => ['permission_name' => 'SIM Validity Add', 'module' => 'sim_validity', 'action' => 'ADD'],
        'sim_validity.edit' => ['permission_name' => 'SIM Validity Edit', 'module' => 'sim_validity', 'action' => 'EDIT'],
        'sim_validity.delete' => ['permission_name' => 'SIM Validity Delete', 'module' => 'sim_validity', 'action' => 'DELETE'],
        'stock_transfer.view' => ['permission_name' => 'Stock Transfer View', 'module' => 'stock_transfer', 'action' => 'VIEW'],
            'stock_transfer.add' => ['permission_name' => 'Stock Transfer Add', 'module' => 'stock_transfer', 'action' => 'ADD'],
            'customers.view' => ['permission_name' => 'Customer Details View', 'module' => 'customers', 'action' => 'VIEW'],
            'customers.add' => ['permission_name' => 'Customer Details Add', 'module' => 'customers', 'action' => 'ADD'],
            'customers.edit' => ['permission_name' => 'Customer Details Edit', 'module' => 'customers', 'action' => 'EDIT'],
            'customers.delete' => ['permission_name' => 'Customer Details Delete', 'module' => 'customers', 'action' => 'DELETE'],
            'customers.export' => ['permission_name' => 'Customer Details Export', 'module' => 'customers', 'action' => 'EXPORT'],
            'customers.update' => ['permission_name' => 'Customer Details Update', 'module' => 'customers', 'action' => 'UPDATE'],
        'customer_reports.view' => ['permission_name' => 'Customer Reports View', 'module' => 'customer_reports', 'action' => 'VIEW'],
        'customer_renewals.view' => ['permission_name' => 'Customer Renewals View', 'module' => 'customer_renewals', 'action' => 'VIEW'],
        'customer_renewals.edit' => ['permission_name' => 'Customer Renewals Edit', 'module' => 'customer_renewals', 'action' => 'EDIT'],
        'customer_renewals.renew' => ['permission_name' => 'Customer Renewals Renew', 'module' => 'customer_renewals', 'action' => 'RENEW'],
        'customer_renewals.history' => ['permission_name' => 'Customer Renewals History', 'module' => 'customer_renewals', 'action' => 'HISTORY'],
        'sim_lifecycle.view' => ['permission_name' => 'SIM Lifecycle View', 'module' => 'sim_lifecycle', 'action' => 'VIEW'],
        'sim_lifecycle.edit' => ['permission_name' => 'SIM Lifecycle Edit', 'module' => 'sim_lifecycle', 'action' => 'EDIT'],
            'platforms.view' => ['permission_name' => 'Platform View', 'module' => 'platforms', 'action' => 'VIEW'],
            'platforms.add' => ['permission_name' => 'Platform Add', 'module' => 'platforms', 'action' => 'ADD'],
            'platforms.edit' => ['permission_name' => 'Platform Edit', 'module' => 'platforms', 'action' => 'EDIT'],
            'platforms.delete' => ['permission_name' => 'Platform Delete', 'module' => 'platforms', 'action' => 'DELETE'],
            'vehicle_types.view' => ['permission_name' => 'Vehicle Types View', 'module' => 'vehicle_types', 'action' => 'VIEW'],
            'vehicle_types.add' => ['permission_name' => 'Vehicle Types Add', 'module' => 'vehicle_types', 'action' => 'ADD'],
            'vehicle_types.edit' => ['permission_name' => 'Vehicle Types Edit', 'module' => 'vehicle_types', 'action' => 'EDIT'],
            'vehicle_types.delete' => ['permission_name' => 'Vehicle Types Delete', 'module' => 'vehicle_types', 'action' => 'DELETE'],
            'lead_closures.view' => ['permission_name' => 'Lead Closure View', 'module' => 'lead_closures', 'action' => 'VIEW'],
            'lead_closures.add' => ['permission_name' => 'Lead Closure Add', 'module' => 'lead_closures', 'action' => 'ADD'],
            'lead_closures.edit' => ['permission_name' => 'Lead Closure Edit', 'module' => 'lead_closures', 'action' => 'EDIT'],
            'lead_closures.delete' => ['permission_name' => 'Lead Closure Delete', 'module' => 'lead_closures', 'action' => 'DELETE'],
            'sale_amounts.view' => ['permission_name' => 'Sale Amount View', 'module' => 'sale_amounts', 'action' => 'VIEW'],
            'sale_amounts.add' => ['permission_name' => 'Sale Amount Add', 'module' => 'sale_amounts', 'action' => 'ADD'],
            'sale_amounts.edit' => ['permission_name' => 'Sale Amount Edit', 'module' => 'sale_amounts', 'action' => 'EDIT'],
            'sale_amounts.delete' => ['permission_name' => 'Sale Amount Delete', 'module' => 'sale_amounts', 'action' => 'DELETE'],
            'support.view' => ['permission_name' => 'Support View', 'module' => 'support', 'action' => 'VIEW'],
            'support.add' => ['permission_name' => 'Support Add', 'module' => 'support', 'action' => 'ADD'],
            'support.edit' => ['permission_name' => 'Support Edit', 'module' => 'support', 'action' => 'EDIT'],
            'support.delete' => ['permission_name' => 'Support Delete', 'module' => 'support', 'action' => 'DELETE'],
            'support.assign' => ['permission_name' => 'Support Assign', 'module' => 'support', 'action' => 'ASSIGN'],
            'support.close' => ['permission_name' => 'Support Close', 'module' => 'support', 'action' => 'CLOSE'],
            'support.qa.view' => ['permission_name' => 'Support Questions View', 'module' => 'support', 'action' => 'QA_VIEW'],
            'support.qa.manage' => ['permission_name' => 'Support Questions Manage', 'module' => 'support', 'action' => 'QA_MANAGE'],
            'password.change' => ['permission_name' => 'Change Password', 'module' => 'password', 'action' => 'CHANGE']
    ];

    foreach ($permissionKeys as $key) {
        if (isset($definitions[$key])) {
            $definitions[$key] = $definitions[$key];
        }
    }

    foreach ($definitions as $key => $info) {
        $safeKey = $conn->real_escape_string($key);
        $safeName = $conn->real_escape_string($info['permission_name']);
        $safeModule = $conn->real_escape_string($info['module']);
        $safeAction = $conn->real_escape_string($info['action']);
        $conn->query("INSERT INTO permissions (permission_key, permission_name, module, action) VALUES ('$safeKey', '$safeName', '$safeModule', '$safeAction') ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), module = VALUES(module), action = VALUES(action)");
    }

    $superRole = $conn->query("SELECT id FROM roles WHERE LOWER(role_name) = 'super admin' LIMIT 1");
    if ($superRole && $superRole->num_rows > 0) {
        $superRoleId = (int) $superRole->fetch_assoc()['id'];
        foreach (array_keys($definitions) as $key) {
            $permIdResult = $conn->query("SELECT id FROM permissions WHERE permission_key = '" . $conn->real_escape_string($key) . "' LIMIT 1");
            if ($permIdResult && $permIdResult->num_rows > 0) {
                $permId = (int) $permIdResult->fetch_assoc()['id'];
                $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($superRoleId, $permId)");
            }
        }
    }

    $conn->close();
}

function getUserPermissions($userId) {
    ensurePermissionDefinitions();
    $conn = (new Database())->getConnection();
    if (!$conn) {
        return [];
    }

    $userQuery = $conn->prepare('SELECT u.role_id, u.role, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $userQuery->bind_param('i', $userId);
    $userQuery->execute();
    $userResult = $userQuery->get_result();
    $userData = $userResult->fetch_assoc();
    $userQuery->close();

    $roleName = $userData['role_name'] ?? $userData['role'] ?? '';
    if (isSuperAdminUser(null, $roleName)) {
        $permResult = $conn->query('SELECT permission_key FROM permissions ORDER BY permission_key ASC');
        $permissions = [];
        if ($permResult) {
            while ($row = $permResult->fetch_assoc()) {
                $permissions[] = $row['permission_key'];
            }
        }
        $conn->close();
        return $permissions;
    }

    $roleId = $userData['role_id'] ?? null;
    if (!$roleId) {
        $conn->close();
        return [];
    }

    $stmt = $conn->prepare('SELECT DISTINCT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key ASC');
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['permission_key'];
    }
    $stmt->close();
    $conn->close();
    return $permissions;
}

function userHasPermission($userId, $permissionKey) {
    $permissions = getUserPermissions($userId);
    return in_array($permissionKey, $permissions, true);
}

function requirePermission($permissionKey) {
    ensurePermissionDefinitions([$permissionKey]);
    $payload = authenticate();
    $userId = (int)($payload['user_id'] ?? 0);
    if (!$userId || !userHasPermission($userId, $permissionKey)) {
        sendResponse(false, 'You do not have permission to perform this action.', [], [], 403);
    }
    return true;
}

function requireAnyPermission($permissionKeys) {
    $payload = authenticate();
    $userId = (int)($payload['user_id'] ?? 0);
    if (!$userId) {
        sendResponse(false, 'Invalid user session.', [], [], 401);
    }

    foreach ($permissionKeys as $permissionKey) {
        if (userHasPermission($userId, $permissionKey)) {
            return true;
        }
    }

    sendResponse(false, 'You do not have permission to perform this action.', [], [], 403);
    return false;
}

function getCurrentUserDetails($userId) {
    $conn = (new Database())->getConnection();
    if (!$conn) {
        return null;
    }

    $stmt = $conn->prepare('SELECT u.id, u.username, u.employee_name, u.mobile_no, u.status, u.role_id, r.role_name, r.is_system_role FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$user) {
        return null;
    }

    $user['permissions'] = getUserPermissions($userId);
    $user['role'] = [
        'id' => (int)($user['role_id'] ?? 0),
        'name' => $user['role_name'] ?? 'No Role'
    ];

    return $user;
}
?>
