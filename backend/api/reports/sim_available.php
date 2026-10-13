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
$allowedStatuses = ['available', 'allocated', 'used', 'reserved', 'active', 'deactive', 'expired', 'safe custody'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid SIM status filter.', [], [], 400);
}

$sql = "
    SELECT report_rows.*
    FROM (
        SELECT
            inventory_rows.*,
            CASE
                WHEN inventory_rows.status = 'allocated'
                    AND LOWER(COALESCE(inventory_rows.allocation_sim_status, 'available')) <> 'available'
                    THEN COALESCE(inventory_rows.allocation_sim_status, 'Available')
                ELSE NULL
            END AS lifecycle_status
        FROM (
            SELECT
                s.id,
                s.sim_no,
                s.purchase_date,
                s.sim_type,
                s.sim_validity_id,
                s.notes,
                v.months AS sim_validity_months,
                sa.sim_status AS allocation_sim_status,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM customer_vehicle_details cvd
                        WHERE cvd.sim_id_1 = s.id OR cvd.sim_id_2 = s.id
                    ) OR EXISTS (
                        SELECT 1
                        FROM stock_transactions st
                        WHERE st.sim_id = s.id
                          AND st.from_owner_type = sa.owner_type
                          AND st.from_owner_id = sa.owner_id
                          AND st.id = (
                              SELECT MAX(st_latest.id)
                              FROM stock_transactions st_latest
                              WHERE st_latest.sim_id = s.id
                                AND st_latest.from_owner_type = sa.owner_type
                                AND st_latest.from_owner_id = sa.owner_id
                          )
                          AND st.transaction_type = 'USE'
                    ) THEN 'used'
                    ELSE LOWER(COALESCE(s.status, 'available'))
                END AS status
            FROM sims s
            LEFT JOIN sim_validities v ON v.id = s.sim_validity_id
            LEFT JOIN stock_allocations sa
                ON sa.id = (
                    SELECT latest_sa.id
                    FROM stock_allocations latest_sa
                    WHERE latest_sa.sim_id = s.id
                    ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                    LIMIT 1
                )
            WHERE s.deleted_at IS NULL
        ) inventory_rows
    ) report_rows
";

if ($statusFilter !== '') {
    $sql .= ' WHERE LOWER(report_rows.status) = ? OR LOWER(COALESCE(report_rows.lifecycle_status, \'\')) = ?';
}
$sql .= ' ORDER BY report_rows.purchase_date DESC, report_rows.id DESC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Failed to prepare SIM report query.', [], [], 500);
}
if ($statusFilter !== '') {
    $stmt->bind_param('ss', $statusFilter, $statusFilter);
}
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch SIM report.', [], [], 500);
}

$sims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
sendResponse(true, "SIMs fetched", ["sims" => $sims]);
$stmt->close();
$conn->close();
?>
