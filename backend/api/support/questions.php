<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

handlePreflight();
$method = $_SERVER['REQUEST_METHOD'];
$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

if ($method === 'GET') {
    requirePermission('support.qa.view');
    $result = $conn->query('SELECT id, question, answer, is_active, display_order, created_at, updated_at FROM support_questions WHERE is_active = 1 ORDER BY display_order ASC, id ASC');
    $questions = [];
    while ($result && ($row = $result->fetch_assoc())) $questions[] = $row;
    $conn->close();
    sendResponse(true, 'Support questions fetched successfully.', ['questions' => $questions]);
}

if ($method !== 'POST' && $method !== 'PUT' && $method !== 'DELETE') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('support.qa.manage');
$currentUser = authenticate();
$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? $_GET['id'] ?? 0);
if ($method === 'DELETE') {
    $stmt = $conn->prepare('DELETE FROM support_questions WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute() || $stmt->affected_rows === 0) sendResponse(false, 'Question not found.', [], [], 404);
    writeAudit($conn, $id, 'Support Question', 'Delete', 'id', $id, null, $currentUser);
    $stmt->close(); $conn->close(); sendResponse(true, 'Question deleted successfully.');
}
$question = trim((string) ($data->question ?? ''));
$answer = trim((string) ($data->answer ?? ''));
$order = (int) ($data->display_order ?? 0);
$active = !empty($data->is_active) ? 1 : 0;
if ($question === '' || $answer === '') sendResponse(false, 'Question and answer are required.', [], [], 400);
if ($method === 'POST') {
    $userId = (int) ($currentUser['user_id'] ?? 0);
    $stmt = $conn->prepare('INSERT INTO support_questions (question, answer, is_active, display_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssiiii', $question, $answer, $active, $order, $userId, $userId);
    if (!$stmt->execute()) sendResponse(false, 'Failed to create support question.', [], [], 400);
    $id = $stmt->insert_id; writeCreatedFields($conn, $id, 'Support Question', ['question' => $question, 'answer' => $answer, 'is_active' => $active, 'display_order' => $order], $currentUser);
    $stmt->close(); $conn->close(); sendResponse(true, 'Question created successfully.', ['id' => $id]);
}
$stmt = $conn->prepare('UPDATE support_questions SET question = ?, answer = ?, is_active = ?, display_order = ?, updated_by = ? WHERE id = ?');
$userId = (int) ($currentUser['user_id'] ?? 0);
$stmt->bind_param('ssiiii', $question, $answer, $active, $order, $userId, $id);
if (!$stmt->execute() || $stmt->affected_rows === 0) sendResponse(false, 'Question not found.', [], [], 404);
writeChangedFields($conn, $id, 'Support Question', [], ['question' => $question, 'answer' => $answer, 'is_active' => $active, 'display_order' => $order], $currentUser);
$stmt->close(); $conn->close(); sendResponse(true, 'Question updated successfully.');
?>
