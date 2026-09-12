<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

requirePermission('customer_reports.view');

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

$search = trim((string)($_GET['search'] ?? ''));
$location = trim((string)($_GET['location'] ?? ''));
$month = (int)($_GET['month'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$installationPerson = trim((string)($_GET['installation_person'] ?? ''));
$leadClosure = (int)($_GET['lead_closure'] ?? 0);
$payment = strtolower(trim((string)($_GET['payment'] ?? '')));
$deviceType = (int)($_GET['device_type'] ?? 0);
$validity = (int)($_GET['validity'] ?? 0);

$conditions = ['1 = 1'];
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $conditions[] = '(c.username LIKE ? OR c.primary_mobile_no LIKE ? OR c.secondary_mobile_no LIKE ? OR cv.vehicle_no LIKE ? OR cv.imei_no LIKE ? OR cv.sim_no_1 LIKE ? OR cv.sim_no_2 LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
    $types .= 'sssssss';
}
if ($location !== '') {
    $conditions[] = 'c.location = ?';
    $params[] = $location;
    $types .= 's';
}
if ($month >= 1 && $month <= 12) {
    $conditions[] = 'MONTH(ci.installation_date) = ?';
    $params[] = $month;
    $types .= 'i';
}
if ($year > 0) {
    $conditions[] = 'YEAR(ci.installation_date) = ?';
    $params[] = $year;
    $types .= 'i';
}
if ($installationPerson !== '') {
    $parts = explode(':', $installationPerson, 2);
    if (count($parts) === 2 && in_array($parts[0], ['Technician', 'Dealer'], true) && (int)$parts[1] > 0) {
        $conditions[] = 'ci.installation_person_type = ? AND ci.installation_person_id = ?';
        $params[] = $parts[0];
        $params[] = (int)$parts[1];
        $types .= 'si';
    }
}
if ($leadClosure > 0) {
    $conditions[] = 'ci.lead_closure_id = ?';
    $params[] = $leadClosure;
    $types .= 'i';
}
if ($payment === 'paid') {
    $conditions[] = "cp.payment_status = 'Paid'";
} elseif ($payment === 'partially_paid') {
    $conditions[] = "cp.payment_status = 'Partially Paid'";
} elseif ($payment === 'not_paid') {
    $conditions[] = "cp.payment_status = 'Not Paid'";
} elseif ($payment === 'pending') {
    $conditions[] = "cp.payment_status IN ('Not Paid', 'Partially Paid', 'Pending')";
}
if ($deviceType > 0) {
    $conditions[] = 'cv.device_model_id = ?';
    $params[] = $deviceType;
    $types .= 'i';
}
if ($validity > 0) {
    $conditions[] = 'cv.validity_months = ?';
    $params[] = $validity;
    $types .= 'i';
}

$where = implode(' AND ', $conditions);
$sql = "
    SELECT
        c.id,
        c.username,
        c.primary_mobile_no,
        c.secondary_mobile_no,
        c.email,
        c.location,
        c.pincode,
        cv.vehicle_no,
        vt.vehicle_type,
        dt.device_type AS device_type,
        cv.imei_no,
        cv.sim_no_1,
        cv.sim_no_2,
        cv.validity_months,
        ci.installation_person_type,
        CASE WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name ELSE t.technician_name END AS installation_person,
        lc.lead_closure_name AS lead_closure,
        ci.installation_date,
        cp.payment_status,
        cp.amount_paid,
        cp.amount_pending,
        c.created_at
    FROM customers c
    LEFT JOIN customer_vehicle_details cv ON cv.id = (
        SELECT id FROM customer_vehicle_details
        WHERE customer_id = c.id
        ORDER BY created_at DESC, id DESC LIMIT 1
    )
    LEFT JOIN vehicle_types vt ON vt.id = cv.vehicle_type_id
    LEFT JOIN device_types dt ON dt.id = cv.device_model_id
    LEFT JOIN customer_installations ci ON ci.id = (
        SELECT id FROM customer_installations
        WHERE customer_id = c.id
        ORDER BY created_at DESC, id DESC LIMIT 1
    )
    LEFT JOIN technicians t ON t.id = ci.installation_person_id AND ci.installation_person_type = 'Technician'
    LEFT JOIN dealers d ON d.id = ci.installation_person_id AND ci.installation_person_type = 'Dealer'
    LEFT JOIN lead_closures lc ON lc.id = ci.lead_closure_id
    LEFT JOIN customer_payments cp ON cp.id = (
        SELECT id FROM customer_payments
        WHERE customer_id = c.id
        ORDER BY created_at DESC, id DESC LIMIT 1
    )
    WHERE $where
    ORDER BY c.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    sendResponse(false, 'Failed to prepare customer report query.', [], ['error' => $conn->error], 500);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$reports = [];
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

$locations = [];
$optionResult = $conn->query('SELECT DISTINCT location FROM customers WHERE location IS NOT NULL AND location <> \'\' ORDER BY location');
while ($optionResult && ($row = $optionResult->fetch_assoc())) $locations[] = $row['location'];

$years = [];
$optionResult = $conn->query('SELECT DISTINCT YEAR(installation_date) AS year FROM customer_installations WHERE installation_date IS NOT NULL ORDER BY year DESC');
while ($optionResult && ($row = $optionResult->fetch_assoc())) $years[] = (int)$row['year'];

$months = [];
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
foreach ($monthNames as $monthValue => $monthLabel) {
    $months[] = ['value' => $monthValue, 'label' => $monthLabel];
}

$people = [];
$optionResult = $conn->query("SELECT 'Technician' AS person_type, t.id, t.technician_name AS name, NULL AS dealer_status FROM technicians t UNION ALL SELECT 'Dealer' AS person_type, d.id, d.dealer_name AS name, d.installation_status AS dealer_status FROM dealers d WHERE d.installation_status IN ('Onsite', 'Offsite') ORDER BY name, person_type");
while ($optionResult && ($row = $optionResult->fetch_assoc())) {
    $typeLabel = $row['person_type'] === 'Dealer' ? $row['dealer_status'] . ' Dealer' : 'Technician';
    $people[] = ['value' => $row['person_type'] . ':' . $row['id'], 'label' => $row['name'], 'type' => $typeLabel];
}

$leadClosures = [];
$optionResult = $conn->query("SELECT id, lead_closure_name FROM lead_closures WHERE status = 'Active' OR status IS NULL ORDER BY lead_closure_name");
while ($optionResult && ($row = $optionResult->fetch_assoc())) $leadClosures[] = ['id' => (int)$row['id'], 'name' => $row['lead_closure_name']];

$deviceTypes = [];
$optionResult = $conn->query('SELECT id, device_type FROM device_types ORDER BY device_type');
while ($optionResult && ($row = $optionResult->fetch_assoc())) $deviceTypes[] = ['id' => (int)$row['id'], 'name' => $row['device_type']];

$validities = [];
$optionResult = $conn->query("SELECT months FROM sim_validities WHERE status = 'active' ORDER BY months");
while ($optionResult && ($row = $optionResult->fetch_assoc())) $validities[] = (int)$row['months'];

$conn->close();
sendResponse(true, 'Customer reports fetched successfully.', [
    'reports' => $reports,
    'options' => compact('locations', 'months', 'years', 'people', 'leadClosures', 'deviceTypes', 'validities')
]);
?>
