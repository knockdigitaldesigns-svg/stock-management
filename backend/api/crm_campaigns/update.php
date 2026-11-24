<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

authenticate();
requirePermission('crm_campaigns.edit');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$id = (int) ($data->id ?? 0);
$campaignName = trim((string) ($data->campaign_name ?? ''));
$pipelineId = (int) ($data->pipeline_id ?? 0);
$managerValue = $data->manager_user_id ?? null;
$managerUserId = null;
if ($managerValue !== null && $managerValue !== '') {
    $validatedManagerId = filter_var($managerValue, FILTER_VALIDATE_INT);
    if ($validatedManagerId === false || $validatedManagerId <= 0) {
        sendResponse(false, 'Select a valid campaign manager.', [], [], 400);
    }
    $managerUserId = $validatedManagerId;
}
$startDate = trim((string) ($data->start_date ?? ''));
$endDate = trim((string) ($data->end_date ?? ''));
$description = trim((string) ($data->description ?? ''));
$status = trim((string) ($data->status ?? 'Active'));
if ($id <= 0) {
    sendResponse(false, 'Campaign id is required.', [], [], 400);
}
if ($campaignName === '' || $campaignType === '' || $pipelineId <= 0 || $leadSource === '' || $startDate === '') {
    sendResponse(false, 'Campaign name, type, pipeline, lead source, and start date are required.', [], [], 400);
}
if (mb_strlen($campaignName) > 150) {
    sendResponse(false, 'Campaign name exceeds the allowed length.', [], [], 400);
}
if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid campaign status.', [], [], 400);
}
$validStart = DateTime::createFromFormat('!Y-m-d', $startDate);
$validEnd = $endDate === '' ? null : DateTime::createFromFormat('!Y-m-d', $endDate);
if (!$validStart || $validStart->format('Y-m-d') !== $startDate) {
    sendResponse(false, 'Start date must be a valid date.', [], [], 400);
}
if ($endDate !== '' && (!$validEnd || $validEnd->format('Y-m-d') !== $endDate)) {
    sendResponse(false, 'End date must be a valid date.', [], [], 400);
}
if ($validEnd && $validEnd < $validStart) {
    sendResponse(false, 'End date cannot be earlier than start date.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$hasManagerColumn = campaignManagerColumnExists($conn);
if ($managerUserId !== null && !$hasManagerColumn) {
    requireCampaignManagerColumn($conn);
}

$existing = $conn->prepare('SELECT pipeline_id FROM crm_campaigns WHERE id = ? LIMIT 1');
if (!$existing) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate campaign: ' . $error, [], [], 500);
}
$existing->bind_param('i', $id);
$existing->execute();
$campaign = $existing->get_result()->fetch_assoc();
$existing->close();
if (!$campaign) {
    $conn->close();
    sendResponse(false, 'Campaign not found.', [], [], 404);
}

$pipeline = $conn->prepare('SELECT id, status FROM crm_pipelines WHERE id = ? LIMIT 1');
if (!$pipeline) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate pipeline: ' . $error, [], [], 500);
}
$pipeline->bind_param('i', $pipelineId);
$pipeline->execute();
$pipelineRecord = $pipeline->get_result()->fetch_assoc();
$pipeline->close();
if (!$pipelineRecord || ($pipelineRecord['status'] !== 'Active' && (int) $campaign['pipeline_id'] !== $pipelineId)) {
    $conn->close();
    sendResponse(false, 'Select an active pipeline.', [], [], 400);
}

if ($managerUserId !== null) {
    $manager = $conn->prepare(
        "SELECT u.id
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
           AND LOWER(COALESCE(u.status, 'active')) = 'active'
           AND LOWER(COALESCE(r.status, 'active')) = 'active'
         LIMIT 1"
    );
    if (!$manager) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to validate campaign manager: ' . $error, [], [], 500);
    }
    $manager->bind_param('i', $managerUserId);
    $manager->execute();
    if ($manager->get_result()->num_rows === 0) {
        $manager->close();
        $conn->close();
        sendResponse(false, 'Select an active user for Managed By.', [], [], 400);
    }
    $manager->close();
}

$duplicate = $conn->prepare(
    'SELECT id FROM crm_campaigns
     WHERE pipeline_id = ? AND LOWER(TRIM(campaign_name)) = LOWER(?) AND id <> ?
     LIMIT 1'
);
if (!$duplicate) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate campaign name: ' . $error, [], [], 500);
}
$duplicate->bind_param('isi', $pipelineId, $campaignName, $id);
$duplicate->execute();
if ($duplicate->get_result()->num_rows > 0) {
    $duplicate->close();
    $conn->close();
    sendResponse(false, 'A campaign with this name already exists in the selected pipeline.', [], [], 409);
}
$duplicate->close();

$updateSql = $hasManagerColumn
    ? "UPDATE crm_campaigns
       SET pipeline_id = ?, manager_user_id = ?, campaign_name = ?,
           start_date = ?, end_date = NULLIF(?, ''), description = ?, status = ?, updated_at = NOW()
       WHERE id = ?"
    : "UPDATE crm_campaigns
       SET pipeline_id = ?, campaign_name = ?,
           start_date = ?, end_date = NULLIF(?, ''), description = ?, status = ?, updated_at = NOW()
       WHERE id = ?";
$stmt = $conn->prepare($updateSql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to update campaign: ' . $error, [], [], 500);
}
if ($hasManagerColumn) {
    $stmt->bind_param(
        'iisssssi',
        $pipelineId,
        $managerUserId,
        $campaignName,
        $startDate,
        $endDate,
        $description,
        $status,
        $id
    );
} else {
    $stmt->bind_param(
        'isssssi',
        $pipelineId,
        $campaignName,
        $startDate,
        $endDate,
        $description,
        $status,
        $id
    );
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to update campaign: ' . $error, [], [], 500);
}

$stmt->close();
$conn->close();
sendResponse(true, 'Campaign updated successfully.');
?>
