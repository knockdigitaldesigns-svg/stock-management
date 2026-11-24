<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.edit');
$userId = (int) ($currentUser['user_id'] ?? 0);

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = filter_var($data->id ?? null, FILTER_VALIDATE_INT);
$leadName = trim((string) ($data->lead_name ?? ''));
$mobileInput = trim((string) ($data->mobile_no ?? ''));
$mobile = normalizeCrmLeadMobile($mobileInput);
$alternateMobile = trim((string) ($data->alternate_mobile_no ?? ''));
$email = trim((string) ($data->email ?? ''));
$location = trim((string) ($data->location ?? ''));
$pincode = trim((string) ($data->pincode ?? ''));
$pipelineId = filter_var($data->pipeline_id ?? null, FILTER_VALIDATE_INT);
$campaignValue = $data->campaign_id ?? null;
$campaignId = null;
$leadSource = trim((string) ($data->lead_source ?? ''));
$customerNeed = trim((string) ($data->customer_need ?? ''));
$priority = trim((string) ($data->priority ?? 'Normal'));
$remarks = trim((string) ($data->remarks ?? ''));
$leadSources = ['Manual', 'Facebook', 'Google', 'Instagram', 'Website', 'Other'];

if ($campaignValue !== null && $campaignValue !== '') {
    $validatedCampaignId = filter_var($campaignValue, FILTER_VALIDATE_INT);
    if ($validatedCampaignId === false || $validatedCampaignId <= 0) {
        sendResponse(false, 'Select a valid campaign.', [], [], 400);
    }
    $campaignId = $validatedCampaignId;
}
if ($id === false || $id <= 0) {
    sendResponse(false, 'Lead id is required.', [], [], 400);
}
if ($leadName === '' || $mobile === null || $location === '' || $pipelineId === false || $pipelineId <= 0 || $leadSource === '') {
    sendResponse(false, 'Lead name, mobile number, location, pipeline, and lead source are required.', [], [], 400);
}
if (mb_strlen($leadName) > 150 || mb_strlen($mobileInput) > 30 || mb_strlen($alternateMobile) > 20 || mb_strlen($email) > 150 || mb_strlen($location) > 150 || mb_strlen($pincode) > 10 || mb_strlen($leadSource) > 50 || mb_strlen($priority) > 50 || strlen($customerNeed) > 65535 || strlen($remarks) > 65535) {
    sendResponse(false, 'One or more lead fields exceed the allowed length.', [], [], 400);
}
if ($alternateMobile !== '' && !preg_match('/^\d{10}$/', $alternateMobile)) {
    sendResponse(false, 'Alternate mobile number must contain exactly 10 digits.', [], [], 400);
}
if ($alternateMobile !== '' && $alternateMobile === $mobile) {
    sendResponse(false, 'Alternate mobile number must differ from the primary mobile number.', [], [], 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Enter a valid email address.', [], [], 400);
}
if ($pincode !== '' && !preg_match('/^\d{6}$/', $pincode)) {
    sendResponse(false, 'Pincode must contain exactly 6 digits.', [], [], 400);
}
if (!in_array($leadSource, $leadSources, true)) {
    sendResponse(false, 'Invalid lead source.', [], [], 400);
}
if ($priority === '') {
    $priority = 'Normal';
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$isEmployee = crmLeadsIsEmployee($userId, $conn);
$hasSoftDeleteSchema = crmLeadsHasSoftDeleteSchema($conn);
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin lead update: ' . $error, [], [], 500);
}

$activeLeadCondition = $hasSoftDeleteSchema ? ' AND deleted_at IS NULL' : '';
$existingStmt = $conn->prepare(
    'SELECT pipeline_id, campaign_id, assigned_user_id
     FROM crm_leads WHERE id = ?' . $activeLeadCondition . ' LIMIT 1 FOR UPDATE'
);
if (!$existingStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate lead: ' . $error, [], [], 500);
}
$existingStmt->bind_param('i', $id);
if (!$existingStmt->execute()) {
    $error = $existingStmt->error;
    $existingStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to validate lead: ' . $error, [], [], 500);
}
$existingLead = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();
if (!$existingLead) {
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if ($isEmployee && (int) ($existingLead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->close();
    sendResponse(false, 'Lead not found.', [], [], 404);
}
if (!$isEmployee && !userHasPermission($userId, 'crm_leads.view') &&
    (int) ($existingLead['assigned_user_id'] ?? 0) !== $userId) {
    $conn->close();
    sendResponse(false, 'You are only authorized to update leads assigned to you.', [], [], 403);
}

$pipeline = $conn->prepare('SELECT id, status FROM crm_pipelines WHERE id = ? LIMIT 1');
if (!$pipeline) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate pipeline: ' . $error, [], [], 500);
}
$pipeline->bind_param('i', $pipelineId);
if (!$pipeline->execute()) {
    $error = $pipeline->error;
    $pipeline->close();
    $conn->close();
    sendResponse(false, 'Unable to validate pipeline: ' . $error, [], [], 500);
}
$pipelineRecord = $pipeline->get_result()->fetch_assoc();
$pipeline->close();
if (!$pipelineRecord || ($pipelineRecord['status'] !== 'Active' && (int) $existingLead['pipeline_id'] !== $pipelineId)) {
    $conn->close();
    sendResponse(false, 'Select an active pipeline.', [], [], 400);
}

if ($campaignId !== null) {
    $campaign = $conn->prepare('SELECT pipeline_id, status FROM crm_campaigns WHERE id = ? LIMIT 1');
    if (!$campaign) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to validate campaign: ' . $error, [], [], 500);
    }
    $campaign->bind_param('i', $campaignId);
    if (!$campaign->execute()) {
        $error = $campaign->error;
        $campaign->close();
        $conn->close();
        sendResponse(false, 'Unable to validate campaign: ' . $error, [], [], 500);
    }
    $campaignRecord = $campaign->get_result()->fetch_assoc();
    $campaign->close();
    $isSameInactiveCampaign = (int) ($existingLead['campaign_id'] ?? 0) === $campaignId;
    if (!$campaignRecord || (int) $campaignRecord['pipeline_id'] !== $pipelineId || ($campaignRecord['status'] !== 'Active' && !$isSameInactiveCampaign)) {
        $conn->close();
        sendResponse(false, 'Select an active campaign associated with the selected pipeline.', [], [], 400);
    }
    $campaignLock = $conn->prepare('SELECT id FROM crm_campaigns WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$campaignLock) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to lock campaign for lead update: ' . $error, [], [], 500);
    }
    $campaignLock->bind_param('i', $campaignId);
    if (!$campaignLock->execute()) {
        $error = $campaignLock->error;
        $campaignLock->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to lock campaign for lead update: ' . $error, [], [], 500);
    }
    $campaignLock->close();
    if (crmLeadCampaignMobileExists($conn, $campaignId, $mobile, $id, true)) {
        $conn->rollback();
        $conn->close();
        sendResponse(
            false,
            'This mobile number already exists in the selected campaign. Please use a different number or select another campaign.',
            [],
            [],
            409
        );
    }
}

$stmt = $conn->prepare(
    "UPDATE crm_leads
     SET pipeline_id = ?, campaign_id = ?, lead_name = ?, mobile_no = ?,
         alternate_mobile_no = NULLIF(?, ''), email = NULLIF(?, ''),
         location = ?, pincode = NULLIF(?, ''), lead_source = ?,
         customer_need = NULLIF(?, ''), priority = ?,
         remarks = NULLIF(?, ''), updated_at = NOW()
     WHERE id = ?"
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to update lead: ' . $error, [], [], 500);
}
$stmt->bind_param(
    'iissssssssssi',
    $pipelineId,
    $campaignId,
    $leadName,
    $mobile,
    $alternateMobile,
    $email,
    $location,
    $pincode,
    $leadSource,
    $customerNeed,
    $priority,
    $remarks,
    $id
);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $duplicateKey = $stmt->errno === 1062;
    $stmt->close();
    $conn->rollback();
    $conn->close();
    if ($duplicateKey) {
        sendResponse(
            false,
            'This mobile number already exists in the selected campaign. Please use a different number or select another campaign.',
            [],
            [],
            409
        );
    }
    sendResponse(false, 'Unable to update lead: ' . $error, [], [], 500);
}

$stmt->close();
if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to save lead update: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Lead updated successfully.');
?>
