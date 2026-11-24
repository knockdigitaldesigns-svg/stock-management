<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['crm_leads.add', 'crm_leads.edit']);
$userId = (int) ($currentUser['user_id'] ?? 0);

$campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT);
$mobileInput = trim((string) ($_GET['mobile_no'] ?? ''));
$mobile = normalizeCrmLeadMobile($mobileInput);
$excludeLeadId = null;
$excludeLeadValue = trim((string) ($_GET['exclude_lead_id'] ?? ''));

if ($campaignId === false || $campaignId <= 0 || $mobile === null) {
    sendResponse(false, 'A valid campaign and 10-digit mobile number are required.', [], [], 400);
}
if ($excludeLeadValue !== '') {
    $validatedExcludeLeadId = filter_var($excludeLeadValue, FILTER_VALIDATE_INT);
    if ($validatedExcludeLeadId === false || $validatedExcludeLeadId <= 0) {
        sendResponse(false, 'A valid lead ID is required for duplicate checking.', [], [], 400);
    }
    $excludeLeadId = $validatedExcludeLeadId;
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

if ($excludeLeadId !== null) {
    $leadStmt = $conn->prepare('SELECT assigned_user_id FROM crm_leads WHERE id = ? LIMIT 1');
    if (!$leadStmt) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to validate lead access: ' . $error, [], [], 500);
    }
    $leadStmt->bind_param('i', $excludeLeadId);
    if (!$leadStmt->execute()) {
        $error = $leadStmt->error;
        $leadStmt->close();
        $conn->close();
        sendResponse(false, 'Unable to validate lead access: ' . $error, [], [], 500);
    }
    $lead = $leadStmt->get_result()->fetch_assoc();
    $leadStmt->close();
    $isEmployee = crmLeadsIsEmployee($userId, $conn);
    $canViewLead = $lead &&
        (($isEmployee || !userHasPermission($userId, 'crm_leads.view'))
            ? (int) ($lead['assigned_user_id'] ?? 0) === $userId
            : true);
    if (!$canViewLead) {
        $conn->close();
        sendResponse(false, 'Lead not found.', [], [], 404);
    }
}

$exists = crmLeadCampaignMobileExists($conn, $campaignId, $mobile, $excludeLeadId);
$conn->close();

sendResponse(true, 'Campaign mobile number checked successfully.', ['duplicate' => $exists]);
?>
