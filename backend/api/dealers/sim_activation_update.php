<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/date.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['dealers.edit']);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($data['allocation_id'] ?? 0);
$action = trim((string) ($data['action'] ?? 'update'));

if ($id <= 0) sendResponse(false, 'Valid allocation ID is required', [], [], 400);

$db = new Database();
$conn = $db->getConnection();
$conn->begin_transaction();

try {
    // 1. Ensure allocation_id column exists on renewal_history
    $colCheck = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'allocation_id'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE renewal_history ADD COLUMN allocation_id INT DEFAULT NULL AFTER renewal_id");
    }

    $stmt = $conn->prepare("SELECT * FROM stock_allocations WHERE id = ? AND owner_type = 'dealer' AND sim_id IS NOT NULL FOR UPDATE");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $oldAllocation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$oldAllocation) {
        throw new Exception('Stock allocation not found or not a valid dealer SIM.');
    }

    $oldStatus = $oldAllocation['sim_status'] ?: 'Available';
    $oldValidityId = (int) ($oldAllocation['sim_validity_id'] ?? 0);
    $oldActivation = $oldAllocation['sim_activation_date'] ?: null;
    $oldDeactivation = $oldAllocation['sim_deactivation_date'] ?: null;
    $oldExpiry = $oldAllocation['sim_expiry_date'] ?: null;
    
    $newStatus = $oldStatus;
    $newActivation = $oldActivation;
    $newDeactivation = $oldDeactivation;
    $newExpiry = $oldExpiry;
    $newValidityId = $oldValidityId;
    
    $actionTypeLog = null;
    $logActionDate = null;
    $logNewRenewalDate = null;
    $logNewValidityMonths = null;
    
    function getValidityMonths($conn, $vid) {
        $vStmt = $conn->prepare("SELECT months FROM sim_validities WHERE id = ? LIMIT 1");
        $vStmt->bind_param('i', $vid);
        $vStmt->execute();
        $vRow = $vStmt->get_result()->fetch_assoc();
        $vStmt->close();
        if (!$vRow) throw new Exception('Selected SIM validity is invalid');
        return (int) $vRow['months'];
    }

    if ($oldStatus === 'Available') {
        // Initial activation
        $activationDate = trim((string) ($data['activation_date'] ?? ''));
        $validityId = (int) ($data['sim_validity_id'] ?? 0);
        
        if ($activationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) throw new Exception('Valid activation date is required');
        if ($activationDate !== '' && isFutureDate($activationDate)) throw new Exception('Activation date cannot be in the future');
        if ($activationDate !== '' && $validityId <= 0) throw new Exception('Validity is required when activating');
        
        if ($activationDate !== '') {
            $months = getValidityMonths($conn, $validityId);
            $newExpiry = (new DateTimeImmutable($activationDate))->modify('+' . $months . ' months')->format('Y-m-d');
            $newStatus = 'Active';
            $newActivation = $activationDate;
            $newValidityId = $validityId;
        } else {
            if ($validityId > 0) $newValidityId = $validityId;
        }
        
    } else {
        // Active, Expired, Safe Custody, Deactive
        if ($action === 'renew') {
            if ($oldStatus === 'Deactive') throw new Exception('Cannot renew a deactivated SIM');
            
            $renewalDate = trim((string) ($data['renewal_date'] ?? ''));
            $renewalValidityId = (int) ($data['renewal_validity_id'] ?? 0);
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $renewalDate)) throw new Exception('Valid renewal date is required');
            if ($renewalValidityId <= 0) throw new Exception('Renewal validity is required');
            
            $months = getValidityMonths($conn, $renewalValidityId);
            
            // Calculate new expiry from OLD expiry
            if (!$oldExpiry) $oldExpiry = $renewalDate; // fallback
            $newExpiry = (new DateTimeImmutable($oldExpiry))->modify('+' . $months . ' months')->format('Y-m-d');
            $newStatus = 'Active';
            $newValidityId = $renewalValidityId;
            
            $actionTypeLog = 'Renew SIM';
            $logActionDate = $renewalDate;
            $logNewRenewalDate = $newExpiry;
            $logNewValidityMonths = $months;
            
        } elseif ($action === 'deactivate') {
            if ($oldStatus === 'Deactive') throw new Exception('SIM is already deactivated');
            
            $deactivationDate = trim((string) ($data['deactivation_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deactivationDate)) throw new Exception('Valid deactivation date is required');
            if ($oldActivation && $deactivationDate < $oldActivation) throw new Exception('Deactivation date cannot be before activation date');
            
            $newStatus = 'Deactive';
            $newDeactivation = $deactivationDate;
            
            $actionTypeLog = 'Deactivate SIM';
            $logActionDate = $deactivationDate;
            
        } elseif ($action === 'safe_custody') {
            if ($oldStatus === 'Deactive') throw new Exception('Cannot move deactivated SIM to safe custody');
            
            $newStatus = 'Safe Custody';
            $actionTypeLog = 'Safe Custody';
            $logActionDate = date('Y-m-d');
            
        } elseif ($action === 'reactivate') {
            if ($oldStatus !== 'Deactive') throw new Exception('Only deactivated SIMs can be reactivated');
            
            $reactivationDate = trim((string) ($data['reactivation_date'] ?? ''));
            $reactivationValidityId = (int) ($data['reactivation_validity_id'] ?? 0);
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reactivationDate)) throw new Exception('Valid reactivation date is required');
            if ($reactivationValidityId <= 0) throw new Exception('Reactivation validity is required');
            if ($oldDeactivation && $reactivationDate < $oldDeactivation) throw new Exception('Reactivation date cannot be before deactivation date');
            
            $months = getValidityMonths($conn, $reactivationValidityId);
            $newExpiry = (new DateTimeImmutable($reactivationDate))->modify('+' . $months . ' months')->format('Y-m-d');
            
            $newStatus = 'Active';
            $newValidityId = $reactivationValidityId;
            
            $actionTypeLog = 'Reactivate SIM';
            $logActionDate = $reactivationDate;
            $logNewRenewalDate = $newExpiry;
            $logNewValidityMonths = $months;
            
        } elseif ($action === 'update') {
            $activationDate = trim((string) ($data['activation_date'] ?? ''));
            $validityId = (int) ($data['sim_validity_id'] ?? 0);
            
            if ($activationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationDate)) throw new Exception('Valid activation date is required');
            
            if ($activationDate !== $oldActivation || $validityId !== $oldValidityId) {
                if ($activationDate !== '') {
                    $months = getValidityMonths($conn, $validityId);
                    $newExpiry = (new DateTimeImmutable($activationDate))->modify('+' . $months . ' months')->format('Y-m-d');
                    $newActivation = $activationDate;
                    $newValidityId = $validityId;
                }
            }
        } else {
            throw new Exception('Invalid action');
        }
    }

    $auditFields = [
        'sim_activation_date' => $newActivation,
        'sim_validity_id' => $newValidityId,
        'sim_expiry_date' => $newExpiry,
        'sim_deactivation_date' => $newDeactivation,
        'sim_status' => $newStatus
    ];

    writeChangedFields($conn, $id, 'Stock Allocation SIM Lifecycle', $oldAllocation, $auditFields, $currentUser);

    $updateSql = "UPDATE stock_allocations SET 
                  sim_activation_date = ?, 
                  sim_validity_id = ?, 
                  sim_expiry_date = ?, 
                  sim_deactivation_date = ?, 
                  sim_status = ? 
                  WHERE id = ?";
                  
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param('sisssi', 
        $newActivation, 
        $newValidityId, 
        $newExpiry, 
        $newDeactivation, 
        $newStatus, 
        $id
    );
    
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update SIM lifecycle.');
    }
    $updateStmt->close();
    
    if ($actionTypeLog) {
        $oldValidityMonths = null;
        if ($oldValidityId > 0) {
            $vStmt = $conn->prepare("SELECT months FROM sim_validities WHERE id = ? LIMIT 1");
            $vStmt->bind_param('i', $oldValidityId);
            $vStmt->execute();
            $vRow = $vStmt->get_result()->fetch_assoc();
            $vStmt->close();
            if ($vRow) $oldValidityMonths = (int) $vRow['months'];
        }
        
        $histSql = "INSERT INTO renewal_history 
                    (renewal_id, allocation_id, customer_id, action_type, action_date, old_status, new_status, old_validity_months, new_validity_months, old_renewal_date, new_renewal_date, changed_by) 
                    VALUES (0, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $histStmt = $conn->prepare($histSql);
        $userId = $currentUser['id'] ?? null;
        $histStmt->bind_param('issssiissi', 
            $id,
            $actionTypeLog,
            $logActionDate,
            $oldStatus,
            $newStatus,
            $oldValidityMonths,
            $logNewValidityMonths,
            $oldExpiry,
            $logNewRenewalDate,
            $userId
        );
        $histStmt->execute();
        $histStmt->close();
    }
    
    $conn->commit();
    $conn->close();
    
    sendResponse(true, 'SIM lifecycle updated successfully', []);
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
