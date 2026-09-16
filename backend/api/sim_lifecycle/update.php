<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../renewals/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('sim_lifecycle.edit');

$payload = json_decode(file_get_contents('php://input'), true) ?: [];

$expiredToSafeDays = $payload['expired_to_safe_days'] ?? null;
$safeToDeactiveDays = $payload['safe_to_deactive_days'] ?? null;

if (
    $expiredToSafeDays === null ||
    $safeToDeactiveDays === null ||
    filter_var($expiredToSafeDays, FILTER_VALIDATE_INT) === false ||
    filter_var($safeToDeactiveDays, FILTER_VALIDATE_INT) === false ||
    (int)$expiredToSafeDays < 0 ||
    (int)$safeToDeactiveDays < 0
) {
    sendResponse(false, 'Lifecycle days must be non-negative whole numbers.', [], [], 400);
}

$expiredToSafeDays = (int)$expiredToSafeDays;
$safeToDeactiveDays = (int)$safeToDeactiveDays;

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    ensureRenewalTables($conn);

    $stmt = $conn->prepare('INSERT INTO renewal_settings (expired_to_safe_days, safe_to_deactive_days) VALUES (?, ?)');
    if (!$stmt) {
        throw new Exception('Failed to prepare update query.');
    }

    $stmt->bind_param('ii', $expiredToSafeDays, $safeToDeactiveDays);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to save settings: ' . $error);
    }
    $stmt->close();
    $conn->close();

    sendResponse(true, 'SIM lifecycle settings updated successfully.', [
        'expired_to_safe_days' => $expiredToSafeDays,
        'safe_to_deactive_days' => $safeToDeactiveDays
    ]);
} catch (Throwable $e) {
    if ($conn) $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
