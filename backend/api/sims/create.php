<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
requirePermission('sims.add');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->sims) || !is_array($data->sims) || count($data->sims) === 0) {
    sendResponse(false, "No SIMs provided", [], [], 400);
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$conn->begin_transaction();

$stmt = null;

try {

    /*
     * SIM validity is no longer part of SIM Maintenance entry.
     */
    $stmt = $conn->prepare(
        "INSERT INTO sims
        (purchase_date, sim_no, sim_type, notes)
        VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception("Failed to prepare SIM insert.");
    }

    $seenSims = [];

    foreach ($data->sims as $index => $sim) {

        $rowNum = $index + 1;

        $purchaseDate = trim((string) ($sim->purchase_date ?? ''));
        $simNo = trim((string) ($sim->sim_no ?? ''));
        $simType = trim((string) ($sim->sim_type ?? ''));
        $notes = trim((string) ($sim->notes ?? ''));

        if ($purchaseDate === '') {
            throw new Exception(
                "Row $rowNum: Purchase date is required."
            );
        }

        if (isFutureDate($purchaseDate)) {
            throw new Exception(
                "Row $rowNum: Future dates are not allowed."
            );
        }

        if ($simNo === '') {
            throw new Exception(
                "Row $rowNum: SIM number is required."
            );
        }

        if (!in_array($simType, ['Voice', 'Non Voice'], true)) {
            throw new Exception(
                "Row $rowNum: SIM type must be Voice or Non Voice."
            );
        }

        if (!preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $simNo)) {
            throw new Exception(
                "Row $rowNum: SIM number must contain exactly 10 OR exactly 13 digits."
            );
        }

        if (in_array($simNo, $seenSims, true)) {
            throw new Exception(
                "Row $rowNum: Duplicate SIM number ($simNo) found in the request."
            );
        }

        $seenSims[] = $simNo;

        /*
         * Check uniqueness in DB
         */
        $checkStmt = $conn->prepare(
            "SELECT id FROM sims WHERE sim_no = ? LIMIT 1"
        );

        if (!$checkStmt) {
            throw new Exception("Failed to prepare duplicate check.");
        }

        $checkStmt->bind_param("s", $simNo);
        $checkStmt->execute();

        if ($checkStmt->get_result()->num_rows > 0) {
            $checkStmt->close();

            throw new Exception(
                "Row $rowNum: SIM number already exists."
            );
        }

        $checkStmt->close();

        /*
         * Insert SIM
         */
        $stmt->bind_param(
            "ssss",
            $purchaseDate,
            $simNo,
            $simType,
            $notes
        );

        if (!$stmt->execute()) {

            if ($stmt->errno === 1062) {
                throw new Exception(
                    "Row $rowNum: SIM number already exists."
                );
            }

            throw new Exception(
                "Row $rowNum: Database error - " . $stmt->error
            );
        }

        $simId = $conn->insert_id;

        /*
         * Audit
         */
        $snapshotStmt = $conn->prepare('SELECT * FROM sims WHERE id = ? LIMIT 1');
        $snapshotStmt->bind_param('i', $simId);
        $snapshotStmt->execute();
        $snapshot = $snapshotStmt->get_result()->fetch_assoc() ?: [];
        $snapshotStmt->close();
        writeAuditSnapshot($conn, $simId, 'SIM', 'Create', null, $snapshot, $currentUser);
    }

    $conn->commit();

    $stmt->close();
    $conn->close();

    sendResponse(
        true,
        "SIMs added successfully"
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
        400
    );
}
?>