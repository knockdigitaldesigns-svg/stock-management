<?php

require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, "Method not allowed", [], [], 405);
}

authenticate();

/*
|--------------------------------------------------------------------------
| Temporary diagnostic settings
|--------------------------------------------------------------------------
| Logs go to the PHP error log; they are not returned to normal API callers.
| Use ?debug=1 during testing to include safe timing/count diagnostics in the
| successful response. Remove this block after confirming the endpoint works.
*/
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
$requestStartedAt = microtime(true);

ini_set('log_errors', '1');
@set_time_limit(120);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        error_log('[SIM_LIST] Database connection failed.');
        sendResponse(false, "Database connection failed", [], [], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */
    $where = ['s.deleted_at IS NULL'];
    $customerId = max(0, (int) ($_GET['customer_id'] ?? 0));
    $vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? 0));

    // These five parameters correspond to the five placeholders in the SELECT.
    $params = [$customerId, $customerId, $customerId, $vehicleId, $vehicleId];
    $types = 'iiiii';

    // Search
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

    // Year filter
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
    if ($year !== false && $year !== null && $year > 0) {
        $where[] = 'YEAR(s.purchase_date) = ?';
        $params[] = $year;
        $types .= 'i';
    }

    // Month filter
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
    if ($month !== false && $month !== null && $month >= 1 && $month <= 12) {
        $where[] = 'MONTH(s.purchase_date) = ?';
        $params[] = $month;
        $types .= 'i';
    }

    // SIM Type filter
    $simType = trim((string) ($_GET['simType'] ?? ''));
    if ($simType !== '') {
        $where[] = 's.sim_type = ?';
        $params[] = $simType;
        $types .= 's';
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
    $offset = ($page - 1) * $pageSize;
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*) AS total FROM sims s' . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        sendResponse(false, 'Failed to prepare SIM count query.', [], [], 500);
    }
    $countParams = array_slice($params, 5);
    $countTypes = substr($types, 5);
    if ($countParams && !$countStmt->bind_param($countTypes, ...$countParams)) {
        $countStmt->close();
        sendResponse(false, 'Failed to bind SIM count query parameters.', [], [], 500);
    }
    if (!$countStmt->execute()) {
        $countStmt->close();
        sendResponse(false, 'Failed to count SIMs.', [], [], 500);
    }
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sqlBase = '
        SELECT
            s.id,
            s.created_at AS _sort_created_at,
            s.purchase_date,
            s.sim_no,
            s.sim_type,
            s.notes,

            EXISTS (
                SELECT 1 FROM stock_allocations dep WHERE dep.sim_id = s.id
                UNION ALL
                SELECT 1 FROM stock_transactions dep WHERE dep.sim_id = s.id
                UNION ALL
                SELECT 1 FROM stock_transfers dep WHERE dep.sim_id = s.id
                UNION ALL
                SELECT 1 FROM customer_vehicle_details dep
                    WHERE dep.sim_id_1 = s.id OR dep.sim_id_2 = s.id
                UNION ALL
                SELECT 1 FROM courier_requests dep WHERE dep.sim_id = s.id
            ) AS has_history_dependencies,

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

            EXISTS (
                SELECT 1
                FROM customer_vehicle_details assigned
                WHERE (assigned.sim_id_1 = s.id OR assigned.sim_id_2 = s.id)
                  AND (? = 0 OR assigned.customer_id <> ?)
            ) AS is_assigned_to_another_customer,

            EXISTS (
                SELECT 1
                FROM customer_vehicle_details assigned
                WHERE (assigned.sim_id_1 = s.id OR assigned.sim_id_2 = s.id)
                  AND assigned.customer_id = ?
                  AND ? > 0
                  AND assigned.id = ?
            ) AS is_assigned_to_current_customer,

            sa.allocation_date,
            sa.owner_type,
            sa.owner_id,
            dl.installation_status AS owner_installation_status,
            sa.payment_status,
            sa.software,

            CASE
                WHEN sa.owner_type = "dealer" THEN dl.dealer_name
                WHEN sa.owner_type = "technician" THEN t.technician_name
                ELSE NULL
            END AS owner_name

        FROM sims s

        LEFT JOIN stock_allocations sa
            ON sa.sim_id = s.id
           AND sa.id = (
                SELECT MAX(sa_latest.id)
                FROM stock_allocations sa_latest
                WHERE sa_latest.sim_id = s.id
           )

        LEFT JOIN dealers dl
            ON dl.id = sa.owner_id
           AND sa.owner_type = "dealer"

        LEFT JOIN technicians t
            ON t.id = sa.owner_id
           AND sa.owner_type = "technician"
    ';

    $sql = $sqlBase . $whereSql . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?';
    $params[] = $pageSize;
    $params[] = $offset;
    $types .= 'ii';
    $sims = [];
    $queryStartedAt = microtime(true);
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendResponse(false, 'Failed to prepare SIM query.', [], [], 500);
    }
    if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(false, 'Failed to fetch SIMs: ' . $error, [], [], 500);
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        sendResponse(false, 'Failed to retrieve SIMs.', [], [], 500);
    }
    while ($row = $result->fetch_assoc()) {
        $row['status'] = strtolower(trim((string) ($row['status'] ?? 'available')));

        if ($row['status'] === 'active') {
            $row['status'] = 'available';
        } elseif ($row['status'] === 'deactive' || $row['status'] === 'expired') {
            $row['status'] = 'used';
        } elseif ($row['status'] === 'safe custody') {
            $row['status'] = 'allocated';
        }

        unset($row['_sort_created_at']);
        $sims[] = $row;
    }
    $result->free();

    $resultCount = count($sims);
    $queryDurationSeconds = round(microtime(true) - $queryStartedAt, 3);
    $totalDurationSeconds = round(microtime(true) - $requestStartedAt, 3);
    $memoryPeakMb = round(memory_get_peak_usage(true) / 1048576, 2);
    $memoryUsedMb = round(memory_get_usage(true) / 1048576, 2);

    error_log('[SIM_LIST] Success. rows=' . $resultCount . ' query_seconds=' . $queryDurationSeconds);

    $stmt->close();
    $conn->close();

    $responseData = [
        'sims' => $sims,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, (int) ceil($total / $pageSize))
        ],
    ];

    // Safe temporary diagnostics, only when explicitly requested.
    if ($debugMode) {
        $responseData['debug'] = [
            'returned_count' => $resultCount,
            'query_seconds' => $queryDurationSeconds,
            'total_seconds' => $totalDurationSeconds,
            'memory_used_mb' => $memoryUsedMb,
            'memory_peak_mb' => $memoryPeakMb,
            'pagination_enabled' => true,
            'internal_batch_size' => $pageSize,
            'internal_batch_count' => 1,
        ];
    }

    sendResponse(true, "SIMs fetched successfully", $responseData);

} catch (Throwable $e) {
    $duration = round(microtime(true) - $requestStartedAt, 3);

    // Keep detailed diagnostics in server logs, not in the public API response.
    error_log(
        '[SIM_LIST] FAILED. type=' . get_class($e) .
        ' message=' . $e->getMessage() .
        ' file=' . $e->getFile() .
        ' line=' . $e->getLine() .
        ' elapsed_seconds=' . $duration .
        ' memory_peak_mb=' . round(memory_get_peak_usage(true) / 1048576, 2)
    );

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        try {
            $stmt->close();
        } catch (Throwable $ignored) {
            // Nothing else to do during cleanup.
        }
    }

    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $ignored) {
            // Nothing else to do during cleanup.
        }
    }

    sendResponse(
        false,
        "Failed to fetch SIMs. Check the PHP error log for [SIM_LIST] diagnostics.",
        [],
        [],
        500
    );
}
?>
