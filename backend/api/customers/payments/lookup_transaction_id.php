<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('transaction_ids.lookup');

function findTransactionLookupRow(mysqli $conn, string $query, string $value, ?string $secondValue = null): ?array
{
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare transaction record lookup.');
    }
    if ($secondValue === null) {
        $stmt->bind_param('s', $value);
    } else {
        $stmt->bind_param('ss', $value, $secondValue);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to read transaction record details: ' . $error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function findTransactionAllocationOwner(mysqli $conn, string $allocationId): ?array
{
    $row = findTransactionLookupRow(
        $conn,
        "SELECT sa.owner_type,
                CASE
                    WHEN sa.owner_type = 'customer' THEN c.username
                    WHEN sa.owner_type = 'dealer' THEN d.dealer_name
                    WHEN sa.owner_type = 'technician' THEN t.technician_name
                END AS subject_name
         FROM stock_allocations sa
         LEFT JOIN customers c ON sa.owner_type = 'customer' AND c.id = sa.owner_id
         LEFT JOIN dealers d ON sa.owner_type = 'dealer' AND d.id = sa.owner_id
         LEFT JOIN technicians t ON sa.owner_type = 'technician' AND t.id = sa.owner_id
         WHERE sa.id = ?
         LIMIT 1",
        $allocationId
    );
    if (!$row) {
        return null;
    }

    $ownerType = (string) $row['owner_type'];
    $subjectTypes = [
        'customer' => 'Customer',
        'dealer' => 'Dealer',
        'technician' => 'Technician'
    ];
    return [
        'subject_type' => $subjectTypes[$ownerType] ?? 'Account',
        'subject_name' => $row['subject_name'] !== null ? (string) $row['subject_name'] : ''
    ];
}

function findTransactionSubject(mysqli $conn, string $sourceType, string $sourceId, string $transactionId): ?array
{
    switch ($sourceType) {
        case 'stock_allocations':
            return findTransactionAllocationOwner($conn, $sourceId);
        case 'customer_payments':
            $row = findTransactionLookupRow(
                $conn,
                'SELECT c.username AS subject_name
                 FROM customer_payments cp
                 INNER JOIN customers c ON c.id = cp.customer_id
                 WHERE cp.id = ?
                 LIMIT 1',
                $sourceId
            );
            return $row ? ['subject_type' => 'Customer', 'subject_name' => (string) $row['subject_name']] : null;
        case 'customer_cash_collections':
        case 'customer_cash_settlements':
            $collectionTable = $sourceType === 'customer_cash_collections'
                ? 'customer_cash_collections'
                : 'customer_cash_settlements';
            $row = findTransactionLookupRow(
                $conn,
                "SELECT c.username AS subject_name
                 FROM $collectionTable source_record
                 INNER JOIN customers c ON c.id = source_record.customer_id
                 WHERE source_record.id = ?
                 LIMIT 1",
                $sourceId
            );
            return $row ? ['subject_type' => 'Customer', 'subject_name' => (string) $row['subject_name']] : null;
        case 'dealer_sim_allocation_payments':
            $row = findTransactionLookupRow(
                $conn,
                'SELECT allocation_id
                 FROM dealer_sim_allocation_payments
                 WHERE id = ?
                 LIMIT 1',
                $sourceId
            );
            return $row ? findTransactionAllocationOwner($conn, (string) $row['allocation_id']) : null;
        case 'dealer_allocation_payment_requests':
            $row = findTransactionLookupRow(
                $conn,
                'SELECT allocation_id
                 FROM dealer_allocation_payment_requests
                 WHERE request_key = ? AND transaction_id = ?
                 LIMIT 1',
                $sourceId,
                $transactionId
            );
            return $row ? findTransactionAllocationOwner($conn, (string) $row['allocation_id']) : null;
        case 'renewal_history':
            $row = findTransactionLookupRow(
                $conn,
                'SELECT allocation_id
                 FROM renewal_history
                 WHERE id = ?
                 LIMIT 1',
                $sourceId
            );
            return $row ? findTransactionAllocationOwner($conn, (string) $row['allocation_id']) : null;
        case 'cash_collection_settlements':
            $row = findTransactionLookupRow(
                $conn,
                "SELECT
                    CASE
                        WHEN COUNT(DISTINCT c.username) > 0 THEN 'Customer(s)'
                        ELSE s.recipient_type
                    END AS subject_type,
                    COALESCE(
                        GROUP_CONCAT(DISTINCT c.username ORDER BY c.username SEPARATOR ', '),
                        CASE
                            WHEN s.recipient_type = 'Dealer' THEN d.dealer_name
                            WHEN s.recipient_type = 'Technician' THEN t.technician_name
                        END
                    ) AS subject_name
                 FROM cash_collection_settlements s
                 LEFT JOIN cash_collection_settlement_allocations a ON a.settlement_id = s.id
                 LEFT JOIN customer_cash_collections collection ON collection.id = a.collection_id
                 LEFT JOIN customers c ON c.id = collection.customer_id
                 LEFT JOIN dealers d ON s.recipient_type = 'Dealer' AND d.id = s.recipient_id
                 LEFT JOIN technicians t ON s.recipient_type = 'Technician' AND t.id = s.recipient_id
                 WHERE s.id = ?
                 GROUP BY s.id
                 LIMIT 1",
                $sourceId
            );
            return $row ? [
                'subject_type' => (string) $row['subject_type'],
                'subject_name' => (string) ($row['subject_name'] ?? '')
            ] : null;
        default:
            return null;
    }
}

$transactionId = trim((string) ($_GET['transaction_id'] ?? ''));
if (!preg_match('/^[0-9]{6}$/', $transactionId)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$sourceLabels = [
    'stock_allocations' => 'Stock Allocation Payment',
    'dealer_sim_allocation_payments' => 'Dealer SIM Payment',
    'dealer_allocation_payment_requests' => 'Dealer Bulk Payment Request',
    'customer_payments' => 'Customer Payment',
    'renewal_history' => 'SIM Renewal / Lifecycle Payment',
    'cash_collection_settlements' => 'Cash Collection Settlement',
    'customer_cash_collections' => 'Customer Cash Collection',
    'customer_cash_settlements' => 'Customer Cash Settlement'
];

try {
    $matches = [];
    $registered = $conn->prepare(
        'SELECT source_type, source_id, created_at
         FROM transaction_id_registry
         WHERE transaction_id = ?
         LIMIT 1'
    );
    if (!$registered) {
        throw new RuntimeException('Unable to prepare transaction ID lookup.');
    }
    $registered->bind_param('s', $transactionId);
    if (!$registered->execute()) {
        $registered->close();
        throw new RuntimeException('Unable to search the transaction ID registry.');
    }
    $registeredRecord = $registered->get_result()->fetch_assoc();
    $registered->close();

    if ($registeredRecord) {
        $sourceType = (string) $registeredRecord['source_type'];
        $subject = findTransactionSubject(
            $conn,
            $sourceType,
            (string) $registeredRecord['source_id'],
            $transactionId
        );
        $matches[] = [
            'source_type' => $sourceType,
            'source_label' => $sourceLabels[$sourceType] ?? 'Other Payment Record',
            'subject_type' => $subject['subject_type'] ?? 'Customer / Owner',
            'subject_name' => $subject['subject_name'] ?? '',
            'status' => 'Registered',
            'created_at' => $registeredRecord['created_at']
        ];
    }

    $pending = $conn->prepare(
        "SELECT request.id, c.username AS customer_name, request.amount, request.status, request.created_at
         FROM customer_payment_installment_requests request
         INNER JOIN customers c ON c.id = request.customer_id
         WHERE request.transaction_id = ?
           AND request.status IN ('Pending Verification', 'Applying')
         ORDER BY request.id ASC"
    );
    if (!$pending) {
        throw new RuntimeException('Unable to prepare pending installment lookup.');
    }
    $pending->bind_param('s', $transactionId);
    if (!$pending->execute()) {
        $pending->close();
        throw new RuntimeException('Unable to search pending installment requests.');
    }
    $pendingResults = $pending->get_result();
    while ($pendingRecord = $pendingResults->fetch_assoc()) {
        $matches[] = [
            'source_type' => 'customer_payment_installment_requests',
            'source_label' => 'Customer Payment Installment Request',
            'subject_type' => 'Customer',
            'subject_name' => (string) $pendingRecord['customer_name'],
            'amount' => (float) $pendingRecord['amount'],
            'status' => (string) $pendingRecord['status'],
            'created_at' => $pendingRecord['created_at']
        ];
    }
    $pending->close();
    $conn->close();

    sendResponse(true, 'Transaction ID lookup completed.', [
        'transaction_id' => $transactionId,
        'used' => count($matches) > 0,
        'matches' => $matches
    ]);
} catch (Throwable $error) {
    error_log('Transaction ID lookup failed: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to look up this transaction ID.', [], [], 500);
}

?>
