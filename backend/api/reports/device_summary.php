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

$summary = [
    'total' => 0,
    'available' => 0,
    'allocated' => 0,
    'used' => 0,
    'reserved' => 0
];

$sql = "
    SELECT report_rows.status, COUNT(*) AS count
    FROM (
        SELECT
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM customer_vehicle_details cvd
                    WHERE cvd.device_id = d.id
                       OR BINARY cvd.imei_no = BINARY d.imei_no
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
    GROUP BY report_rows.status
";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row['status'], $summary)) {
            $summary[$row['status']] = (int)$row['count'];
        }
        $summary['total'] += (int)$row['count'];
    }
}

$model_counts = [];
$sql_models = "
    SELECT dm.device_type AS model_name, COUNT(d.id) as count 
    FROM devices d 
    JOIN device_types dm ON d.device_model_id = dm.id
    WHERE d.deleted_at IS NULL
    GROUP BY dm.device_type
";
$res_models = $conn->query($sql_models);
if ($res_models) {
    while ($row = $res_models->fetch_assoc()) {
        $model_counts[$row['model_name']] = (int)$row['count'];
    }
}

sendResponse(true, "Device summary fetched", [
    "summary" => $summary,
    "model_counts" => $model_counts
]);
$conn->close();
?>
