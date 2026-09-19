<?php
require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('sim_lifecycle.view');

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$sql = "SELECT sa.id AS allocation_id, sa.owner_id, d.dealer_name, s.id AS sim_id, s.sim_no, s.sim_type,
               COALESCE(sa.sim_given_date, sa.allocation_date) AS given_date,
               sa.sim_activation_date AS activation_date, sa.sim_expiry_date AS expiry_date,
               sa.sim_deactivation_date AS deactivation_date,
               COALESCE(sa.sim_validity_id, s.sim_validity_id) AS sim_validity_id,
               sv.months AS validity_months, COALESCE(sa.sim_status, s.status, 'Available') AS sim_status,
               sa.allocation_date, sa.software, sa.notes
        FROM stock_allocations sa
        INNER JOIN sims s ON s.id = sa.sim_id
        INNER JOIN dealers d ON d.id = sa.owner_id
        LEFT JOIN sim_validities sv ON sv.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
        WHERE sa.owner_type = 'dealer'
        ORDER BY d.dealer_name ASC, s.sim_no ASC";
$result = $conn->query($sql);
$rows = [];
while ($result && ($row = $result->fetch_assoc())) {
    $row['allocation_id'] = (int) $row['allocation_id'];
    $row['owner_id'] = (int) $row['owner_id'];
    $row['sim_id'] = (int) $row['sim_id'];
    $row['sim_validity_id'] = $row['sim_validity_id'] !== null ? (int) $row['sim_validity_id'] : null;
    $row['validity_months'] = $row['validity_months'] !== null ? (int) $row['validity_months'] : null;
    $rows[] = $row;
}
$conn->close();
sendResponse(true, 'Dealer SIM activation records fetched.', ['records' => $rows]);
?>