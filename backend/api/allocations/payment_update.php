<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/stock_allocation_payment.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}
requireAnyPermission([
    'dealers.edit',
    'technicians.edit',
    'stock.update'
]);

$currentUser = getCurrentUserFromToken();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$allocationId = (int) ($data['allocation_id'] ?? 0);
if ($allocationId <= 0) {
    sendResponse(false, 'Valid allocation ID is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$conn->begin_transaction();
try {
    $payment = updateStockAllocationPayment($conn, $allocationId, $data, $currentUser);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Payment updated successfully.', $payment);
} catch (InvalidArgumentException $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 400);
} catch (RuntimeException $e) {
    $conn->rollback();
    $conn->close();
    $statusCode = $e->getMessage() === 'Allocation not found.' ? 404 : 500;
    sendResponse(false, $e->getMessage(), [], [], $statusCode);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $e->getMessage(), [], [], 500);
}
?>
