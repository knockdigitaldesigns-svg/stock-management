<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_pipelines.delete');

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);

if ($id <= 0) {
    sendResponse(false, 'Pipeline id is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$campaignCheck = $conn->prepare('SELECT id FROM crm_campaigns WHERE pipeline_id = ? LIMIT 1');
if ($campaignCheck) {
    $campaignCheck->bind_param('i', $id);
    $campaignCheck->execute();
    if ($campaignCheck->get_result()->num_rows > 0) {
        $campaignCheck->close();
        $conn->close();
        sendResponse(false, 'This pipeline is already used by one or more campaigns. Deactivate it instead of deleting it.', [], [], 409);
    }
    $campaignCheck->close();
}

$leadCheck = $conn->prepare('SELECT id FROM crm_leads WHERE pipeline_id = ? LIMIT 1');
if ($leadCheck) {
    $leadCheck->bind_param('i', $id);
    $leadCheck->execute();
    if ($leadCheck->get_result()->num_rows > 0) {
        $leadCheck->close();
        $conn->close();
        sendResponse(false, 'This pipeline is already assigned to one or more leads. Deactivate it instead of deleting it.', [], [], 409);
    }
    $leadCheck->close();
}

$existing = $conn->prepare('SELECT * FROM crm_pipelines WHERE id = ? LIMIT 1');
if (!$existing) {
    $conn->close();
    sendResponse(false, 'Unable to find the pipeline record.', [], [], 500);
}

$existing->bind_param('i', $id);
$existing->execute();
$record = $existing->get_result()->fetch_assoc();
$existing->close();

if (!$record) {
    $conn->close();
    sendResponse(false, 'Pipeline not found.', [], [], 404);
}

$conn->begin_transaction();
try {
    writeDeleteSnapshot($conn, $id, 'CRM Pipeline', $record, $currentUser);

    $stmt = $conn->prepare('DELETE FROM crm_pipelines WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to delete pipeline record.');
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to remove the pipeline.');
    }
    $stmt->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Pipeline deleted successfully.');
} catch (Throwable $e) {
    $conn->rollback();
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
