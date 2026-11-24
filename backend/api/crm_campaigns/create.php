<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_campaigns.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

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
if ($campaignName === '' || $pipelineId <= 0 || $startDate === '') {
    sendResponse(false, 'Campaign name, pipeline, and start date are required.', [], [], 400);
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

$pipeline = $conn->prepare("SELECT id FROM crm_pipelines WHERE id = ? AND status = 'Active' LIMIT 1");
if (!$pipeline) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate pipeline: ' . $error, [], [], 500);
}
$pipeline->bind_param('i', $pipelineId);
$pipeline->execute();
if ($pipeline->get_result()->num_rows === 0) {
    $pipeline->close();
    $conn->close();
    sendResponse(false, 'Select an active pipeline.', [], [], 400);
}
$pipeline->close();

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
     WHERE pipeline_id = ? AND LOWER(TRIM(campaign_name)) = LOWER(?)
     LIMIT 1'
);
if (!$duplicate) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to validate campaign name: ' . $error, [], [], 500);
}
$duplicate->bind_param('is', $pipelineId, $campaignName);
$duplicate->execute();
if ($duplicate->get_result()->num_rows > 0) {
    $duplicate->close();
    $conn->close();
    sendResponse(false, 'A campaign with this name already exists in the selected pipeline.', [], [], 409);
}
$duplicate->close();

$userId = (int) ($currentUser['user_id'] ?? 0);
$insertSql = $hasManagerColumn
    ? "INSERT INTO crm_campaigns
           (pipeline_id, manager_user_id, campaign_name, start_date, end_date, description, status, created_by_user_id)
       VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?)"
    : "INSERT INTO crm_campaigns
           (pipeline_id, campaign_name, start_date, end_date, description, status, created_by_user_id)
       VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?)";
$stmt = $conn->prepare($insertSql);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to create campaign: ' . $error, [], [], 500);
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
        $userId
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
        $userId
    );
}
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to create campaign: ' . $error, [], [], 500);
}

$id = $conn->insert_id;
$stmt->close();
$conn->close();
sendResponse(true, 'Campaign created successfully.', ['id' => $id]);
?>
