<?php

require_once __DIR__ . '/common.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requirePermission('crm_closures.add');
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
crmClosureRequireWorkflowSchema($conn);

$platformResult = $conn->query(
    "SELECT id, platform_name
     FROM platforms
     WHERE status = 'Active'
     ORDER BY platform_name ASC, id ASC"
);
if (!$platformResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load active platforms: ' . $error, [], [], 500);
}
$platforms = [];
while ($row = $platformResult->fetch_assoc()) {
    $platforms[] = $row;
}

$vehicleStatusColumn = $conn->query("SHOW COLUMNS FROM vehicle_types LIKE 'status'");
if (!$vehicleStatusColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify vehicle type options: ' . $error, [], [], 500);
}
$vehicleCondition = $vehicleStatusColumn->num_rows > 0
    ? " WHERE status = 'Active' OR status IS NULL OR status = ''"
    : '';
$vehicleResult = $conn->query('SELECT vehicle_type FROM vehicle_types' . $vehicleCondition . ' ORDER BY vehicle_type ASC');
if (!$vehicleResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load vehicle types: ' . $error, [], [], 500);
}
$vehicleTypes = [];
while ($row = $vehicleResult->fetch_assoc()) {
    $vehicleTypes[] = $row['vehicle_type'];
}

$deviceStatusColumn = $conn->query("SHOW COLUMNS FROM device_types LIKE 'status'");
if (!$deviceStatusColumn) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to verify device type options: ' . $error, [], [], 500);
}
$deviceCondition = $deviceStatusColumn->num_rows > 0
    ? " WHERE status = 'Active' OR status IS NULL OR status = ''"
    : '';
$deviceResult = $conn->query('SELECT device_type FROM device_types' . $deviceCondition . ' ORDER BY device_type ASC');
if (!$deviceResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to load device types: ' . $error, [], [], 500);
}
$deviceTypes = [];
while ($row = $deviceResult->fetch_assoc()) {
    $deviceTypes[] = $row['device_type'];
}

$conn->close();
sendResponse(true, 'Lead converter options loaded successfully.', [
    'platforms' => $platforms,
    'vehicle_types' => $vehicleTypes,
    'device_types' => $deviceTypes
]);
?>
