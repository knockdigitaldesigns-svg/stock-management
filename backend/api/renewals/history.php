<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.history');
$renewalId = (int)($_GET['renewal_id'] ?? 0);
if ($renewalId <= 0) sendResponse(false, 'Renewal ID is required.', [], [], 400);
$conn = (new Database())->getConnection();
$undoUserSelect = renewalUndoSchemaReady($conn)
    ? 'undo_user.username AS undone_by_name'
    : 'NULL AS undone_by_name';
$undoUserJoin = renewalUndoSchemaReady($conn)
    ? 'LEFT JOIN users undo_user ON undo_user.id = rh.undone_by'
    : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM renewal_history WHERE renewal_id = ?');
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal history count.', [], [], 500);
}
$countStmt->bind_param('i', $renewalId);
if (!$countStmt->execute()) {
    $error = $countStmt->error;
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count renewal history: ' . $error, [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare("SELECT rh.*, u.username AS changed_by_name, $undoUserSelect FROM renewal_history rh LEFT JOIN users u ON u.id = rh.changed_by $undoUserJoin WHERE rh.renewal_id = ? ORDER BY rh.id DESC LIMIT ? OFFSET ?");
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal history: ' . $error, [], [], 500);
}
$stmt->bind_param('iii', $renewalId, $pageSize, $offset);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load renewal history: ' . $error, [], [], 500);
}
$result = $stmt->get_result();
$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();
$conn->close();
sendResponse(true, 'Renewal history fetched successfully.', [
    'history' => $history,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
?>
