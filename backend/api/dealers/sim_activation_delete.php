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
requireAnyPermission(['dealer_sim_activation.delete', 'dealers.delete']);
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$allocationId = (int) ($data['allocation_id'] ?? 0);
if ($allocationId <= 0) {
    sendResponse(false, 'Valid SIM activation ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();
try {
    $select = $conn->prepare(
        "SELECT sa.*, d.dealer_name, s.sim_no, s.sim_type
         FROM stock_allocations sa
         INNER JOIN dealers d ON d.id = sa.owner_id
         INNER JOIN sims s ON s.id = sa.sim_id
         WHERE sa.id = ? AND sa.owner_type = 'dealer' AND sa.sim_id IS NOT NULL
         FOR UPDATE"
    );
    if (!$select) {
        throw new RuntimeException('Unable to prepare SIM activation lookup.');
    }
    $select->bind_param('i', $allocationId);
    if (!$select->execute()) {
        throw new RuntimeException('Unable to load SIM activation.');
    }
    $allocation = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$allocation) {
        throw new RuntimeException('Dealer SIM activation record not found.');
    }

    writeDeleteSnapshot($conn, $allocationId, 'Dealer SIM Activation', $allocation, $currentUser);

    $simId = (int) $allocation['sim_id'];
    $updateSim = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ?");
    if (!$updateSim) {
        throw new RuntimeException('Unable to prepare SIM stock update.');
    }
    $updateSim->bind_param('i', $simId);
    if (!$updateSim->execute()) {
        throw new RuntimeException('Unable to return SIM to available stock.');
    }
    $updateSim->close();

    $delete = $conn->prepare('DELETE FROM stock_allocations WHERE id = ?');
    if (!$delete) {
        throw new RuntimeException('Unable to prepare SIM activation deletion.');
    }
    $delete->bind_param('i', $allocationId);
    if (!$delete->execute() || $delete->affected_rows !== 1) {
        throw new RuntimeException('Unable to delete SIM activation.');
    }
    $delete->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Dealer SIM activation deleted successfully.', ['deleted' => 1]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>
