<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';

function renewalSettings($conn) {
    $result = $conn->query('SELECT expired_to_safe_days, safe_to_deactive_days FROM renewal_settings ORDER BY id DESC LIMIT 1');
    $row = $result ? $result->fetch_assoc() : [];
    return [
        'expired_to_safe_days' => (int)($row['expired_to_safe_days'] ?? 0),
        'safe_to_deactive_days' => (int)($row['safe_to_deactive_days'] ?? 0)
    ];
}

function renewalBind($stmt, $types, $values) {
    if ($types === '') return;
    $bindings = [$types];
    foreach ($values as $key => $value) $bindings[] = &$values[$key];
    call_user_func_array([$stmt, 'bind_param'], $bindings);
}

function renewalInitialize($conn, $settings) {
    $sql = "INSERT INTO customer_renewals
        (customer_id, installation_date, next_renewal_date, validity_months, sim_status, expired_to_safe_days, safe_to_deactive_days)
        SELECT c.id, ci.installation_date,
            DATE_ADD(ci.installation_date, INTERVAL cv.validity_months MONTH),
            cv.validity_months, 'Active', ?, ?
        FROM customers c
        INNER JOIN customer_vehicle_details cv ON cv.id = (
            SELECT id FROM customer_vehicle_details WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1
        )
        INNER JOIN customer_installations ci ON ci.id = (
            SELECT id FROM customer_installations WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1
        )
        WHERE cv.validity_months > 0 AND ci.installation_date IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM customer_renewals cr WHERE cr.customer_id = c.id)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $settings['expired_to_safe_days'], $settings['safe_to_deactive_days']);
    $stmt->execute();
    $stmt->close();
}

function renewalRowQuery() {
    return "SELECT cr.*, c.id AS customer_record_id, c.platform_id, c.status AS customer_status, c.username, c.primary_mobile_no, c.secondary_mobile_no, c.email, c.location, c.pincode,
        p.platform_name, cv.id AS vehicle_record_id, cv.vehicle_type_id, cv.device_model_id, cv.device_id, cv.sim_id_1, cv.sim_id_2,
        cv.vehicle_no, cv.imei_no, cv.sim_no_1, cv.sim_no_2, cv.validity_months AS vehicle_validity_months,
        (SELECT sv.id FROM sim_validities sv WHERE sv.months = cv.validity_months LIMIT 1) AS validity_id,
        dt.device_type AS device_model, vt.vehicle_type, ci.id AS installation_record_id,
        ci.installation_person_id, ci.lead_closure_id, ci.installation_date,
        ci.installation_person_type, lc.lead_closure_name AS lead_closure,
        CASE WHEN ci.installation_person_type = 'Dealer' THEN d.dealer_name ELSE t.technician_name END AS installation_person
        FROM customer_renewals cr
        INNER JOIN customers c ON c.id = cr.customer_id
        LEFT JOIN platforms p ON p.id = c.platform_id
        LEFT JOIN customer_vehicle_details cv ON cv.id = (
            SELECT id FROM customer_vehicle_details WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1
        )
        LEFT JOIN device_types dt ON dt.id = cv.device_model_id
        LEFT JOIN vehicle_types vt ON vt.id = cv.vehicle_type_id
        LEFT JOIN customer_installations ci ON ci.id = (
            SELECT id FROM customer_installations WHERE customer_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1
        )
        LEFT JOIN technicians t ON t.id = ci.installation_person_id AND ci.installation_person_type = 'Technician'
        LEFT JOIN dealers d ON d.id = ci.installation_person_id AND ci.installation_person_type = 'Dealer'
        LEFT JOIN lead_closures lc ON lc.id = ci.lead_closure_id";
}

function renewalHistoryInsert($conn, $row, $action, $userId, $newStatus, $newValidity, $newDate, $payment = [], $notes = null) {
    $amount = (float)($payment['payment_amount'] ?? 0);
    $paid = (float)($payment['amount_paid'] ?? 0);
    $pending = (float)($payment['amount_pending'] ?? 0);
    $mode = $payment['payment_mode'] ?? null;
    $transaction = $payment['transaction_id'] ?? null;
    $stmt = $conn->prepare('INSERT INTO renewal_history (renewal_id, customer_id, action_type, action_date, old_status, new_status, old_validity_months, new_validity_months, old_renewal_date, new_renewal_date, payment_amount, amount_paid, amount_pending, payment_mode, transaction_id, changed_by, notes) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iisssiissdddssis', $row['id'], $row['customer_id'], $action, $row['sim_status'], $newStatus, $row['validity_months'], $newValidity, $row['next_renewal_date'], $newDate, $amount, $paid, $pending, $mode, $transaction, $userId, $notes);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function renewalLifecycle($conn, $settings = []) {
    $today = date('Y-m-d');
    $result = $conn->query("SELECT * FROM customer_renewals WHERE sim_status IN ('Active', 'Expired', 'Safe Custody')");
    if (!$result) return;
    while ($row = $result->fetch_assoc()) {
        $newStatus = null;
        $action = null;
        if ($row['sim_status'] === 'Active' && $row['next_renewal_date'] && $row['next_renewal_date'] <= $today) {
            $newStatus = 'Expired';
            $action = 'Auto Expired';
        } elseif ($row['sim_status'] === 'Expired' && $row['next_renewal_date'] && (strtotime($today) - strtotime($row['next_renewal_date'])) >= ((int)$row['expired_to_safe_days'] * 86400)) {
            $newStatus = 'Safe Custody';
            $action = 'Auto Safe Custody';
        } elseif ($row['sim_status'] === 'Safe Custody') {
            $history = $conn->prepare("SELECT action_date FROM renewal_history WHERE renewal_id = ? AND action_type IN ('Safe Custody', 'Auto Safe Custody') ORDER BY id DESC LIMIT 1");
            $history->bind_param('i', $row['id']);
            $history->execute();
            $entered = $history->get_result()->fetch_assoc();
            $history->close();
            $enteredDate = $entered['action_date'] ?? substr($row['updated_at'], 0, 10);
            if ((strtotime($today) - strtotime($enteredDate)) >= ((int)$row['safe_to_deactive_days'] * 86400)) {
                $newStatus = 'Deactive';
                $action = 'Auto Deactive';
            }
        }
        if (!$newStatus) continue;
        $conn->begin_transaction();
        $lock = $conn->prepare('SELECT * FROM customer_renewals WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $row['id']);
        $lock->execute();
        $current = $lock->get_result()->fetch_assoc();
        $lock->close();
        if ($current && $current['sim_status'] === $row['sim_status']) {
            $update = $conn->prepare('UPDATE customer_renewals SET sim_status = ? WHERE id = ?');
            $update->bind_param('si', $newStatus, $row['id']);
            $update->execute();
            $update->close();
            renewalHistoryInsert($conn, $current, $action, null, $newStatus, $current['validity_months'], $current['next_renewal_date']);
        }
        $conn->commit();
    }
}
