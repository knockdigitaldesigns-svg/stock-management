<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, "Method not allowed", [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, "Database connection failed", [], [], 500);

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['available', 'allocated', 'used', 'reserved'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid device status filter.', [], [], 400);
}

$sql = "
    SELECT report_rows.*
    FROM (
        SELECT
            d.id,
            d.imei_no,
            d.purchase_date,
            d.notes,
            dm.device_type AS model_name,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = d.id
                ) OR EXISTS (
                    SELECT 1
                    FROM stock_transactions st
                    WHERE st.device_id = d.id
                      AND st.from_owner_type = sa.owner_type
                      AND st.from_owner_id = sa.owner_id
                      AND st.id = (
                          SELECT MAX(st_latest.id)
                          FROM stock_transactions st_latest
                          WHERE st_latest.device_id = d.id
                            AND st_latest.from_owner_type = sa.owner_type
                            AND st_latest.from_owner_id = sa.owner_id
                      )
                      AND st.transaction_type = 'USE'
                ) THEN 'used'
                ELSE CASE
                    WHEN LOWER(COALESCE(d.status, 'available')) = 'active' THEN 'available'
                    WHEN LOWER(COALESCE(d.status, 'available')) IN ('deactive', 'expired') THEN 'used'
                    ELSE LOWER(COALESCE(d.status, 'available'))
                END
            END AS status
        FROM devices d
        JOIN device_types dm ON dm.id = d.device_model_id
        LEFT JOIN stock_allocations sa
            ON sa.id = (
                SELECT latest_sa.id
                FROM stock_allocations latest_sa
                WHERE latest_sa.device_id = d.id
                ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                LIMIT 1
            )
        WHERE d.deleted_at IS NULL
    ) report_rows
";

if ($statusFilter !== '') {
    $sql .= ' WHERE report_rows.status = ?';
}
$sql .= ' ORDER BY report_rows.purchase_date DESC, report_rows.id DESC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare device report query.', [], [], 500);
}
if ($statusFilter !== '') {
    $stmt->bind_param('s', $statusFilter);
}
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch device report.', [], [], 500);
}

$devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
sendResponse(true, "Devices fetched", ["devices" => $devices]);
$stmt->close();
$conn->close();
?>
