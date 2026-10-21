<?php
require_once __DIR__ . '/common.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('customer_renewals.view');
$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);
$settings = renewalSettings($conn);
renewalInitialize($conn, $settings);
renewalLifecycle($conn, $settings);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 10)));
$filterQuery = renewalListFilters($_GET);
$condition = $filterQuery['condition'];
$params = $filterQuery['params'];
$types = $filterQuery['types'];
$countSql = "SELECT COUNT(*) AS total FROM customer_renewals cr INNER JOIN customers c ON c.id = cr.customer_id LEFT JOIN customer_vehicle_details cv ON cv.id = (SELECT id FROM customer_vehicle_details WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) LEFT JOIN device_types dt ON dt.id = cv.device_model_id LEFT JOIN customer_installations ci ON ci.id = (SELECT id FROM customer_installations WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1)" . $condition;
$count = $conn->prepare($countSql); $countParams = $params; renewalBind($count, $types, $countParams); $count->execute(); $total = (int)$count->get_result()->fetch_assoc()['total']; $count->close();
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare(renewalRowQuery() . $condition . ' ORDER BY ci.installation_date DESC, cr.id DESC LIMIT ? OFFSET ?');
$queryParams = array_merge($params, [$pageSize, $offset]); renewalBind($stmt, $types . 'ii', $queryParams); $stmt->execute();
$result = $stmt->get_result(); $rows = []; while ($row = $result->fetch_assoc()) $rows[] = $row; $stmt->close();
$validitiesResult = $conn->query("SELECT months FROM sim_validities WHERE status = 'active' ORDER BY months"); $validities = []; while ($validitiesResult && ($validity = $validitiesResult->fetch_assoc())) $validities[] = (int)$validity['months'];
$yearsResult = $conn->query('SELECT DISTINCT YEAR(installation_date) AS year FROM customer_installations WHERE installation_date IS NOT NULL ORDER BY year DESC'); $years = []; while ($yearsResult && ($year = $yearsResult->fetch_assoc())) $years[] = (int)$year['year'];
$conn->close();
sendResponse(true, 'Renewals fetched successfully.', ['renewals' => $rows, 'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $pageSize))], 'validities' => $validities, 'years' => $years]);
?>