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
     ORDER BY ci.installation_date DESC, ccc.id DESC'
);
$stmt->bind_param('si', $recipientType, $recipientId);
$stmt->execute();
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
$totalCollected = 0.0;
$totalSettled = 0.0;
$totalPending = 0.0;
foreach ($collections as $collection) {
    $totalCollected += (float) $collection['amount_collected'];
    $totalSettled += (float) $collection['amount_remitted'];
    $totalPending += (float) $collection['pending_amount'];
}
$conn->close();
sendResponse(true, 'Customer cash collections fetched successfully.', [
    'collections' => $collections,
    'summary' => [
        'total_collected' => round($totalCollected, 2),
        'total_settled' => round($totalSettled, 2),
        'total_pending' => round($totalPending, 2)
    ],
    'settlement_history' => $settlementHistory
]);
?>