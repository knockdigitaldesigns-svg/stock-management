<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.view');
$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
if ($customerId === false || $customerId < 0) {
    $conn->close();
    sendResponse(false, 'Invalid customer ID filter.', [], [], 400);
}
$customerId = $customerId ?: 0;
$settings = renewalSettings($conn);
renewalInitialize($conn, $settings);
renewalLifecycle($conn, $settings);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 10)));
$filterQuery = renewalListFilters($_GET);
$condition = $filterQuery['condition'];
$params = $filterQuery['params'];
$types = $filterQuery['types'];
$countSql = renewalCountQuery() . $condition;
$count = $conn->prepare($countSql); $countParams = $params; renewalBind($count, $types, $countParams); $count->execute(); $total = (int)$count->get_result()->fetch_assoc()['total']; $count->close();
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(renewalRowQuery($conn) . $condition . ' ORDER BY ci.installation_date DESC, cr.id DESC LIMIT ? OFFSET ?');
$queryParams = array_merge($params, [$pageSize, $offset]); renewalBind($stmt, $types . 'ii', $queryParams); $stmt->execute();
$result = $stmt->get_result(); $rows = []; while ($row = $result->fetch_assoc()) $rows[] = $row; $stmt->close();
$validitiesResult = $conn->query("SELECT months FROM sim_validities WHERE status = 'active' ORDER BY months"); $validities = []; while ($validitiesResult && ($validity = $validitiesResult->fetch_assoc())) $validities[] = (int)$validity['months'];
$yearsResult = $conn->query('SELECT DISTINCT YEAR(installation_date) AS year FROM customer_installations WHERE installation_date IS NOT NULL ORDER BY year DESC'); $years = []; while ($yearsResult && ($year = $yearsResult->fetch_assoc())) $years[] = (int)$year['year'];
$customerResult = $conn->query('SELECT id, username, primary_mobile_no FROM customers ORDER BY username, id');
if (!$customerResult) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to fetch customer options: ' . $error, [], [], 500);
}
$customers = [];
while ($customer = $customerResult->fetch_assoc()) {
    $customers[] = $customer;
}
$summarySql = "
    SELECT
        COUNT(DISTINCT CASE WHEN lifecycle.sim_status = 'active' THEN lifecycle.sim_id END) AS active_sims,
        COUNT(DISTINCT CASE WHEN lifecycle.sim_status = 'safe custody' THEN lifecycle.sim_id END) AS safe_custody_sims,
        COUNT(DISTINCT CASE WHEN lifecycle.sim_status = 'expired' THEN lifecycle.sim_id END) AS expired_sims,
        COUNT(DISTINCT CASE WHEN lifecycle.sim_status IN ('deactive', 'deactivated') THEN lifecycle.sim_id END) AS deactivated_sims
    FROM (
        SELECT assigned_sims.sim_id,
               LOWER(TRIM(SUBSTRING_INDEX(
                   GROUP_CONCAT(assigned_sims.sim_status ORDER BY assigned_sims.renewal_id DESC),
                   ',',
                   1
               ))) AS sim_status
        FROM (
            SELECT cv.sim_id_1 AS sim_id, COALESCE(cr.id, 0) AS renewal_id,
                   COALESCE(cr.sim_status, 'Active') AS sim_status, cv.customer_id
            FROM customer_vehicle_details cv
            INNER JOIN customers c ON c.id = cv.customer_id
            INNER JOIN sims s ON s.id = cv.sim_id_1
            LEFT JOIN customer_renewals cr ON cr.vehicle_id = cv.id AND cr.customer_id = c.id
            WHERE cv.sim_id_1 IS NOT NULL
            UNION ALL
            SELECT cv.sim_id_2 AS sim_id, COALESCE(cr.id, 0) AS renewal_id,
                   COALESCE(cr.sim_status, 'Active') AS sim_status, cv.customer_id
            FROM customer_vehicle_details cv
            INNER JOIN customers c ON c.id = cv.customer_id
            INNER JOIN sims s ON s.id = cv.sim_id_2
            LEFT JOIN customer_renewals cr ON cr.vehicle_id = cv.id AND cr.customer_id = c.id
            WHERE cv.sim_id_2 IS NOT NULL
        ) assigned_sims
        WHERE (? = 0 OR assigned_sims.customer_id = ?)
        GROUP BY assigned_sims.sim_id
    ) lifecycle
";
$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Failed to prepare customer SIM status summary: ' . $error, [], [], 500);
}
$summaryStmt->bind_param('ii', $customerId, $customerId);
if (!$summaryStmt->execute()) {
    $error = $summaryStmt->error;
    $summaryStmt->close();
    $conn->close();
    sendResponse(false, 'Failed to fetch customer SIM status summary: ' . $error, [], [], 500);
}
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();
$conn->close();
sendResponse(true, 'Renewals fetched successfully.', [
    'renewals' => $rows,
    'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $pageSize))],
    'validities' => $validities,
    'years' => $years,
    'customers' => $customers,
    'summary_counts' => [
        'active_sims' => (int) ($summary['active_sims'] ?? 0),
        'safe_custody_sims' => (int) ($summary['safe_custody_sims'] ?? 0),
        'expired_sims' => (int) ($summary['expired_sims'] ?? 0),
        'deactivated_sims' => (int) ($summary['deactivated_sims'] ?? 0)
    ]
]);
?>