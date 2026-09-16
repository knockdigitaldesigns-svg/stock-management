<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/response.php';
require_once __DIR__ . '/../../middleware/auth.php';

function ensureRenewalTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS renewal_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expired_to_safe_days INT NOT NULL DEFAULT 10,
        safe_to_deactive_days INT NOT NULL DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("INSERT IGNORE INTO renewal_settings (id, expired_to_safe_days, safe_to_deactive_days) VALUES (1, 10, 10)");

    $conn->query("CREATE TABLE IF NOT EXISTS customer_renewals (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        installation_date DATE NOT NULL,
        next_renewal_date DATE DEFAULT NULL,
        validity_months INT NOT NULL,
        sim_status ENUM('Active','Deactive','Expired','Safe Custody') NOT NULL DEFAULT 'Active',
        expired_to_safe_days INT DEFAULT NULL,
        safe_to_deactive_days INT DEFAULT NULL,
        last_renewed_date DATE DEFAULT NULL,
        safe_custody_date DATE DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_renewal_customer (customer_id),
        INDEX idx_customer_next_renewal (next_renewal_date),
        INDEX idx_customer_sim_status (sim_status),
        CONSTRAINT fk_customer_renewal_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $colCheck = $conn->query("SHOW COLUMNS FROM customer_renewals LIKE 'safe_custody_date'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE customer_renewals ADD COLUMN safe_custody_date DATE DEFAULT NULL AFTER last_renewed_date");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS renewal_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT NOT NULL,
        customer_id INT NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        action_date DATE NOT NULL,
        old_status VARCHAR(50) DEFAULT NULL,
        new_status VARCHAR(50) DEFAULT NULL,
        old_validity_months INT DEFAULT NULL,
        new_validity_months INT DEFAULT NULL,
        old_renewal_date DATE DEFAULT NULL,
        new_renewal_date DATE DEFAULT NULL,
        payment_amount DECIMAL(10,2) DEFAULT 0.00,
        amount_paid DECIMAL(10,2) DEFAULT 0.00,
        amount_pending DECIMAL(10,2) DEFAULT 0.00,
        payment_mode VARCHAR(50) DEFAULT NULL,
        transaction_id VARCHAR(100) DEFAULT NULL,
        changed_by INT DEFAULT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function renewalSettings($conn) {
    ensureRenewalTables($conn);
    $result = $conn->query('SELECT expired_to_safe_days, safe_to_deactive_days FROM renewal_settings ORDER BY id DESC LIMIT 1');
    $row = $result ? $result->fetch_assoc() : [];
    $expired = (int)($row['expired_to_safe_days'] ?? 10);
    $safe = (int)($row['safe_to_deactive_days'] ?? 10);
    return [
        'expired_to_safe_days' => $expired > 0 ? $expired : 10,
        'safe_to_deactive_days' => $safe > 0 ? $safe : 10
    ];
}

function renewalBind($stmt, $types, $values) {
    if ($types === '') return;
    $bindings = [$types];
    foreach ($values as $key => $value) $bindings[] = &$values[$key];
    call_user_func_array([$stmt, 'bind_param'], $bindings);
}

function renewalInitialize($conn, $settings) {
    ensureRenewalTables($conn);
    $expiredDays = (int)($settings['expired_to_safe_days'] ?? 10);
    $deactiveDays = (int)($settings['safe_to_deactive_days'] ?? 10);
    if ($expiredDays <= 0) $expiredDays = 10;
    if ($deactiveDays <= 0) $deactiveDays = 10;

    $sql = "INSERT INTO customer_renewals
        (customer_id, installation_date, next_renewal_date, validity_months, sim_status, expired_to_safe_days, safe_to_deactive_days)
        SELECT c.id, ci.installation_date,
            DATE_ADD(ci.installation_date, INTERVAL cv.validity_months MONTH),
            cv.validity_months, 'Active', ?, ?
        FROM customers c
        INNER JOIN customer_vehicle_details cv ON cv.id = (
            SELECT id FROM customer_vehicle_details WHERE customer_id = c.id ORDER BY id DESC LIMIT 1
        )
        INNER JOIN customer_installations ci ON ci.id = (
            SELECT id FROM customer_installations WHERE customer_id = c.id ORDER BY id DESC LIMIT 1
        )
        WHERE cv.validity_months > 0 AND ci.installation_date IS NOT NULL AND ci.installation_date != '0000-00-00'
          AND NOT EXISTS (SELECT 1 FROM customer_renewals cr WHERE cr.customer_id = c.id)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $expiredDays, $deactiveDays);
        $stmt->execute();
        $stmt->close();
    }
}

function renewalRowQuery() {
    return "SELECT cr.*, COALESCE(cr.last_renewed_date, (SELECT MAX(rh.action_date) FROM renewal_history rh WHERE rh.renewal_id = cr.id AND rh.action_type IN ('Renew SIM', 'Reactivate SIM'))) AS last_renewed_date, c.id AS customer_record_id, c.platform_id, c.status AS customer_status, c.username, c.primary_mobile_no, c.secondary_mobile_no, c.email, c.location, c.pincode,
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
    ensureRenewalTables($conn);
    $today = date('Y-m-d');

    if (empty($settings)) {
        $settings = renewalSettings($conn);
    }

    $defaultExpiredDays = max(1, (int)($settings['expired_to_safe_days'] ?? 10));
    $defaultDeactiveDays = max(1, (int)($settings['safe_to_deactive_days'] ?? 10));

    $result = $conn->query("SELECT * FROM customer_renewals");
    if (!$result) return;

    while ($row = $result->fetch_assoc()) {
        $renewalId = (int)$row['id'];
        $currentStatus = $row['sim_status'];
        $nextRenewalDate = $row['next_renewal_date'];

        if (!$nextRenewalDate || $nextRenewalDate === '0000-00-00') {
            continue;
        }

        $expiredToSafeDays = (int)($row['expired_to_safe_days'] ?? 0);
        if ($expiredToSafeDays <= 0) {
            $expiredToSafeDays = $defaultExpiredDays;
        }

        $safeToDeactiveDays = (int)($row['safe_to_deactive_days'] ?? 0);
        if ($safeToDeactiveDays <= 0) {
            $safeToDeactiveDays = $defaultDeactiveDays;
        }

        $isManualDeactivate = false;
        $isManualSafeCustody = false;
        $manualSafeCustodyDate = null;

        $histStmt = $conn->prepare("SELECT action_type, action_date FROM renewal_history WHERE renewal_id = ? AND action_type IN ('Deactivate SIM', 'Safe Custody', 'Renew SIM', 'Reactivate SIM') ORDER BY id DESC LIMIT 1");
        if ($histStmt) {
            $histStmt->bind_param('i', $renewalId);
            $histStmt->execute();
            $histRes = $histStmt->get_result()->fetch_assoc();
            $histStmt->close();

            if ($histRes) {
                if ($histRes['action_type'] === 'Deactivate SIM') {
                    $isManualDeactivate = true;
                } elseif ($histRes['action_type'] === 'Safe Custody') {
                    $isManualSafeCustody = true;
                    $manualSafeCustodyDate = $histRes['action_date'];
                }
            }
        }

        if ($currentStatus === 'Deactive' && $isManualDeactivate) {
            continue;
        }

        $targetSafeDate = date('Y-m-d', strtotime($nextRenewalDate . ' +' . $expiredToSafeDays . ' days'));

        $calculatedStatus = 'Active';
        $newSafeCustodyDate = $row['safe_custody_date'];

        if ($today >= $nextRenewalDate) {
            if ($today >= $targetSafeDate || $isManualSafeCustody) {
                $effectiveSafeCustodyDate = $row['safe_custody_date'] ?: ($manualSafeCustodyDate ?: $targetSafeDate);
                $newSafeCustodyDate = $effectiveSafeCustodyDate;

                $targetDeactiveDate = date('Y-m-d', strtotime($effectiveSafeCustodyDate . ' +' . ($safeToDeactiveDays + 1) . ' days'));

                if ($today >= $targetDeactiveDate) {
                    $calculatedStatus = 'Deactive';
                } else {
                    $calculatedStatus = 'Safe Custody';
                }
            } else {
                $calculatedStatus = 'Expired';
                $newSafeCustodyDate = null;
            }
        } else {
            if ($isManualSafeCustody || ($currentStatus === 'Safe Custody' && $manualSafeCustodyDate)) {
                $effectiveSafeCustodyDate = $row['safe_custody_date'] ?: ($manualSafeCustodyDate ?: $today);
                $newSafeCustodyDate = $effectiveSafeCustodyDate;

                $targetDeactiveDate = date('Y-m-d', strtotime($effectiveSafeCustodyDate . ' +' . ($safeToDeactiveDays + 1) . ' days'));

                if ($today >= $targetDeactiveDate) {
                    $calculatedStatus = 'Deactive';
                } else {
                    $calculatedStatus = 'Safe Custody';
                }
            } else {
                $calculatedStatus = 'Active';
                $newSafeCustodyDate = null;
            }
        }

        if ($calculatedStatus !== $currentStatus) {
            $action = 'Auto ' . $calculatedStatus;

            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT * FROM customer_renewals WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $renewalId);
            $lock->execute();
            $current = $lock->get_result()->fetch_assoc();
            $lock->close();

            if ($current && $current['sim_status'] === $currentStatus) {
                $update = $conn->prepare('UPDATE customer_renewals SET sim_status = ?, expired_to_safe_days = ?, safe_to_deactive_days = ?, safe_custody_date = ? WHERE id = ?');
                $update->bind_param('siisi', $calculatedStatus, $expiredToSafeDays, $safeToDeactiveDays, $newSafeCustodyDate, $renewalId);
                $update->execute();
                $update->close();

                if (!renewalHistoryInsert($conn, $current, $action, null, $calculatedStatus, $current['validity_months'], $current['next_renewal_date'])) {
                    $conn->rollback();
                    continue;
                }
            }
            $conn->commit();
        } else {
            if ((int)($row['expired_to_safe_days'] ?? 0) <= 0 || (int)($row['safe_to_deactive_days'] ?? 0) <= 0) {
                $updateSettings = $conn->prepare('UPDATE customer_renewals SET expired_to_safe_days = ?, safe_to_deactive_days = ? WHERE id = ?');
                $updateSettings->bind_param('iii', $expiredToSafeDays, $safeToDeactiveDays, $renewalId);
                $updateSettings->execute();
                $updateSettings->close();
            }
        }
    }
}
