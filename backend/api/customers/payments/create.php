<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/audit.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(
        false,
        'Method not allowed',
        [],
        [],
        405
    );
}

$currentUser = authenticate();

$data = json_decode(
    file_get_contents('php://input')
);

if (!$data) {
    sendResponse(
        false,
        'Invalid request data.',
        [],
        [],
        400
    );
}

$customerId = isset($data->customer_id)
    ? (int) $data->customer_id
    : 0;
$vehicleId = isset($data->vehicle_id) ? (int) $data->vehicle_id : 0;

$paymentStep = isset($data->payment_step)
    ? (int) $data->payment_step
    : 1;

$totalSaleAmount = isset($data->total_sale_amount)
    ? (float) $data->total_sale_amount
    : 0;

$transactionId = trim(
    (string) ($data->transaction_id ?? '')
);

$paymentMode = trim(
    (string) ($data->payment_mode ?? '')
);

$deviceCharge = isset($data->device_charge)
    ? (float) $data->device_charge
    : 0;

$softwareCharge = isset($data->software_charge)
    ? (float) $data->software_charge
    : 0;

$technicianCharge = isset($data->technician_charge)
    ? (float) $data->technician_charge
    : 0;

$simCharge = isset($data->sim_charge)
    ? (float) $data->sim_charge
    : 0;

$courierCharge = isset($data->courier_charge)
    ? (float) $data->courier_charge
    : 0;

$totalAmount = isset($data->total_amount)
    ? (float) $data->total_amount
    : 0;

$amountPaid = isset($data->amount_paid)
    ? (float) $data->amount_paid
    : 0;

$amountPending = isset($data->amount_pending)
    ? (float) $data->amount_pending
    : 0;

$paymentStatus = trim(
    (string) ($data->payment_status ?? '')
);

$cashToTechnician = !empty($data->cash_to_technician);
$cashToDealer = !empty($data->cash_to_dealer);


/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if ($customerId <= 0) {
    sendResponse(
        false,
        'Customer ID is required.',
        [],
        [],
        400
    );
}

if ($paymentStep !== 1 && $paymentStep !== 2) {
    sendResponse(
        false,
        'Invalid payment step.',
        [],
        [],
        400
    );
}

if ($totalSaleAmount <= 0) {
    sendResponse(
        false,
        'Total Sale Amount is required.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Transaction ID validation
|--------------------------------------------------------------------------
*/

if (
    $transactionId !== '' &&
    !preg_match('/^[A-Za-z0-9]{1,12}$/', $transactionId)
) {
    sendResponse(
        false,
        'Transaction ID must contain exactly 6 digits.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Payment mode validation
|--------------------------------------------------------------------------
*/

$allowedPaymentModes = [
    'Cash',
    'UPI',
    'Card',
    'Bank Transfer'
];

if (
    $paymentMode !== '' &&
    !in_array(
        $paymentMode,
        $allowedPaymentModes,
        true
    )
) {
    sendResponse(
        false,
        'Invalid payment mode.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Payment Part 1 rules
|--------------------------------------------------------------------------
|
| No mode:
|   Transaction ID must also be empty.
|
| Cash:
|   Transaction ID is optional.
|
| UPI/Card/Bank Transfer:
|   Transaction ID is mandatory.
|
|--------------------------------------------------------------------------
*/

if (
    $transactionId !== '' &&
    $paymentMode === ''
) {
    sendResponse(
        false,
        'Payment Mode is required when Transaction ID is entered.',
        [],
        [],
        400
    );
}

if (
    $paymentMode !== '' &&
    $paymentMode !== 'Cash' &&
    $transactionId === ''
) {
    sendResponse(
        false,
        'Transaction ID is required for UPI, Card and Bank Transfer.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Charge validation
|--------------------------------------------------------------------------
*/

if (
    $deviceCharge < 0 ||
    $softwareCharge < 0 ||
    $technicianCharge < 0 ||
    $simCharge < 0 ||
    $courierCharge < 0
) {
    sendResponse(
        false,
        'Charges cannot be negative.',
        [],
        [],
        400
    );
}

if ($amountPaid < 0) {
    sendResponse(
        false,
        'Amount Paid cannot be negative.',
        [],
        [],
        400
    );
}

if ($paymentStep === 2) {
    if ($cashToTechnician && $cashToDealer) {
        sendResponse(false, 'Cash to Technician and Cash to Dealer are mutually exclusive.', [], [], 400);
    }

    $chargeValues = [
        'Device Charge' => $deviceCharge,
        'Software Charge' => $softwareCharge,
        'Technician Charge' => $technicianCharge,
        'SIM Charge' => $simCharge,
        'Courier Charge' => $courierCharge
    ];
    foreach ($chargeValues as $chargeName => $chargeValue) {
        if ($chargeValue > $totalSaleAmount) {
            sendResponse(false, $chargeName . ' cannot exceed Total Sale Amount.', [], [], 400);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Part 1
|--------------------------------------------------------------------------
|
| Part 1 only creates/updates the payment header.
|
| Example:
|
| Sale Amount = 4000
| Mode        = empty
|
| payment_status = Pending
|
| Cash:
| Sale Amount = 4000
| Mode        = Cash
| Transaction = empty
|
| Still Pending until Part 2 is completed.
|
|--------------------------------------------------------------------------
*/

if ($paymentStep === 1) {

    /*
     * Part 1 intentionally keeps charge
     * breakdown at zero.
     */
    $deviceCharge = 0;
    $softwareCharge = 0;
    $technicianCharge = 0;
    $simCharge = 0;
    $courierCharge = 0;

    $totalAmount = 0;
    $amountPaid = 0;
    $amountPending = 0;

    $paymentStatus = 'Pending';
}


/*
|--------------------------------------------------------------------------
| Part 2
|--------------------------------------------------------------------------
*/

if ($paymentStep === 2) {

    if (
        abs(
            $totalAmount -
            $totalSaleAmount
        ) > 0.005
    ) {

        $formattedSaleAmount = rtrim(
            rtrim(
                number_format(
                    $totalSaleAmount,
                    2,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
        );

        sendResponse(
            false,
            'Total Amount must equal Total Sale Amount (₹' .
                $formattedSaleAmount .
                ').',
            [],
            [],
            400
        );
    }

    if ($amountPaid > $totalAmount) {
        sendResponse(
            false,
            'Amount Paid cannot exceed Total Amount.',
            [],
            [],
            400
        );
    }

    /*
     * Always calculate pending on server.
     */
    $amountPending = round(
        max(
            0,
            $totalAmount - $amountPaid
        ),
        2
    );

    /*
     * Always calculate status on server.
     */
    if ($amountPaid <= 0) {

        $paymentStatus = ($paymentStatus === 'Pending') ? 'Pending' : 'Not Paid';

    } elseif ($amountPaid < $totalAmount) {

        $paymentStatus = 'Partially Paid';

    } else {

        $paymentStatus = 'Paid';
    }
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}

try {

    /*
     * ---------------------------------------------------------------
     * Customer validation
     * ---------------------------------------------------------------
     */

    $customerStmt = $conn->prepare(
        'SELECT *
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );

    if (!$customerStmt) {
        throw new Exception(
            'Failed to prepare customer query.'
        );
    }

    $customerStmt->bind_param(
        'i',
        $customerId
    );

    if (!$customerStmt->execute()) {
        $customerStmt->close();

        throw new Exception(
            'Failed to validate customer.'
        );
    }

    $customerResult =
        $customerStmt->get_result();

    if ($customerResult->num_rows === 0) {
        $customerStmt->close();

        throw new Exception(
            'Customer not found.'
        );
    }

    $customerStmt->close();


    /*
     * ---------------------------------------------------------------
     * Transaction
     * ---------------------------------------------------------------
     */

    $conn->begin_transaction();

    if ($vehicleId > 0) {
        $vehicleCheck = $conn->prepare('SELECT id FROM customer_vehicle_details WHERE id = ? AND customer_id = ? LIMIT 1');
        $vehicleCheck->bind_param('ii', $vehicleId, $customerId);
        $vehicleCheck->execute();
        if ($vehicleCheck->get_result()->num_rows === 0) {
            $vehicleCheck->close();
            throw new Exception('Selected customer vehicle was not found.');
        }
        $vehicleCheck->close();
    }

    $completeCustomerStock = static function ($conn, $customerId, $vehicleId) {
        $vehicleStmt = $conn->prepare('SELECT device_id, sim_id_1, sim_id_2 FROM customer_vehicle_details WHERE ' . ($vehicleId > 0 ? 'id = ?' : 'customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 1') . ' FOR UPDATE');
        $stockTargetId = $vehicleId > 0 ? $vehicleId : $customerId;
        $vehicleStmt->bind_param('i', $stockTargetId);
        $vehicleStmt->execute();
        $vehicle = $vehicleStmt->get_result()->fetch_assoc();
        $vehicleStmt->close();
        if (!$vehicle) {
            throw new Exception('Customer vehicle details are required before completing payment.');
        }

        $consume = static function ($conn, $assetType, $assetId, $customerId) {
            if (!$assetId) {
                return;
            }
            $column = $assetType === 'device' ? 'device_id' : 'sim_id';
            $table = $assetType === 'device' ? 'devices' : 'sims';
            $assetStmt = $conn->prepare("SELECT id, status FROM {$table} WHERE id = ? FOR UPDATE");
            $assetStmt->bind_param('i', $assetId);
            $assetStmt->execute();
            $asset = $assetStmt->get_result()->fetch_assoc();
            $assetStmt->close();
            if (!$asset) {
                throw new Exception('Customer stock asset was not found.');
            }

            $allocationStmt = $conn->prepare("SELECT owner_type, owner_id FROM stock_allocations WHERE {$column} = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE");
            $allocationStmt->bind_param('i', $assetId);
            $allocationStmt->execute();
            $allocation = $allocationStmt->get_result()->fetch_assoc();
            $allocationStmt->close();

            if ($allocation) {
                $allocationOwnerType = (string) $allocation['owner_type'];
                $allocationOwnerId = (int) $allocation['owner_id'];
                $transactionStmt = $conn->prepare("SELECT id FROM stock_transactions WHERE {$column} = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET' LIMIT 1");
                $transactionStmt->bind_param('isi', $assetId, $allocationOwnerType, $allocationOwnerId);
                $transactionStmt->execute();
                $hasEtTransaction = $transactionStmt->get_result()->num_rows > 0;
                $transactionStmt->close();
                if (!$hasEtTransaction) {
                    $transactionStmt = $conn->prepare("INSERT INTO stock_transactions ({$column}, from_owner_type, from_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, ?, ?, 'USE', 'ET', CURDATE(), ?)");
                    $notes = 'Customer vehicle completion';
                    $transactionStmt->bind_param('isis', $assetId, $allocationOwnerType, $allocationOwnerId, $notes);
                    if (!$transactionStmt->execute()) {
                        throw new Exception('Failed to record customer stock usage.');
                    }
                    $transactionStmt->close();
                }
            }

            $statusStmt = $conn->prepare("UPDATE {$table} SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statusStmt->bind_param('i', $assetId);
            if (!$statusStmt->execute()) {
                throw new Exception('Failed to mark customer stock asset as used.');
            }
            $statusStmt->close();
        };

        $consume($conn, 'device', (int) ($vehicle['device_id'] ?? 0), $customerId);
        $consume($conn, 'sim', (int) ($vehicle['sim_id_1'] ?? 0), $customerId);
        $consume($conn, 'sim', (int) ($vehicle['sim_id_2'] ?? 0), $customerId);
    };

    $syncCashCollection = static function ($conn, int $customerId, int $vehicleId, int $paymentId, float $totalAmount, bool $cashToTechnician, bool $cashToDealer, array $currentUser): void {
        $selectedType = $cashToTechnician ? 'Technician' : ($cashToDealer ? 'Dealer' : null);
        $existingStmt = $conn->prepare('SELECT * FROM customer_cash_collections WHERE customer_id = ? AND payment_id = ? LIMIT 1 FOR UPDATE');
        $existingStmt->bind_param('ii', $customerId, $paymentId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if ($selectedType === null) {
            if ($existing) {
                $deleteStmt = $conn->prepare('DELETE FROM customer_cash_collections WHERE id = ?');
                $collectionId = (int) $existing['id'];
                $deleteStmt->bind_param('i', $collectionId);
                if (!$deleteStmt->execute()) throw new Exception('Failed to remove customer cash collection.');
                $deleteStmt->close();
                writeDeleteSnapshot($conn, $collectionId, 'Customer Cash Collection', $existing, $currentUser);
            }
            return;
        }

        $installationStmt = $conn->prepare('SELECT id, installation_person_type, installation_person_id FROM customer_installations WHERE ' . ($vehicleId > 0 ? 'vehicle_id = ?' : 'customer_id = ? ORDER BY created_at DESC, id DESC LIMIT 1') . ' FOR UPDATE');
        $installationTargetId = $vehicleId > 0 ? $vehicleId : $customerId;
        $installationStmt->bind_param('i', $installationTargetId);
        $installationStmt->execute();
        $installation = $installationStmt->get_result()->fetch_assoc();
        $installationStmt->close();
        if (!$installation) throw new Exception('Installation details are required before assigning customer cash.');
        $installationPersonType = $installation['installation_person_type'];
        if ($installationPersonType === '') {
            $dealerCheck = $conn->prepare('SELECT id FROM dealers WHERE id = ? LIMIT 1');
            $installationPersonId = (int) $installation['installation_person_id'];
            $dealerCheck->bind_param('i', $installationPersonId);
            $dealerCheck->execute();
            $isDealer = $dealerCheck->get_result()->num_rows > 0;
            $dealerCheck->close();
            $installationPersonType = $isDealer ? 'Dealer' : 'Technician';
        }
        $installationRecipientType = in_array(
            $installationPersonType,
            ['Onsite Dealer', 'Offsite Dealer', 'Dealer'],
            true
        ) ? 'Dealer' : $installationPersonType;
        if ($installationRecipientType !== $selectedType) {
            throw new Exception('Cash recipient must match the installation person type.');
        }

        $recipientId = (int) $installation['installation_person_id'];
        $amountCollected = round($totalAmount, 2);
        $amountRemitted = $existing ? round((float) $existing['amount_remitted'], 2) : 0.0;
        if ($amountRemitted > $amountCollected) {
            throw new Exception('Collected amount cannot be less than the amount already remitted.');
        }
        $pendingAmount = round($amountCollected - $amountRemitted, 2);
        $status = $pendingAmount <= 0 ? 'Paid' : ($amountRemitted > 0 ? 'Partially Paid' : 'Pending');

        if ($existing && ($existing['recipient_type'] !== $selectedType || (int) $existing['recipient_id'] !== $recipientId)) {
            $deleteStmt = $conn->prepare('DELETE FROM customer_cash_collections WHERE id = ?');
            $collectionId = (int) $existing['id'];
            $deleteStmt->bind_param('i', $collectionId);
            if (!$deleteStmt->execute()) throw new Exception('Failed to replace customer cash collection.');
            $deleteStmt->close();
            $existing = null;
        }

        if ($existing) {
            $updateStmt = $conn->prepare('UPDATE customer_cash_collections SET amount_collected = ?, pending_amount = ?, settlement_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $collectionId = (int) $existing['id'];
            $updateStmt->bind_param('ddsi', $amountCollected, $pendingAmount, $status, $collectionId);
            if (!$updateStmt->execute()) throw new Exception('Failed to update customer cash collection.');
            $updateStmt->close();
            writeChangedFields($conn, $collectionId, 'Customer Cash Collection', $existing, ['amount_collected' => $amountCollected, 'pending_amount' => $pendingAmount, 'settlement_status' => $status], $currentUser);
            return;
        }

        $insertStmt = $conn->prepare('INSERT INTO customer_cash_collections (customer_id, payment_id, installation_id, recipient_type, recipient_id, amount_collected, amount_remitted, pending_amount, settlement_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $installationId = (int) $installation['id'];
        $insertStmt->bind_param('iiisiddds', $customerId, $paymentId, $installationId, $selectedType, $recipientId, $amountCollected, $amountRemitted, $pendingAmount, $status);
        if (!$insertStmt->execute()) throw new Exception('Failed to create customer cash collection.');
        $collectionId = $insertStmt->insert_id;
        $insertStmt->close();
        writeCreatedFields($conn, $collectionId, 'Customer Cash Collection', ['customer_id' => $customerId, 'payment_id' => $paymentId, 'recipient_type' => $selectedType, 'recipient_id' => $recipientId, 'amount_collected' => $amountCollected, 'amount_remitted' => $amountRemitted, 'pending_amount' => $pendingAmount, 'settlement_status' => $status], $currentUser);
    };


    /*
     * ---------------------------------------------------------------
     * Check existing payment row
     * ---------------------------------------------------------------
     */

    $paymentWhere = $vehicleId > 0 ? 'vehicle_id = ?' : 'customer_id = ?';
    $checkStmt = $conn->prepare(
        "SELECT id
         FROM customer_payments
         WHERE {$paymentWhere}
         ORDER BY created_at DESC, id DESC
         LIMIT 1
         FOR UPDATE"
    );

    if (!$checkStmt) {
        throw new Exception(
            'Failed to prepare existing payment check.'
        );
    }

    $paymentTargetId = $vehicleId > 0 ? $vehicleId : $customerId;
    $checkStmt->bind_param('i', $paymentTargetId);

    if (!$checkStmt->execute()) {
        $checkStmt->close();

        throw new Exception(
            'Failed to check existing payment.'
        );
    }

    $existingResult =
        $checkStmt->get_result();

    $existingPaymentId = null;

    if ($existingResult->num_rows > 0) {

        $existingRow =
            $existingResult->fetch_assoc();

        $existingPaymentId =
            (int) $existingRow['id'];
    }

    $checkStmt->close();


    /*
     * ---------------------------------------------------------------
     * Prepare nullable values
     * ---------------------------------------------------------------
     */

    $transactionIdValue =
        $transactionId === ''
            ? null
            : $transactionId;

    $paymentModeValue =
        $paymentMode === ''
            ? null
            : $paymentMode;


    /*
     * ---------------------------------------------------------------
     * UPDATE existing payment
     * ---------------------------------------------------------------
     */

if ($existingPaymentId !== null) {
    $paymentChanges = [
        'total_sale_amount' => $totalSaleAmount,
        'transaction_id' => $transactionIdValue,
        'payment_mode' => $paymentModeValue
    ];
    if ($paymentStep !== 1) {
        $paymentChanges += [
            'device_charge' => $deviceCharge,
            'software_charge' => $softwareCharge,
            'technician_charge' => $technicianCharge,
            'sim_charge' => $simCharge,
            'courier_charge' => $courierCharge,
            'total_amount' => $totalAmount,
            'amount_paid' => $amountPaid,
            'amount_pending' => $amountPending,
            'payment_status' => $paymentStatus
        ];
    }
    writeChangedFields($conn, $existingPaymentId, 'Customer Payment', $existingRow, $paymentChanges, $currentUser);
    if ($paymentStep === 1) {
        // Only update header fields for step 1
        $updateStmt = $conn->prepare(
            'UPDATE customer_payments
             SET total_sale_amount = ?,
                 transaction_id = ?,
                 payment_mode = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$updateStmt) {
            throw new Exception('Failed to prepare payment update (step 1).');
        }
        $bindResult = $updateStmt->bind_param(
            'dssi',
            $totalSaleAmount,
            $transactionIdValue,
            $paymentModeValue,
            $existingPaymentId
        );
    } else {
        // Full update for step 2
        $updateStmt = $conn->prepare(
            'UPDATE customer_payments
             SET total_sale_amount = ?,
                 transaction_id = ?,
                 payment_mode = ?,
                 device_charge = ?,
                 software_charge = ?,
                 technician_charge = ?,
                 sim_charge = ?,
                 courier_charge = ?,
                 total_amount = ?,
                 amount_paid = ?,
                 amount_pending = ?,
                 payment_status = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$updateStmt) {
            throw new Exception('Failed to prepare payment update.');
        }
        $bindResult = $updateStmt->bind_param(
            'dssddddddddsi',
            $totalSaleAmount,
            $transactionIdValue,
            $paymentModeValue,
            $deviceCharge,
            $softwareCharge,
            $technicianCharge,
            $simCharge,
            $courierCharge,
            $totalAmount,
            $amountPaid,
            $amountPending,
            $paymentStatus,
            $existingPaymentId
        );
    }
    if (!$bindResult) {
        $updateStmt->close();
        throw new Exception('Failed to bind payment update parameters.');
    }
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        throw new Exception('Failed to update payment details.');
    }
    $updateStmt->close();
    if ($paymentStep === 2) {
        $syncCashCollection($conn, $customerId, $vehicleId, $existingPaymentId, $totalAmount, $cashToTechnician, $cashToDealer, $currentUser);
        $completeCustomerStock($conn, $customerId, $vehicleId);
    }
    $conn->commit();
    $conn->close();
    sendResponse(
        true,
        $paymentStep === 1
            ? 'Payment Part 1 updated successfully.'
            : 'Payment details updated successfully.',
        [
            'customer_id' => $customerId,
            'payment_id' => $existingPaymentId,
            'action' => 'update'
        ]
    );
}


    /*
     * ---------------------------------------------------------------
     * INSERT new payment
     * ---------------------------------------------------------------
     */

    $insertStmt = $conn->prepare(
        'INSERT INTO customer_payments (
            customer_id,
            vehicle_id,
            total_sale_amount,
            transaction_id,
            payment_mode,
            device_charge,
            software_charge,
            technician_charge,
            sim_charge,
            courier_charge,
            total_amount,
            amount_paid,
            amount_pending,
            payment_status
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$insertStmt) {
        throw new Exception(
            'Failed to prepare payment insert.'
        );
    }

    $paymentVehicleId = $vehicleId > 0 ? $vehicleId : null;
    $bindResult = $insertStmt->bind_param(
        'iidssdddddddds',
        $customerId,
        $paymentVehicleId,
        $totalSaleAmount,
        $transactionIdValue,
        $paymentModeValue,
        $deviceCharge,
        $softwareCharge,
        $technicianCharge,
        $simCharge,
        $courierCharge,
        $totalAmount,
        $amountPaid,
        $amountPending,
        $paymentStatus
    );

    if (!$bindResult) {
        $insertStmt->close();

        throw new Exception(
            'Failed to bind payment insert parameters.'
        );
    }

    if (!$insertStmt->execute()) {
        $insertStmt->close();

        throw new Exception(
            'Failed to create payment details.'
        );
    }

    $paymentId =
        $insertStmt->insert_id;

    $insertStmt->close();

    writeCreatedFields($conn, $paymentId, 'Customer Payment', [
        'customer_id' => $customerId,
        'total_sale_amount' => $totalSaleAmount,
        'transaction_id' => $transactionIdValue,
        'payment_mode' => $paymentModeValue,
        'device_charge' => $deviceCharge,
        'software_charge' => $softwareCharge,
        'technician_charge' => $technicianCharge,
        'sim_charge' => $simCharge,
        'courier_charge' => $courierCharge,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_pending' => $amountPending,
        'payment_status' => $paymentStatus
    ], $currentUser);

    if ($paymentStep === 2) {
        $syncCashCollection($conn, $customerId, $vehicleId, $paymentId, $totalAmount, $cashToTechnician, $cashToDealer, $currentUser);
        $completeCustomerStock($conn, $customerId, $vehicleId);
    }


    /*
     * ---------------------------------------------------------------
     * Commit
     * ---------------------------------------------------------------
     */

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        $paymentStep === 1
            ? 'Payment Part 1 saved successfully.'
            : 'Payment details saved successfully.',
        [
            'customer_id' => $customerId,
            'payment_id' => $paymentId,
            'action' => 'insert'
        ]
    );

} catch (Throwable $e) {

    if ($conn) {
        $conn->rollback();
        $conn->close();
    }

    error_log(
        'Customer Payment Error: ' .
        $e->getMessage()
    );

    sendResponse(
        false,
        $e->getMessage(),
        [],
        [],
        400
    );
}

?>