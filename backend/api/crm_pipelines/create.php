<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_pipelines.add');

$data = json_decode(file_get_contents('php://input'));
if (!$data) {
    sendResponse(false, 'Invalid request payload.', [], [], 400);
}

$pipelineName = trim((string) ($data->pipeline_name ?? ''));
$description = trim((string) ($data->description ?? ''));
$status = trim((string) ($data->status ?? 'Active'));
$displayOrder = isset($data->display_order) ? (int) $data->display_order : 0;

if ($pipelineName === '') {
    sendResponse(false, 'Pipeline name is required.', [], [], 400);
}

if (mb_strlen($pipelineName) > 100) {
    sendResponse(false, 'Pipeline name must be 100 characters or fewer.', [], [], 400);
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    sendResponse(false, 'Invalid pipeline status.', [], [], 400);
}

if ($displayOrder < 0) {
    sendResponse(false, 'Display order must be zero or greater.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$check = $conn->prepare('SELECT id FROM crm_pipelines WHERE LOWER(TRIM(pipeline_name)) = LOWER(?) LIMIT 1');
if (!$check) {
    $conn->close();
    sendResponse(false, 'Failed to validate pipeline name.', [], [], 500);
}

$check->bind_param('s', $pipelineName);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();
    sendResponse(false, 'A pipeline with this name already exists.', [], [], 409);
}
$check->close();

$userId = (int) ($currentUser['user_id'] ?? 0);
$stmt = $conn->prepare(
    'INSERT INTO crm_pipelines (pipeline_name, description, status, display_order, created_by_user_id)
     VALUES (?, ?, ?, ?, ?)'
);

if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to create pipeline record.', [], [], 500);
}

$stmt->bind_param('sssii', $pipelineName, $description, $status, $displayOrder, $userId);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to add pipeline.', [], [], 500);
}

$insertedId = $conn->insert_id;
$stmt->close();
$conn->close();

sendResponse(true, 'Pipeline created successfully.', [
    'id' => $insertedId
]);
?>
