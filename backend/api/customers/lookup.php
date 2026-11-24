<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission(['customers.add', 'customers.edit']);

$customerId = (int) ($_GET['customer_id'] ?? 0);
$platformId = (int) ($_GET['platform_id'] ?? 0);
if ($customerId <= 0 || $platformId <= 0) {
    sendResponse(false, 'Customer ID and platform ID are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'SELECT c.id, c.platform_id, p.platform_name, c.username,
            c.primary_mobile_no, c.secondary_mobile_no, c.email,
            c.location, c.pincode
     FROM customers c
     LEFT JOIN platforms p ON p.id = c.platform_id
     WHERE c.id = ? AND c.platform_id = ?
     LIMIT 1'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare customer lookup.', [], [], 500);
}

$stmt->bind_param('ii', $customerId, $platformId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch customer details.', [], [], 500);
}

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$customer) {
    sendResponse(false, 'Customer was not found for the selected platform.', [], [], 404);
}

$customer['id'] = (int) $customer['id'];
$customer['platform_id'] = (int) $customer['platform_id'];
sendResponse(true, 'Customer details fetched.', ['customer' => $customer]);
