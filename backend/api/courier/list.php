<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

$currentUser = authenticate();
ensurePermissionDefinitions([
    'courier.view', 'courier.add', 'courier.edit', 'courier.delete', 'courier.approve'
]);

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, "Database connection failed", [], [], 500);
}

$userId = (int)($currentUser['user_id'] ?? 0);
$isSuperAdmin = isSuperAdminUser($userId);
$userPermissions = getUserPermissions($userId);

if (!$isSuperAdmin && !in_array('courier.view', $userPermissions, true)) {
    sendResponse(false, "Unauthorized access", [], [], 403);
}

$canApprove = $isSuperAdmin || in_array('courier.approve', $userPermissions, true);

// Search filter
if (($search = trim((string)($_GET['search'] ?? ''))) !== '') {
    $where[] = '(
        dl.dealer_name LIKE ? OR 
        tech.technician_name LIKE ? OR 
        d.imei_no LIKE ? OR 
        s.sim_no LIKE ? OR 
        cr.tracking_id LIKE ? OR 
        cr.courier_send_via LIKE ? OR 
        cr.software LIKE ? OR 
        cr.requested_by_name LIKE ? OR
        dt.device_type LIKE ? OR
        cr.sim_type LIKE ? OR
        c.username LIKE ?
    )';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= 'sssssssssss';
}

// Approval status filter (Pending Approval, Approved, Rejected)
$approvalStatus = trim((string)($_GET['approval_status'] ?? ''));
if ($approvalStatus === 'Pending Approval') {
    if (!$canApprove) {
        sendResponse(false, "Unauthorized: You do not have permission to access pending courier approvals.", [], [], 403);
    }
    $where[] = 'cr.approval_status = ?';
    $params[] = $approvalStatus;
    $types .= 's';
} else if ($approvalStatus !== '') {
    $where[] = 'cr.approval_status = ?';
    $params[] = $approvalStatus;
    $types .= 's';
} else if (!$canApprove) {
    $where[] = "cr.approval_status != 'Pending Approval'";
}

// Courier status filter (Reached, Not Reached)
if (($courierStatus = trim((string)($_GET['courier_status'] ?? ''))) !== '') {
    $where[] = 'cr.courier_status = ?';
    $params[] = $courierStatus;
    $types .= 's';
}

// Courier To Person filter (Dealer, Technician, Customer)
if (($courierTo = trim((string)($_GET['courier_to_person'] ?? ''))) !== '') {
    $where[] = 'cr.courier_to_person = ?';
    $params[] = $courierTo;
    $types .= 's';
}

// Dealer filter
if (($dealerId = filter_input(INPUT_GET, 'dealer_id', FILTER_VALIDATE_INT)) !== false && $dealerId) {
    $where[] = 'cr.dealer_id = ?';
    $params[] = $dealerId;
    $types .= 'i';
}

// Technician filter
if (($technicianId = filter_input(INPUT_GET, 'technician_id', FILTER_VALIDATE_INT)) !== false && $technicianId) {
    $where[] = 'cr.technician_id = ?';
    $params[] = $technicianId;
    $types .= 'i';
}

// Date filters
if (!empty($_GET['date_from'])) {
    $where[] = 'cr.courier_date >= ?';
    $params[] = $_GET['date_from'];
    $types .= 's';
}
if (!empty($_GET['date_to'])) {
    $where[] = 'cr.courier_date <= ?';
    $params[] = $_GET['date_to'];
    $types .= 's';
}

$sql = "
    SELECT 
        cr.id,
        cr.request_code,
        cr.courier_to_person,
        cr.dealer_id,
        cr.technician_id,
        cr.customer_id,
        cr.is_new_customer,
        cr.new_customer_data,
        dl.dealer_name,
        dl.installation_status AS dealer_status,
        tech.technician_name,
        c.username AS customer_name,
        c.status AS customer_status,
        CASE 
            WHEN cr.is_new_customer = 1 THEN COALESCE(c.username, JSON_UNQUOTE(JSON_EXTRACT(cr.new_customer_data, '$.step1.username')), 'New Customer')
            WHEN cr.courier_to_person = 'Technician' THEN tech.technician_name 
            WHEN cr.courier_to_person = 'Customer' THEN c.username
            ELSE dl.dealer_name 
        END AS recipient_name,
        CASE 
            WHEN cr.is_new_customer = 1 THEN 'New Customer'
            WHEN cr.courier_to_person = 'Technician' THEN 'Technician' 
            WHEN cr.courier_to_person = 'Customer' THEN c.status
            ELSE dl.installation_status 
        END AS recipient_status,
        cr.asset_type,
        cr.device_model_id,
        dt.device_type AS device_model,
        cr.device_id,
        d.imei_no,
        cr.sim_type,
        cr.sim_id,
        s.sim_no,
        cr.software,
        cr.request_date,
        cr.notes,
        cr.courier_date,
        cr.tracking_id,
        cr.courier_send_via,
        cr.device_count,
        cr.sim_count,
        cr.courier_status,
        cr.courier_reason,
        cr.approval_status,
        cr.requested_by_user_id,
        COALESCE(cr.requested_by_name, u.employee_name, u.username, 'Admin') AS requested_by_name,
        cr.created_at,
        cr.updated_at
    FROM courier_requests cr
    LEFT JOIN dealers dl ON dl.id = cr.dealer_id
    LEFT JOIN technicians tech ON tech.id = cr.technician_id
    LEFT JOIN customers c ON c.id = cr.customer_id
    LEFT JOIN device_types dt ON dt.id = cr.device_model_id
    LEFT JOIN devices d ON d.id = cr.device_id
    LEFT JOIN sims s ON s.id = cr.sim_id
    LEFT JOIN users u ON u.id = cr.requested_by_user_id
";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY cr.created_at DESC, cr.id DESC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare courier request query: ' . $conn->error, [], [], 500);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$requests = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['request_code'] = 'CR-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT);
        $requests[] = $row;
    }
}

$stmt->close();
$conn->close();

sendResponse(true, "Courier requests fetched successfully", [
    "requests" => $requests,
    "can_approve" => $canApprove
]);
?>
