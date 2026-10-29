<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';
require_once '../../utils/excel_reader.php';
require_once '../../utils/date.php';
require_once '../../utils/payment_modes.php';
require_once '../../utils/customer_payment_verification.php';
require_once '../../utils/validation_error_response.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('customers.add');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    sendResponse(false, 'Please upload a valid Excel file.', [], [], 400);
}

$file = $_FILES['file'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    sendResponse(false, 'Only .xlsx files are allowed.', [], [], 400);
}

if (!isset($file['size']) || $file['size'] === 0) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

/*
|--------------------------------------------------------------------------
| FIELD DEFINITIONS & ALIASES
|--------------------------------------------------------------------------
*/

$fieldAliases = [
    'Platform' => ['platform'],
    'Username' => ['username', 'user_name'],
    'Primary Mobile No' => ['primary mobile no', 'primary mobile', 'primary_mobile_no', 'mobile no', 'mobile_no', 'mobile', 'primary mobile number'],
    'Secondary Mobile No' => ['secondary mobile no', 'secondary mobile', 'secondary_mobile_no', 'secondary mobile number'],
    'Email' => ['email', 'email address', 'email_id'],
    'Location' => ['location', 'city'],
    'Pincode' => ['pincode', 'pin code', 'pin', 'zipcode'],
    'Customer Status' => ['customer status', 'status'],
    'Vehicle No' => ['vehicle no', 'vehicle number', 'vehicle_no', 'vehicleno'],
    'Vehicle Type' => ['vehicle type', 'vehicle_type', 'vehicletype'],
    'IMEI No' => ['imei no', 'imei', 'imei_no', 'imei number'],
    'SIM No 1' => ['sim no 1', 'sim 1', 'sim_no_1', 'sim no. 1', 'sim number 1', 'sim1'],
    'SIM No 2' => ['sim no 2', 'sim 2', 'sim_no_2', 'sim no. 2', 'sim number 2', 'sim2'],
    'Device Model' => ['device model', 'device_model', 'device model id', 'model'],
    'SIM Validity' => ['sim validity', 'sim_validity', 'validity', 'validity months'],
    'Installation Person' => ['installation person', 'installation_person', 'installer name', 'installer'],
    'Installation Person Type' => ['installation person type', 'installation_person_type', 'installer type', 'person type'],
    'Lead Closure By' => ['lead closure by', 'lead_closure_by', 'lead closure', 'lead_closure'],
    'Installation Date' => ['installation date', 'installation_date', 'install date'],
    'Total Sale Amount' => ['total sale amount', 'total_sale_amount', 'sale amount', 'sale_amount'],
    'Transaction ID' => ['transaction id', 'transaction_id', 'txn id', 'transaction no'],
    'Payment Mode' => ['payment mode', 'payment_mode', 'mode of payment', 'mode'],
    'Device Charge' => ['device charge', 'device_charge', 'device cost'],
    'Software Charge' => ['software charge', 'software_charge', 'software cost'],
    'Technician Charge' => ['technician charge', 'technician_charge', 'installation charge'],
    'SIM Charge' => ['sim charge', 'sim_charge', 'sim cost'],
    'Courier Charge' => ['courier charge', 'courier_charge', 'courier cost'],
    'Total Amount' => ['total amount', 'total_amount', 'total'],
    'Amount Paid' => ['amount paid', 'amount_paid', 'paid amount'],
    'Amount Pending' => ['amount pending', 'amount_pending', 'pending amount', 'balance'],
    'Payment Status' => ['payment status', 'payment_status']
];

$mandatoryHeaders = [
    'Platform',
    'Username',
    'Primary Mobile No',
    'Location',
    'Pincode',
    'Vehicle No',
    'Vehicle Type',
    'IMEI No',
    'SIM No 1',
    'Device Model',
    'SIM Validity',
    'Installation Person',
    'Installation Person Type',
    'Lead Closure By',
    'Installation Date',
    'Total Sale Amount'
];

/*
|--------------------------------------------------------------------------
| PARSE EXCEL USING XLSX ZIP ARCHIVE
|--------------------------------------------------------------------------
*/

if (!class_exists('ZipArchive')) {
    sendResponse(false, 'XLSX support is not available on the server.', [], [], 500);
}

$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    sendResponse(false, 'Invalid or corrupted Excel file.', [], [], 400);
}

$loadXml = function ($path) use ($zip) {
    $xml = $zip->getFromName($path);
    return $xml === false ? false : simplexml_load_string($xml);
};

$sharedStrings = [];
$sharedXml = $loadXml('xl/sharedStrings.xml');
if ($sharedXml !== false) {
    foreach ($sharedXml->xpath('//*[local-name()="si"]') ?: [] as $item) {
        $sharedStrings[] = trim(implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]') ?: [])));
    }
}

$workbookXml = $loadXml('xl/workbook.xml');
$relsXml = $loadXml('xl/_rels/workbook.xml.rels');
if ($workbookXml === false || $relsXml === false) {
    $zip->close();
    sendResponse(false, 'Invalid or corrupted Excel workbook.', [], [], 400);
}

$relationships = [];
$worksheetTargets = [];
foreach ($relsXml->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
    $id = (string) $relationship['Id'];
    $target = (string) $relationship['Target'];
    $type = (string) $relationship['Type'];
    $relationships[$id] = $target;
    if (substr($type, -9) === 'worksheet' || strpos($type, '/worksheet') !== false) {
        $worksheetTargets[] = $target;
    }
}

$sheets = $workbookXml->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
if (!$sheets) {
    $zip->close();
    sendResponse(false, 'Excel workbook contains no worksheets.', [], [], 400);
}

$firstSheet = $sheets[0];
$relId = (string) ($firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
if (!$relId) {
    $relId = (string) ($firstSheet->attributes('http://purl.oclc.org/ooxml/officeDocument/relationships')['id'] ?? '');
}
if (!$relId) {
    foreach ($firstSheet->attributes() as $attrName => $attrVal) {
        if (strtolower($attrName) === 'id' || strtolower($attrName) === 'rid') {
            $relId = (string) $attrVal;
            break;
        }
    }
}

$target = $relationships[$relId] ?? ($worksheetTargets[0] ?? '');
if (!$target) {
    $zip->close();
    sendResponse(false, 'Invalid or corrupted Excel workbook: first worksheet is missing.', [], [], 400);
}

$cleanTarget = ltrim(str_replace('\\', '/', $target), '/');
$candidatePaths = [
    strpos($cleanTarget, 'xl/') === 0 ? $cleanTarget : 'xl/' . $cleanTarget,
    $cleanTarget
];

$worksheet = false;
foreach ($candidatePaths as $p) {
    $worksheet = $loadXml($p);
    if ($worksheet !== false) break;
}

if ($worksheet === false) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $entryName)) {
            $worksheet = $loadXml($entryName);
            if ($worksheet !== false) break;
        }
    }
}

if ($worksheet === false) {
    $zip->close();
    sendResponse(false, 'Unable to read worksheet from Excel file.', [], [], 400);
}

$rawRows = [];
$rawRowNumbers = [];
foreach ($worksheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
    $values = [];
    foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
        $ref = (string) ($cell['r'] ?? '');
        $index = $ref ? columnIndexFromReference($ref) : count($values);
        while (count($values) < $index) $values[] = '';
        $values[$index] = convertCellValue($cell, $sharedStrings);
    }
    $hasContent = false;
    foreach ($values as $v) {
        if (trim((string) $v) !== '') {
            $hasContent = true;
            break;
        }
    }
    if ($hasContent) {
        $rawRows[] = $values;
        $rawRowNumbers[] = (int) ($rowNode['r'] ?? (count($rawRowNumbers) + 1));
    }
}
$zip->close();

if (empty($rawRows)) {
    sendResponse(false, 'Excel file is empty.', [], [], 400);
}

// Extract headers and build column mapping
$rawHeaders = $rawRows[0];
$normalizedHeaderToIndex = [];
foreach ($rawHeaders as $idx => $headerText) {
    $norm = normalizeExcelHeader($headerText);
    if ($norm !== '') {
        $normalizedHeaderToIndex[$norm] = $idx;
    }
}

$fieldToColIndex = [];
foreach ($fieldAliases as $canonicalField => $aliases) {
    foreach ($aliases as $alias) {
        $normAlias = normalizeExcelHeader($alias);
        if (isset($normalizedHeaderToIndex[$normAlias])) {
            $fieldToColIndex[$canonicalField] = $normalizedHeaderToIndex[$normAlias];
            break;
        }
    }
}

// Verify required columns exist in header
$missingColumns = [];
foreach ($mandatoryHeaders as $mandField) {
    if (!isset($fieldToColIndex[$mandField])) {
        $missingColumns[] = $mandField;
    }
}

if (!empty($missingColumns)) {
    sendValidationErrorResponse(
        'Required customer import columns are missing.',
        array_map(static function ($field) {
            return "Required column '{$field}' is missing.";
        }, $missingColumns)
    );
}

$dataRows = array_slice($rawRows, 1);
if (empty($dataRows)) {
    sendResponse(false, 'Excel file contains no data rows.', [], [], 400);
}

/*
|--------------------------------------------------------------------------
| CONNECT TO DATABASE & PRELOAD MASTER DATA
|--------------------------------------------------------------------------
*/

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

// Preload Platforms
$platformMap = [];
$res = $conn->query("SELECT id, platform_name FROM platforms WHERE status = 'Active' OR status IS NULL");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $platformMap[strtolower(trim($r['platform_name']))] = (int) $r['id'];
    }
}

// Preload Vehicle Types
$vehicleTypeMap = [];
$res = $conn->query("SELECT id, vehicle_type FROM vehicle_types WHERE status = 'Active' OR status IS NULL");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $vehicleTypeMap[strtolower(trim($r['vehicle_type']))] = (int) $r['id'];
    }
}

// Preload Device Models
$deviceModelIdToName = [];
$deviceModelNameToId = [];
$res = $conn->query("SELECT id, device_type FROM device_types");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $nameNorm = strtolower(trim($r['device_type']));
        $deviceModelIdToName[(int) $r['id']] = $nameNorm;
        $deviceModelNameToId[$nameNorm] = (int) $r['id'];
    }
}

// Preload SIM Validities
$validityMap = []; // months int => ['id' => id, 'months' => months]
$res = $conn->query("SELECT id, months FROM sim_validities WHERE status = 'active' OR status IS NULL");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $validityMap[(int) $r['months']] = [
            'id' => (int) $r['id'],
            'months' => (int) $r['months']
        ];
    }
}

// Preload Technicians
$technicianMap = []; // lowercase name => id
$technicianIdToName = [];
$res = $conn->query("SELECT id, technician_name FROM technicians");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $nameNorm = strtolower(trim($r['technician_name']));
        $technicianMap[$nameNorm] = (int) $r['id'];
        $technicianIdToName[(int) $r['id']] = $r['technician_name'];
    }
}

// Preload Dealers
$dealerMap = []; // lowercase name => ['id' => id, 'name' => name, 'status' => status]
$dealerIdMap = []; // id => ['id' => id, 'name' => name, 'status' => status]
$res = $conn->query("SELECT id, dealer_name, installation_status FROM dealers");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $nameNorm = strtolower(trim($r['dealer_name']));
        $statusNorm = strtolower(trim((string) $r['installation_status']));
        $dealerData = [
            'id' => (int) $r['id'],
            'name' => $r['dealer_name'],
            'status' => $statusNorm
        ];
        $dealerMap[$nameNorm] = $dealerData;
        $dealerIdMap[(int) $r['id']] = $dealerData;
    }
}

// Preload Lead Closures
$leadClosureMap = [];
$res = $conn->query("SELECT id, lead_closure_name FROM lead_closures WHERE status = 'Active' OR status IS NULL");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $leadClosureMap[strtolower(trim($r['lead_closure_name']))] = (int) $r['id'];
    }
}

// Preload Existing Customers
$existingCustomersByUsername = [];
$existingUsernameMobileMap = [];
$existingCustomerByKey = [];
$res = $conn->query(
    "SELECT id, platform_id, username, primary_mobile_no, secondary_mobile_no,
            email, location, pincode, status
     FROM customers ORDER BY id ASC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $usernameValue = strtolower(trim((string) $r['username']));
        $mobileValue = trim((string) $r['primary_mobile_no']);

        $platformCustomerKey = (int) $r['platform_id'] . '|' . $usernameValue;
        $existingCustomersByUsername[$platformCustomerKey][] = [
            'primary_mobile_no' => $mobileValue
        ];

        $customerKey = (int) $r['platform_id'] . '|' . $usernameValue . '|' . $mobileValue;
        if (!isset($existingUsernameMobileMap[$customerKey])) {
            $existingUsernameMobileMap[$customerKey] = (int) $r['id'];
            $existingCustomerByKey[$customerKey] = $r;
        }
    }
}

// Preload Existing Customer Vehicle Details
$existingVehicleNos = [];
$existingVehicleCustomerIds = [];
$assignedImeis = [];
$assignedSims = [];
$res = $conn->query("SELECT customer_id, vehicle_no, imei_no, sim_no_1, sim_no_2 FROM customer_vehicle_details");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $vehicleNoValue = strtoupper(trim($r['vehicle_no']));
        $existingVehicleNos[$vehicleNoValue] = true;
        $existingVehicleCustomerIds[$vehicleNoValue] = (int) $r['customer_id'];
        if (!empty($r['imei_no'])) $assignedImeis[trim($r['imei_no'])] = true;
        if (!empty($r['sim_no_1'])) $assignedSims[trim($r['sim_no_1'])] = true;
        if (!empty($r['sim_no_2'])) $assignedSims[trim($r['sim_no_2'])] = true;
    }
}

// Preload Device Stock Inventory
$deviceInventory = []; // imei => ['id' => id, 'device_model_id' => mid, 'status' => status, 'owner_type' => type, 'owner_id' => id]
$res = $conn->query(
    "SELECT d.id, d.imei_no, d.device_model_id, d.status, sa.owner_type, sa.owner_id
     FROM devices d
     LEFT JOIN stock_allocations sa ON sa.id = (
         SELECT MAX(id) FROM stock_allocations WHERE device_id = d.id
     )
     WHERE d.deleted_at IS NULL"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $imei = trim($r['imei_no']);
        $deviceInventory[$imei] = [
            'id' => (int) $r['id'],
            'device_model_id' => (int) $r['device_model_id'],
            'status' => strtolower(trim((string) $r['status'])),
            'owner_type' => strtolower(trim((string) ($r['owner_type'] ?? ''))),
            'owner_id' => (int) ($r['owner_id'] ?? 0)
        ];
    }
}

// Preload SIM Stock Inventory
$simInventory = []; // sim_no => ['id' => id, 'status' => status, 'owner_type' => type, 'owner_id' => id]
$res = $conn->query(
    "SELECT s.id, s.sim_no, s.status, sa.owner_type, sa.owner_id
     FROM sims s
     LEFT JOIN stock_allocations sa ON sa.id = (
         SELECT MAX(id) FROM stock_allocations WHERE sim_id = s.id
     )
     WHERE s.deleted_at IS NULL"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $simNo = trim($r['sim_no']);
        $simInventory[$simNo] = [
            'id' => (int) $r['id'],
            'status' => strtolower(trim((string) $r['status'])),
            'owner_type' => strtolower(trim((string) ($r['owner_type'] ?? ''))),
            'owner_id' => (int) ($r['owner_id'] ?? 0)
        ];
    }
}

/*
|--------------------------------------------------------------------------
| DATE NORMALIZER HELPER
|--------------------------------------------------------------------------
*/

$normalizeDate = function ($rawDate) {
    return parseAndNormalizeDate($rawDate);
};

/*
|--------------------------------------------------------------------------
| VALIDATE EVERY ROW INDEPENDENTLY
|--------------------------------------------------------------------------
*/

$allErrors = [];
$validRows = [];

$batchUsernameMobiles = [];
$batchVehicleNos = [];
$batchVehicleOwners = [];
$batchImeis = [];
$batchSims = [];

foreach ($dataRows as $idx => $row) {
    $rowNumber = $rawRowNumbers[$idx + 1] ?? ($idx + 2);

    $getVal = function ($field) use ($row, $fieldToColIndex) {
        $colIdx = $fieldToColIndex[$field] ?? null;
        return ($colIdx !== null && isset($row[$colIdx])) ? trim((string) $row[$colIdx]) : '';
    };

    // 1. Customer Fields
    $platform = $getVal('Platform');
    $username = $getVal('Username');
    $primaryMobile = $getVal('Primary Mobile No');
    $secondaryMobile = $getVal('Secondary Mobile No');
    $email = $getVal('Email');
    $location = $getVal('Location');
    $pincode = $getVal('Pincode');
    $customerStatus = $getVal('Customer Status');

    // 2. Vehicle Fields
    $vehicleNo = strtoupper($getVal('Vehicle No'));
    $vehicleType = $getVal('Vehicle Type');
    $imeiNo = $getVal('IMEI No');
    $simNo1 = $getVal('SIM No 1');
    $simNo2 = $getVal('SIM No 2');
    $deviceModel = $getVal('Device Model');
    $simValidity = $getVal('SIM Validity');

    // 3. Installation Fields
    $installationPerson = $getVal('Installation Person');
    $installationPersonType = $getVal('Installation Person Type');
    $leadClosureBy = $getVal('Lead Closure By');
    $installationDateRaw = $getVal('Installation Date');

    // 4. Payment Fields
    $totalSaleAmountRaw = $getVal('Total Sale Amount');
    $transactionIdColumn = $fieldToColIndex['Transaction ID'] ?? null;
    $transactionId = ($transactionIdColumn !== null && isset($row[$transactionIdColumn]))
        ? (string) $row[$transactionIdColumn]
        : '';
    $paymentMode = $getVal('Payment Mode');
    $deviceChargeRaw = $getVal('Device Charge');
    $softwareChargeRaw = $getVal('Software Charge');
    $technicianChargeRaw = $getVal('Technician Charge');
    $simChargeRaw = $getVal('SIM Charge');
    $courierChargeRaw = $getVal('Courier Charge');
    $totalAmountRaw = $getVal('Total Amount');
    $amountPaidRaw = $getVal('Amount Paid');
    $amountPendingRaw = $getVal('Amount Pending');
    $paymentStatusRaw = $getVal('Payment Status');

    // ==========================================
    // VALIDATE CUSTOMER SECTION
    // ==========================================

    // Platform
    $platformId = null;
    if ($platform === '') {
        $allErrors[] = "Row {$rowNumber}: Platform - Platform is required.";
    } elseif (!isset($platformMap[strtolower($platform)])) {
        $allErrors[] = "Row {$rowNumber}: Platform - Platform '{$platform}' is invalid or inactive.";
    } else {
        $platformId = $platformMap[strtolower($platform)];
    }

    // Username
    if ($username === '') {
        $allErrors[] = "Row {$rowNumber}: Username - Username is required.";
    } elseif (strlen($username) > 100) {
        $allErrors[] = "Row {$rowNumber}: Username - Value '{$username}' must not exceed 100 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9.@_-]+$/', $username)) {
        $allErrors[] = "Row {$rowNumber}: Username - Value '{$username}' can contain only letters, numbers, @, dot, underscore and hyphen.";
    } else {
        $lowerUser = strtolower($username);
        $platformCustomerKey = (int) ($platformId ?? 0) . '|' . $lowerUser;
        if (isset($batchUsernameMobiles[$platformCustomerKey]) && $batchUsernameMobiles[$platformCustomerKey] !== $primaryMobile) {
            $allErrors[] = "Row {$rowNumber}: Username - Username '{$username}' already exists on platform '{$platform}' with a different primary mobile number '{$primaryMobile}'.";
        } else {
            $batchUsernameMobiles[$platformCustomerKey] = $primaryMobile;
        }

        $dbUserMatches = $existingCustomersByUsername[$platformCustomerKey] ?? [];
        $hasDifferentUsernameMobile = false;
        foreach ($dbUserMatches as $customer) {
            if (trim((string) $customer['primary_mobile_no']) !== $primaryMobile) {
                $hasDifferentUsernameMobile = true;
            }
        }

        if ($hasDifferentUsernameMobile) {
            $allErrors[] = "Row {$rowNumber}: Username - Username '{$username}' already exists on platform '{$platform}' with a different primary mobile number '{$primaryMobile}'.";
        }
    }

    // Primary Mobile
    if ($primaryMobile === '') {
        $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Primary Mobile is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $primaryMobile)) {
        $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Value '{$primaryMobile}' must contain exactly 10 digits.";
    }

    // Secondary Mobile
    if ($secondaryMobile !== '') {
        if (!preg_match('/^[0-9]{10}$/', $secondaryMobile)) {
            $allErrors[] = "Row {$rowNumber}: Secondary Mobile No - Value '{$secondaryMobile}' must contain exactly 10 digits.";
        } elseif ($secondaryMobile === $primaryMobile) {
            $allErrors[] = "Row {$rowNumber}: Secondary Mobile No - Value '{$secondaryMobile}' cannot be the same as Primary Mobile '{$primaryMobile}'.";
        }
    }

    // Email
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $allErrors[] = "Row {$rowNumber}: Email - Value '{$email}' is not a valid email address.";
    }

    // Location
    if ($location === '') {
        $allErrors[] = "Row {$rowNumber}: Location - Location is required.";
    }

    // Pincode
    if ($pincode === '') {
        $allErrors[] = "Row {$rowNumber}: Pincode - Pincode is required.";
    } elseif (!preg_match('/^[0-9]{6}$/', $pincode)) {
        $allErrors[] = "Row {$rowNumber}: Pincode - Value '{$pincode}' must contain exactly 6 digits.";
    }

    // Customer Status
    $resolvedCustomerStatus = 'Active';
    if ($customerStatus !== '') {
        $statusLower = strtolower($customerStatus);
        if ($statusLower === 'active') {
            $resolvedCustomerStatus = 'Active';
        } elseif ($statusLower === 'inactive') {
            $resolvedCustomerStatus = 'Inactive';
        } else {
            $allErrors[] = "Row {$rowNumber}: Customer Status - Value '{$customerStatus}' must be 'Active' or 'Inactive'.";
        }
    }

    // ==========================================
    // VALIDATE VEHICLE SECTION
    // ==========================================

    // Vehicle No
    if ($vehicleNo === '') {
        $allErrors[] = "Row {$rowNumber}: Vehicle No - Vehicle No is required.";
    } else {
        $customerKey = (int) ($platformId ?? 0) . '|' . strtolower(trim($username)) . '|' . trim($primaryMobile);
        if (isset($batchVehicleNos[$vehicleNo])) {
            if (($batchVehicleOwners[$vehicleNo] ?? null) === $customerKey) {
                $allErrors[] = "Row {$rowNumber}: Vehicle No - Vehicle '{$vehicleNo}' already appears under this customer in the upload.";
            } else {
                $allErrors[] = "Row {$rowNumber}: Vehicle No - Duplicate Vehicle No in uploaded Excel ({$vehicleNo}).";
            }
        } else {
            $batchVehicleNos[$vehicleNo] = true;
            $batchVehicleOwners[$vehicleNo] = $customerKey;
        }

        if (isset($existingVehicleNos[$vehicleNo])) {
            $existingCustomerId = $existingVehicleCustomerIds[$vehicleNo] ?? null;
            $matchedCustomerId = $existingUsernameMobileMap[$customerKey] ?? null;
            if ($matchedCustomerId !== null && $existingCustomerId === $matchedCustomerId) {
                $allErrors[] = "Row {$rowNumber}: Vehicle already exists under this customer.";
            } else {
                $allErrors[] = "Row {$rowNumber}: Vehicle No - Vehicle '{$vehicleNo}' already exists in the database.";
            }
        }
    }

    // Vehicle Type
    $vehicleTypeId = null;
    if ($vehicleType === '') {
        $allErrors[] = "Row {$rowNumber}: Vehicle Type - Required field is missing.";
    } elseif (!isset($vehicleTypeMap[strtolower($vehicleType)])) {
        $allErrors[] = "Row {$rowNumber}: Vehicle Type - Value '{$vehicleType}' is invalid or inactive.";
    } else {
        $vehicleTypeId = $vehicleTypeMap[strtolower($vehicleType)];
    }

    // IMEI No
    $deviceInfo = null;
    if ($imeiNo === '') {
        $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI No is required.";
    } elseif (!preg_match('/^[0-9]{15}$/', $imeiNo)) {
        $allErrors[] = "Row {$rowNumber}: IMEI No - Value '{$imeiNo}' must contain exactly 15 digits.";
    } else {
        if (isset($batchImeis[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - Duplicate IMEI No in uploaded Excel ({$imeiNo}).";
        } else {
            $batchImeis[$imeiNo] = true;
        }

        if (isset($assignedImeis[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI '{$imeiNo}' is already assigned to another customer.";
        } elseif (!isset($deviceInventory[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI '{$imeiNo}' does not exist in Device Maintenance.";
        } else {
            $deviceInfo = $deviceInventory[$imeiNo];
            if ($deviceInfo['status'] === 'used') {
                $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI '{$imeiNo}' is not available for allocation.";
            }
        }
    }

    // Device Model
    $deviceModelId = null;
    if ($deviceModel === '') {
        $allErrors[] = "Row {$rowNumber}: Device Model - Device Model is required.";
    } else {
        $deviceModelNorm = strtolower($deviceModel);
        if ($deviceInfo !== null) {
            $linkedModelNorm = $deviceModelIdToName[$deviceInfo['device_model_id']] ?? '';
            if ($deviceModelNorm !== $linkedModelNorm) {
                $allErrors[] = "Row {$rowNumber}: Device Model - Value '{$deviceModel}' does not match the model for IMEI '{$imeiNo}'.";
            } else {
                $deviceModelId = $deviceInfo['device_model_id'];
            }
        } elseif (isset($deviceModelNameToId[$deviceModelNorm])) {
            $deviceModelId = $deviceModelNameToId[$deviceModelNorm];
        } else {
            $allErrors[] = "Row {$rowNumber}: Device Model - Device Model '{$deviceModel}' does not exist.";
        }
    }

    // SIM No 1
    $sim1Info = null;
    if ($simNo1 === '') {
        $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM No 1 is required.";
    } elseif (!preg_match('/^\d{10}$/', $simNo1) && !preg_match('/^\d{13}$/', $simNo1)) {
        $allErrors[] = "Row {$rowNumber}: SIM No 1 - Value '{$simNo1}' must contain exactly 10 or 13 digits.";
    } else {
        if (isset($batchSims[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - Duplicate SIM No 1 in uploaded Excel ({$simNo1}).";
        } else {
            $batchSims[$simNo1] = true;
        }

        if (isset($assignedSims[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM '{$simNo1}' is already assigned to another customer.";
        } elseif (!isset($simInventory[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM '{$simNo1}' was not found in SIM Maintenance.";
        } else {
            $sim1Info = $simInventory[$simNo1];
            if ($sim1Info['status'] === 'used') {
                $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM '{$simNo1}' is not available for allocation.";
            }
        }
    }

    // SIM No 2
    $sim2Info = null;
    if ($simNo2 !== '') {
        if (!preg_match('/^\d{10}$/', $simNo2) && !preg_match('/^\d{13}$/', $simNo2)) {
            $allErrors[] = "Row {$rowNumber}: SIM No 2 - Value '{$simNo2}' must contain exactly 10 or 13 digits.";
        } elseif ($simNo2 === $simNo1) {
            $allErrors[] = "Row {$rowNumber}: SIM No 2 - Value '{$simNo2}' cannot be the same as SIM No 1 '{$simNo1}'.";
        } else {
            if (isset($batchSims[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - Duplicate SIM No 2 in uploaded Excel ({$simNo2}).";
            } else {
                $batchSims[$simNo2] = true;
            }

            if (isset($assignedSims[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM '{$simNo2}' is already assigned to another customer.";
            } elseif (!isset($simInventory[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM '{$simNo2}' was not found in SIM Maintenance.";
            } else {
                $sim2Info = $simInventory[$simNo2];
                if ($sim2Info['status'] === 'used') {
                    $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM '{$simNo2}' is not available for allocation.";
                }
            }
        }
    }

    // SIM Validity
    $validityMonths = null;
    if ($simValidity === '') {
        $allErrors[] = "Row {$rowNumber}: SIM Validity - SIM Validity is required.";
    } else {
        $mNum = null;
        if (is_numeric($simValidity) && (float) $simValidity > 0 && floor((float) $simValidity) === (float) $simValidity) {
            $mNum = (int) $simValidity;
        } elseif (preg_match('/^(\d+)\s+Months?$/i', $simValidity, $vm)) {
            $mNum = (int) $vm[1];
        }

        if ($mNum !== null) {
            if (isset($validityMap[$mNum])) {
                $validityMonths = $mNum;
            } else {
                $allErrors[] = "Row {$rowNumber}: SIM Validity - SIM Validity '{$simValidity}' is invalid or inactive.";
            }
        } else {
            $allErrors[] = "Row {$rowNumber}: SIM Validity - Enter a positive whole number of months for an active SIM validity.";
        }
    }

    // ==========================================
    // VALIDATE INSTALLATION & OWNER SECTION
    // ==========================================

    // Check device and SIM owner compatibility
    $deviceOwnerKey = ($deviceInfo && $deviceInfo['owner_type'] && $deviceInfo['owner_id'] > 0)
        ? $deviceInfo['owner_type'] . ':' . $deviceInfo['owner_id']
        : null;

    $sim1OwnerKey = ($sim1Info && $sim1Info['owner_type'] && $sim1Info['owner_id'] > 0)
        ? $sim1Info['owner_type'] . ':' . $sim1Info['owner_id']
        : null;

    $sim2OwnerKey = ($sim2Info && $sim2Info['owner_type'] && $sim2Info['owner_id'] > 0)
        ? $sim2Info['owner_type'] . ':' . $sim2Info['owner_id']
        : null;

    if ($deviceOwnerKey && $sim1OwnerKey && $deviceOwnerKey !== $sim1OwnerKey) {
        $allErrors[] = "Row {$rowNumber}: Installation Person - IMEI '{$imeiNo}' and SIM No 1 '{$simNo1}' must belong to the same current owner.";
    }
    if ($deviceOwnerKey && $sim2OwnerKey && $deviceOwnerKey !== $sim2OwnerKey) {
        $allErrors[] = "Row {$rowNumber}: Installation Person - IMEI '{$imeiNo}' and SIM No 2 '{$simNo2}' must belong to the same current owner.";
    }
    if ($sim1OwnerKey && $sim2OwnerKey && $sim1OwnerKey !== $sim2OwnerKey) {
        $allErrors[] = "Row {$rowNumber}: Installation Person - SIM No 1 '{$simNo1}' and SIM No 2 '{$simNo2}' must belong to the same current owner.";
    }

    // Installation Person Type
    $normPersonType = '';
    if ($installationPersonType === '') {
        $allErrors[] = "Row {$rowNumber}: Installation Person Type - Installation Person Type is required.";
    } else {
        $ptLower = strtolower($installationPersonType);
        if ($ptLower === 'technician') {
            $normPersonType = 'Technician';
        } elseif ($ptLower === 'onsite dealer') {
            $normPersonType = 'Onsite Dealer';
        } elseif ($ptLower === 'offsite dealer') {
            $normPersonType = 'Offsite Dealer';
        } elseif ($ptLower === 'direct') {
            $normPersonType = 'Direct';
        } else {
            $allErrors[] = "Row {$rowNumber}: Installation Person Type - Value '{$installationPersonType}' must be Technician, Onsite Dealer, Offsite Dealer, or Direct.";
        }
    }

    // Installation Person
    $installationPersonId = null;
    $installationDbPersonType = null;

    if ($normPersonType === 'Direct') {
        $installationDbPersonType = 'Direct';
    } elseif ($installationPerson === '') {
        $allErrors[] = "Row {$rowNumber}: Installation Person - Installation Person is required.";
    } elseif ($normPersonType !== '') {
        $personNorm = strtolower($installationPerson);

        if ($normPersonType === 'Technician') {
            $installationDbPersonType = 'Technician';
            if (!isset($technicianMap[$personNorm])) {
                $allErrors[] = "Row {$rowNumber}: Installation Person - Technician '{$installationPerson}' does not exist.";
            } else {
                $installationPersonId = $technicianMap[$personNorm];
                // Check if device/sim is allocated to a different technician
                if ($deviceOwnerKey && $deviceInfo['owner_type'] === 'technician' && $deviceInfo['owner_id'] !== $installationPersonId) {
                    $expectedTech = $technicianIdToName[$deviceInfo['owner_id']] ?? "#{$deviceInfo['owner_id']}";
                    $allErrors[] = "Row {$rowNumber}: Installation Person - Technician '{$installationPerson}' does not match the owner of IMEI '{$imeiNo}' (allocated Technician '{$expectedTech}').";
                }
            }
        } elseif ($normPersonType === 'Onsite Dealer') {
            $installationDbPersonType = 'Dealer';
            if (!isset($dealerMap[$personNorm])) {
                $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' does not exist.";
            } else {
                $dealerInfo = $dealerMap[$personNorm];
                if ($dealerInfo['status'] !== 'onsite') {
                    $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' is not an Onsite Dealer.";
                } else {
                    $installationPersonId = $dealerInfo['id'];
                    if ($deviceOwnerKey && $deviceInfo['owner_type'] === 'dealer' && $deviceInfo['owner_id'] !== $installationPersonId) {
                        $expectedDealer = $dealerIdMap[$deviceInfo['owner_id']]['name'] ?? "#{$deviceInfo['owner_id']}";
                        $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' does not match the owner of IMEI '{$imeiNo}' (allocated Dealer '{$expectedDealer}').";
                    }
                }
            }
        } elseif ($normPersonType === 'Offsite Dealer') {
            $installationDbPersonType = 'Dealer';
            if (!isset($dealerMap[$personNorm])) {
                $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' does not exist.";
            } else {
                $dealerInfo = $dealerMap[$personNorm];
                if ($dealerInfo['status'] !== 'offsite') {
                    $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' is not an Offsite Dealer.";
                } else {
                    $installationPersonId = $dealerInfo['id'];
                    if ($deviceOwnerKey && $deviceInfo['owner_type'] === 'dealer' && $deviceInfo['owner_id'] !== $installationPersonId) {
                        $expectedDealer = $dealerIdMap[$deviceInfo['owner_id']]['name'] ?? "#{$deviceInfo['owner_id']}";
                        $allErrors[] = "Row {$rowNumber}: Installation Person - Dealer '{$installationPerson}' does not match the owner of IMEI '{$imeiNo}' (allocated Dealer '{$expectedDealer}').";
                    }
                }
            }
        }
    }

    // Lead Closure By
    $leadClosureId = null;
    if ($leadClosureBy === '') {
        $allErrors[] = "Row {$rowNumber}: Lead Closure By - Lead Closure By is required.";
    } elseif (!isset($leadClosureMap[strtolower($leadClosureBy)])) {
        $allErrors[] = "Row {$rowNumber}: Lead Closure By - Lead Closure By '{$leadClosureBy}' is invalid or inactive.";
    } else {
        $leadClosureId = $leadClosureMap[strtolower($leadClosureBy)];
    }

    // Installation Date
    $installationDate = null;
    if ($installationDateRaw === '') {
        $allErrors[] = "Row {$rowNumber}: Installation Date - Installation Date is required.";
    } else {
        $parsedDate = $normalizeDate($installationDateRaw);
        if ($parsedDate === false || $parsedDate === '') {
            $allErrors[] = "Row {$rowNumber}: Installation Date - Value '{$installationDateRaw}' has an invalid format (expected DD-MM-YYYY).";
        } elseif ($parsedDate > date('Y-m-d')) {
            $allErrors[] = "Row {$rowNumber}: Installation Date - Future date '{$installationDateRaw}' is not allowed.";
        } else {
            $installationDate = $parsedDate;
        }
    }

    // ==========================================
    // VALIDATE PAYMENT SECTION
    // ==========================================

    $totalSaleAmount = 0.0;
    if ($totalSaleAmountRaw === '') {
        $allErrors[] = "Row {$rowNumber}: Total Sale Amount - Total Sale Amount is required.";
    } elseif (!is_numeric($totalSaleAmountRaw) || (float) $totalSaleAmountRaw <= 0) {
        $allErrors[] = "Row {$rowNumber}: Total Sale Amount - Value '{$totalSaleAmountRaw}' must be greater than zero.";
    } else {
        $totalSaleAmount = round((float) $totalSaleAmountRaw, 2);
    }

    // Check if any payment-detail fields are provided
    $hasPaymentDetails = (
        $paymentMode !== '' ||
        $transactionId !== '' ||
        $deviceChargeRaw !== '' ||
        $softwareChargeRaw !== '' ||
        $technicianChargeRaw !== '' ||
        $simChargeRaw !== '' ||
        $courierChargeRaw !== '' ||
        $totalAmountRaw !== '' ||
        $amountPaidRaw !== '' ||
        $amountPendingRaw !== ''
    );

    $deviceCharge = 0.0;
    $softwareCharge = 0.0;
    $technicianCharge = 0.0;
    $simCharge = 0.0;
    $courierCharge = 0.0;
    $totalAmount = 0.0;
    $amountPaid = 0.0;
    $amountPending = 0.0;
    $resolvedPaymentStatus = 'Pending';
    $resolvedPaymentMode = null;
    $resolvedTransactionId = null;

    if (!$hasPaymentDetails) {
        // MINIMUM PAYMENT CONDITION: ONLY Total Sale Amount
        $totalAmount = $totalSaleAmount;
        $amountPaid = 0.0;
        $amountPending = $totalSaleAmount;
        $resolvedPaymentStatus = 'Pending';
    } else {
        // FULL PAYMENT VALIDATION

        // Parse individual charges
        $parseCharge = function ($val, $fieldName) use ($rowNumber, $totalSaleAmount, &$allErrors) {
            if ($val === '') return 0.0;
            if (!is_numeric($val) || (float) $val < 0) {
                $allErrors[] = "Row {$rowNumber}: {$fieldName} - Value '{$val}' must be a non-negative number.";
                return 0.0;
            }
            $num = round((float) $val, 2);
            if ($totalSaleAmount > 0 && $num > $totalSaleAmount) {
                $allErrors[] = "Row {$rowNumber}: {$fieldName} - Value '{$val}' makes Total Amount exceed Total Sale Amount '{$totalSaleAmount}'.";
            }
            return $num;
        };

        $deviceCharge = $parseCharge($deviceChargeRaw, 'Device Charge');
        $softwareCharge = $parseCharge($softwareChargeRaw, 'Software Charge');
        $technicianCharge = $parseCharge($technicianChargeRaw, 'Technician Charge');
        $simCharge = $parseCharge($simChargeRaw, 'SIM Charge');
        $courierCharge = $parseCharge($courierChargeRaw, 'Courier Charge');

        $sumOfCharges = round($deviceCharge + $softwareCharge + $technicianCharge + $simCharge + $courierCharge, 2);

        if ($totalAmountRaw !== '') {
            if (!is_numeric($totalAmountRaw) || (float) $totalAmountRaw < 0) {
                $allErrors[] = "Row {$rowNumber}: Total Amount - Value '{$totalAmountRaw}' must be a non-negative number.";
                $totalAmount = $sumOfCharges;
            } else {
                $totalAmount = round((float) $totalAmountRaw, 2);
                if (abs($totalAmount - $sumOfCharges) > 0.01) {
                    $allErrors[] = "Row {$rowNumber}: Total Amount - Value '{$totalAmountRaw}' must equal the sum of charges '{$sumOfCharges}'.";
                }
            }
        } else {
            $totalAmount = $sumOfCharges;
        }

        // Total Sale Amount MUST equal Total Amount
        if ($totalSaleAmount > 0 && abs($totalSaleAmount - $totalAmount) > 0.01) {
            $allErrors[] = "Row {$rowNumber}: Total Amount - Value '{$totalAmount}' must equal Total Sale Amount '{$totalSaleAmount}'.";
        }

        // Amount Paid
        if ($amountPaidRaw !== '') {
            if (!is_numeric($amountPaidRaw) || (float) $amountPaidRaw < 0) {
                $allErrors[] = "Row {$rowNumber}: Amount Paid - Value '{$amountPaidRaw}' cannot be negative.";
            } else {
                $amountPaid = round((float) $amountPaidRaw, 2);
                if ($amountPaid > $totalAmount) {
                    $allErrors[] = "Row {$rowNumber}: Amount Paid - Value '{$amountPaid}' cannot be greater than Total Amount '{$totalAmount}'.";
                }
            }
        } else {
            $amountPaid = 0.0;
        }

        // Amount Pending (0 is valid!)
        $amountPending = round(max(0, $totalAmount - $amountPaid), 2);

        // Derive Payment Status
        if ($amountPaid <= 0) {
            $resolvedPaymentStatus = 'Not Paid';
        } elseif ($amountPaid < $totalAmount) {
            $resolvedPaymentStatus = 'Partially Paid';
        } else {
            $resolvedPaymentStatus = 'Paid';
        }

        // Payment Mode & Transaction ID
        if ($transactionId !== '' && !preg_match('/^[0-9]{6}$/', $transactionId)) {
            $allErrors[] = "Row {$rowNumber}: Transaction ID - Value '{$transactionId}' must contain exactly 6 digits.";
        }

        if ($transactionId !== '' && $paymentMode === '') {
            $allErrors[] = "Row {$rowNumber}: Payment Mode - Payment Mode is required when Transaction ID is entered.";
        }

        if ($paymentMode !== '') {
            $allowedModes = getPaymentModes();
            $matchedMode = null;
            foreach ($allowedModes as $m) {
                if (strtolower($m) === strtolower($paymentMode)) {
                    $matchedMode = $m;
                    break;
                }
            }

            if (!$matchedMode) {
                $allErrors[] = "Row {$rowNumber}: Payment Mode - Invalid payment mode '{$paymentMode}'.";
            } else {
                $resolvedPaymentMode = $matchedMode;
                if ($matchedMode !== 'Cash') {
                    if ($transactionId === '') {
                        $allErrors[] = "Row {$rowNumber}: Transaction ID - Transaction ID is required for this payment mode.";
                    } elseif (preg_match('/^[0-9]{6}$/', $transactionId)) {
                        $resolvedTransactionId = $transactionId;
                    }
                } else {
                    $resolvedTransactionId = $transactionId !== '' && preg_match('/^[0-9]{6}$/', $transactionId) ? $transactionId : null;
                }
            }
        }
    }

    $validRows[] = [
        '_row_number' => $rowNumber,
        // Customer
        'platform_id' => $platformId,
        'platform_name' => $platform,
        'username' => $username,
        'primary_mobile_no' => $primaryMobile,
        'secondary_mobile_no' => $secondaryMobile !== '' ? $secondaryMobile : null,
        'email' => $email !== '' ? $email : null,
        'location' => $location,
        'pincode' => $pincode,
        'customer_status' => $resolvedCustomerStatus,

        // Vehicle
        'vehicle_no' => $vehicleNo,
        'vehicle_type_id' => $vehicleTypeId,
        'validity_id' => $validityMap[$validityMonths]['id'] ?? null,
        'device_id' => $deviceInfo ? $deviceInfo['id'] : null,
        'device_model_id' => $deviceModelId,
        'imei_no' => $imeiNo,
        'sim_id_1' => $sim1Info ? $sim1Info['id'] : null,
        'sim_no_1' => $simNo1,
        'sim_id_2' => $sim2Info ? $sim2Info['id'] : null,
        'sim_no_2' => $simNo2 !== '' ? $simNo2 : null,
        'validity_months' => $validityMonths,
        'device_owner_type' => $deviceInfo ? $deviceInfo['owner_type'] : '',
        'device_owner_id' => $deviceInfo ? $deviceInfo['owner_id'] : 0,
        'sim1_owner_type' => $sim1Info ? $sim1Info['owner_type'] : '',
        'sim1_owner_id' => $sim1Info ? $sim1Info['owner_id'] : 0,
        'sim2_owner_type' => $sim2Info ? $sim2Info['owner_type'] : '',
        'sim2_owner_id' => $sim2Info ? $sim2Info['owner_id'] : 0,

        // Installation
        'installation_person_type' => $installationDbPersonType,
        'installation_person_label' => $normPersonType,
        'installation_person_id' => $installationPersonId,
        'lead_closure_id' => $leadClosureId,
        'installation_date' => $installationDate,

        // Payment
        'total_sale_amount' => $totalSaleAmount,
        'transaction_id' => $resolvedTransactionId,
        'payment_mode' => $resolvedPaymentMode,
        'device_charge' => $deviceCharge,
        'software_charge' => $softwareCharge,
        'technician_charge' => $technicianCharge,
        'sim_charge' => $simCharge,
        'courier_charge' => $courierCharge,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_pending' => $amountPending,
        'payment_status' => $resolvedPaymentStatus,
        'submitted_payment_status' => $paymentStatusRaw
    ];
}

/*
|--------------------------------------------------------------------------
| STAGE VALIDATED ROWS FOR CUSTOMER VERIFICATION
|--------------------------------------------------------------------------
*/

if (!empty($allErrors)) {
    $duplicateRows = [];
    foreach ($allErrors as $validationError) {
        if (preg_match('/^Row (\d+):/', $validationError, $matches)) {
            if (preg_match('/duplicate|already exists/i', $validationError)) {
                $duplicateRows[(int) $matches[1]] = true;
            }
        }
    }
    $conn->close();
    $uniqueErrors = array_values(array_unique($allErrors));
    sendResponse(false, 'Excel validation failed. No rows were submitted because this import validates the batch atomically.' . "\n" . implode("\n", $uniqueErrors), [
        'successful_submissions' => 0,
        'customer_rows_submitted' => 0,
        'rejected_rows' => count($dataRows),
        'duplicate_rows' => count($duplicateRows),
        'validation_errors' => $uniqueErrors,
        'validation_error_details' => buildValidationErrorDetails($uniqueErrors),
        'validation_error_count' => count($uniqueErrors)
    ], [], 400);
}

$groups = [];
foreach ($validRows as $row) {
    $customerKey = (int) $row['platform_id'] . '|' . strtolower(trim((string) $row['username'])) . '|' . trim((string) $row['primary_mobile_no']);
    $customer = [
        'platform_id' => (int) $row['platform_id'],
        'platform_name' => $row['platform_name'],
        'username' => $row['username'],
        'primary_mobile_no' => $row['primary_mobile_no'],
        'secondary_mobile_no' => $row['secondary_mobile_no'] ?? '',
        'email' => $row['email'] ?? '',
        'location' => $row['location'],
        'pincode' => $row['pincode'],
        'status' => $row['customer_status']
    ];
    $existingCustomer = $existingCustomerByKey[$customerKey] ?? null;
    if (!isset($groups[$customerKey])) {
        $groups[$customerKey] = [
            'existing_customer_id' => $existingCustomer ? (int) $existingCustomer['id'] : 0,
            'customer' => $customer,
            'existing_customer' => $existingCustomer,
            'rows' => []
        ];
    } elseif ($groups[$customerKey]['customer'] !== $customer) {
        $allErrors[] = 'Row ' . (int) $row['_row_number'] . ": Customer fields for Username '{$customer['username']}' and Primary Mobile No '{$customer['primary_mobile_no']}' must match across rows.";
    }
    $groups[$customerKey]['rows'][] = $row;
}
if ($allErrors) {
    $uniqueErrors = array_values(array_unique($allErrors));
    $conn->close();
    sendResponse(false, 'Excel validation failed. No rows were submitted.' . "\n" . implode("\n", $uniqueErrors), [
        'successful_submissions' => 0,
        'customer_rows_submitted' => 0,
        'rejected_rows' => count($dataRows),
        'duplicate_rows' => 0,
        'validation_errors' => $uniqueErrors,
        'validation_error_details' => buildValidationErrorDetails($uniqueErrors),
        'validation_error_count' => count($uniqueErrors)
    ], [], 400);
}

$uploadHash = hash_file('sha256', $file['tmp_name']);
if (!is_string($uploadHash) || $uploadHash === '') {
    $conn->close();
    sendResponse(false, 'Unable to fingerprint the uploaded workbook for safe retry handling.', [], [], 500);
}
$userId = (int) ($currentUser['user_id'] ?? 0);
$lockName = 'customer_excel_verification_import';
$lockResult = $conn->query("SELECT GET_LOCK('customer_excel_verification_import', 10) AS acquired");
if (!$lockResult || (int) ($lockResult->fetch_assoc()['acquired'] ?? 0) !== 1) {
    $conn->close();
    sendResponse(false, 'Another customer Excel verification import is in progress. Please retry shortly.', [], [], 503);
}

$stagedSubmissions = 0;
$submittedRows = 0;
$duplicateRowCount = 0;
try {
    $conn->begin_transaction();
    $insertSubmission = $conn->prepare(
        "INSERT INTO customer_payment_verification_submissions
            (submitter_user_id, existing_customer_id, status, submission_data, submitted_at)
         VALUES (?, NULLIF(?, 0), 'Pending Verification', ?, CURRENT_TIMESTAMP)"
    );
    if (!$insertSubmission) {
        throw new RuntimeException('Unable to prepare customer verification submission.');
    }

    foreach ($groups as $group) {
        $rowsToStage = [];
        $rowHashes = [];
        foreach ($group['rows'] as $row) {
            $rowForHash = $row;
            unset($rowForHash['_row_number']);
            $rowHash = hash('sha256', json_encode($rowForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $duplicateStmt = $conn->prepare(
                'SELECT id FROM customer_payment_verification_submissions WHERE submission_data LIKE ? LIMIT 1'
            );
            $hashPattern = '%' . $rowHash . '%';
            $duplicateStmt->bind_param('s', $hashPattern);
            $duplicateStmt->execute();
            $isDuplicate = $duplicateStmt->get_result()->num_rows > 0;
            $duplicateStmt->close();
            if ($isDuplicate) {
                $duplicateRowCount++;
                continue;
            }
            $row['_row_hash'] = $rowHash;
            $rowsToStage[] = $row;
            $rowHashes[] = $rowHash;
        }
        if (!$rowsToStage) {
            continue;
        }

        $existingCustomerId = (int) $group['existing_customer_id'];
        $customer = $group['customer'];
        if ($existingCustomerId > 0) {
            $pendingStmt = $conn->prepare(
                "SELECT id FROM customer_payment_verification_submissions
                 WHERE existing_customer_id = ? AND status IN ('Pending Verification', 'Applying')
                 LIMIT 1"
            );
            $pendingStmt->bind_param('i', $existingCustomerId);
        } else {
            $pendingStmt = $conn->prepare(
                "SELECT id FROM customer_payment_verification_submissions
                 WHERE status IN ('Pending Verification', 'Applying')
                   AND JSON_UNQUOTE(JSON_EXTRACT(
                       CASE WHEN JSON_VALID(submission_data) THEN submission_data ELSE '{}' END,
                       '$.customer.platform_id'
                   )) = ?
                   AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(
                       CASE WHEN JSON_VALID(submission_data) THEN submission_data ELSE '{}' END,
                       '$.customer.username'
                   ))) = LOWER(?)
                   AND JSON_UNQUOTE(JSON_EXTRACT(
                       CASE WHEN JSON_VALID(submission_data) THEN submission_data ELSE '{}' END,
                       '$.customer.primary_mobile_no'
                   )) = ?
                 LIMIT 1"
            );
            $pendingStmt->bind_param('iss', $customer['platform_id'], $customer['username'], $customer['primary_mobile_no']);
        }
        $pendingStmt->execute();
        $pendingCustomer = $pendingStmt->get_result()->num_rows > 0;
        $pendingStmt->close();
        if ($pendingCustomer) {
            $duplicateRowCount += count($rowsToStage);
            continue;
        }

        $existingCustomer = $group['existing_customer'];
        $customerChanged = !$existingCustomer;
        if ($existingCustomer) {
            $customerChanged =
                (int) $existingCustomer['platform_id'] !== (int) $customer['platform_id'] ||
                (string) $existingCustomer['username'] !== (string) $customer['username'] ||
                (string) $existingCustomer['primary_mobile_no'] !== (string) $customer['primary_mobile_no'] ||
                (string) ($existingCustomer['secondary_mobile_no'] ?? '') !== (string) ($customer['secondary_mobile_no'] ?? '') ||
                (string) ($existingCustomer['email'] ?? '') !== (string) ($customer['email'] ?? '') ||
                (string) $existingCustomer['location'] !== (string) $customer['location'] ||
                (string) $existingCustomer['pincode'] !== (string) $customer['pincode'] ||
                (string) $existingCustomer['status'] !== (string) $customer['status'];
        }

        $vehicles = [];
        foreach ($rowsToStage as $vehicleIndex => $row) {
            $payment = [
                'total_sale_amount' => $row['total_sale_amount'],
                'transaction_id' => $row['transaction_id'],
                'payment_mode' => $row['payment_mode'],
                'device_charge' => $row['device_charge'],
                'software_charge' => $row['software_charge'],
                'technician_charge' => $row['technician_charge'],
                'sim_charge' => $row['sim_charge'],
                'courier_charge' => $row['courier_charge'],
                'cash_to_technician' => false,
                'cash_to_dealer' => false,
                'cash_to_et' => false,
                'total_amount' => $row['total_amount'],
                'amount_paid' => $row['amount_paid'],
                'amount_pending' => $row['amount_pending'],
                'payment_status' => $row['payment_status'],
                'submitted_payment_status' => $row['submitted_payment_status']
            ];
            $vehicles[] = [
                'vehicle_no' => $row['vehicle_no'],
                'vehicle_type_id' => $row['vehicle_type_id'],
                'imei_no' => $row['imei_no'],
                'sim_no_1' => $row['sim_no_1'],
                'sim_no_2' => $row['sim_no_2'],
                'device_model_id' => $row['device_model_id'],
                'validity_id' => $row['validity_id'],
                'validity_months' => $row['validity_months'],
                'append_vehicle' => $existingCustomerId > 0 || $vehicleIndex > 0,
                'changed' => ['vehicle' => true, 'installation' => true, 'paymentPart1' => true, 'paymentPart2' => true],
                'installation' => [
                    'installation_person_type' => $row['installation_person_label'],
                    'installation_person_id' => $row['installation_person_id'],
                    'lead_closure_id' => $row['lead_closure_id'],
                    'installation_date' => $row['installation_date']
                ],
                'payment_part1' => [
                    'total_sale_amount' => $row['total_sale_amount'],
                    'transaction_id' => $row['transaction_id'],
                    'payment_mode' => $row['payment_mode']
                ],
                'payment_part2' => $payment,
                'import_row_number' => $row['_row_number']
            ];
        }
        $submissionData = [
            'source' => 'customer_excel_upload',
            'existing_customer_id' => $existingCustomerId ?: null,
            'customer' => $customer,
            'customer_changed' => $customerChanged,
            'vehicles' => $vehicles,
            'payment_part2' => $vehicles[0]['payment_part2'],
            'import_metadata' => [
                'file_name' => basename((string) $file['name']),
                'file_sha256' => $uploadHash,
                'row_numbers' => array_column($rowsToStage, '_row_number'),
                'row_hashes' => $rowHashes
            ]
        ];
        $submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($submissionJson === false || strlen($submissionJson) > 2000000) {
            throw new RuntimeException('Customer verification snapshot is too large or could not be encoded.');
        }
        $insertSubmission->bind_param('iis', $userId, $existingCustomerId, $submissionJson);
        if (!$insertSubmission->execute()) {
            throw new RuntimeException('Failed to save an Excel row for Admin verification.');
        }
        $submissionId = (int) $insertSubmission->insert_id;
        insertCustomerPaymentVerificationHistory($conn, $submissionId, 'Draft', 'Pending Verification', null, $userId);
        $stagedSubmissions++;
        $submittedRows += count($rowsToStage);
    }
    $insertSubmission->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('customer_excel_verification_import')");
    $conn->close();
    sendResponse(false, 'Failed to submit customer Excel rows for verification: ' . $error->getMessage(), [], [], 500);
}
$conn->query("SELECT RELEASE_LOCK('customer_excel_verification_import')");
$conn->close();
sendResponse(true, 'Customer Excel upload processed. Submitted records are pending Admin verification.', [
    'successful_submissions' => $stagedSubmissions,
    'customer_rows_submitted' => $submittedRows,
    'duplicate_rows' => $duplicateRowCount,
    'rejected_rows' => 0,
    'validation_errors' => []
]);
