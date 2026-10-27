<?php
require_once __DIR__ . '/common.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.view');

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
ensureRenewalTables($conn);

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(500, max(1, (int)($_GET['page_size'] ?? 500)));
$filterQuery = renewalListFilters($_GET);
$condition = $filterQuery['condition'];
$params = $filterQuery['params'];
$types = $filterQuery['types'];

$count = $conn->prepare(renewalCountQuery() . $condition);
if (!$count) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal export count: ' . $error, [], [], 500);
}
renewalBind($count, $types, $params);
if (!$count->execute()) {
    $error = $count->error;
    $count->close();
    $conn->close();
    sendResponse(false, 'Unable to count renewal export records: ' . $error, [], [], 500);
}
$total = (int)$count->get_result()->fetch_assoc()['total'];
$count->close();

$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(
    renewalRowQuery($conn) . $condition .
    ' ORDER BY ci.installation_date DESC, cr.id DESC LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to prepare renewal export: ' . $error, [], [], 500);
}
$queryParams = array_merge($params, [$pageSize, $offset]);
renewalBind($stmt, $types . 'ii', $queryParams);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to load renewal export records: ' . $error, [], [], 500);
}

$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
$conn->close();

sendResponse(true, 'Renewal export records loaded successfully.', [
    'renewals' => $rows,
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / $pageSize))
    ]
]);
?>
