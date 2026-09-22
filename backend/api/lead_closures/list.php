<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('lead_closures.view');

$conn = (new Database())->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$result = $conn->query(
    'SELECT
        id,
        lead_closure_name,
        mobile_no,
        location,
        status,
        created_at
     FROM lead_closures
     ORDER BY lead_closure_name ASC'
);

$leadClosures = [];

while ($row = $result->fetch_assoc()) {
    $leadClosures[] = $row;
}

$conn->close();

sendResponse(
    true,
    'Lead closures fetched successfully.',
    ['lead_closures' => $leadClosures]
);
?>