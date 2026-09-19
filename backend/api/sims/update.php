<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
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

/*
 * Fetch old record for history
 *
 * Validity is intentionally not part of SIM Maintenance edit.
 */
$oldStmt = $conn->prepare(
    'SELECT
        purchase_date,
        sim_no,
        sim_type,
        notes
     FROM sims
     WHERE id = ?
     LIMIT 1'
);

$oldStmt->bind_param('i', $id);
$oldStmt->execute();

$oldSim = $oldStmt->get_result()->fetch_assoc();

$oldStmt->close();

if (!$oldSim) {
    $conn->close();

    sendResponse(
        false,
        'SIM not found',
        [],
        [],
        404
    );
}

$newSim = [
    'purchase_date' => $purchaseDate,
    'sim_no' => $simNo,
    'sim_type' => $simType,
    'notes' => $notes
];

$conn->begin_transaction();

$stmt = null;

try {

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
             notes = ?
         WHERE id = ?'
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Failed to prepare SIM update.'
        );
    }

    $stmt->bind_param(
        'ssssi',
        $purchaseDate,
        $simNo,
        $simType,
        $notes,
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
        'SIM updated successfully'
    );

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