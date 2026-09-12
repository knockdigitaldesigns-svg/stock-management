<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.view');
$conn = (new Database())->getConnection();
renewalLifecycle($conn, []);
$conn->close();
sendResponse(true, 'Renewal lifecycle synchronized successfully.');
?>