<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../utils/notifications.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
if (PHP_SAPI !== 'cli') {
    $currentUser = authenticate();
    if ((int) ($currentUser['is_admin'] ?? 0) !== 1 &&
        !in_array(strtolower((string) ($currentUser['role_name'] ?? '')), ['admin', 'super admin', 'super_admin'], true)) {
        sendResponse(false, 'Forbidden.', [], [], 403);
    }
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$now = $conn->query('SELECT NOW() AS current_time')->fetch_assoc()['current_time'];
$hasCancelled = $conn->query("SHOW COLUMNS FROM crm_lead_followups LIKE 'cancelled_at'")->num_rows > 0;
$cancelCondition = $hasCancelled ? 'AND f.cancelled_at IS NULL' : '';

$reminderSql =
    "SELECT f.id AS followup_id, f.lead_id, l.assigned_user_id, l.lead_name,
            CONCAT(f.next_followup_date, ' ', COALESCE(f.next_followup_time, '00:00:00')) AS due_at
     FROM crm_lead_followups f
     INNER JOIN crm_leads l ON l.id = f.lead_id
     WHERE f.completed_at IS NULL {$cancelCondition}
       AND f.next_followup_date IS NOT NULL
       AND TIMESTAMP(f.next_followup_date, COALESCE(f.next_followup_time, '00:00:00')) <= ?
       AND TIMESTAMP(f.next_followup_date, COALESCE(f.next_followup_time, '00:00:00')) >= DATE_SUB(?, INTERVAL 5 MINUTE)
       AND l.assigned_user_id IS NOT NULL";
$reminders = $conn->prepare($reminderSql);
$reminders->bind_param('ss', $now, $now);
$reminders->execute();
$result = $reminders->get_result();
$reminderCount = 0;
while ($row = $result->fetch_assoc()) {
    $created = crmCreateNotification(
        $conn,
        (int) $row['assigned_user_id'],
        'followup_reminder',
        'Follow-up Reminder',
        'A scheduled follow-up for ' . $row['lead_name'] . ' is due.',
        'High',
        'crm_followup',
        (int) $row['followup_id'],
        'followup_reminder:' . $row['followup_id'],
        (int) $row['lead_id']
    );
    $reminderCount += $created ? 1 : 0;
}
$reminders->close();

$overdueSql =
    "SELECT f.id AS followup_id, f.lead_id, l.assigned_user_id, l.lead_name
     FROM crm_lead_followups f
     INNER JOIN crm_leads l ON l.id = f.lead_id
     WHERE f.completed_at IS NULL {$cancelCondition}
       AND f.next_followup_date IS NOT NULL
       AND TIMESTAMP(f.next_followup_date, COALESCE(f.next_followup_time, '00:00:00')) < ?";
$overdue = $conn->prepare($overdueSql);
$overdue->bind_param('s', $now);
$overdue->execute();
$result = $overdue->get_result();
$overdueCount = 0;
while ($row = $result->fetch_assoc()) {
    $created = crmCreateNotification(
        $conn,
        (int) $row['assigned_user_id'],
        'followup_overdue',
        'Follow-up Overdue',
        'The follow-up for ' . $row['lead_name'] . ' is overdue.',
        'High',
        'crm_followup',
        (int) $row['followup_id'],
        'followup_overdue:' . $row['followup_id'],
        (int) $row['lead_id']
    );
    $overdueCount += $created ? 1 : 0;
}
$overdue->close();
$conn->close();
sendResponse(true, 'Scheduled follow-up notifications processed.', [
    'reminders_created' => $reminderCount,
    'overdue_created' => $overdueCount
]);
?>
