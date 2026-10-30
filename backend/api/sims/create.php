<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/validation_error_response.php';

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

$stmt = null;

try {
    $conn->begin_transaction();
    $validationErrors = [];
    $validatedSims = [];
    $seenSims = [];
    foreach ($data->sims as $index => $sim) {
        $rowNum = max(1, (int) ($sim->row_number ?? ($index + 1)));
        $purchaseDateRaw = $sim->purchase_date ?? '';
        $purchaseDate = parseAndNormalizeDate($purchaseDateRaw);
        $simNo = trim((string) ($sim->sim_no ?? ''));
        $simType = trim((string) ($sim->sim_type ?? ''));
        $notes = trim((string) ($sim->notes ?? ''));
        $rowErrors = [];

        if ($purchaseDateRaw === '' || $purchaseDateRaw === null) {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Purchase Date is required.";
        } elseif ($purchaseDate === false || $purchaseDate === '') {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Date value '{$purchaseDateRaw}' is invalid.";
        } elseif (isFutureDate($purchaseDate)) {
            $rowErrors[] = "Row {$rowNum}: Purchase Date - Future date '{$purchaseDateRaw}' is not allowed.";
        }
        if ($simNo === '') {
            $rowErrors[] = "Row {$rowNum}: SIM No - SIM number is required.";
        } elseif (!preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $simNo)) {
            $rowErrors[] = "Row {$rowNum}: SIM No - Value '{$simNo}' must contain exactly 10 or 13 digits.";
        } elseif (isset($seenSims[$simNo])) {
            $rowErrors[] = "Row {$rowNum}: SIM No - Duplicate SIM number '{$simNo}' in this file.";
        } else {
            $seenSims[$simNo] = true;
        }
        if (!in_array($simType, ['Voice', 'Non Voice'], true)) {
            $rowErrors[] = "Row {$rowNum}: SIM Type - Value '{$simType}' must be Voice or Non Voice.";
        }

        if ($simNo !== '' && preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $simNo)) {
            $checkStmt = $conn->prepare('SELECT id FROM sims WHERE sim_no = ? LIMIT 1 FOR UPDATE');
            if (!$checkStmt) {
                throw new RuntimeException('Failed to prepare duplicate SIM check.');
            }
            $checkStmt->bind_param('s', $simNo);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $rowErrors[] = "Row {$rowNum}: SIM No - SIM number '{$simNo}' already exists.";
            }
            $checkStmt->close();
        }

        if ($rowErrors) {
            $validationErrors = array_merge($validationErrors, $rowErrors);
        } else {
            $validatedSims[] = [
                'row_number' => $rowNum,
                'purchase_date' => $purchaseDate,
                'sim_no' => $simNo,
                'sim_type' => $simType,
                'notes' => $notes
            ];
        }
    }

    if ($validationErrors) {
        $conn->rollback();
        $conn->close();
        sendValidationErrorResponse('Excel validation failed; no SIMs were added.', $validationErrors);
    }

    $stmt = $conn->prepare(
        'INSERT INTO sims (purchase_date, sim_no, sim_type, notes) VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare SIM insert.');
    }

    foreach ($validatedSims as $sim) {
        $stmt->bind_param('ssss', $sim['purchase_date'], $sim['sim_no'], $sim['sim_type'], $sim['notes']);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) {
                throw new RuntimeException("SIM number '{$sim['sim_no']}' already exists.");
            }
            throw new RuntimeException('Failed to add SIM: ' . $stmt->error);
        }

        $simId = $conn->insert_id;
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

    sendResponse(true, 'SIMs added successfully');

} catch (Throwable $e) {

    $conn->rollback();

    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    $conn->close();

    sendResponse(false, $e->getMessage(), [], [], 400);
}
?>