<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

// 1. Fetch Dealers (including Onsite, Offsite, Not Willing)
$dealersSql = "SELECT id, dealer_name, mobile_no, location, installation_status, software FROM dealers ORDER BY dealer_name ASC";
$dealersRes = $conn->query($dealersSql);
$dealers = [];
if ($dealersRes) {
    while ($row = $dealersRes->fetch_assoc()) {
        $swStmt = $conn->prepare("SELECT software FROM dealer_software WHERE dealer_id = ?");
        $swStmt->bind_param("i", $row['id']);
        $swStmt->execute();
        $swRes = $swStmt->get_result();
        $swList = [];
        while ($swRow = $swRes->fetch_assoc()) {
            if (!empty($swRow['software'])) {
                $swList[] = trim($swRow['software']);
            }
        }
        $swStmt->close();
        if (empty($swList) && !empty($row['software'])) {
            $swList = array_values(array_filter(array_map('trim', explode(',', $row['software']))));
        }
        $row['software_list'] = $swList;
        $dealers[] = $row;
    }
}

// 2. Fetch Technicians
$techSql = "SELECT id, technician_name, mobile_no, location FROM technicians ORDER BY technician_name ASC";
$techRes = $conn->query($techSql);
$technicians = [];
if ($techRes) {
    while ($row = $techRes->fetch_assoc()) {
        $technicians[] = $row;
    }
}

// 2.5 Fetch Customers
$custSql = "SELECT id, username, status FROM customers ORDER BY username ASC";
$custRes = $conn->query($custSql);
$customers = [];
if ($custRes) {
    while ($row = $custRes->fetch_assoc()) {
        $customers[] = $row;
    }
}

// 3. Fetch Device Models (from device_types)
$modelsRes = $conn->query("SELECT id, device_type FROM device_types ORDER BY device_type ASC");
$deviceModels = [];
if ($modelsRes) {
    while ($row = $modelsRes->fetch_assoc()) {
        $deviceModels[] = [
            'id' => (int)$row['id'],
            'name' => $row['device_type']
        ];
    }
}

// 4. Fetch Available IMEIs (optionally filtered by device_model_id)
$modelFilterId = filter_input(INPUT_GET, 'device_model_id', FILTER_VALIDATE_INT);
$imeiSql = "
    SELECT d.id, d.imei_no, d.device_model_id, dt.device_type AS model_name
    FROM devices d
    JOIN device_types dt ON dt.id = d.device_model_id
    WHERE d.status = 'available'
      AND NOT EXISTS (
          SELECT 1 FROM courier_requests cr 
          WHERE cr.device_id = d.id AND cr.approval_status = 'Pending Approval'
      )
";
$params = [];
$types = '';
if ($modelFilterId) {
    $imeiSql .= " AND d.device_model_id = ?";
    $params[] = $modelFilterId;
    $types .= 'i';
}
$imeiSql .= " ORDER BY d.imei_no ASC";

$imeiStmt = $conn->prepare($imeiSql);
if ($params) {
    $imeiStmt->bind_param($types, ...$params);
}
$imeiStmt->execute();
$imeiRes = $imeiStmt->get_result();
$availableImeis = [];
if ($imeiRes) {
    while ($row = $imeiRes->fetch_assoc()) {
        $availableImeis[] = [
            'id' => (int)$row['id'],
            'imei_no' => $row['imei_no'],
            'device_model_id' => (int)$row['device_model_id'],
            'model_name' => $row['model_name']
        ];
    }
}
$imeiStmt->close();

// 5. Fetch SIM Types
$simTypesRes = $conn->query("
    SELECT DISTINCT sim_type FROM (
        SELECT sim_type FROM sim_types WHERE sim_type IS NOT NULL AND sim_type != ''
        UNION
        SELECT sim_type FROM sims WHERE sim_type IS NOT NULL AND sim_type != '' AND status = 'available'
    ) AS combined_sim_types ORDER BY sim_type ASC
");
$simTypes = [];
if ($simTypesRes) {
    while ($row = $simTypesRes->fetch_assoc()) {
        if (!empty($row['sim_type'])) {
            $simTypes[] = trim($row['sim_type']);
        }
    }
}

// 6. Fetch Available SIMs (optionally filtered by sim_type)
$simTypeFilter = trim((string)($_GET['sim_type'] ?? ''));
$simSql = "
    SELECT s.id, s.sim_no, s.sim_type
    FROM sims s
    WHERE s.status = 'available'
      AND NOT EXISTS (
          SELECT 1 FROM courier_requests cr 
          WHERE cr.sim_id = s.id AND cr.approval_status = 'Pending Approval'
      )
";
$simParams = [];
$simTypesStr = '';
if ($simTypeFilter !== '') {
    $simSql .= " AND s.sim_type = ?";
    $simParams[] = $simTypeFilter;
    $simTypesStr .= 's';
}
$simSql .= " ORDER BY s.sim_no ASC";

$simStmt = $conn->prepare($simSql);
if ($simParams) {
    $simStmt->bind_param($simTypesStr, ...$simParams);
}
$simStmt->execute();
$simRes = $simStmt->get_result();
$availableSims = [];
if ($simRes) {
    while ($row = $simRes->fetch_assoc()) {
        $availableSims[] = [
            'id' => (int)$row['id'],
            'sim_no' => $row['sim_no'],
            'sim_type' => $row['sim_type']
        ];
    }
}
$simStmt->close();

// 7. Fetch Platforms
$platformsRes = $conn->query("SELECT id, platform_name, status FROM platforms WHERE status = 'Active' ORDER BY platform_name ASC");
$platforms = [];
if ($platformsRes) {
    while ($row = $platformsRes->fetch_assoc()) {
        $platforms[] = [
            'id' => (int)$row['id'],
            'name' => $row['platform_name']
        ];
    }
}

// 8. Fetch Vehicle Types
$vehTypesRes = $conn->query("SELECT id, vehicle_type, status FROM vehicle_types WHERE status = 'Active' OR status IS NULL ORDER BY vehicle_type ASC");
$vehicleTypes = [];
if ($vehTypesRes) {
    while ($row = $vehTypesRes->fetch_assoc()) {
        $vehicleTypes[] = [
            'id' => (int)$row['id'],
            'name' => $row['vehicle_type']
        ];
    }
}

// 9. Fetch SIM Validities
$validitiesRes = $conn->query("SELECT id, months, status FROM sim_validities WHERE status = 'active' OR status IS NULL ORDER BY months ASC");
$validities = [];
if ($validitiesRes) {
    while ($row = $validitiesRes->fetch_assoc()) {
        $validities[] = [
            'id' => (int)$row['id'],
            'months' => (int)$row['months'],
            'label' => $row['months'] . ' Months'
        ];
    }
}

// 10. Fetch Lead Closures
$leadClosuresRes = $conn->query("SELECT id, lead_closure_name, status FROM lead_closures WHERE status = 'Active' OR status IS NULL ORDER BY lead_closure_name ASC");
$leadClosures = [];
if ($leadClosuresRes) {
    while ($row = $leadClosuresRes->fetch_assoc()) {
        $leadClosures[] = [
            'id' => (int)$row['id'],
            'name' => $row['lead_closure_name']
        ];
    }
}

// 11. Fetch Sale Amounts
$saleAmountsRes = $conn->query("SELECT id, sale_amount, status FROM sale_amounts WHERE status = 'Active' OR status IS NULL ORDER BY sale_amount ASC");
$saleAmounts = [];
if ($saleAmountsRes) {
    while ($row = $saleAmountsRes->fetch_assoc()) {
        $saleAmounts[] = [
            'id' => (int)$row['id'],
            'amount' => $row['sale_amount']
        ];
    }
}

$conn->close();

sendResponse(true, "Available stock fetched successfully", [
    "dealers" => $dealers,
    "technicians" => $technicians,
    "customers" => $customers,
    "device_models" => $deviceModels,
    "available_imeis" => $availableImeis,
    "sim_types" => $simTypes,
    "available_sims" => $availableSims,
    "platforms" => $platforms,
    "vehicle_types" => $vehicleTypes,
    "validities" => $validities,
    "lead_closures" => $leadClosures,
    "sale_amounts" => $saleAmounts
]);
?>
