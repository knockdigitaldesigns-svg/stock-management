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
        LEFT JOIN stock_allocations sa
            ON sa.id = (
                SELECT latest_sa.id
                FROM stock_allocations latest_sa
                WHERE latest_sa.sim_id = s.id
                ORDER BY latest_sa.created_at DESC, latest_sa.id DESC
                LIMIT 1
            )
        WHERE s.deleted_at IS NULL
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

sendResponse(true, "SIM summary fetched", ["summary" => $summary]);
$conn->close();
?>
