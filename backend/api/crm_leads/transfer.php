<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/../../utils/crm_lead_transfers.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.transfer');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
$leadId = filter_var(is_object($data) ? ($data->lead_id ?? null) : null, FILTER_VALIDATE_INT);
$employeeId = filter_var(is_object($data) ? ($data->employee_id ?? null) : null, FILTER_VALIDATE_INT);
$reason = trim((string) (is_object($data) ? ($data->reason ?? '') : ''));
if ($userId <= 0 || $leadId === false || $leadId <= 0 ||
    $employeeId === false || $employeeId <= 0 || $reason === '' || mb_strlen($reason) > 1000) {
    sendResponse(false, 'A valid lead, eligible user, and transfer reason of up to 1,000 characters are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin lead transfer: ' . $error, [], [], 500);
}

try {
    $result = crmLeadTransferBatch(
        $conn,
        [(int) $leadId],
        (int) $employeeId,
        $userId,
        $reason,
        'Single',
        crmLeadsCanViewAll($userId, $conn)
    );
    if (!$result['success']) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'The lead was not transferred. Review the transfer issues and try again.', [
            'failures' => $result['failures']
        ], [], 409);
    }
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit lead transfer: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'Lead transferred successfully.', $result);
} catch (DomainException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Unable to transfer CRM lead: ' . $error->getMessage());
    sendResponse(false, 'Unable to transfer the lead. No changes were saved.', [], [], 500);
}
?>
