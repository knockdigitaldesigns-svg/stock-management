<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/date.php';
require_once '../../utils/dealer_sim_activation.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('dealer_sim_activation.import');

$data = json_decode(file_get_contents('php://input'), true);
$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
if (!$rows) {
    sendResponse(false, 'Excel file contains no data rows.', [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$errors = [];
$validated = [];
$seenSimNumbers = [];

try {
    $conn->begin_transaction();

    $validityResult = $conn->query(
        "SELECT id, months FROM sim_validities WHERE LOWER(COALESCE(status, 'active')) = 'active'"
    );
    if (!$validityResult) {
        throw new RuntimeException('Unable to load active SIM validities.');
    }
    $validities = [];
    while ($validity = $validityResult->fetch_assoc()) {
        $validities[(int) $validity['months']] = (int) $validity['id'];
    }

    foreach ($rows as $index => $row) {
        $rowNumber = max(2, (int) ($row['row_number'] ?? ($index + 2)));
        $simNo = trim((string) ($row['sim_no'] ?? ''));
        $dealerName = trim((string) ($row['dealer_name'] ?? ''));
        $activationDate = trim((string) ($row['activation_date'] ?? ''));
        $validityLabel = trim((string) ($row['validity'] ?? ''));
        $rowHasErrors = false;

        if ($simNo === '') {
            $errors[] = "Row {$rowNumber}: SIM No is required.";
            $rowHasErrors = true;
        } elseif (isset($seenSimNumbers[$simNo])) {
            $errors[] = "Row {$rowNumber}: SIM {$simNo} appears more than once in this file.";
            $rowHasErrors = true;
        } else {
            $seenSimNumbers[$simNo] = true;
        }

        if ($dealerName === '') {
            $errors[] = "Row {$rowNumber}: Dealer Name is required.";
            $rowHasErrors = true;
        }

        $activationDateObject = null;
        if ($activationDate === '') {
            $errors[] = "Row {$rowNumber}: Activation Date is required.";
            $rowHasErrors = true;
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) {
            $errors[] = "Row {$rowNumber}: Invalid activation date. Use DD-MM-YYYY format.";
            $rowHasErrors = true;
        } else {
            $activationDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $activationDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                !$activationDateObject ||
                ($dateErrors && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $activationDateObject->format('Y-m-d') !== $activationDate
            ) {
                $errors[] = "Row {$rowNumber}: Invalid activation date.";
                $rowHasErrors = true;
                $activationDateObject = null;
            }
        }

        $validityMonths = null;
        if ($validityLabel === '') {
            $errors[] = "Row {$rowNumber}: Validity is required.";
            $rowHasErrors = true;
        } elseif (!preg_match('/^(\d+)\s+Months?$/i', $validityLabel, $validityMatch)) {
            $errors[] = "Row {$rowNumber}: Invalid validity.";
            $rowHasErrors = true;
        } else {
            $validityMonths = (int) $validityMatch[1];
            if (!isset($validities[$validityMonths])) {
                $errors[] = "Row {$rowNumber}: Invalid validity.";
                $rowHasErrors = true;
            }
        }

        $dealerId = 0;
        if ($dealerName !== '') {
            $dealerStmt = $conn->prepare(
                'SELECT id FROM dealers WHERE LOWER(TRIM(dealer_name)) = LOWER(TRIM(?)) LIMIT 1'
            );
            if (!$dealerStmt) {
                throw new RuntimeException('Unable to prepare dealer lookup.');
            }
            $dealerStmt->bind_param('s', $dealerName);
            $dealerStmt->execute();
            $dealerRow = $dealerStmt->get_result()->fetch_assoc();
            $dealerStmt->close();
            if (!$dealerRow) {
                $errors[] = "Row {$rowNumber}: Dealer not found.";
                $rowHasErrors = true;
            } else {
                $dealerId = (int) $dealerRow['id'];
            }
        }

        $simId = 0;
        $allocationId = 0;
        $oldAllocation = null;
        if ($simNo !== '') {
            $simStmt = $conn->prepare('SELECT id, status FROM sims WHERE sim_no = ? LIMIT 1 FOR UPDATE');
            if (!$simStmt) {
                throw new RuntimeException('Unable to prepare SIM lookup.');
            }
            $simStmt->bind_param('s', $simNo);
            $simStmt->execute();
            $simRow = $simStmt->get_result()->fetch_assoc();
            $simStmt->close();

            if (!$simRow) {
                $errors[] = "Row {$rowNumber}: SIM {$simNo} not found.";
                $rowHasErrors = true;
            } else {
                $simId = (int) $simRow['id'];
                $allocationStmt = $conn->prepare(
                    "SELECT id, owner_type, owner_id, sim_status, sim_activation_date
                     FROM stock_allocations
                     WHERE sim_id = ?
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1
                     FOR UPDATE"
                );
                if (!$allocationStmt) {
                    throw new RuntimeException('Unable to prepare current SIM allocation lookup.');
                }
                $allocationStmt->bind_param('i', $simId);
                $allocationStmt->execute();
                $allocation = $allocationStmt->get_result()->fetch_assoc();
                $allocationStmt->close();

                if (!$allocation) {
                    $errors[] = "Row {$rowNumber}: SIM {$simNo} is invalid or unavailable.";
                    $rowHasErrors = true;
                } else {
                    $allocationId = (int) $allocation['id'];
                    if (
                        strtolower((string) $allocation['owner_type']) !== 'dealer' ||
                        $dealerId <= 0 ||
                        (int) $allocation['owner_id'] !== $dealerId
                    ) {
                        if ($dealerName !== '') {
                            $errors[] = "Row {$rowNumber}: SIM {$simNo} is not allocated to dealer {$dealerName}.";
                        } else {
                            $errors[] = "Row {$rowNumber}: SIM {$simNo} is not allocated to the specified dealer.";
                        }
                        $rowHasErrors = true;
                    } elseif (
                        strtolower((string) ($allocation['sim_status'] ?? 'available')) === 'active' ||
                        !empty($allocation['sim_activation_date'])
                    ) {
                        $errors[] = "Row {$rowNumber}: SIM {$simNo} is already activated.";
                        $rowHasErrors = true;
                    } elseif (strtolower((string) $simRow['status']) !== 'allocated') {
                        $errors[] = "Row {$rowNumber}: SIM {$simNo} is invalid or unavailable.";
                        $rowHasErrors = true;
                    } else {
                        $usedStmt = $conn->prepare(
                            'SELECT id FROM customer_vehicle_details WHERE sim_id_1 = ? OR sim_id_2 = ? LIMIT 1 FOR UPDATE'
                        );
                        if (!$usedStmt) {
                            throw new RuntimeException('Unable to prepare SIM usage lookup.');
                        }
                        $usedStmt->bind_param('ii', $simId, $simId);
                        $usedStmt->execute();
                        $isUsed = $usedStmt->get_result()->num_rows > 0;
                        $usedStmt->close();
                        if ($isUsed) {
                            $errors[] = "Row {$rowNumber}: SIM {$simNo} is already used by a customer.";
                            $rowHasErrors = true;
                        } else {
                            $transactionStmt = $conn->prepare(
                                "SELECT id
                                 FROM stock_transactions
                                 WHERE sim_id = ?
                                   AND from_owner_type = 'dealer'
                                   AND from_owner_id = ?
                                   AND id = (
                                       SELECT MAX(latest.id)
                                       FROM stock_transactions latest
                                       WHERE latest.sim_id = ?
                                         AND latest.from_owner_type = 'dealer'
                                         AND latest.from_owner_id = ?
                                   )
                                   AND transaction_type = 'USE'
                                 LIMIT 1"
                            );
                            if (!$transactionStmt) {
                                throw new RuntimeException('Unable to prepare SIM transaction lookup.');
                            }
                            $ownerId = (int) $allocation['owner_id'];
                            $transactionStmt->bind_param('iiii', $simId, $ownerId, $simId, $ownerId);
                            $transactionStmt->execute();
                            $isUsedInTransaction = $transactionStmt->get_result()->num_rows > 0;
                            $transactionStmt->close();
                            if ($isUsedInTransaction) {
                                $errors[] = "Row {$rowNumber}: SIM {$simNo} is already used by a customer.";
                                $rowHasErrors = true;
                            }
                        }
                    }

                    if (!$rowHasErrors && $dealerId > 0 && $simId > 0) {
                        $oldStmt = $conn->prepare('SELECT * FROM stock_allocations WHERE id = ? FOR UPDATE');
                        if (!$oldStmt) {
                            throw new RuntimeException('Unable to prepare SIM allocation audit lookup.');
                        }
                        $oldStmt->bind_param('i', $allocationId);
                        $oldStmt->execute();
                        $oldAllocation = $oldStmt->get_result()->fetch_assoc();
                        $oldStmt->close();
                        if (!$oldAllocation) {
                            throw new RuntimeException("SIM allocation {$allocationId} could not be locked.");
                        }
                    }
                }
            }
        }

        if (
            !$rowHasErrors &&
            $dealerId > 0 &&
            $simId > 0 &&
            $allocationId > 0 &&
            $activationDateObject &&
            $validityMonths !== null
        ) {
            $validated[] = [
                'row_number' => $rowNumber,
                'allocation_id' => $allocationId,
                'sim_id' => $simId,
                'old_allocation' => $oldAllocation,
                'activation_date' => $activationDate,
                'validity_id' => $validities[$validityMonths],
                'validity_months' => $validityMonths,
                'expiry_date' => calculateDealerSimExpiryDate($activationDate, $validityMonths)
            ];
        }
    }

    if ($errors) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, "Bulk upload failed.\n" . implode("\n", $errors), ['row_errors' => $errors], [], 422);
    }

    foreach ($validated as $activation) {
        $newStatus = 'Active';
        $newDeactivationDate = $activation['old_allocation']['sim_deactivation_date'];
        $auditFields = [
            'sim_activation_date' => $activation['activation_date'],
            'sim_validity_id' => $activation['validity_id'],
            'sim_expiry_date' => $activation['expiry_date'],
            'sim_deactivation_date' => $newDeactivationDate,
            'sim_status' => $newStatus
        ];

        saveDealerSimLifecycle(
            $conn,
            $activation['allocation_id'],
            $activation['old_allocation'],
            $auditFields,
            $currentUser
        );
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, count($validated) . ' SIMs activated successfully.', ['activated_count' => count($validated)]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 500);
}
?>
