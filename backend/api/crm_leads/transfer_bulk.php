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
requirePermission('crm_leads.transfer_bulk');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'));
if (!is_object($data) || !isset($data->lead_ids) || !is_array($data->lead_ids)) {
    sendResponse(false, 'A list of leads, Employee, and transfer reason are required.', [], [], 400);
}
$employeeId = filter_var($data->employee_id ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($data->reason ?? ''));
if ($userId <= 0 || $employeeId === false || $employeeId <= 0 ||
    $reason === '' || mb_strlen($reason) > 1000 || count($data->lead_ids) === 0) {
    sendResponse(false, 'Select at least one lead, an active eligible user, and a transfer reason of up to 1,000 characters.', [], [], 400);
}

$leadIds = [];
foreach ($data->lead_ids as $value) {
    $leadId = filter_var($value, FILTER_VALIDATE_INT);
    if ($leadId === false || $leadId <= 0) {
        sendResponse(false, 'All lead IDs must be positive integers.', [], [], 400);
    }
    $leadIds[] = (int) $leadId;
}
if (count(array_unique($leadIds)) !== count($leadIds)) {
    sendResponse(false, 'Duplicate lead IDs are not allowed in a bulk transfer.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin bulk lead transfer: ' . $error, [], [], 500);
}

try {
    $result = crmLeadTransferBatch(
        $conn,
        $leadIds,
        (int) $employeeId,
        $userId,
        $reason,
        'Bulk',
        crmLeadsCanViewAll($userId, $conn)
    );
    if (!$result['success']) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'No leads were transferred. Review each issue and try again.', [
            'failures' => $result['failures']
        ], [], 409);
    }
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit bulk lead transfer: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'All selected leads were transferred successfully.', $result);
} catch (DomainException $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Unable to transfer CRM leads in bulk: ' . $error->getMessage());
    sendResponse(false, 'Unable to transfer the selected leads. No changes were saved.', [], [], 500);
}
?>
