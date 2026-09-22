<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/validation.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';
require_once '../../utils/excel_reader.php';
require_once '../../utils/payment_modes.php';

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
    sendResponse(false, "Required column '" . $missingColumns[0] . "' is missing.", [], [], 400);
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
$existingPlatformUsernames = [];
$existingPrimaryMobiles = [];
$res = $conn->query("SELECT platform_id, username, primary_mobile_no FROM customers");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $pKey = ((int) $r['platform_id']) . ':' . strtolower(trim($r['username']));
        $existingPlatformUsernames[$pKey] = true;
        $existingPrimaryMobiles[trim($r['primary_mobile_no'])] = true;
    }
}

// Preload Existing Customer Vehicle Details
$existingVehicleNos = [];
$assignedImeis = [];
$assignedSims = [];
$res = $conn->query("SELECT vehicle_no, imei_no, sim_no_1, sim_no_2 FROM customer_vehicle_details");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $existingVehicleNos[strtoupper(trim($r['vehicle_no']))] = true;
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
     )"
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
     )"
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
    $raw = trim((string) $rawDate);
    if ($raw === '') return '';
    if (is_numeric($raw) && (float) $raw > 1000) {
        return excelSerialDateToIso($raw);
    }
    // DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $raw, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year = $m[3];
        if (checkdate((int) $month, (int) $day, (int) $year)) {
            return "{$year}-{$month}-{$day}";
        }
    }
    // YYYY-MM-DD
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $raw, $m)) {
        $year = $m[1];
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $day = str_pad($m[3], 2, '0', STR_PAD_LEFT);
        if (checkdate((int) $month, (int) $day, (int) $year)) {
            return "{$year}-{$month}-{$day}";
        }
    }
    return false;
};

/*
|--------------------------------------------------------------------------
| VALIDATE EVERY ROW INDEPENDENTLY
|--------------------------------------------------------------------------
*/

$allErrors = [];
$validRows = [];

$batchPlatformUsernames = [];
$batchPrimaryMobiles = [];
$batchVehicleNos = [];
$batchImeis = [];
$batchSims = [];

foreach ($dataRows as $idx => $row) {
    $rowNumber = $idx + 2;

    $getVal = function ($field) use ($row, $fieldToColIndex) {
        $colIdx = $fieldToColIndex[$field] ?? null;
        return ($colIdx !== null && isset($row[$colIdx])) ? trim((string) $row[$colIdx]) : '';
    };

    // 1. Customer Fields
    $platform = $getVal('Platform');
    $username = $getVal('Username');
    $primaryMobile = preg_replace('/\D/', '', $getVal('Primary Mobile No'));
    $secondaryMobile = preg_replace('/\D/', '', $getVal('Secondary Mobile No'));
    $email = $getVal('Email');
    $location = $getVal('Location');
    $pincode = preg_replace('/\D/', '', $getVal('Pincode'));
    $customerStatus = $getVal('Customer Status');

    // 2. Vehicle Fields
    $vehicleNo = strtoupper($getVal('Vehicle No'));
    $vehicleType = $getVal('Vehicle Type');
    $imeiNo = preg_replace('/\D/', '', $getVal('IMEI No'));
    $simNo1 = preg_replace('/\D/', '', $getVal('SIM No 1'));
    $simNo2 = preg_replace('/\D/', '', $getVal('SIM No 2'));
    $deviceModel = $getVal('Device Model');
    $simValidity = $getVal('SIM Validity');

    // 3. Installation Fields
    $installationPerson = $getVal('Installation Person');
    $installationPersonType = $getVal('Installation Person Type');
    $leadClosureBy = $getVal('Lead Closure By');
    $installationDateRaw = $getVal('Installation Date');

    // 4. Payment Fields
    $totalSaleAmountRaw = $getVal('Total Sale Amount');
    $transactionId = $getVal('Transaction ID');
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
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $allErrors[] = "Row {$rowNumber}: Username - Username can contain only letters, numbers, dot, underscore and hyphen.";
    } else {
        $lowerUser = strtolower($username);
        if ($platformId !== null) {
            $batchKey = $platformId . ':' . $lowerUser;
            if (isset($batchPlatformUsernames[$batchKey])) {
                $allErrors[] = "Row {$rowNumber}: Username - Duplicate Username for this platform in uploaded Excel ({$username}).";
            } else {
                $batchPlatformUsernames[$batchKey] = true;
            }

            if (isset($existingPlatformUsernames[$batchKey])) {
                $allErrors[] = "Row {$rowNumber}: Username - Username already exists for this platform.";
            }
        }
    }

    // Primary Mobile
    if ($primaryMobile === '') {
        $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Primary Mobile is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $primaryMobile)) {
        $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Primary Mobile must contain exactly 10 digits.";
    } else {
        if (isset($batchPrimaryMobiles[$primaryMobile])) {
            $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Duplicate Primary Mobile in uploaded Excel ({$primaryMobile}).";
        } else {
            $batchPrimaryMobiles[$primaryMobile] = true;
        }

        if (isset($existingPrimaryMobiles[$primaryMobile])) {
            $allErrors[] = "Row {$rowNumber}: Primary Mobile No - Primary Mobile '{$primaryMobile}' already exists in database.";
        }
    }

    // Secondary Mobile
    if ($secondaryMobile !== '') {
        if (!preg_match('/^[0-9]{10}$/', $secondaryMobile)) {
            $allErrors[] = "Row {$rowNumber}: Secondary Mobile No - Secondary Mobile must contain exactly 10 digits.";
        } elseif ($secondaryMobile === $primaryMobile) {
            $allErrors[] = "Row {$rowNumber}: Secondary Mobile No - Secondary Mobile cannot be the same as Primary Mobile.";
        }
    }

    // Email
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $allErrors[] = "Row {$rowNumber}: Email - Invalid email format.";
    }

    // Location
    if ($location === '') {
        $allErrors[] = "Row {$rowNumber}: Location - Location is required.";
    }

    // Pincode
    if ($pincode === '') {
        $allErrors[] = "Row {$rowNumber}: Pincode - Pincode is required.";
    } elseif (!preg_match('/^[0-9]{6}$/', $pincode)) {
        $allErrors[] = "Row {$rowNumber}: Pincode - Pincode must contain exactly 6 digits.";
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
            $allErrors[] = "Row {$rowNumber}: Customer Status - Customer Status must be 'Active' or 'Inactive'.";
        }
    }

    // ==========================================
    // VALIDATE VEHICLE SECTION
    // ==========================================

    // Vehicle No
    if ($vehicleNo === '') {
        $allErrors[] = "Row {$rowNumber}: Vehicle No - Vehicle No is required.";
    } else {
        if (isset($batchVehicleNos[$vehicleNo])) {
            $allErrors[] = "Row {$rowNumber}: Vehicle No - Duplicate Vehicle No in uploaded Excel ({$vehicleNo}).";
        } else {
            $batchVehicleNos[$vehicleNo] = true;
        }

        if (isset($existingVehicleNos[$vehicleNo])) {
            $allErrors[] = "Row {$rowNumber}: Vehicle No - Vehicle No '{$vehicleNo}' already exists in database.";
        }
    }

    // Vehicle Type
    $vehicleTypeId = null;
    if ($vehicleType === '') {
        $allErrors[] = "Row {$rowNumber}: Vehicle Type - Vehicle Type is required.";
    } elseif (!isset($vehicleTypeMap[strtolower($vehicleType)])) {
        $allErrors[] = "Row {$rowNumber}: Vehicle Type - Vehicle Type '{$vehicleType}' is invalid or inactive.";
    } else {
        $vehicleTypeId = $vehicleTypeMap[strtolower($vehicleType)];
    }

    // IMEI No
    $deviceInfo = null;
    if ($imeiNo === '') {
        $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI No is required.";
    } elseif (!preg_match('/^[0-9]{15}$/', $imeiNo)) {
        $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI No must contain exactly 15 digits.";
    } else {
        if (isset($batchImeis[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - Duplicate IMEI No in uploaded Excel ({$imeiNo}).";
        } else {
            $batchImeis[$imeiNo] = true;
        }

        if (isset($assignedImeis[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI is already assigned to another customer.";
        } elseif (!isset($deviceInventory[$imeiNo])) {
            $allErrors[] = "Row {$rowNumber}: IMEI No - IMEI does not exist in Device Maintenance.";
        } else {
            $deviceInfo = $deviceInventory[$imeiNo];
            if ($deviceInfo['status'] === 'used') {
                $allErrors[] = "Row {$rowNumber}: IMEI No - This IMEI is not available for allocation.";
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
                $allErrors[] = "Row {$rowNumber}: Device Model - Device Model does not match the IMEI device.";
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
        $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM No 1 must contain exactly 10 or 13 digits.";
    } else {
        if (isset($batchSims[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - Duplicate SIM No 1 in uploaded Excel ({$simNo1}).";
        } else {
            $batchSims[$simNo1] = true;
        }

        if (isset($assignedSims[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM already assigned to another customer.";
        } elseif (!isset($simInventory[$simNo1])) {
            $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM No 1 was not found in SIM Maintenance.";
        } else {
            $sim1Info = $simInventory[$simNo1];
            if ($sim1Info['status'] === 'used') {
                $allErrors[] = "Row {$rowNumber}: SIM No 1 - SIM No 1 is not available for allocation.";
            }
        }
    }

    // SIM No 2
    $sim2Info = null;
    if ($simNo2 !== '') {
        if (!preg_match('/^\d{10}$/', $simNo2) && !preg_match('/^\d{13}$/', $simNo2)) {
            $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM No 2 must contain exactly 10 or 13 digits.";
        } elseif ($simNo2 === $simNo1) {
            $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM No 1 and SIM No 2 cannot be the same.";
        } else {
            if (isset($batchSims[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - Duplicate SIM No 2 in uploaded Excel ({$simNo2}).";
            } else {
                $batchSims[$simNo2] = true;
            }

            if (isset($assignedSims[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM No 2 is already assigned to another customer.";
            } elseif (!isset($simInventory[$simNo2])) {
                $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM No 2 was not found in SIM Maintenance.";
            } else {
                $sim2Info = $simInventory[$simNo2];
                if ($sim2Info['status'] === 'used') {
                    $allErrors[] = "Row {$rowNumber}: SIM No 2 - SIM No 2 is not available for allocation.";
                }
            }
        }
    }

    // SIM Validity
    $validityMonths = null;
    if ($simValidity === '') {
        $allErrors[] = "Row {$rowNumber}: SIM Validity - SIM Validity is required.";
    } else {
        if (preg_match('/\d+/', $simValidity, $vm)) {
            $mNum = (int) $vm[0];
            if (isset($validityMap[$mNum])) {
                $validityMonths = $mNum;
            } else {
                $allErrors[] = "Row {$rowNumber}: SIM Validity - SIM Validity '{$simValidity}' is invalid or inactive.";
            }
        } else {
            $allErrors[] = "Row {$rowNumber}: SIM Validity - Invalid SIM Validity format.";
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
        $allErrors[] = "Row {$rowNumber}: Installation Person - The selected IMEI and SIM No 1 must belong to the same current owner.";
    }
    if ($deviceOwnerKey && $sim2OwnerKey && $deviceOwnerKey !== $sim2OwnerKey) {
        $allErrors[] = "Row {$rowNumber}: Installation Person - The selected IMEI and SIM No 2 must belong to the same current owner.";
    }
    if ($sim1OwnerKey && $sim2OwnerKey && $sim1OwnerKey !== $sim2OwnerKey) {
        $allErrors[] = "Row {$rowNumber}: Installation Person - SIM No 1 and SIM No 2 must belong to the same current owner.";
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
        } else {
            $allErrors[] = "Row {$rowNumber}: Installation Person Type - Installation Person Type must be 'Technician', 'Onsite Dealer', or 'Offsite Dealer'.";
        }
    }

    // Installation Person
    $installationPersonId = null;
    $installationDbPersonType = null; // 'Technician' or 'Dealer' for DB enum

    if ($installationPerson === '') {
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
                    $allErrors[] = "Row {$rowNumber}: Installation Person - Installation Person does not match the allocated Technician ({$expectedTech}).";
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
                        $allErrors[] = "Row {$rowNumber}: Installation Person - Installation Person does not match the allocated Dealer ({$expectedDealer}).";
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
                        $allErrors[] = "Row {$rowNumber}: Installation Person - Installation Person does not match the allocated Dealer ({$expectedDealer}).";
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
            $allErrors[] = "Row {$rowNumber}: Installation Date - Invalid Installation Date format (expected DD-MM-YYYY).";
        } elseif ($parsedDate > date('Y-m-d')) {
            $allErrors[] = "Row {$rowNumber}: Installation Date - Future dates are not allowed.";
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
        $allErrors[] = "Row {$rowNumber}: Total Sale Amount - Total Sale Amount must be greater than zero.";
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
        $totalAmount = 0.0;
        $amountPaid = 0.0;
        $amountPending = 0.0;
        $resolvedPaymentStatus = 'Pending';
    } else {
        // FULL PAYMENT VALIDATION

        // Parse individual charges
        $parseCharge = function ($val, $fieldName) use ($rowNumber, $totalSaleAmount, &$allErrors) {
            if ($val === '') return 0.0;
            if (!is_numeric($val) || (float) $val < 0) {
                $allErrors[] = "Row {$rowNumber}: {$fieldName} - {$fieldName} cannot be negative.";
                return 0.0;
            }
            $num = round((float) $val, 2);
            if ($totalSaleAmount > 0 && $num > $totalSaleAmount) {
                $allErrors[] = "Row {$rowNumber}: {$fieldName} - {$fieldName} makes Total Amount exceed Total Sale Amount.";
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
                $allErrors[] = "Row {$rowNumber}: Total Amount - Total Amount must be a positive number.";
                $totalAmount = $sumOfCharges;
            } else {
                $totalAmount = round((float) $totalAmountRaw, 2);
                if (abs($totalAmount - $sumOfCharges) > 0.01) {
                    $allErrors[] = "Row {$rowNumber}: Total Amount - Total Amount must equal the sum of charges.";
                }
            }
        } else {
            $totalAmount = $sumOfCharges;
        }

        // Total Sale Amount MUST equal Total Amount
        if ($totalSaleAmount > 0 && abs($totalSaleAmount - $totalAmount) > 0.01) {
            $allErrors[] = "Row {$rowNumber}: Total Amount - Total Sale Amount must equal Total Amount.";
        }

        // Amount Paid
        if ($amountPaidRaw !== '') {
            if (!is_numeric($amountPaidRaw) || (float) $amountPaidRaw < 0) {
                $allErrors[] = "Row {$rowNumber}: Amount Paid - Amount Paid cannot be negative.";
            } else {
                $amountPaid = round((float) $amountPaidRaw, 2);
                if ($amountPaid > $totalAmount) {
                    $allErrors[] = "Row {$rowNumber}: Amount Paid - Amount Paid cannot be greater than Total Amount.";
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
                    } elseif (!preg_match('/^[A-Za-z0-9]{6}$/', $transactionId)) {
                        $allErrors[] = "Row {$rowNumber}: Transaction ID - Transaction ID must be exactly 6 digits.";
                    } else {
                        $resolvedTransactionId = $transactionId;
                    }
                } else {
                    $resolvedTransactionId = $transactionId !== '' ? $transactionId : null;
                }
            }
        }
    }

    $validRows[] = [
        '_row_number' => $rowNumber,
        // Customer
        'platform_id' => $platformId,
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
        'payment_status' => $resolvedPaymentStatus
    ];
}

/*
|--------------------------------------------------------------------------
| CHECK IF ANY VALIDATION ERRORS OCCURRED
|--------------------------------------------------------------------------
*/

if (!empty($allErrors)) {
    $conn->close();
    sendResponse(
        false,
        'Excel validation failed.',
        ['errors' => array_values(array_unique($allErrors))],
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| ATOMIC DATABASE INSERTION (TRANSACTION)
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {
    // 1. Prepare Customer Insert
    $custStmt = $conn->prepare(
        "INSERT INTO customers (
            platform_id,
            username,
            primary_mobile_no,
            secondary_mobile_no,
            email,
            location,
            pincode,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$custStmt) {
        throw new Exception('Failed to prepare customer insert: ' . $conn->error);
    }

    // 2. Prepare Vehicle Details Insert
    $vehStmt = $conn->prepare(
        "INSERT INTO customer_vehicle_details (
            customer_id,
            vehicle_no,
            vehicle_type_id,
            device_id,
            device_model_id,
            imei_no,
            sim_id_1,
            sim_no_1,
            sim_id_2,
            sim_no_2,
            validity_months
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$vehStmt) {
        throw new Exception('Failed to prepare vehicle insert: ' . $conn->error);
    }

    // 3. Prepare Installation Insert
    $instStmt = $conn->prepare(
        "INSERT INTO customer_installations (
            customer_id,
            installation_person_type,
            installation_person_id,
            lead_closure_id,
            installation_date
        ) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$instStmt) {
        throw new Exception('Failed to prepare installation insert: ' . $conn->error);
    }

    // 4. Prepare Payment Insert
    $payStmt = $conn->prepare(
        "INSERT INTO customer_payments (
            customer_id,
            total_sale_amount,
            transaction_id,
            payment_mode,
            device_charge,
            software_charge,
            technician_charge,
            sim_charge,
            courier_charge,
            total_amount,
            amount_paid,
            amount_pending,
            payment_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$payStmt) {
        throw new Exception('Failed to prepare payment insert: ' . $conn->error);
    }

    // 5. Prepare Asset Status Updates & Stock Transactions
    $updateDeviceStmt = $conn->prepare("UPDATE devices SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    if (!$updateDeviceStmt) {
        throw new Exception('Failed to prepare device status update: ' . $conn->error);
    }

    $updateSimStmt = $conn->prepare("UPDATE sims SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    if (!$updateSimStmt) {
        throw new Exception('Failed to prepare SIM status update: ' . $conn->error);
    }

    $checkDeviceTxStmt = $conn->prepare(
        "SELECT id FROM stock_transactions WHERE device_id = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET' LIMIT 1"
    );
    if (!$checkDeviceTxStmt) {
        throw new Exception('Failed to prepare device stock transaction check: ' . $conn->error);
    }

    $insertDeviceTxStmt = $conn->prepare(
        "INSERT INTO stock_transactions (device_id, from_owner_type, from_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, ?, ?, 'USE', 'ET', CURDATE(), 'Customer vehicle completion')"
    );
    if (!$insertDeviceTxStmt) {
        throw new Exception('Failed to prepare device stock transaction insert: ' . $conn->error);
    }

    $checkSimTxStmt = $conn->prepare(
        "SELECT id FROM stock_transactions WHERE sim_id = ? AND from_owner_type = ? AND from_owner_id = ? AND transaction_type = 'USE' AND usage_type = 'ET' LIMIT 1"
    );
    if (!$checkSimTxStmt) {
        throw new Exception('Failed to prepare SIM stock transaction check: ' . $conn->error);
    }

    $insertSimTxStmt = $conn->prepare(
        "INSERT INTO stock_transactions (sim_id, from_owner_type, from_owner_id, transaction_type, usage_type, transaction_date, notes) VALUES (?, ?, ?, 'USE', 'ET', CURDATE(), 'Customer vehicle completion')"
    );
    if (!$insertSimTxStmt) {
        throw new Exception('Failed to prepare SIM stock transaction insert: ' . $conn->error);
    }

    foreach ($validRows as $row) {
        // A. Insert Customer
        $custStmt->bind_param(
            'isssssss',
            $row['platform_id'],
            $row['username'],
            $row['primary_mobile_no'],
            $row['secondary_mobile_no'],
            $row['email'],
            $row['location'],
            $row['pincode'],
            $row['customer_status']
        );
        if (!$custStmt->execute()) {
            throw new Exception('Failed to insert customer ' . $row['username'] . ': ' . $custStmt->error);
        }
        $customerId = (int) $conn->insert_id;

        // B. Insert Vehicle Details
        $vehStmt->bind_param(
            'isiiisisssi',
            $customerId,
            $row['vehicle_no'],
            $row['vehicle_type_id'],
            $row['device_id'],
            $row['device_model_id'],
            $row['imei_no'],
            $row['sim_id_1'],
            $row['sim_no_1'],
            $row['sim_id_2'],
            $row['sim_no_2'],
            $row['validity_months']
        );
        if (!$vehStmt->execute()) {
            throw new Exception('Failed to insert vehicle details for ' . $row['username'] . ': ' . $vehStmt->error);
        }

        // C. Insert Installation Details
        $instStmt->bind_param(
            'isiis',
            $customerId,
            $row['installation_person_type'],
            $row['installation_person_id'],
            $row['lead_closure_id'],
            $row['installation_date']
        );
        if (!$instStmt->execute()) {
            throw new Exception('Failed to insert installation details for ' . $row['username'] . ': ' . $instStmt->error);
        }

        // D. Insert Payment
        $payStmt->bind_param(
            'idssdddddddds',
            $customerId,
            $row['total_sale_amount'],
            $row['transaction_id'],
            $row['payment_mode'],
            $row['device_charge'],
            $row['software_charge'],
            $row['technician_charge'],
            $row['sim_charge'],
            $row['courier_charge'],
            $row['total_amount'],
            $row['amount_paid'],
            $row['amount_pending'],
            $row['payment_status']
        );
        if (!$payStmt->execute()) {
            throw new Exception('Failed to insert payment details for ' . $row['username'] . ': ' . $payStmt->error);
        }

        // E. Consume Device Stock Asset
        if (!empty($row['device_id'])) {
            $devId = (int) $row['device_id'];
            $updateDeviceStmt->bind_param('i', $devId);
            $updateDeviceStmt->execute();

            if (!empty($row['device_owner_type']) && (int) $row['device_owner_id'] > 0) {
                $ownerType = (string) $row['device_owner_type'];
                $ownerId = (int) $row['device_owner_id'];
                
                $checkDeviceTxStmt->bind_param('isi', $devId, $ownerType, $ownerId);
                $checkDeviceTxStmt->execute();
                $hasTx = $checkDeviceTxStmt->get_result()->num_rows > 0;

                if (!$hasTx) {
                    $insertDeviceTxStmt->bind_param('isi', $devId, $ownerType, $ownerId);
                    $insertDeviceTxStmt->execute();
                }
            }
        }

        // F. Consume SIM 1 Stock Asset
        if (!empty($row['sim_id_1'])) {
            $sId1 = (int) $row['sim_id_1'];
            $updateSimStmt->bind_param('i', $sId1);
            $updateSimStmt->execute();

            if (!empty($row['sim1_owner_type']) && (int) $row['sim1_owner_id'] > 0) {
                $ownerType = (string) $row['sim1_owner_type'];
                $ownerId = (int) $row['sim1_owner_id'];

                $checkSimTxStmt->bind_param('isi', $sId1, $ownerType, $ownerId);
                $checkSimTxStmt->execute();
                $hasTx = $checkSimTxStmt->get_result()->num_rows > 0;

                if (!$hasTx) {
                    $insertSimTxStmt->bind_param('isi', $sId1, $ownerType, $ownerId);
                    $insertSimTxStmt->execute();
                }
            }
        }

        // G. Consume SIM 2 Stock Asset
        if (!empty($row['sim_id_2'])) {
            $sId2 = (int) $row['sim_id_2'];
            $updateSimStmt->bind_param('i', $sId2);
            $updateSimStmt->execute();

            if (!empty($row['sim2_owner_type']) && (int) $row['sim2_owner_id'] > 0) {
                $ownerType = (string) $row['sim2_owner_type'];
                $ownerId = (int) $row['sim2_owner_id'];

                $checkSimTxStmt->bind_param('isi', $sId2, $ownerType, $ownerId);
                $checkSimTxStmt->execute();
                $hasTx = $checkSimTxStmt->get_result()->num_rows > 0;

                if (!$hasTx) {
                    $insertSimTxStmt->bind_param('isi', $sId2, $ownerType, $ownerId);
                    $insertSimTxStmt->execute();
                }
            }
        }

        $customerSnapshot = customerAuditSnapshot($conn, $customerId);
        $customerSnapshot['_audit'] = [
            'source' => 'Excel Import',
            'file' => $file['name'],
            'row' => $row['_row_number'] ?? null
        ];
        writeAuditSnapshot($conn, $customerId, 'Customer', 'Create', null, $customerSnapshot, $currentUser);
    }

    $custStmt->close();
    $vehStmt->close();
    $instStmt->close();
    $payStmt->close();
    $updateDeviceStmt->close();
    $updateSimStmt->close();
    $checkDeviceTxStmt->close();
    $insertDeviceTxStmt->close();
    $checkSimTxStmt->close();
    $insertSimTxStmt->close();

    $conn->commit();
    $conn->close();

    $count = count($validRows);
    sendResponse(true, "{$count} customers imported successfully.", ['count' => $count]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, 'Failed to import customers: ' . $e->getMessage(), [], [], 500);
}

