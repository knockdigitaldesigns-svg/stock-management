<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once 'distribution_common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
requireLeadDistributionAccess($conn);
$employees = getLeadDistributionEmployees($conn);
$conn->close();

sendResponse(true, 'Eligible employees loaded successfully.', ['employees' => $employees]);
?>
