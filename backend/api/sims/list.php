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

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

/*
 * Search
 */
$search = trim((string) ($_GET['search'] ?? ''));

if ($search !== '') {
    $where[] = '(
        s.sim_no LIKE ?
        OR s.sim_type LIKE ?
        OR s.notes LIKE ?
    )';

    $like = "%{$search}%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'sss';
}

/*
 * Year filter
 */
$year = filter_input(
    INPUT_GET,
    'year',
    FILTER_VALIDATE_INT
);

if ($year !== false && $year) {
    $where[] = 'YEAR(s.purchase_date) = ?';
    $params[] = $year;
    $types .= 'i';
}

/*
 * Month filter
 */
$month = filter_input(
    INPUT_GET,
    'month',
    FILTER_VALIDATE_INT
);

if (
    $month !== false &&
    $month >= 1 &&
    $month <= 12
) {
    $where[] = 'MONTH(s.purchase_date) = ?';
    $params[] = $month;
    $types .= 'i';
}

/*
 * SIM Type filter
 */
$simType = trim(
    (string) ($_GET['simType'] ?? '')
);

if ($simType !== '') {
    $where[] = 's.sim_type = ?';
    $params[] = $simType;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| Main Query
|--------------------------------------------------------------------------
|
| SIM Validity has intentionally been removed from SIM Maintenance.
|
| Removed:
| - s.sim_validity_id
| - v.months AS sim_validity_months
| - LEFT JOIN sim_validities
|
*/

$sql = '
    SELECT
        s.id,
        s.purchase_date,
        s.sim_no,
        s.sim_type,
        s.notes,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM customer_vehicle_details cvd
                WHERE cvd.sim_id_1 = s.id
                   OR cvd.sim_id_2 = s.id
            )
            OR EXISTS (
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
                  AND st.transaction_type = "USE"
            )
            THEN "used"

            ELSE s.status
        END AS status,

        sa.allocation_date,
        sa.owner_type,
        sa.owner_id,
        sa.payment_status,
        sa.software,

        CASE
            WHEN sa.owner_type = "dealer"
                THEN dl.dealer_name

            WHEN sa.owner_type = "technician"
                THEN t.technician_name

            ELSE NULL
        END AS owner_name

    FROM sims s

    LEFT JOIN stock_allocations sa
        ON sa.sim_id = s.id
       AND sa.id = (
           SELECT MAX(id)
           FROM stock_allocations
           WHERE sim_id = s.id
       )

    LEFT JOIN dealers dl
        ON dl.id = sa.owner_id
       AND sa.owner_type = "dealer"

    LEFT JOIN technicians t
        ON t.id = sa.owner_id
       AND sa.owner_type = "technician"
';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY s.created_at DESC';

/*
|--------------------------------------------------------------------------
| Prepare
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $conn->close();

    sendResponse(
        false,
        'Failed to prepare SIM query.',
        [],
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Bind filters
|--------------------------------------------------------------------------
*/

if ($params) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        'Failed to fetch SIMs.',
        [],
        [],
        500
    );
}

$result = $stmt->get_result();

$sims = [];

/*
|--------------------------------------------------------------------------
| Build response
|--------------------------------------------------------------------------
*/

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $row['status'] = strtolower(
            trim((string) ($row['status'] ?? 'available'))
        );

        /*
         * SIM Maintenance should use only:
         * available / allocated / used
         */
        if ($row['status'] === 'active') {
            $row['status'] = 'available';
        }

        if ($row['status'] === 'deactive') {
            $row['status'] = 'used';
        }

        if ($row['status'] === 'expired') {
            $row['status'] = 'used';
        }

        if ($row['status'] === 'safe custody') {
            $row['status'] = 'allocated';
        }

        $sims[] = $row;
    }
}

$stmt->close();
$conn->close();

sendResponse(
    true,
    "SIMs fetched successfully",
    [
        "sims" => $sims
    ]
);

?>