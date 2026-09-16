<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('support.view');
$data = json_decode(file_get_contents('php://input'));
$mobile = trim((string) ($data->mobile_no ?? ''));
$vehicle = trim((string) ($data->vehicle_no ?? ''));
$imei = trim((string) ($data->imei_no ?? ''));
$username = trim((string) ($data->username ?? ''));
if ($mobile === '') sendResponse(false, 'Please enter Mobile No.', [], [], 400);
if ($vehicle === '' && $imei === '' && $username === '') sendResponse(false, 'Please enter at least one of Vehicle No, IMEI No or Username.', [], [], 400);

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
$stmt = $conn->prepare("SELECT c.id, c.username, c.primary_mobile_no, c.secondary_mobile_no, p.platform_name, cv.id AS vehicle_id, cv.vehicle_no, cv.imei_no, cr.next_renewal_date FROM customers c LEFT JOIN platforms p ON p.id = c.platform_id LEFT JOIN customer_vehicle_details cv ON cv.id = (SELECT v.id FROM customer_vehicle_details v WHERE v.customer_id = c.id ORDER BY v.created_at DESC, v.id DESC LIMIT 1) LEFT JOIN customer_renewals cr ON cr.customer_id = c.id WHERE c.primary_mobile_no = ? OR c.secondary_mobile_no = ? OR LOWER(c.username) = LOWER(?) OR cv.vehicle_no = ? OR cv.imei_no = ?");
$stmt->bind_param('sssss', $mobile, $mobile, $username, $vehicle, $imei);
$stmt->execute();
$result = $stmt->get_result();
$matches = [];
while ($row = $result->fetch_assoc()) $matches[(int) $row['id']] = $row;
$stmt->close();
$conn->close();

$valid = [];
foreach ($matches as $row) {
    $mobileMatch = $row['primary_mobile_no'] === $mobile || $row['secondary_mobile_no'] === $mobile;
    $vehicleMatch = $vehicle === '' || $row['vehicle_no'] === $vehicle;
    $imeiMatch = $imei === '' || $row['imei_no'] === $imei;
    $usernameMatch = $username === '' || strcasecmp($row['username'], $username) === 0;
    if ($mobileMatch && $vehicleMatch && $imeiMatch && $usernameMatch) $valid[] = $row;
}
if (count($valid) === 0) {
    if (count($matches) > 0) sendResponse(false, 'Customer verification failed. Mobile number and identifier do not belong to the same customer.', [], [], 400);
    sendResponse(false, 'Customer not found with the provided details.', [], [], 404);
}
if (count($valid) > 1) sendResponse(false, 'Customer verification is ambiguous. Please provide one unique identifier.', [], [], 409);
$row = $valid[0];
sendResponse(true, 'Customer verified successfully.', ['customer' => [
    'username' => $row['username'],
    'mobile_no' => $row['primary_mobile_no'],
    'imei_no' => $row['imei_no'],
    'vehicle_no' => $row['vehicle_no'],
    'expiry_date' => $row['next_renewal_date'],
    'platform' => $row['platform_name'],
    'customer_id' => (int) $row['id'],
    'vehicle_id' => $row['vehicle_id'] ? (int) $row['vehicle_id'] : null
]]);
?>
