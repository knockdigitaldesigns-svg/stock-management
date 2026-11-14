<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireAnyPermission(['customers.add', 'customers.edit']);

$platformId = (int) ($_GET['platform_id'] ?? 0);
$query = trim((string) ($_GET['query'] ?? ''));
if ($platformId <= 0 || $query === '') {
    sendResponse(true, 'Username suggestions fetched.', ['usernames' => []]);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$stmt = $conn->prepare(
    'SELECT DISTINCT TRIM(username) AS username
     FROM customers
     WHERE platform_id = ?
       AND TRIM(username) LIKE ?
       AND TRIM(username) <> \'\'
     ORDER BY username ASC
     LIMIT 10'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare username suggestions query.', [], [], 500);
}

$pattern = '%' . $query . '%';
$stmt->bind_param('is', $platformId, $pattern);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch username suggestions.', [], [], 500);
}

$result = $stmt->get_result();
$usernames = [];
while ($row = $result->fetch_assoc()) {
    $usernames[] = (string) $row['username'];
}

$stmt->close();
$conn->close();
sendResponse(true, 'Username suggestions fetched.', ['usernames' => $usernames]);
