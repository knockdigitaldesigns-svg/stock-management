<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requireAnyPermission([
    'dealers.view',
    'technicians.view',
    'customers.view'
]);

$recipientType = trim((string) ($_GET['recipient_type'] ?? ''));
$recipientId = (int) ($_GET['recipient_id'] ?? 0);
if (!in_array($recipientType, ['Technician', 'Dealer'], true) || $recipientId <= 0) {
    sendResponse(false, 'Recipient type and recipient ID are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$countStmt = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM customer_cash_collections ccc
     INNER JOIN customers c ON c.id = ccc.customer_id
     INNER JOIN customer_installations ci ON ci.id = ccc.installation_id
     WHERE ccc.recipient_type = ? AND ccc.recipient_id = ?'
);
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare cash collection count query.', [], [], 500);
}
$countStmt->bind_param('si', $recipientType, $recipientId);
if (!$countStmt->execute()) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to count cash collections.', [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$summaryStmt = $conn->prepare(
    'SELECT COALESCE(SUM(ccc.amount_collected), 0) AS total_collected,
            COALESCE(SUM(ccc.amount_remitted), 0) AS total_settled,
            COALESCE(SUM(ccc.pending_amount), 0) AS total_pending
     FROM customer_cash_collections ccc
     INNER JOIN customers c ON c.id = ccc.customer_id
     INNER JOIN customer_installations ci ON ci.id = ccc.installation_id
     WHERE ccc.recipient_type = ? AND ccc.recipient_id = ?'
);
if (!$summaryStmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare cash collection summary query.', [], [], 500);
}
$summaryStmt->bind_param('si', $recipientType, $recipientId);
if (!$summaryStmt->execute()) {
    $summaryStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to summarize cash collections.', [], [], 500);
}
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$stmt = $conn->prepare(
    'SELECT ccc.*, c.username, c.primary_mobile_no, c.location,
            ci.installation_person_type, ci.installation_date,
            p.platform_name, cv.vehicle_no, cv.imei_no, cv.validity_months,
            cv.device_model_id, dt.device_type AS device_model
     FROM customer_cash_collections ccc
     INNER JOIN customers c ON c.id = ccc.customer_id
     INNER JOIN customer_installations ci ON ci.id = ccc.installation_id
     LEFT JOIN platforms p ON p.id = c.platform_id
     LEFT JOIN customer_vehicle_details cv ON cv.id = (
        SELECT latest_cv.id FROM customer_vehicle_details latest_cv
        WHERE latest_cv.customer_id = c.id ORDER BY latest_cv.created_at DESC, latest_cv.id DESC LIMIT 1
     )
     LEFT JOIN device_types dt ON dt.id = cv.device_model_id
     WHERE ccc.recipient_type = ? AND ccc.recipient_id = ?
     ORDER BY ci.installation_date DESC, ccc.id DESC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare cash collection query.', [], [], 500);
}
$stmt->bind_param('siii', $recipientType, $recipientId, $pageSize, $offset);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch cash collections.', [], [], 500);
}
$result = $stmt->get_result();
$collections = [];
while ($row = $result->fetch_assoc()) {
    foreach (['amount_collected', 'amount_remitted', 'pending_amount'] as $field) $row[$field] = (float) $row[$field];
    $collections[] = $row;
}
$stmt->close();

$historyStmt = $conn->prepare(
    'SELECT s.id, s.settlement_amount, s.settlement_date, s.created_at, s.payment_mode,
            s.transaction_id, s.notes, s.settled_by_name, s.outstanding_before, s.outstanding_after
     FROM cash_collection_settlements s
     WHERE s.recipient_type = ? AND s.recipient_id = ?
     ORDER BY s.created_at ASC, s.id ASC'
);
$historyStmt->bind_param('si', $recipientType, $recipientId);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
$settlementHistory = [];
while ($settlement = $historyResult->fetch_assoc()) {
    $settlement['settlement_amount'] = (float) $settlement['settlement_amount'];
    $settlement['outstanding_before'] = (float) $settlement['outstanding_before'];
    $settlement['outstanding_after'] = (float) $settlement['outstanding_after'];
    $settlement['allocations'] = [];
    $settlementHistory[(int) $settlement['id']] = $settlement;
}
$historyStmt->close();

if ($settlementHistory) {
    $settlementIds = implode(',', array_map('intval', array_keys($settlementHistory)));
    $allocationResult = $conn->query(
        'SELECT a.settlement_id, a.collection_id, a.customer_id, a.amount_allocated,
                a.outstanding_before, a.outstanding_after, c.username
         FROM cash_collection_settlement_allocations a
         INNER JOIN customers c ON c.id = a.customer_id
         WHERE a.settlement_id IN (' . $settlementIds . ')
         ORDER BY a.settlement_id ASC, a.id ASC'
    );
    while ($allocation = $allocationResult->fetch_assoc()) {
        $allocation['amount_allocated'] = (float) $allocation['amount_allocated'];
        $allocation['outstanding_before'] = (float) $allocation['outstanding_before'];
        $allocation['outstanding_after'] = (float) $allocation['outstanding_after'];
        $settlementHistory[(int) $allocation['settlement_id']]['allocations'][] = $allocation;
    }
}
$settlementHistory = array_values($settlementHistory);
$conn->close();
sendResponse(true, 'Customer cash collections fetched successfully.', [
    'collections' => $collections,
    'summary' => [
        'total_collected' => (float) $summary['total_collected'],
        'total_settled' => (float) $summary['total_settled'],
        'total_pending' => (float) $summary['total_pending']
    ],
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ],
    'settlement_history' => $settlementHistory
]);
?>