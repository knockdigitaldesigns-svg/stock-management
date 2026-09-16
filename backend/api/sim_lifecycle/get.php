<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../renewals/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sim_lifecycle.view');

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $settings = renewalSettings($conn);
    $conn->close();

    sendResponse(true, 'SIM lifecycle settings fetched successfully.', [
        'expired_to_safe_days' => (int)($settings['expired_to_safe_days'] ?? 10),
        'safe_to_deactive_days' => (int)($settings['safe_to_deactive_days'] ?? 10)
    ]);
} catch (Throwable $e) {
    if ($conn) $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
