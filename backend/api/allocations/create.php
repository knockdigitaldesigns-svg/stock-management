<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/dealer_threshold.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

requireAnyPermission([
    'dealers.edit',
    'dealers.add',
    'technicians.edit',
    'technicians.add',
    'stock.update',
    'stock_transfer.add'
]);

$data = json_decode(file_get_contents("php://input"));

if (empty($data->owner_type) || empty($data->owner_id) || empty($data->allocation_date)) {
    sendResponse(false, "Owner type, ID, and Allocation date are required", [], [], 400);
}
if (isFutureDate($data->allocation_date)) sendResponse(false, 'Future dates are not allowed', [], [], 400);

if (empty($data->devices) && empty($data->sims)) {
    sendResponse(false, "At least one device or SIM must be provided for allocation", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// Transaction start
$conn->begin_transaction();

try {
    $owner_type = $data->owner_type;
    $owner_id = $data->owner_id;
    $allocation_date = $data->allocation_date;
    $total_amount_raw = isset($data->total_amount) ? $data->total_amount : null;
    $pending_amount_raw = isset($data->pending_amount) ? $data->pending_amount : null;
    $payment_status_raw = isset($data->payment_status) ? trim((string) $data->payment_status) : '';
    $payment_mode_raw = isset($data->payment_mode) ? trim((string) $data->payment_mode) : '';
    $transaction_id = isset($data->transaction_id) ? trim((string) $data->transaction_id) : '';
    $amount_paid_raw = isset($data->amount_paid) ? $data->amount_paid : null;
    $total_amount = is_numeric($total_amount_raw) ? (float) $total_amount_raw : 0.0;
    $amount_paid = is_numeric($amount_paid_raw) ? (float) $amount_paid_raw : 0.0;
    if ($amount_paid < 0 || $amount_paid > $total_amount) throw new Exception('Amount Paid cannot be greater than Total Amount.');
    $pending_amount = max(0, $total_amount - $amount_paid);
    $payment_status = $total_amount <= 0 ? 'Not Paid' : ($pending_amount <= 0 ? 'Paid' : ($amount_paid > 0 ? 'Partially Paid' : 'Not Paid'));
    $payment_mode = $payment_mode_raw !== '' ? $payment_mode_raw : null;
    $allowedPaymentModes = ['Cash', 'UPI', 'Bank Transfer', 'Card', 'Other'];
    if ($payment_mode !== null && !in_array($payment_mode, $allowedPaymentModes, true)) throw new Exception('Invalid payment mode.');
    $amount_paid_entered = $amount_paid_raw !== null && trim((string) $amount_paid_raw) !== '';
    if ($amount_paid_entered && $payment_mode_raw === '') throw new Exception('Payment Mode is required when Amount Paid is entered.');
    if ($payment_mode !== null && $payment_mode !== 'Cash' && $transaction_id === '') throw new Exception('Transaction ID is required for the selected Payment Mode.');
    if ($payment_mode === 'Cash') $transaction_id = '';
    $software = isset($data->software) ? trim((string) $data->software) : '';
    $allowedSoftware = ['Tracoo', 'Tracco', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];

    if ($software !== '') {
        if (!in_array($software, $allowedSoftware, true)) {
            throw new Exception("Invalid software selection.");
        }

        if ($owner_type === 'dealer') {
            $swStmt = $conn->prepare("SELECT software FROM dealer_software WHERE dealer_id = ?");
            $swStmt->bind_param("i", $owner_id);
            $swStmt->execute();
            $swRes = $swStmt->get_result();
            $dealerSwList = [];
            while ($swRow = $swRes->fetch_assoc()) {
                if (!empty($swRow['software'])) {
                    $dealerSwList[] = trim($swRow['software']);
                }
            }
            $swStmt->close();

            if (empty($dealerSwList)) {
                $dSwStmt = $conn->prepare("SELECT software FROM dealers WHERE id = ?");
                $dSwStmt->bind_param("i", $owner_id);
                $dSwStmt->execute();
                $dSwRow = $dSwStmt->get_result()->fetch_assoc();
                $dSwStmt->close();

                if (!empty($dSwRow['software'])) {
                    $dealerSwList = array_values(array_filter(array_map('trim', explode(',', $dSwRow['software']))));
                }
            }

            if (!empty($dealerSwList)) {
                if (!in_array($software, $dealerSwList, true)) {
                    throw new Exception("Selected software '{$software}' is not assigned to this dealer.");
                }
            }
        }
    }

    $server_total_amount = 0.0;
    $remaining_paid = $amount_paid;
    $seenDeviceIds = [];
    $seenSimIds = [];
    $sim_given_date = null;
    $sim_activation_date = null;
    $sim_validity_id = 0;
    $sim_expiry_date = null;
    $sim_deactivation_date = null;
    $sim_lifecycle_status = 'Available';
    
    // Validate Owner
    if ($owner_type === 'dealer') {
        $checkOwner = $conn->prepare("SELECT id, installation_status FROM dealers WHERE id = ?");
        $checkOwner->bind_param("i", $owner_id);
        $checkOwner->execute();
        $ownerResult = $checkOwner->get_result()->fetch_assoc();
        if (!$ownerResult) throw new Exception("Dealer not found");

        if ($ownerResult['installation_status'] === 'Not Willing') {
            if ($total_amount_raw === null || trim((string) $total_amount_raw) === '' || ($amount_paid_entered && $payment_mode_raw === '')) {
                throw new Exception("Payment details are mandatory for Not Willing dealers.");
            }

            if (!is_numeric($total_amount_raw) || ($amount_paid_entered && !is_numeric($amount_paid_raw))) {
                throw new Exception("Payment details are mandatory for Not Willing dealers.");
            }
        }
        $checkOwner->close();

        // Check Pending Amount Threshold for Dealer Device Allocation
        if (!empty($data->devices)) {
            $thresholdCheck = checkDealerPendingThreshold($conn, $owner_id, true);
            if (!$thresholdCheck['allowed']) {
                $conn->rollback();
                sendResponse(false, $thresholdCheck['message'], [
                    'error_code' => $thresholdCheck['error_code'],
                    'pending_amount' => $thresholdCheck['pending_amount'],
                    'threshold_amount' => $thresholdCheck['threshold_amount']
                ], [], 400);
            }
        }
    } else if ($owner_type === 'technician') {
        $checkOwner = $conn->prepare("SELECT id FROM technicians WHERE id = ?");
        $checkOwner->bind_param("i", $owner_id);
        $checkOwner->execute();
        if ($checkOwner->get_result()->num_rows === 0) throw new Exception("Technician not found");
        $checkOwner->close();
    } else {
        throw new Exception("Invalid owner type");
    }

    $allocation_type = $owner_type; // 'dealer' or 'technician'

    // Allocate Devices
    if (!empty($data->devices)) {
        foreach ($data->devices as $device) {
            $device_id = (int) ($device->id ?? 0);
            $device_amount = is_numeric($device->amount ?? null) ? (float) $device->amount : 0.0;
            $device_allocation_date = trim((string) ($device->allocation_date ?? $allocation_date));
            $notes = trim((string) ($device->notes ?? ''));
            if ($device_id <= 0 || $device_allocation_date === '') throw new Exception('Invalid device allocation row.');
            if (isFutureDate($device_allocation_date)) throw new Exception('Future dates are not allowed.');
            if (isset($seenDeviceIds[$device_id])) throw new Exception("Device ID $device_id was selected more than once.");
            if ($device_amount < 0) throw new Exception("Device amount for ID $device_id cannot be negative.");
            $seenDeviceIds[$device_id] = true;
            $server_total_amount += $device_amount;

            // 1. Check exists & available (using FOR UPDATE to lock row)
            $checkDev = $conn->prepare("SELECT status FROM devices WHERE id = ? FOR UPDATE");
            $checkDev->bind_param("i", $device_id);
            $checkDev->execute();
            $devResult = $checkDev->get_result()->fetch_assoc();
            
            if (!$devResult) throw new Exception("Device ID $device_id not found");
            if ($devResult['status'] !== 'available') throw new Exception("Device ID $device_id is already {$devResult['status']}");
            $checkDev->close();

            // 2. Insert Allocation
            $rowPaid = min($remaining_paid, $device_amount); $remaining_paid -= $rowPaid; $rowPending = $device_amount - $rowPaid; $rowStatus = $device_amount <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));
            $allocStmt = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, device_id, allocation_type, allocation_date, device_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, transaction_id, software, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $allocStmt->bind_param("siissddddsssss", $owner_type, $owner_id, $device_id, $allocation_type, $device_allocation_date, $device_amount, $device_amount, $rowPaid, $rowPending, $rowStatus, $payment_mode, $transaction_id, $software, $notes);
            if (!$allocStmt->execute()) throw new Exception("Failed to allocate device $device_id: " . $allocStmt->error);
            $allocStmt->close();

            // 3. Update Device Status
            $updDev = $conn->prepare("UPDATE devices SET status = 'allocated' WHERE id = ?");
            $updDev->bind_param("i", $device_id);
            if (!$updDev->execute()) throw new Exception("Failed to update device status");
            $updDev->close();
        }
    }

    // Allocate SIMs
    if (!empty($data->sims)) {
        foreach ($data->sims as $sim) {
            $sim_id = (int) ($sim->id ?? 0);
            $sim_amount = is_numeric($sim->amount ?? null) ? (float) $sim->amount : 0.0;
            $sim_allocation_date = trim((string) ($sim->allocation_date ?? $allocation_date));
            $notes = trim((string) ($sim->notes ?? ''));
            if ($sim_id <= 0 || $sim_allocation_date === '') throw new Exception('Invalid SIM allocation row.');
            if (isFutureDate($sim_allocation_date)) throw new Exception('Future dates are not allowed.');
            if (isset($seenSimIds[$sim_id])) throw new Exception("SIM ID $sim_id was selected more than once.");
            if ($sim_amount < 0) throw new Exception("SIM amount for ID $sim_id cannot be negative.");
            $seenSimIds[$sim_id] = true;
            $server_total_amount += $sim_amount;

            // 1. Check exists & available (using FOR UPDATE to lock row)
            $checkSim = $conn->prepare("SELECT id, sim_validity_id, status FROM sims WHERE id = ? FOR UPDATE");
            $checkSim->bind_param("i", $sim_id);
            $checkSim->execute();
            $simResult = $checkSim->get_result()->fetch_assoc();
            
            if (!$simResult) throw new Exception("SIM ID $sim_id not found");
            if ($simResult['status'] !== 'available') throw new Exception("SIM ID $sim_id is already {$simResult['status']}");
            $checkSim->close();

            // 2. Insert Allocation
            $rowPaid = min($remaining_paid, $sim_amount); $remaining_paid -= $rowPaid; $rowPending = $sim_amount - $rowPaid; $rowStatus = $sim_amount <= 0 ? 'Not Paid' : ($rowPending <= 0 ? 'Paid' : ($rowPaid > 0 ? 'Partially Paid' : 'Not Paid'));
            $sim_given_date = $sim_allocation_date;
            $sim_activation_date = null;
            $sim_validity_id = (int) ($simResult['sim_validity_id'] ?? 0);
            $sim_expiry_date = null;
            $sim_deactivation_date = null;
            $sim_lifecycle_status = 'Available';
            $allocStmt = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, sim_id, allocation_type, allocation_date, sim_given_date, sim_activation_date, sim_validity_id, sim_expiry_date, sim_deactivation_date, sim_status, sim_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, transaction_id, software, notes) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $allocStmt->bind_param("siissssisssddddsssss", $owner_type, $owner_id, $sim_id, $allocation_type, $sim_allocation_date, $sim_given_date, $sim_activation_date, $sim_validity_id, $sim_expiry_date, $sim_deactivation_date, $sim_lifecycle_status, $sim_amount, $sim_amount, $rowPaid, $rowPending, $rowStatus, $payment_mode, $transaction_id, $software, $notes);
            if (!$allocStmt->execute()) throw new Exception("Failed to allocate SIM $sim_id: " . $allocStmt->error);
            $allocStmt->close();

            // 3. Update SIM Status
            $updSim = $conn->prepare("UPDATE sims SET status = 'allocated' WHERE id = ?");
            $updSim->bind_param("i", $sim_id);
            if (!$updSim->execute()) throw new Exception("Failed to update SIM status");
            $updSim->close();
        }
    }

    if (abs($server_total_amount - $total_amount) > 0.01) {
        throw new Exception('Total Amount must equal the device and SIM amounts.');
    }

    $conn->commit();
    sendResponse(true, "Allocation successful");

} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

$conn->close();
