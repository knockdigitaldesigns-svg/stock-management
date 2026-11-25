<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/access.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_leads.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

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
$leadStageId = null;
$remarks = trim((string) ($data->remarks ?? ''));
$leadSources = ['Manual', 'Facebook', 'Google', 'Instagram', 'Website', 'Other'];

if ($campaignValue !== null && $campaignValue !== '') {
    $validatedCampaignId = filter_var($campaignValue, FILTER_VALIDATE_INT);
    if ($validatedCampaignId === false || $validatedCampaignId <= 0) {
        sendResponse(false, 'Select a valid campaign.', [], [], 400);
    }
    $campaignId = $validatedCampaignId;
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

$stage = $conn->prepare(
    "SELECT id FROM crm_lead_stage_master
     WHERE LOWER(TRIM(stage_name)) = 'new lead' AND status = 'Active'
     LIMIT 1"
);
if (!$stage) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to find the active New Lead stage: ' . $error, [], [], 500);
}
if (!$stage->execute()) {
    $error = $stage->error;
    $stage->close();
    $conn->close();
    sendResponse(false, 'Unable to find the active New Lead stage: ' . $error, [], [], 500);
}
$newLeadStage = $stage->get_result()->fetch_assoc();
$stage->close();
if (!$newLeadStage) {
    $conn->close();
    sendResponse(false, 'An active "New Lead" stage is required before creating leads.', [], [], 503);
}
$leadStageId = (int) $newLeadStage['id'];

$pipeline = $conn->prepare("SELECT id FROM crm_pipelines WHERE id = ? AND status = 'Active' LIMIT 1");
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
if ($pipeline->get_result()->num_rows === 0) {
    $pipeline->close();
    $conn->close();
    sendResponse(false, 'Select an active pipeline.', [], [], 400);
}
$pipeline->close();

if ($campaignId !== null) {
    $campaign = $conn->prepare(
        "SELECT id FROM crm_campaigns
         WHERE id = ? AND pipeline_id = ? AND status = 'Active'
         LIMIT 1"
    );
    if (!$campaign) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to validate campaign: ' . $error, [], [], 500);
    }
    $campaign->bind_param('ii', $campaignId, $pipelineId);
    if (!$campaign->execute()) {
        $error = $campaign->error;
        $campaign->close();
        $conn->close();
        sendResponse(false, 'Unable to validate campaign: ' . $error, [], [], 500);
    }
    if ($campaign->get_result()->num_rows === 0) {
        $campaign->close();
        $conn->close();
        sendResponse(false, 'Select an active campaign associated with the selected pipeline.', [], [], 400);
    }
    $campaign->close();
}

$createdBy = (int) ($currentUser['user_id'] ?? 0);
if ($createdBy <= 0) {
    $conn->close();
    sendResponse(false, 'Invalid authenticated user.', [], [], 401);
}
$shouldAutoAssignToCreator = crmLeadsShouldAutoAssignOnCreate($createdBy, $conn);
$assignedUserId = $shouldAutoAssignToCreator ? $createdBy : null;
$assignedByUserId = $assignedUserId;
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin lead creation: ' . $error, [], [], 500);
}
if ($campaignId !== null) {
    $campaignLock = $conn->prepare('SELECT id FROM crm_campaigns WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$campaignLock) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to lock campaign for lead creation: ' . $error, [], [], 500);
    }
    $campaignLock->bind_param('i', $campaignId);
    if (!$campaignLock->execute()) {
        $error = $campaignLock->error;
        $campaignLock->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to lock campaign for lead creation: ' . $error, [], [], 500);
    }
    $campaignLock->close();
    if (crmLeadCampaignMobileExists($conn, $campaignId, $mobile, null, true)) {
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
    "INSERT INTO crm_leads
        (pipeline_id, campaign_id, lead_name, mobile_no, alternate_mobile_no, email,
         location, pincode, lead_source, customer_need, priority, lead_status_id, lead_stage_id,
         remarks, assigned_user_id, assigned_by_user_id, assigned_at, created_by_user_id)
     VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''),
             ?, NULLIF(?, ''), ?, NULL, ?, NULLIF(?, ''), ?, ?,
             IF(? IS NULL, NULL, CURRENT_TIMESTAMP), ?)"
);
if (!$stmt) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to create lead: ' . $error, [], [], 500);
}
$stmt->bind_param(
    'ii' . str_repeat('s', 9) . 'isiiii',
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
    $leadStageId,
    $remarks,
    $assignedUserId,
    $assignedByUserId,
    $assignedUserId,
    $createdBy
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
    sendResponse(false, 'Unable to create lead: ' . $error, [], [], 500);
}

$id = $conn->insert_id;
$stmt->close();
if ($shouldAutoAssignToCreator) {
    $assignmentType = 'Distribution';
    $assignmentNotes = '';
    $assignmentStmt = $conn->prepare(
        "INSERT INTO crm_lead_assignments
            (lead_id, assigned_to_user_id, assigned_by_user_id, assignment_type, assignment_notes)
         VALUES (?, ?, ?, ?, NULLIF(?, ''))"
    );
    if (!$assignmentStmt) {
        $error = $conn->error;
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to record lead assignment: ' . $error, [], [], 500);
    }
    $assignmentStmt->bind_param(
        'iiiss',
        $id,
        $assignedUserId,
        $assignedByUserId,
        $assignmentType,
        $assignmentNotes
    );
    if (!$assignmentStmt->execute()) {
        $error = $assignmentStmt->error;
        $assignmentStmt->close();
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Unable to record lead assignment: ' . $error, [], [], 500);
    }
    $assignmentStmt->close();
}
if (!$conn->commit()) {
    $error = $conn->error;
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Unable to save lead creation: ' . $error, [], [], 500);
}
$conn->close();
sendResponse(true, 'Lead created successfully.', ['id' => $id]);
?>
