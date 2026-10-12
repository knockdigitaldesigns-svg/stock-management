<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('sims.delete');
$isSuperAdmin = isSuperAdminUser((int) ($currentUser['user_id'] ?? 0));

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, 'SIM ID is required', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$conn->begin_transaction();
$stmt = null;

try {
    $checkStmt = $conn->prepare('SELECT * FROM sims WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
    if (!$checkStmt) {
        throw new RuntimeException('Unable to prepare SIM lookup.');
    }
    $checkStmt->bind_param('i', $id);
    if (!$checkStmt->execute()) {
        throw new RuntimeException('Unable to load SIM.');
    }
    $sim = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$sim) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'SIM not found', [], [], 404);
    }

    if (!$isSuperAdmin && in_array(strtolower((string) $sim['status']), ['allocated', 'used'], true)) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'This SIM is currently ' . $sim['status'] . ' and cannot be deleted.', [], [], 400);
    }

    $dependencyStmt = $conn->prepare("
        SELECT EXISTS (
            SELECT 1 FROM stock_allocations WHERE sim_id = ?
            UNION ALL SELECT 1 FROM stock_transactions WHERE sim_id = ?
            UNION ALL SELECT 1 FROM stock_transfers WHERE sim_id = ?
            UNION ALL SELECT 1 FROM customer_vehicle_details WHERE sim_id_1 = ? OR sim_id_2 = ?
            UNION ALL SELECT 1 FROM courier_requests WHERE sim_id = ?
        ) AS has_dependencies
    ");
    if (!$dependencyStmt) {
        throw new RuntimeException('Unable to check SIM history dependencies.');
    }
    $dependencyStmt->bind_param('iiiiii', $id, $id, $id, $id, $id, $id);
    if (!$dependencyStmt->execute()) {
        throw new RuntimeException('Unable to check SIM history dependencies.');
    }
    $hasDependencies = (bool) ($dependencyStmt->get_result()->fetch_assoc()['has_dependencies'] ?? false);
    $dependencyStmt->close();

    writeDeleteSnapshot($conn, $id, 'SIM', $sim, $currentUser);

    if ($hasDependencies) {
        $stmt = $conn->prepare('UPDATE sims SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare SIM archive.');
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('Failed to archive SIM.');
        }
        $stmt->close();
        $stmt = null;
        $conn->commit();
        $conn->close();
        sendResponse(true, 'SIM archived to preserve its history.', ['archived' => true]);
    }

    $stmt = $conn->prepare('DELETE FROM sims WHERE id = ? AND deleted_at IS NULL');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SIM deletion.');
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('Failed to delete SIM.');
    }
    $stmt->close();
    $stmt = null;
    $conn->commit();
    $conn->close();
    sendResponse(true, 'SIM deleted successfully', ['archived' => false]);
} catch (Throwable $error) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 500);
}
?>
