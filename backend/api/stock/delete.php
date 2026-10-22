<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);

$currentUser = authenticate();
requirePermission('stock.update');
$data = json_decode(file_get_contents('php://input'), true);
$allocationId = (int) ($data['allocation_id'] ?? 0);
$requestedDealerId = isset($data['dealer_id']) ? (int) $data['dealer_id'] : 0;
if ($allocationId <= 0) sendResponse(false, 'Valid allocation ID is required.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        'SELECT sa.*, d.dealer_name, t.technician_name, dev.imei_no, dev.status AS device_status,
                dt.device_type AS device_model, s.sim_no, s.sim_type, s.status AS sim_master_status
         FROM stock_allocations sa
         LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = "dealer"
         LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician"
         LEFT JOIN devices dev ON dev.id = sa.device_id
         LEFT JOIN device_types dt ON dt.id = dev.device_model_id
         LEFT JOIN sims s ON s.id = sa.sim_id
         WHERE sa.id = ? FOR UPDATE'
    );
    if (!$stmt) throw new RuntimeException('Unable to prepare allocation lookup: ' . $conn->error);
    $stmt->bind_param('i', $allocationId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load stock allocation: ' . $stmt->error);
    $allocation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$allocation) throw new OutOfBoundsException('Stock allocation not found.');

    $isDealerAllocation = $allocation['owner_type'] === 'dealer';
    if ($isDealerAllocation && !isSuperAdminUser((int) ($currentUser['user_id'] ?? 0))) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Only a Super Admin can delete dealer allocations.', [], [], 403);
    }
    if ($isDealerAllocation && $requestedDealerId < 0) {
        throw new InvalidArgumentException('A valid dealer ID is required.');
    }
    if ($isDealerAllocation && $requestedDealerId > 0 && $requestedDealerId !== (int) $allocation['owner_id']) {
        throw new InvalidArgumentException('The selected dealer does not match this allocation.');
    }

    $deviceId = (int) ($allocation['device_id'] ?? 0);
    $simId = (int) ($allocation['sim_id'] ?? 0);
    if ($isDealerAllocation && (($deviceId > 0) === ($simId > 0))) {
        throw new InvalidArgumentException('Only a single-device or single-SIM dealer allocation can be deleted.');
    }

    if ($isDealerAllocation) {
        if ($deviceId > 0) {
            $assetStmt = $conn->prepare('SELECT status, imei_no FROM devices WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            if (!$assetStmt) throw new RuntimeException('Unable to prepare device lookup: ' . $conn->error);
            $assetStmt->bind_param('i', $deviceId);
            if (!$assetStmt->execute()) throw new RuntimeException('Unable to load device: ' . $assetStmt->error);
            $device = $assetStmt->get_result()->fetch_assoc();
            $assetStmt->close();
            if (!$device) throw new OutOfBoundsException('Allocated device not found.');
            if (strtolower((string) $device['status']) !== 'allocated') {
                throw new InvalidArgumentException('This device is not in an eligible allocated state.');
            }

            $duplicateStmt = $conn->prepare('SELECT id FROM stock_allocations WHERE device_id = ? AND id <> ? LIMIT 1 FOR UPDATE');
            if (!$duplicateStmt) throw new RuntimeException('Unable to check duplicate device allocations: ' . $conn->error);
            $duplicateStmt->bind_param('ii', $deviceId, $allocationId);
            if (!$duplicateStmt->execute()) throw new RuntimeException('Unable to check duplicate device allocations: ' . $duplicateStmt->error);
            $hasOtherAllocation = (bool) $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();
            if ($hasOtherAllocation) throw new InvalidArgumentException('This device has another allocation and cannot be returned to stock.');

            $dependencyStmt = $conn->prepare(
                'SELECT
                    EXISTS (SELECT 1 FROM stock_transactions WHERE device_id = ?) OR
                    EXISTS (SELECT 1 FROM stock_transfers WHERE device_id = ? OR allocation_id = ?) OR
                    EXISTS (SELECT 1 FROM customer_vehicle_details WHERE device_id = ? OR imei_no = ?) OR
                    EXISTS (SELECT 1 FROM courier_requests WHERE device_id = ?) AS has_dependencies'
            );
            if (!$dependencyStmt) throw new RuntimeException('Unable to check device history: ' . $conn->error);
            $imei = (string) $device['imei_no'];
            $dependencyStmt->bind_param('iiiisi', $deviceId, $deviceId, $allocationId, $deviceId, $imei, $deviceId);
            if (!$dependencyStmt->execute()) throw new RuntimeException('Unable to check device history: ' . $dependencyStmt->error);
            $hasDependencies = (bool) ($dependencyStmt->get_result()->fetch_assoc()['has_dependencies'] ?? false);
            $dependencyStmt->close();
            if ($hasDependencies) {
                throw new InvalidArgumentException('This device has installation, transaction, transfer, or courier history and cannot be deleted.');
            }

            $assetUpdate = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ? AND status = 'allocated' AND deleted_at IS NULL");
            if (!$assetUpdate) throw new RuntimeException('Unable to prepare device stock update: ' . $conn->error);
            $assetUpdate->bind_param('i', $deviceId);
            if (!$assetUpdate->execute() || $assetUpdate->affected_rows !== 1) throw new RuntimeException('Unable to return device to available stock.');
            $assetUpdate->close();
            $itemIdentifier = 'Device ' . $imei;
        } else {
            $assetStmt = $conn->prepare('SELECT status, sim_no FROM sims WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            if (!$assetStmt) throw new RuntimeException('Unable to prepare SIM lookup: ' . $conn->error);
            $assetStmt->bind_param('i', $simId);
            if (!$assetStmt->execute()) throw new RuntimeException('Unable to load SIM: ' . $assetStmt->error);
            $sim = $assetStmt->get_result()->fetch_assoc();
            $assetStmt->close();
            if (!$sim) throw new OutOfBoundsException('Allocated SIM not found.');
            if (strtolower((string) $sim['status']) !== 'allocated') {
                throw new InvalidArgumentException('This SIM is not in an eligible allocated state.');
            }
            if (
                !empty($allocation['sim_activation_date'])
                || !empty($allocation['sim_deactivation_date'])
                || !empty($allocation['sim_reactivation_date'])
                || strtolower((string) ($allocation['sim_status'] ?? 'available')) !== 'available'
            ) {
                throw new InvalidArgumentException('This SIM has lifecycle activity and cannot be deleted.');
            }

            $duplicateStmt = $conn->prepare('SELECT id FROM stock_allocations WHERE sim_id = ? AND id <> ? LIMIT 1 FOR UPDATE');
            if (!$duplicateStmt) throw new RuntimeException('Unable to check duplicate SIM allocations: ' . $conn->error);
            $duplicateStmt->bind_param('ii', $simId, $allocationId);
            if (!$duplicateStmt->execute()) throw new RuntimeException('Unable to check duplicate SIM allocations: ' . $duplicateStmt->error);
            $hasOtherAllocation = (bool) $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();
            if ($hasOtherAllocation) throw new InvalidArgumentException('This SIM has another allocation and cannot be returned to stock.');

            $dependencyStmt = $conn->prepare(
                'SELECT
                    EXISTS (SELECT 1 FROM stock_transactions WHERE sim_id = ?) OR
                    EXISTS (SELECT 1 FROM stock_transfers WHERE sim_id = ? OR allocation_id = ?) OR
                    EXISTS (SELECT 1 FROM customer_vehicle_details WHERE sim_id_1 = ? OR sim_id_2 = ? OR sim_no_1 = ? OR sim_no_2 = ?) OR
                    EXISTS (SELECT 1 FROM courier_requests WHERE sim_id = ?) OR
                    EXISTS (SELECT 1 FROM renewal_history WHERE allocation_id = ?) AS has_dependencies'
            );
            if (!$dependencyStmt) throw new RuntimeException('Unable to check SIM history: ' . $conn->error);
            $simNo = (string) $sim['sim_no'];
            $dependencyStmt->bind_param('iiiiissii', $simId, $simId, $allocationId, $simId, $simId, $simNo, $simNo, $simId, $allocationId);
            if (!$dependencyStmt->execute()) throw new RuntimeException('Unable to check SIM history: ' . $dependencyStmt->error);
            $hasDependencies = (bool) ($dependencyStmt->get_result()->fetch_assoc()['has_dependencies'] ?? false);
            $dependencyStmt->close();
            if ($hasDependencies) {
                throw new InvalidArgumentException('This SIM has activation, transaction, transfer, installation, or courier history and cannot be deleted.');
            }

            $assetUpdate = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ? AND status = 'allocated' AND deleted_at IS NULL");
            if (!$assetUpdate) throw new RuntimeException('Unable to prepare SIM stock update: ' . $conn->error);
            $assetUpdate->bind_param('i', $simId);
            if (!$assetUpdate->execute() || $assetUpdate->affected_rows !== 1) throw new RuntimeException('Unable to return SIM to available stock.');
            $assetUpdate->close();
            $itemIdentifier = 'SIM ' . $simNo;
        }

        $reason = 'Super Admin removed dealer allocation; asset master and historical records preserved.';
        writeDeleteSnapshot($conn, $allocationId, 'Dealer Stock Allocation', $allocation, $currentUser);
        writeAudit($conn, $allocationId, 'Dealer Stock Allocation', 'Delete', 'reason', null, $reason, $currentUser);

        if ($simId > 0) {
            foreach ([
                'payments' => 'SELECT * FROM dealer_sim_allocation_payments WHERE allocation_id = ? ORDER BY id',
                'payment_requests' => 'SELECT * FROM dealer_allocation_payment_requests WHERE allocation_id = ? ORDER BY id'
            ] as $historyKey => $historySql) {
                $historyStmt = $conn->prepare($historySql);
                if (!$historyStmt) throw new RuntimeException('Unable to prepare SIM allocation ' . str_replace('_', ' ', $historyKey) . ' history snapshot: ' . $conn->error);
                $historyStmt->bind_param('i', $allocationId);
                if (!$historyStmt->execute()) throw new RuntimeException('Unable to load SIM allocation ' . str_replace('_', ' ', $historyKey) . ' history: ' . $historyStmt->error);
                $historyRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $historyStmt->close();
                if ($historyRows) {
                    writeAudit(
                        $conn,
                        $allocationId,
                        'Dealer Stock Allocation',
                        'Delete',
                        $historyKey,
                        null,
                        $historyRows,
                        $currentUser
                    );
                }
            }
        }

        $deleteStmt = $conn->prepare(
            'DELETE FROM stock_allocations
             WHERE id = ? AND owner_type = "dealer" AND owner_id = ?
               AND ((device_id = ? AND sim_id IS NULL) OR (sim_id = ? AND device_id IS NULL))'
        );
        if (!$deleteStmt) throw new RuntimeException('Unable to prepare dealer allocation deletion: ' . $conn->error);
        $ownerId = (int) $allocation['owner_id'];
        $deleteStmt->bind_param('iiii', $allocationId, $ownerId, $deviceId, $simId);
        if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to delete the selected dealer allocation.');
        }
        $deleteStmt->close();
    } else {
        writeDeleteSnapshot($conn, $allocationId, 'Stock Allocation', $allocation, $currentUser);
        if ($deviceId > 0) {
            $assetStmt = $conn->prepare("UPDATE devices SET status = 'available' WHERE id = ?");
            $assetStmt->bind_param('i', $deviceId);
            if (!$assetStmt->execute()) throw new RuntimeException('Unable to return device to stock.');
            $assetStmt->close();
        }
        if ($simId > 0) {
            $assetStmt = $conn->prepare("UPDATE sims SET status = 'available' WHERE id = ?");
            $assetStmt->bind_param('i', $simId);
            if (!$assetStmt->execute()) throw new RuntimeException('Unable to return SIM to stock.');
            $assetStmt->close();
        }
        $deleteStmt = $conn->prepare('DELETE FROM stock_allocations WHERE id = ? AND owner_type <> "dealer"');
        $deleteStmt->bind_param('i', $allocationId);
        if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) throw new RuntimeException('Unable to delete allocated stock.');
        $deleteStmt->close();
        $itemIdentifier = 'Stock allocation';
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, $itemIdentifier . ' allocation deleted successfully.', ['deleted' => 1]);
} catch (OutOfBoundsException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 404);
} catch (InvalidArgumentException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Stock allocation deletion failed: ' . $error->getMessage());
    sendResponse(false, 'Unable to delete stock allocation. No changes were saved.', [], [], 500);
}
?>
