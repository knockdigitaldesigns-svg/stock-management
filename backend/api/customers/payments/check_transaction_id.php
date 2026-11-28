<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission(['customers.add', 'customers.edit']);

$transactionId = trim((string) ($_GET['transaction_id'] ?? ''));
if (!preg_match('/^[0-9]{6}$/', $transactionId)) {
    sendResponse(false, 'Transaction ID must contain exactly 6 digits.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $registered = $conn->prepare(
        'SELECT 1 FROM transaction_id_registry WHERE transaction_id = ? LIMIT 1'
    );
    if (!$registered) {
        throw new RuntimeException('Unable to prepare transaction ID availability check.');
    }
    $registered->bind_param('s', $transactionId);
    if (!$registered->execute()) {
        $registered->close();
        throw new RuntimeException('Unable to check transaction ID availability.');
    }
    $isRegistered = $registered->get_result()->num_rows > 0;
    $registered->close();

    $hasPendingRequest = false;
    if (!$isRegistered) {
        $pending = $conn->prepare(
            "SELECT 1
             FROM customer_payment_installment_requests
             WHERE transaction_id = ?
               AND status IN ('Pending Verification', 'Applying')
             LIMIT 1"
        );
        if (!$pending) {
            throw new RuntimeException('Unable to prepare pending transaction ID check.');
        }
        $pending->bind_param('s', $transactionId);
        if (!$pending->execute()) {
            $pending->close();
            throw new RuntimeException('Unable to check pending transaction IDs.');
        }
        $hasPendingRequest = $pending->get_result()->num_rows > 0;
        $pending->close();
    }

    $available = !$isRegistered && !$hasPendingRequest;
    $conn->close();
    sendResponse(
        true,
        $available ? 'Transaction ID is available.' : 'Transaction ID is already in use.',
        ['available' => $available]
    );
} catch (Throwable $error) {
    error_log('Transaction ID availability check failed: ' . $error->getMessage());
    $conn->close();
    sendResponse(false, 'Unable to verify transaction ID availability.', [], [], 500);
}

?>
