<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/dealer_threshold.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/excel_reader.php';
require_once '../../utils/payment_modes.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('dealers.import');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    sendResponse(false, 'Please upload a valid Excel file.', [], [], 400);
}

$file = $_FILES['file'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    sendResponse(false, 'Only .xlsx files are allowed.', [], [], 400);
}

if (!isset($file['size']) || $file['size'] === 0) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

$requiredHeaders = ['Dealer Name', 'Allocation Type'];
$optionalHeaders = [
    'Device Date', 'IMEI No', 'Device Amount', 'Device Notes',
    'SIM Date', 'SIM Number', 'SIM Amount', 'SIM Notes',
    'Software', 'Total Amount', 'Amount Paid', 'Payment Mode', 'Transaction ID'
];

$parsed = parseXlsxRows($file['tmp_name'], $requiredHeaders, $optionalHeaders);
if (isset($parsed['error'])) {
    sendResponse(false, $parsed['error'], [], [], 400);
}

$rows = $parsed['rows'];
if (count($rows) === 0) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$errors = [];
$validRows = [];
$seenImeis = [];
$seenSimNos = [];
$allowedSoftware = ['Tracoo', 'Tracco', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
$allowedPaymentModes = getPaymentModes();

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;
    $rowErrors = [];

    $dealerName = trim((string) ($row['Dealer Name'] ?? ''));
    $allocationType = strtolower(trim((string) ($row['Allocation Type'] ?? '')));

    if ($dealerName === '') {
        $rowErrors[] = "Row {$rowNumber}: Dealer Name is required.";
    }

    if (!in_array($allocationType, ['device', 'sim', 'both'], true)) {
        $rowErrors[] = "Row {$rowNumber}: Allocation Type must be device, sim, or both.";
    }

    // 1. Dealer Lookup
    $dealer = null;
    if ($dealerName !== '') {
        $dealerStmt = $conn->prepare("SELECT id, installation_status, threshold_amount FROM dealers WHERE LOWER(TRIM(dealer_name)) = LOWER(TRIM(?)) LIMIT 1");
        $dealerStmt->bind_param('s', $dealerName);
        $dealerStmt->execute();
        $dealer = $dealerStmt->get_result()->fetch_assoc();
        $dealerStmt->close();

        if (!$dealer) {
            $rowErrors[] = "Row {$rowNumber}: Dealer '{$dealerName}' not found.";
        }
    }

    $hasDevices = ($allocationType === 'device' || $allocationType === 'both');
    $hasSims = ($allocationType === 'sim' || $allocationType === 'both');
    $deviceRow = null;
    $simRow = null;

    // 2. Threshold validation for Dealer Device Allocation
    if ($dealer && $hasDevices) {
        $thresholdCheck = checkDealerPendingThreshold($conn, $dealer['id'], true);
        if (!$thresholdCheck['allowed']) {
            $rowErrors[] = "Row {$rowNumber}: Your pending amount exceeds the threshold amount. Clear the pending to buy GPS device.";
        }
    }

    // 3. Device Validation
    $devAmtRaw = $row['Device Amount'] ?? '';
    $devDateIso = null;
    if ($hasDevices) {
        $imeiNo = trim((string) ($row['IMEI No'] ?? ''));
        $devDateRaw = trim((string) ($row['Device Date'] ?? ''));

        if ($imeiNo === '') {
            $rowErrors[] = "Row {$rowNumber}: IMEI No is required for device allocation.";
        } else {
            if (isset($seenImeis[$imeiNo])) {
                $rowErrors[] = "Row {$rowNumber}: Duplicate IMEI No '{$imeiNo}' in uploaded Excel.";
            }
            $seenImeis[$imeiNo] = true;

            $devStmt = $conn->prepare("SELECT id, imei_no, status FROM devices WHERE imei_no = ? LIMIT 1");
            $devStmt->bind_param('s', $imeiNo);
            $devStmt->execute();
            $deviceRow = $devStmt->get_result()->fetch_assoc();
            $devStmt->close();

            if (!$deviceRow) {
                $rowErrors[] = "Row {$rowNumber}: Device IMEI '{$imeiNo}' not found.";
            } elseif (strtolower((string)$deviceRow['status']) !== 'available') {
                $rowErrors[] = "Row {$rowNumber}: Device IMEI '{$imeiNo}' is already allocated or used.";
            }
        }

        if ($devDateRaw === '') {
            $rowErrors[] = "Row {$rowNumber}: Device Date is required.";
        } else {
            $parsedDevDate = parseAndNormalizeDate($devDateRaw);
            if ($parsedDevDate === false || isFutureDate($parsedDevDate)) {
                $rowErrors[] = "Row {$rowNumber}: Invalid or future Device Date.";
            } else {
                $devDateIso = $parsedDevDate;
            }
        }

        if ($dealer && $dealer['installation_status'] === 'Not Willing') {
            if ($devAmtRaw === '' || !is_numeric($devAmtRaw) || (float)$devAmtRaw < 0) {
                $rowErrors[] = "Row {$rowNumber}: Device Amount is required for Not Willing dealer.";
            }
        }
    }

    // 4. SIM Validation
    $simAmtRaw = $row['SIM Amount'] ?? '';
    $simDateIso = null;
    if ($hasSims) {
        $simNo = trim((string) ($row['SIM Number'] ?? ''));
        $simDateRaw = trim((string) ($row['SIM Date'] ?? ''));

        if ($simNo === '') {
            $rowErrors[] = "Row {$rowNumber}: SIM Number is required for SIM allocation.";
        } else {
            if (isset($seenSimNos[$simNo])) {
                $rowErrors[] = "Row {$rowNumber}: Duplicate SIM Number '{$simNo}' in uploaded Excel.";
            }
            $seenSimNos[$simNo] = true;

            $simStmt = $conn->prepare("SELECT id, sim_no, sim_validity_id, status FROM sims WHERE sim_no = ? LIMIT 1");
            $simStmt->bind_param('s', $simNo);
            $simStmt->execute();
            $simRow = $simStmt->get_result()->fetch_assoc();
            $simStmt->close();

            if (!$simRow) {
                $rowErrors[] = "Row {$rowNumber}: SIM Number '{$simNo}' not found.";
            } elseif (strtolower((string)$simRow['status']) !== 'available') {
                $rowErrors[] = "Row {$rowNumber}: SIM Number '{$simNo}' is already allocated or used.";
            }
        }

        if ($simDateRaw === '') {
            $rowErrors[] = "Row {$rowNumber}: SIM Date is required.";
        } else {
            $parsedSimDate = parseAndNormalizeDate($simDateRaw);
            if ($parsedSimDate === false || isFutureDate($parsedSimDate)) {
                $rowErrors[] = "Row {$rowNumber}: Invalid or future SIM Date.";
            } else {
                $simDateIso = $parsedSimDate;
            }
        }

        if ($dealer && $dealer['installation_status'] === 'Not Willing') {
            if ($simAmtRaw === '' || !is_numeric($simAmtRaw) || (float)$simAmtRaw < 0) {
                $rowErrors[] = "Row {$rowNumber}: SIM Amount is required for Not Willing dealer.";
            }
        }
    }

    // 5. Software Validation
    $software = trim((string) ($row['Software'] ?? ''));
    if ($software !== '') {
        if (!in_array($software, $allowedSoftware, true)) {
            $rowErrors[] = "Row {$rowNumber}: Invalid Software.";
        } elseif ($dealer) {
            $swStmt = $conn->prepare("SELECT software FROM dealer_software WHERE dealer_id = ?");
            $swStmt->bind_param("i", $dealer['id']);
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
                $dSwStmt->bind_param("i", $dealer['id']);
                $dSwStmt->execute();
                $dSwRow = $dSwStmt->get_result()->fetch_assoc();
                $dSwStmt->close();

                if (!empty($dSwRow['software'])) {
                    $dealerSwList = array_values(array_filter(array_map('trim', explode(',', $dSwRow['software']))));
                }
            }

            if (!empty($dealerSwList) && !in_array($software, $dealerSwList, true)) {
                $rowErrors[] = "Row {$rowNumber}: Software '{$software}' is not assigned to dealer '{$dealerName}'.";
            }
        }
    }

    // 6. Amounts & Payment Calculation
    $devAmt = is_numeric($devAmtRaw) ? (float)$devAmtRaw : 0.0;
    $simAmt = is_numeric($simAmtRaw) ? (float)$simAmtRaw : 0.0;
    $totalAmountRaw = $row['Total Amount'] ?? '';
    $totalAmount = ($devAmt + $simAmt) > 0 ? ($devAmt + $simAmt) : (is_numeric($totalAmountRaw) ? (float)$totalAmountRaw : 0.0);

    if ($dealer && $dealer['installation_status'] === 'Not Willing' && $totalAmount <= 0) {
        $rowErrors[] = "Row {$rowNumber}: Total Amount is required and must be greater than 0 for Not Willing dealer.";
    }

    $amountPaidRaw = $row['Amount Paid'] ?? '';
    $amountPaid = is_numeric($amountPaidRaw) ? (float)$amountPaidRaw : 0.0;

    if ($amountPaid < 0) {
        $rowErrors[] = "Row {$rowNumber}: Amount Paid cannot be negative.";
    }
    if ($amountPaid > $totalAmount) {
        $rowErrors[] = "Row {$rowNumber}: Amount Paid cannot exceed Total Amount.";
    }

    $pendingAmount = max(0, $totalAmount - $amountPaid);
    $paymentStatus = $totalAmount <= 0 ? 'Not Paid' : ($pendingAmount <= 0 ? 'Paid' : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid'));

    // 7. Payment Mode & Transaction ID Validation
    $paymentMode = trim((string) ($row['Payment Mode'] ?? ''));
    $transactionId = trim((string) ($row['Transaction ID'] ?? ''));

    if ($paymentStatus === 'Partially Paid' || $paymentStatus === 'Paid') {
        if ($paymentMode === '') {
            $rowErrors[] = "Row {$rowNumber}: Payment Mode is required for {$paymentStatus} payment.";
        } elseif (!in_array($paymentMode, $allowedPaymentModes, true)) {
            $rowErrors[] = "Row {$rowNumber}: Invalid Payment Mode.";
        }

        if ($paymentMode !== '' && $paymentMode !== 'Cash' && $transactionId === '') {
            $rowErrors[] = "Row {$rowNumber}: Transaction ID is required for non-cash payment mode.";
        }
    }

    if (!empty($rowErrors)) {
        $errors = array_merge($errors, $rowErrors);
    } else {
        $validRows[] = [
            'dealer_id' => $dealer['id'],
            'allocation_type' => $allocationType,
            'has_devices' => $hasDevices,
            'has_sims' => $hasSims,
            'device_id' => $deviceRow ? $deviceRow['id'] : null,
            'device_date' => $devDateIso,
            'device_amount' => $devAmt,
            'device_notes' => trim((string) ($row['Device Notes'] ?? '')),
            'sim_id' => $simRow ? $simRow['id'] : null,
            'sim_validity_id' => $simRow ? (int)($simRow['sim_validity_id'] ?? 0) : 0,
            'sim_date' => $simDateIso,
            'sim_amount' => $simAmt,
            'sim_notes' => trim((string) ($row['SIM Notes'] ?? '')),
            'software' => $software,
            'total_amount' => $totalAmount,
            'amount_paid' => $amountPaid,
            'pending_amount' => $pendingAmount,
            'payment_status' => $paymentStatus,
            'payment_mode' => $paymentMode ?: null,
            'transaction_id' => ($paymentMode && $paymentMode !== 'Cash') ? $transactionId : null
        ];
    }
}

if (!empty($errors)) {
    $conn->close();
    sendResponse(false, 'Excel validation failed.', ['errors' => array_values(array_unique($errors))], [], 400);
}

$conn->begin_transaction();
try {
    $devAllocStmt = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, device_id, allocation_type, allocation_date, device_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, transaction_id, software, notes) VALUES ('dealer', ?, ?, 'dealer', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $simAllocStmt = $conn->prepare("INSERT INTO stock_allocations (owner_type, owner_id, sim_id, allocation_type, allocation_date, sim_given_date, sim_validity_id, sim_status, sim_amount, total_amount, amount_paid, pending_amount, payment_status, payment_mode, transaction_id, software, notes) VALUES ('dealer', ?, ?, 'dealer', ?, ?, NULLIF(?, 0), 'Available', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updDevStmt = $conn->prepare("UPDATE devices SET status = 'allocated' WHERE id = ?");
    $updSimStmt = $conn->prepare("UPDATE sims SET status = 'allocated' WHERE id = ?");

    foreach ($validRows as $item) {
        $dId = $item['dealer_id'];
        $mode = $item['payment_mode'];
        $tx = $item['transaction_id'];
        $sw = $item['software'];
        $remPaid = $item['amount_paid'];

        $hasDev = $item['has_devices'] && $item['device_id'];
        $hasSim = $item['has_sims'] && $item['sim_id'];

        if ($hasDev && $hasSim) {
            $devTot = $item['device_amount'];
            $simTot = $item['sim_amount'];
            if ($devTot + $simTot <= 0 && $item['total_amount'] > 0) {
                $devTot = $item['total_amount'] / 2;
                $simTot = $item['total_amount'] / 2;
            }
        } elseif ($hasDev) {
            $devTot = $item['total_amount'] > 0 ? $item['total_amount'] : $item['device_amount'];
            $simTot = 0;
        } elseif ($hasSim) {
            $simTot = $item['total_amount'] > 0 ? $item['total_amount'] : $item['sim_amount'];
            $devTot = 0;
        } else {
            $devTot = 0;
            $simTot = 0;
        }

        if ($hasDev) {
            $devAmt = $item['device_amount'] > 0 ? $item['device_amount'] : $devTot;
            $devPaid = min($remPaid, $devTot);
            $remPaid -= $devPaid;
            $devPend = max(0, $devTot - $devPaid);
            $devStatus = $devTot <= 0 ? 'Not Paid' : ($devPend <= 0 ? 'Paid' : ($devPaid > 0 ? 'Partially Paid' : 'Not Paid'));
            $notes = $item['device_notes'];
            $date = $item['device_date'];

            $devAllocStmt->bind_param('iissddddssss', $dId, $item['device_id'], $date, $devAmt, $devTot, $devPaid, $devPend, $devStatus, $mode, $tx, $sw, $notes);
            if (!$devAllocStmt->execute()) throw new Exception("Failed to allocate device: " . $devAllocStmt->error);
            $allocationId = (int) $conn->insert_id;
            $snapshotStmt = $conn->prepare('SELECT sa.*, d.imei_no, d.device_model_id, dt.device_type AS device_model, dl.dealer_name AS owner_name FROM stock_allocations sa LEFT JOIN devices d ON d.id = sa.device_id LEFT JOIN device_types dt ON dt.id = d.device_model_id LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = "dealer" WHERE sa.id = ? LIMIT 1');
            $snapshotStmt->bind_param('i', $allocationId);
            $snapshotStmt->execute();
            $allocationSnapshot = $snapshotStmt->get_result()->fetch_assoc() ?: [];
            $snapshotStmt->close();
            $allocationSnapshot['_audit'] = ['source' => 'Excel Import', 'file' => $file['name']];
            writeAuditSnapshot($conn, $allocationId, 'Stock Allocation', 'Create', null, $allocationSnapshot, $currentUser);
            $updDevStmt->bind_param('i', $item['device_id']);
            if (!$updDevStmt->execute()) throw new Exception("Failed to update device status: " . $updDevStmt->error);
        }

        if ($hasSim) {
            $simAmt = $item['sim_amount'] > 0 ? $item['sim_amount'] : $simTot;
            $simPaid = min($remPaid, $simTot);
            $remPaid -= $simPaid;
            $simPend = max(0, $simTot - $simPaid);
            $simStatus = $simTot <= 0 ? 'Not Paid' : ($simPend <= 0 ? 'Paid' : ($simPaid > 0 ? 'Partially Paid' : 'Not Paid'));
            $notes = $item['sim_notes'];
            $date = $item['sim_date'];
            $validityId = $item['sim_validity_id'];

            $simAllocStmt->bind_param('iisssddddsssss', $dId, $item['sim_id'], $date, $date, $validityId, $simAmt, $simTot, $simPaid, $simPend, $simStatus, $mode, $tx, $sw, $notes);
            if (!$simAllocStmt->execute()) throw new Exception("Failed to allocate SIM: " . $simAllocStmt->error);
            $allocationId = (int) $conn->insert_id;
            $snapshotStmt = $conn->prepare('SELECT sa.*, s.sim_no, s.sim_type, dl.dealer_name AS owner_name FROM stock_allocations sa LEFT JOIN sims s ON s.id = sa.sim_id LEFT JOIN dealers dl ON dl.id = sa.owner_id AND sa.owner_type = "dealer" WHERE sa.id = ? LIMIT 1');
            $snapshotStmt->bind_param('i', $allocationId);
            $snapshotStmt->execute();
            $allocationSnapshot = $snapshotStmt->get_result()->fetch_assoc() ?: [];
            $snapshotStmt->close();
            $allocationSnapshot['_audit'] = ['source' => 'Excel Import', 'file' => $file['name']];
            writeAuditSnapshot($conn, $allocationId, 'Stock Allocation', 'Create', null, $allocationSnapshot, $currentUser);
            $updSimStmt->bind_param('i', $item['sim_id']);
            if (!$updSimStmt->execute()) throw new Exception("Failed to update SIM status: " . $updSimStmt->error);
        }
    }

    $devAllocStmt->close();
    $simAllocStmt->close();
    $updDevStmt->close();
    $updSimStmt->close();

    $conn->commit();
    sendResponse(true, "Bulk stock allocation completed successfully for " . count($validRows) . " rows.", ['count' => count($validRows)]);
} catch (Exception $ex) {
    $conn->rollback();
    sendResponse(false, 'Failed to import stock allocations: ' . $ex->getMessage(), [], [], 500);
}
$conn->close();
