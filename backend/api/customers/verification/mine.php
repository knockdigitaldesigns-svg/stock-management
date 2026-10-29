<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

function collectCustomerVerificationValues(array $source, array $allowedKeys): array
{
    $values = [];
    foreach ($source as $key => $value) {
        if (is_array($value)) {
            $values = array_merge($values, collectCustomerVerificationValues($value, $allowedKeys));
            continue;
        }
        if (is_string($key) && isset($allowedKeys[strtolower($key)])
            && is_scalar($value) && trim((string) $value) !== '') {
            $values[] = trim((string) $value);
        }
    }
    return array_values(array_unique($values));
}

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$currentUser = authenticate();
requireAnyPermission(['customers.add', 'customers.edit', 'courier.add']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$requestTypeFilter = trim((string) ($_GET['request_type'] ?? ''));

$normalize = static function (string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$searchTerms = preg_split('/\s+/u', $normalize($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$customerKeys = array_fill_keys([
    'username', 'customer_name', 'customer_username', 'customername', 'name'
], true);

$stmt = $conn->prepare(
    'SELECT id, status, submission_data, rejection_reason, submitted_at, decided_at
     FROM customer_payment_verification_submissions
     WHERE submitter_user_id = ?
       AND status <> \'Committed\'
     ORDER BY COALESCE(submitted_at, created_at) DESC, id DESC
    '
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare your submissions query.', [], [], 500);
}
$stmt->bind_param('i', $userId);
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch your submissions.', [], [], 500);
}
$result = $stmt->get_result();
$submissions = [];
$total = 0;
$statusCounts = [
    'Draft' => 0,
    'Pending Verification' => 0,
    'Approved' => 0,
    'Rejected' => 0
];
while ($row = $result->fetch_assoc()) {
    try {
        $submission = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    } catch (Throwable $error) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'A saved customer draft could not be read.', [], [], 500);
    }
    $customerSources = array_filter([
        $submission['customer'] ?? null,
        $submission['step1'] ?? null
    ], 'is_array');
    $customerValues = [];
    foreach ($customerSources as $customerSource) {
        $customerValues = array_merge($customerValues, collectCustomerVerificationValues($customerSource, $customerKeys));
    }
    $customerValues = array_values(array_unique($customerValues));
    $customerName = (string) ($customerValues[0] ?? '—');
    $payment = $submission['payment_part2'] ?? [];
    $vehicleSources = [];
    foreach (['vehicle', 'step2', 'vehicles'] as $vehicleKey) {
        if (is_array($submission[$vehicleKey] ?? null)) {
            $vehicleSources[] = $submission[$vehicleKey];
        }
    }
    $vehicleValues = [
        'vehicle_numbers' => [],
        'imei_numbers' => [],
        'sim_numbers' => []
    ];
    foreach ($vehicleSources as $vehicleSource) {
        $vehicleValues['vehicle_numbers'] = array_merge($vehicleValues['vehicle_numbers'], collectCustomerVerificationValues($vehicleSource, array_fill_keys([
            'vehicle_no', 'vehicle_number', 'registration_number'
        ], true)));
        $vehicleValues['imei_numbers'] = array_merge($vehicleValues['imei_numbers'], collectCustomerVerificationValues($vehicleSource, array_fill_keys([
            'imei_no', 'imei'
        ], true)));
        $vehicleValues['sim_numbers'] = array_merge($vehicleValues['sim_numbers'], collectCustomerVerificationValues($vehicleSource, array_fill_keys([
            'sim_no_1', 'sim_no_2', 'sim_no', 'sim_number', 'sim1', 'sim2'
        ], true)));
    }
    foreach ($vehicleValues as &$values) {
        $values = array_values(array_unique($values));
    }
    unset($values);
    $requestType = ($submission['source'] ?? '') === 'courier' ? 'courier' : 'customer_payment';
    if ($requestTypeFilter !== '' && $requestType !== $requestTypeFilter) {
        continue;
    }
    $searchableValues = array_merge(
        $customerValues,
        $vehicleValues['vehicle_numbers'],
        $vehicleValues['imei_numbers'],
        $vehicleValues['sim_numbers']
    );
    $matchesSearch = true;
    foreach ($searchTerms as $searchTerm) {
        $termMatched = false;
        foreach ($searchableValues as $value) {
            $position = function_exists('mb_stripos')
                ? mb_stripos($normalize($value), $searchTerm, 0, 'UTF-8')
                : stripos($value, $searchTerm);
            if ($position !== false) {
                $termMatched = true;
                break;
            }
        }
        if (!$termMatched) {
            $matchesSearch = false;
            break;
        }
    }
    if (!$matchesSearch) {
        continue;
    }
    if ($row['status'] === 'Applying' || $row['status'] === 'Pending Verification') {
        $statusCounts['Pending Verification']++;
    } elseif (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']]++;
    }
    $matchesStatus = $statusFilter === ''
        || ($statusFilter === 'Pending Verification'
            ? in_array($row['status'], ['Pending Verification', 'Applying'], true)
            : $row['status'] === $statusFilter);
    if (!$matchesStatus) {
        continue;
    }
    if ($total >= $offset && $total < $offset + $pageSize) {
        $submissions[] = [
            'id' => (int) $row['id'],
            'submission_type' => $requestType === 'courier' ? 'Courier Request' : 'Customer Payment',
            'status' => $row['status'],
            'customer_name' => $customerName,
            'primary_mobile_no' => (string) (($customerSources[0]['primary_mobile_no'] ?? $customerSources[1]['primary_mobile_no'] ?? '') ?: ''),
            'location' => (string) (($customerSources[0]['location'] ?? $customerSources[1]['location'] ?? '') ?: ''),
            'vehicle_numbers' => $vehicleValues['vehicle_numbers'],
            'imei_numbers' => $vehicleValues['imei_numbers'],
            'sim_numbers' => $vehicleValues['sim_numbers'],
            'payment_status' => (string) ($payment['payment_status'] ?? ''),
            'rejection_reason' => $row['rejection_reason'],
            'submitted_at' => $row['submitted_at'],
            'decided_at' => $row['decided_at']
        ];
    }
    $total++;
}
$stmt->close();
$conn->close();
sendResponse(true, 'Your customer submissions were fetched.', [
    'submissions' => $submissions,
    'status_counts' => $statusCounts,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
