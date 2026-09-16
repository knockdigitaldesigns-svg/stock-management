<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();

$recipientType = trim((string) ($_GET['recipient_type'] ?? ''));
$recipientId = (int) ($_GET['recipient_id'] ?? 0);
if (!in_array($recipientType, ['Technician', 'Dealer'], true) || $recipientId <= 0) {
    sendResponse(false, 'Recipient type and recipient ID are required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed.', [], [], 500);

$stmt = $conn->prepare(
    'SELECT ccc.*, c.username, c.primary_mobile_no, c.location,
            ci.installation_person_type, ci.installation_date,
            p.platform_name, cv.vehicle_no, cv.imei_no, cv.validity_months,
            cv.device_model_id, dt.device_type AS device_model
     FROM customer_cash_collections ccc
     INNER JOIN customers c ON c.id = ccc.customer_id
     INNER JOIN customer_installations ci ON ci.id = ccc.installation_id
     LEFT JOIN platforms p ON p.id = c.platform_id
     LEFT JOIN customer_vehicle_details cv ON cv.id = (
        SELECT latest_cv.id FROM customer_vehicle_details latest_cv
        WHERE latest_cv.customer_id = c.id ORDER BY latest_cv.created_at DESC, latest_cv.id DESC LIMIT 1
     )
     LEFT JOIN device_types dt ON dt.id = cv.device_model_id
     WHERE ccc.recipient_type = ? AND ccc.recipient_id = ?
     ORDER BY ci.installation_date DESC, ccc.id DESC'
);
$stmt->bind_param('si', $recipientType, $recipientId);
$stmt->execute();
$result = $stmt->get_result();
$collections = [];
while ($row = $result->fetch_assoc()) {
    foreach (['amount_collected', 'amount_remitted', 'pending_amount'] as $field) $row[$field] = (float) $row[$field];
    $collections[] = $row;
}
$stmt->close();
$conn->close();
sendResponse(true, 'Customer cash collections fetched successfully.', ['collections' => $collections]);
?>