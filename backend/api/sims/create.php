<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/date.php';
require_once '../../utils/validation.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();
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

try {
    $stmt = $conn->prepare("INSERT INTO sims (purchase_date, sim_no, sim_type, sim_validity_id, notes) VALUES (?, ?, ?, ?, ?)");
    
    $seenSims = [];

    foreach ($data->sims as $index => $sim) {
        $rowNum = $index + 1;
        
        if (empty($sim->purchase_date)) throw new Exception("Row $rowNum: Purchase date is required.");
        if (isFutureDate($sim->purchase_date)) throw new Exception("Row $rowNum: Future dates are not allowed.");
        if (empty($sim->sim_no)) throw new Exception("Row $rowNum: SIM number is required.");
        $simType = trim((string) ($sim->sim_type ?? ''));
        $simValidityId = (int) ($sim->sim_validity_id ?? 0);
        if (!in_array($simType, ['Voice', 'Non Voice'], true)) throw new Exception("Row $rowNum: SIM type must be Voice or Non Voice.");
        if ($simValidityId <= 0) throw new Exception("Row $rowNum: SIM validity is required.");
        
        if (!preg_match('/^(?:[0-9]{10}|[0-9]{13})$/', $sim->sim_no)) {
            throw new Exception("Row $rowNum: SIM number must contain exactly 10 OR exactly 13 digits.");
        }
        
        if (in_array($sim->sim_no, $seenSims)) {
            throw new Exception("Row $rowNum: Duplicate SIM number ({$sim->sim_no}) found in the request.");
        }
        $seenSims[] = $sim->sim_no;

        $validityCheck = $conn->prepare('SELECT id FROM sim_validities WHERE id = ? LIMIT 1');
        $validityCheck->bind_param('i', $simValidityId);
        $validityCheck->execute();
        if ($validityCheck->get_result()->num_rows === 0) { $validityCheck->close(); throw new Exception("Row $rowNum: SIM validity does not exist."); }
        $validityCheck->close();
        
        // Check uniqueness in DB
        $checkStmt = $conn->prepare("SELECT id FROM sims WHERE sim_no = ?");
        $checkStmt->bind_param("s", $sim->sim_no);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception("Row $rowNum: SIM number already exists.");
        }
        $checkStmt->close();
        
        $notes = trim((string) ($sim->notes ?? ''));
        $stmt->bind_param("sssis", $sim->purchase_date, $sim->sim_no, $simType, $simValidityId, $notes);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) throw new Exception("Row $rowNum: SIM number already exists.");
            throw new Exception("Row $rowNum: Database error - " . $stmt->error);
        }
    }
    
    $conn->commit();
    sendResponse(true, "SIMs added successfully");

} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage(), [], [], 400);
}

$stmt->close();
$conn->close();
?>
