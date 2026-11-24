<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../utils/inventory_status.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('sims.edit');

$data = json_decode(file_get_contents('php://input'));

if (!$data || !isset($data->id)) {
    sendResponse(false, 'SIM ID is required', [], [], 400);
}

$id = (int) $data->id;

$purchaseDate = trim(
    (string) ($data->purchase_date ?? '')
);

$simNo = trim(
    (string) ($data->sim_no ?? '')
);

$simType = trim(
    (string) ($data->sim_type ?? '')
);

$statusProvided = property_exists($data, 'status');
$requestedStatus = $statusProvided
    ? strtolower(trim((string) $data->status))
    : null;

$notes = trim(
    (string) ($data->notes ?? '')
);

if ($purchaseDate === '') {
    sendResponse(
        false,
        'Purchase date is required',
        [],
        [],
        400
    );
}

if (isFutureDate($purchaseDate)) {
    sendResponse(
        false,
        'Future dates are not allowed',
        [],
        [],
        400
    );
}

if (!preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $simNo)) {
    sendResponse(
        false,
        'SIM number must contain exactly 10 or 13 digits',
        [],
        [],
        400
    );
}

if (!in_array($simType, ['Voice', 'Non Voice'], true)) {
    sendResponse(
        false,
        'SIM type must be Voice or Non Voice',
        [],
        [],
        400
    );
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed',
        [],
        [],
        500
    );
}

/*
 * Check duplicate SIM number
 */
$checkStmt = $conn->prepare(
    'SELECT id
     FROM sims
     WHERE sim_no = ?
       AND id != ?
     LIMIT 1'
);

$checkStmt->bind_param(
    'si',
    $simNo,
    $id
);

$checkStmt->execute();

if ($checkStmt->get_result()->num_rows > 0) {
    $checkStmt->close();
    $conn->close();

    sendResponse(
        false,
        'SIM number already exists.',
        [],
        [],
        400
    );
}

$checkStmt->close();

$conn->begin_transaction();
$stmt = null;

try {
    /*
     * Lock the SIM so assignment checks and the manual status update cannot
     * race with allocation, usage, or courier workflows.
     */
    $oldStmt = $conn->prepare(
        'SELECT purchase_date, sim_no, sim_type, notes, status
         FROM sims
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE'
    );
    if (!$oldStmt) {
        throw new RuntimeException('Failed to prepare SIM lookup.');
    }
    $oldStmt->bind_param('i', $id);
    if (!$oldStmt->execute()) {
        $error = $oldStmt->error;
        $oldStmt->close();
        throw new RuntimeException('Failed to load SIM: ' . $error);
    }
    $oldSim = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    if (!$oldSim) {
        throw new OutOfBoundsException('SIM not found');
    }

    $statusContext = lockInventoryStatusContext($conn, 'sim', $id);
    if (!$statusContext) {
        throw new OutOfBoundsException('SIM not found');
    }
    $currentStatus = $statusContext['effective_status'];
    if ($statusProvided
        && !in_array($requestedStatus, ['available', 'allocated', 'used'], true)
        && !($requestedStatus === 'reserved' && $currentStatus === 'reserved')) {
        throw new InvalidArgumentException('Status must be Available, Allocated, or Used.');
    }

    $statusChanged = $statusProvided && $requestedStatus !== $currentStatus;
    if ($statusChanged) {
        $statusBlocker = inventoryStatusChangeBlocker(
            $conn,
            'sim',
            $id,
            (string) $oldSim['sim_no'],
            $statusContext['stored_status']
        );
        if ($statusBlocker !== null) {
            throw new DomainException($statusBlocker);
        }
    }

    $newSim = [
        'purchase_date' => $purchaseDate,
        'sim_no' => $simNo,
        'sim_type' => $simType,
        'notes' => $notes,
        'status' => $statusChanged ? $requestedStatus : $statusContext['stored_status']
    ];

    /*
     * Save old/new values to History
     */
    writeChangedFields(
        $conn,
        $id,
        'SIM',
        $oldSim,
        $newSim,
        $currentUser
    );

    /*
     * Update only SIM Maintenance fields
     */
    $stmt = $conn->prepare(
        'UPDATE sims
         SET purchase_date = ?,
             sim_no = ?,
             sim_type = ?,
             notes = ?,
             status = ?
         WHERE id = ?'
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare SIM update.'
        );
    }

    $stmt->bind_param(
        'sssssi',
        $purchaseDate,
        $simNo,
        $simType,
        $notes,
        $newSim['status'],
        $id
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Failed to update SIM: ' . $stmt->error
        );
    }

    $stmt->close();

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'SIM updated successfully',
        ['status' => $newSim['status']]
    );

} catch (OutOfBoundsException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 404);
} catch (InvalidArgumentException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
} catch (DomainException $e) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 409);
} catch (Throwable $e) {

    $conn->rollback();

    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $conn->close();

    sendResponse(
        false,
        $e->getMessage(),
        [],
        [],
        500
    );
}
?>