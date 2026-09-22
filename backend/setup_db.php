<?php
$host = "localhost";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE DATABASE IF NOT EXISTS stock_management");
$conn->select_db("stock_management");

$historyExists = $conn->query("SHOW TABLES LIKE 'history'");
$legacyHistoryExists = $conn->query("SHOW TABLES LIKE 'customer_history'");
if ($historyExists && $historyExists->num_rows === 0 && $legacyHistoryExists && $legacyHistoryExists->num_rows > 0) {
    $conn->query('RENAME TABLE customer_history TO history');
}
$conn->query("CREATE TABLE IF NOT EXISTS history (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    field_changed VARCHAR(150) DEFAULT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    changed_by_user_id INT DEFAULT NULL,
    changed_by_name VARCHAR(150) DEFAULT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_customer (customer_id),
    INDEX idx_history_action (action),
    INDEX idx_history_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$tables = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_name VARCHAR(150) DEFAULT NULL,
        mobile_no VARCHAR(20) DEFAULT NULL,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'super_admin',
        role_id INT DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        is_system_role TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        permission_name VARCHAR(150) NOT NULL,
        module VARCHAR(100) NOT NULL,
        action VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_role_permission (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS stock_alert_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_type ENUM('dealer', 'technician') NOT NULL,
        owner_id INT NOT NULL,
        asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device',
        device_model_id INT DEFAULT NULL,
        sim_type_id INT DEFAULT NULL,
        min_count INT NOT NULL DEFAULT 0,
        minimum_device_count INT NOT NULL DEFAULT 0,
        minimum_sim_count INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_alert_config (owner_type, owner_id, asset_type, device_model_id, sim_type_id)
    )",
    "CREATE TABLE IF NOT EXISTS device_alert_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_type VARCHAR(100) NOT NULL,
        enabled TINYINT(1) DEFAULT 1,
        threshold INT DEFAULT 0,
        notification_status VARCHAR(50) DEFAULT 'enabled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS device_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_type VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS sim_validities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        months INT NOT NULL UNIQUE,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_date DATE NOT NULL,
        device_model_id INT NOT NULL,
        imei_no VARCHAR(15) NOT NULL UNIQUE,
        notes TEXT NULL,
        status ENUM('available', 'allocated', 'used') DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_model_id) REFERENCES device_types(id) ON DELETE RESTRICT
    )",
    "CREATE TABLE IF NOT EXISTS sims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_date DATE NOT NULL,
        sim_no VARCHAR(13) NOT NULL UNIQUE,
        sim_type VARCHAR(20) DEFAULT NULL,
        sim_validity_id INT DEFAULT NULL,
        notes TEXT NULL,
        status ENUM('available', 'allocated', 'used') DEFAULT 'available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS dealers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealer_name VARCHAR(100) NOT NULL,
        mobile_no VARCHAR(15) NOT NULL,
        alternate_mobile_no VARCHAR(10) DEFAULT NULL,
        location VARCHAR(255) NOT NULL,
        enrolled_date DATE NOT NULL,
        installation_status ENUM('Onsite', 'Offsite', 'Not Willing') NOT NULL,
        software VARCHAR(50) DEFAULT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS technicians (
        id INT AUTO_INCREMENT PRIMARY KEY,
        technician_name VARCHAR(100) NOT NULL,
        mobile_no VARCHAR(15) NOT NULL,
        alternate_mobile_no VARCHAR(10) DEFAULT NULL,
        location VARCHAR(255) NOT NULL,
        enrolled_date DATE NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS stock_allocations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_type ENUM('dealer', 'technician') NOT NULL,
        owner_id INT NOT NULL,
        device_id INT DEFAULT NULL,
        sim_id INT DEFAULT NULL,
        allocation_type ENUM('ET', 'dealer', 'technician') DEFAULT 'ET',
        allocation_date DATE NOT NULL,
        device_amount DECIMAL(10,2) DEFAULT 0.00,
        sim_amount DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        amount_paid DECIMAL(10,2) DEFAULT 0.00,
        pending_amount DECIMAL(10,2) DEFAULT 0.00,
        software VARCHAR(50) DEFAULT NULL,
        payment_status ENUM('Paid', 'Partially Paid', 'Not Paid') DEFAULT 'Not Paid',
        payment_mode ENUM('ET Gpay', 'ET Phonepe', 'ET Paytm', 'ET Account', '8002 Gpay', '8002 Phonepe', '8002 Paytm', 'Wati Gpay', 'Wati Phonepe', 'Wati Paytm', 'PG Gateway', 'Cash', 'UPI', 'Bank Transfer', 'Card', 'Other') DEFAULT NULL,
        transaction_id VARCHAR(100) DEFAULT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_stock_allocations_owner (owner_type, owner_id),
        INDEX idx_stock_allocations_owner_device (owner_type, owner_id, device_id),
        INDEX idx_stock_allocations_owner_sim (owner_type, owner_id, sim_id),
        INDEX idx_stock_allocations_owner_created (owner_type, owner_id, created_at),
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
        FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        allocation_id INT DEFAULT NULL,
        device_id INT DEFAULT NULL,
        sim_id INT DEFAULT NULL,
        from_owner_type ENUM('dealer', 'technician') NOT NULL,
        from_owner_id INT NOT NULL,
        from_owner_name VARCHAR(150) NOT NULL,
        to_owner_type ENUM('dealer', 'technician') NOT NULL,
        to_owner_id INT NOT NULL,
        to_owner_name VARCHAR(150) NOT NULL,
        transfer_date DATE NOT NULL,
        transferred_by_user_id INT DEFAULT NULL,
        previous_status VARCHAR(50) DEFAULT 'Allocated',
        new_status VARCHAR(50) DEFAULT 'Allocated',
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
        FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS stock_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT DEFAULT NULL,
        sim_id INT DEFAULT NULL,
        from_owner_type ENUM('dealer', 'technician') DEFAULT NULL,
        from_owner_id INT DEFAULT NULL,
        to_owner_type ENUM('dealer', 'technician') DEFAULT NULL,
        to_owner_id INT DEFAULT NULL,
        transaction_type ENUM('ALLOCATE', 'USE', 'RETURN') NOT NULL,
        usage_type ENUM('ET', 'TECHNICIAN', 'DEALER') DEFAULT NULL,
        transaction_date DATE NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_transactions_device_owner (device_id, from_owner_type, from_owner_id, transaction_type),
        INDEX idx_stock_transactions_sim_owner (sim_id, from_owner_type, from_owner_id, transaction_type),
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
        FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS renewal_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expired_to_safe_days INT NOT NULL DEFAULT 10,
        safe_to_deactive_days INT NOT NULL DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS customer_renewals (
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
    )",
    "CREATE TABLE IF NOT EXISTS customer_cash_collections (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        payment_id INT NOT NULL,
        installation_id INT NOT NULL,
        recipient_type ENUM('Technician', 'Dealer') NOT NULL,
        recipient_id INT NOT NULL,
        amount_collected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        amount_remitted DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        settlement_status ENUM('Pending', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Pending',
        settlement_date DATE DEFAULT NULL,
        payment_mode VARCHAR(50) DEFAULT NULL,
        transaction_id VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_cash_collection_payment (customer_id, payment_id),
        INDEX idx_cash_collection_recipient (recipient_type, recipient_id),
        INDEX idx_cash_collection_customer (customer_id),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (payment_id) REFERENCES customer_payments(id) ON DELETE CASCADE,
        FOREIGN KEY (installation_id) REFERENCES customer_installations(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS cash_collection_settlements (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        recipient_type ENUM('Technician', 'Dealer') NOT NULL,
        recipient_id INT NOT NULL,
        settlement_amount DECIMAL(12,2) NOT NULL,
        outstanding_before DECIMAL(12,2) NOT NULL,
        outstanding_after DECIMAL(12,2) NOT NULL,
        settlement_date DATE NOT NULL,
        payment_mode VARCHAR(50) NOT NULL,
        transaction_id VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        settled_by_user_id INT DEFAULT NULL,
        settled_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cash_settlement_recipient (recipient_type, recipient_id),
        INDEX idx_cash_settlement_created_at (created_at)
    )",
    "CREATE TABLE IF NOT EXISTS cash_collection_settlement_allocations (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        settlement_id BIGINT NOT NULL,
        collection_id INT NOT NULL,
        customer_id INT NOT NULL,
        amount_allocated DECIMAL(12,2) NOT NULL,
        outstanding_before DECIMAL(12,2) NOT NULL,
        outstanding_after DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cash_settlement_collection (settlement_id, collection_id),
        INDEX idx_cash_allocation_collection (collection_id),
        FOREIGN KEY (settlement_id) REFERENCES cash_collection_settlements(id) ON DELETE CASCADE,
        FOREIGN KEY (collection_id) REFERENCES customer_cash_collections(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    )",
        "CREATE TABLE IF NOT EXISTS support_questions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_questions_active (is_active, display_order)
        )",
        "CREATE TABLE IF NOT EXISTS support_tickets (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id VARCHAR(30) DEFAULT NULL UNIQUE,
            customer_id INT NOT NULL,
            vehicle_id INT DEFAULT NULL,
            issue TEXT NOT NULL,
            priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
            assigned_to_user_id INT DEFAULT NULL,
            status ENUM('Open', 'Assigned', 'In Progress', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open',
            resolution_notes TEXT DEFAULT NULL,
            created_by_user_id INT NOT NULL,
            closed_by_user_id INT DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_ticket_customer (customer_id),
            INDEX idx_support_ticket_assigned (assigned_to_user_id),
            INDEX idx_support_ticket_status (status)
        )",
    "CREATE TABLE IF NOT EXISTS renewal_history (
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
    )"
];

$conn->query("INSERT IGNORE INTO renewal_settings (id, expired_to_safe_days, safe_to_deactive_days) VALUES (1, 10, 10)");

foreach ($tables as $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "Table migration completed\n";
    } else {
        echo "Error creating table: " . $conn->error . "\n";
    }
}

$columnChecks = [
    ['users', 'employee_name', "ALTER TABLE users ADD COLUMN employee_name VARCHAR(150) DEFAULT NULL AFTER username"],
    ['users', 'mobile_no', "ALTER TABLE users ADD COLUMN mobile_no VARCHAR(20) DEFAULT NULL AFTER employee_name"],
    ['users', 'role_id', "ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL AFTER role"],
    ['users', 'status', "ALTER TABLE users ADD COLUMN status ENUM('active','inactive') DEFAULT 'active' AFTER role_id"],
    ['users', 'updated_at', "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"],
    ['stock_alert_settings', 'owner_type', "ALTER TABLE stock_alert_settings ADD COLUMN owner_type ENUM('dealer', 'technician') NOT NULL AFTER id"],
    ['stock_alert_settings', 'owner_id', "ALTER TABLE stock_alert_settings ADD COLUMN owner_id INT NOT NULL AFTER owner_type"],
    ['stock_alert_settings', 'asset_type', "ALTER TABLE stock_alert_settings ADD COLUMN asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device' AFTER owner_id"],
    ['stock_alert_settings', 'device_model_id', "ALTER TABLE stock_alert_settings ADD COLUMN device_model_id INT DEFAULT NULL AFTER asset_type"],
    ['stock_alert_settings', 'sim_type_id', "ALTER TABLE stock_alert_settings ADD COLUMN sim_type_id INT DEFAULT NULL AFTER device_model_id"],
    ['stock_alert_settings', 'min_count', "ALTER TABLE stock_alert_settings ADD COLUMN min_count INT NOT NULL DEFAULT 0 AFTER sim_type_id"],
    ['stock_alert_settings', 'minimum_device_count', "ALTER TABLE stock_alert_settings ADD COLUMN minimum_device_count INT NOT NULL DEFAULT 0 AFTER min_count"],
    ['stock_alert_settings', 'minimum_sim_count', "ALTER TABLE stock_alert_settings ADD COLUMN minimum_sim_count INT NOT NULL DEFAULT 0 AFTER minimum_device_count"],
    ['sims', 'sim_type', "ALTER TABLE sims ADD COLUMN sim_type VARCHAR(20) DEFAULT NULL AFTER sim_no"],
    ['sims', 'sim_validity_id', "ALTER TABLE sims ADD COLUMN sim_validity_id INT DEFAULT NULL AFTER sim_type"],
    ['sim_validities', 'status', "ALTER TABLE sim_validities ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active' AFTER months"],
    ['devices', 'notes', "ALTER TABLE devices ADD COLUMN notes TEXT NULL AFTER imei_no"],
    ['sims', 'notes', "ALTER TABLE sims ADD COLUMN notes TEXT NULL AFTER sim_validity_id"],
    ['dealers', 'notes', "ALTER TABLE dealers ADD COLUMN notes TEXT NULL AFTER installation_status"],
    ['dealers', 'software', "ALTER TABLE dealers ADD COLUMN software VARCHAR(50) DEFAULT NULL AFTER installation_status"],
    ['dealers', 'alternate_mobile_no', "ALTER TABLE dealers ADD COLUMN alternate_mobile_no VARCHAR(10) DEFAULT NULL AFTER mobile_no"],
    ['stock_allocations', 'amount_paid', "ALTER TABLE stock_allocations ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0.00 AFTER total_amount"],
    ['stock_allocations', 'transaction_id', "ALTER TABLE stock_allocations ADD COLUMN transaction_id VARCHAR(100) DEFAULT NULL AFTER payment_mode"],
    ['stock_allocations', 'software', "ALTER TABLE stock_allocations ADD COLUMN software VARCHAR(50) DEFAULT NULL AFTER pending_amount"],
    ['stock_allocations', 'sim_given_date', "ALTER TABLE stock_allocations ADD COLUMN sim_given_date DATE DEFAULT NULL AFTER allocation_date"],
    ['stock_allocations', 'sim_activation_date', "ALTER TABLE stock_allocations ADD COLUMN sim_activation_date DATE DEFAULT NULL AFTER sim_given_date"],
    ['stock_allocations', 'sim_validity_id', "ALTER TABLE stock_allocations ADD COLUMN sim_validity_id INT DEFAULT NULL AFTER sim_activation_date"],
    ['stock_allocations', 'sim_expiry_date', "ALTER TABLE stock_allocations ADD COLUMN sim_expiry_date DATE DEFAULT NULL AFTER sim_validity_id"],
    ['stock_allocations', 'sim_deactivation_date', "ALTER TABLE stock_allocations ADD COLUMN sim_deactivation_date DATE DEFAULT NULL AFTER sim_expiry_date"],
    ['stock_allocations', 'sim_status', "ALTER TABLE stock_allocations ADD COLUMN sim_status ENUM('Available', 'Active', 'Deactive', 'Expired', 'Safe Custody') DEFAULT 'Available' AFTER sim_deactivation_date"],
    ['technicians', 'notes', "ALTER TABLE technicians ADD COLUMN notes TEXT NULL AFTER enrolled_date"],
    ['technicians', 'alternate_mobile_no', "ALTER TABLE technicians ADD COLUMN alternate_mobile_no VARCHAR(10) DEFAULT NULL AFTER mobile_no"],
    ['stock_allocations', 'notes', "ALTER TABLE stock_allocations ADD COLUMN notes TEXT NULL AFTER payment_mode"],
    ['stock_transactions', 'notes', "ALTER TABLE stock_transactions ADD COLUMN notes TEXT NULL AFTER transaction_date"],
    ['stock_alert_settings', 'notes', "ALTER TABLE stock_alert_settings ADD COLUMN notes TEXT NULL AFTER minimum_sim_count"],
    ['customer_installations', 'vehicle_id', "ALTER TABLE customer_installations ADD COLUMN vehicle_id INT DEFAULT NULL AFTER customer_id"],
    ['customer_payments', 'vehicle_id', "ALTER TABLE customer_payments ADD COLUMN vehicle_id INT DEFAULT NULL AFTER customer_id"],
    ['customer_renewals', 'safe_custody_date', "ALTER TABLE customer_renewals ADD COLUMN safe_custody_date DATE DEFAULT NULL AFTER last_renewed_date"],
];

foreach ($columnChecks as [$table, $column, $alterSql]) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows === 0) {
        if ($conn->query($alterSql) === TRUE) {
            echo "Column '$column' added to $table\n";
        } else {
            echo "Error adding column: " . $conn->error . "\n";
        }
    }
}

$conn->query("ALTER TABLE stock_allocations MODIFY COLUMN sim_status ENUM('Available', 'Active', 'Deactive', 'Expired', 'Safe Custody') DEFAULT 'Available'");
$conn->query("ALTER TABLE stock_allocations MODIFY COLUMN payment_mode ENUM('ET Gpay', 'ET Phonepe', 'ET Paytm', 'ET Account', '8002 Gpay', '8002 Phonepe', '8002 Paytm', 'Wati Gpay', 'Wati Phonepe', 'Wati Paytm', 'PG Gateway', 'Cash', 'UPI', 'Bank Transfer', 'Card', 'Other') DEFAULT NULL");
$conn->query("UPDATE stock_allocations sa JOIN sims s ON s.id = sa.sim_id SET sa.sim_given_date = COALESCE(sa.sim_given_date, sa.allocation_date), sa.sim_validity_id = COALESCE(sa.sim_validity_id, s.sim_validity_id), sa.sim_status = COALESCE(sa.sim_status, 'Available') WHERE sa.sim_id IS NOT NULL");

$installationCustomerSupportIndex = $conn->query("SHOW INDEX FROM customer_installations WHERE Key_name = 'idx_customer_installation_customer'");
if (!$installationCustomerSupportIndex || $installationCustomerSupportIndex->num_rows === 0) {
    $conn->query("ALTER TABLE customer_installations ADD INDEX idx_customer_installation_customer (customer_id)");
}
foreach (['uq_customer_installation_customer', 'uq_customer_installation'] as $indexName) {
    $installationCustomerIndex = $conn->query("SHOW INDEX FROM customer_installations WHERE Key_name = '{$indexName}'");
    if ($installationCustomerIndex && $installationCustomerIndex->num_rows > 0) {
        $conn->query("ALTER TABLE customer_installations DROP INDEX {$indexName}");
    }
}
$installationVehicleIndex = $conn->query("SHOW INDEX FROM customer_installations WHERE Key_name = 'uq_customer_installation_vehicle'");
if (!$installationVehicleIndex || $installationVehicleIndex->num_rows === 0) {
    $conn->query("ALTER TABLE customer_installations ADD UNIQUE KEY uq_customer_installation_vehicle (vehicle_id)");
}
$paymentCustomerSupportIndex = $conn->query("SHOW INDEX FROM customer_payments WHERE Key_name = 'idx_customer_payment_customer'");
if (!$paymentCustomerSupportIndex || $paymentCustomerSupportIndex->num_rows === 0) {
    $conn->query("ALTER TABLE customer_payments ADD INDEX idx_customer_payment_customer (customer_id)");
}
foreach (['uq_customer_payment_customer', 'uq_customer_payment'] as $indexName) {
    $paymentCustomerIndex = $conn->query("SHOW INDEX FROM customer_payments WHERE Key_name = '{$indexName}'");
    if ($paymentCustomerIndex && $paymentCustomerIndex->num_rows > 0) {
        $conn->query("ALTER TABLE customer_payments DROP INDEX {$indexName}");
    }
}
$paymentVehicleIndex = $conn->query("SHOW INDEX FROM customer_payments WHERE Key_name = 'uq_customer_payment_vehicle'");
if (!$paymentVehicleIndex || $paymentVehicleIndex->num_rows === 0) {
    $conn->query("ALTER TABLE customer_payments ADD UNIQUE KEY uq_customer_payment_vehicle (vehicle_id)");
}

$customerMobileIndex = $conn->query("SHOW INDEX FROM customers WHERE Key_name = 'uq_customer_primary_mobile'");
if ($customerMobileIndex && $customerMobileIndex->num_rows > 0) {
    $conn->query("ALTER TABLE customers DROP INDEX uq_customer_primary_mobile");
}

$conn->query("ALTER TABLE stock_alert_settings MODIFY COLUMN asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device'");
$conn->query("UPDATE stock_alert_settings SET minimum_device_count = CASE WHEN asset_type = 'device' AND minimum_device_count = 0 THEN min_count ELSE minimum_device_count END, minimum_sim_count = CASE WHEN asset_type = 'sim' AND minimum_sim_count = 0 THEN min_count ELSE minimum_sim_count END");
$stockAlertIndex = $conn->query("SHOW INDEX FROM stock_alert_settings WHERE Key_name = 'unique_stock_alert_owner'");
if ($stockAlertIndex && $stockAlertIndex->num_rows > 0) {
    $conn->query("ALTER TABLE stock_alert_settings DROP INDEX unique_stock_alert_owner");
}
$stockAlertConfigIndex = $conn->query("SHOW INDEX FROM stock_alert_settings WHERE Key_name = 'unique_alert_config'");
if (!$stockAlertConfigIndex || $stockAlertConfigIndex->num_rows === 0) {
    $conn->query("CREATE UNIQUE INDEX unique_alert_config ON stock_alert_settings(owner_type, owner_id, asset_type, device_model_id, sim_type_id)");
}

$uniqueMobileMigrations = [
    ['dealers', 'dealer_mobile_unique'],
    ['technicians', 'technician_mobile_unique']
];

$userUsernameIndex = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'username'");
if (!$userUsernameIndex || $userUsernameIndex->num_rows === 0) {
    $duplicates = $conn->query("SELECT LOWER(username) AS normalized_username, COUNT(*) AS duplicate_count FROM users GROUP BY LOWER(username) HAVING duplicate_count > 1");
    if ($duplicates && $duplicates->num_rows > 0) {
        while ($row = $duplicates->fetch_assoc()) echo "Skipped users.username unique index: existing duplicate username: " . ($row['normalized_username'] ?? '') . "\n";
    } else {
        $conn->query("ALTER TABLE users ADD UNIQUE KEY username_unique (username)");
        echo "Added unique index username_unique\n";
    }
}

$uniqueNameMigrations = [
    ['dealers', 'dealer_name', 'dealer_name_unique'],
    ['technicians', 'technician_name', 'technician_name_unique']
];

$duplicateAudits = [
    ['devices', 'imei_no', 'IMEI'],
    ['sims', 'sim_no', 'SIM number']
];
foreach ($duplicateAudits as [$table, $column, $label]) {
    $rows = $conn->query("SELECT `$column`, COUNT(*) AS duplicate_count FROM `$table` GROUP BY LOWER(TRIM(`$column`)) HAVING duplicate_count > 1");
    if ($rows && $rows->num_rows > 0) {
        while ($row = $rows->fetch_assoc()) {
            echo "Existing duplicate $label: " . ($row[$column] ?? '') . " (" . $row['duplicate_count'] . " records)\n";
        }
    }
}

foreach ($uniqueMobileMigrations as [$table, $indexName]) {
    $indexExists = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($indexExists && $indexExists->num_rows > 0) continue;

    $rows = $conn->query("SELECT id, mobile_no FROM `$table`");
    $seen = [];
    $duplicates = [];
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $normalized = preg_replace('/\D+/', '', trim((string) $row['mobile_no']));
            if ($normalized !== '' && isset($seen[$normalized])) $duplicates[] = $normalized;
            if ($normalized !== '') $seen[$normalized] = true;
        }
    }

    if ($duplicates) {
        echo "Skipped $indexName: existing normalized duplicate mobile numbers: " . implode(', ', array_unique($duplicates)) . "\n";
    } else {
        $conn->query("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (mobile_no)");
        echo "Added unique index $indexName\n";
    }
}

foreach ($uniqueNameMigrations as [$table, $column, $indexName]) {
    $indexExists = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($indexExists && $indexExists->num_rows > 0) continue;
    $duplicates = $conn->query("SELECT LOWER(TRIM(`$column`)) AS normalized_name, COUNT(*) AS duplicate_count FROM `$table` GROUP BY LOWER(TRIM(`$column`)) HAVING duplicate_count > 1");
    if ($duplicates && $duplicates->num_rows > 0) {
        while ($row = $duplicates->fetch_assoc()) echo "Skipped $indexName: existing duplicate name: " . ($row['normalized_name'] ?? '') . "\n";
    } else {
        $conn->query("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (`$column`)");
        echo "Added unique index $indexName\n";
    }
}

$simValidityForeignKey = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sims' AND COLUMN_NAME = 'sim_validity_id' AND REFERENCED_TABLE_NAME = 'sim_validities' LIMIT 1");
if (!$simValidityForeignKey || $simValidityForeignKey->num_rows === 0) {
    $conn->query("ALTER TABLE sims ADD CONSTRAINT sims_sim_validity_fk FOREIGN KEY (sim_validity_id) REFERENCES sim_validities(id) ON DELETE RESTRICT");
}
$defaultValidity = $conn->query("SELECT id FROM sim_validities WHERE months = 12 LIMIT 1");
if ($defaultValidity && ($defaultRow = $defaultValidity->fetch_assoc())) {
    $defaultValidityId = (int) $defaultRow['id'];
    $conn->query("UPDATE sims SET sim_type = 'Voice', sim_validity_id = $defaultValidityId WHERE sim_type IS NULL OR sim_validity_id IS NULL");
}
$conn->query("INSERT IGNORE INTO sim_validities (months, status) VALUES (5, 'active'), (12, 'active'), (24, 'active'), (36, 'active')");
$defaultValidity = $conn->query("SELECT id FROM sim_validities WHERE months = 12 LIMIT 1");
if ($defaultValidity && ($defaultRow = $defaultValidity->fetch_assoc())) {
    $conn->query("UPDATE sims SET sim_type = 'Voice', sim_validity_id = " . (int) $defaultRow['id'] . " WHERE sim_type IS NULL OR sim_validity_id IS NULL");
}

$legacyTable = $conn->query("SHOW TABLES LIKE 'device_models'");
if ($legacyTable && $legacyTable->num_rows > 0) {
    $conn->query("INSERT IGNORE INTO device_types (device_type, created_at, updated_at) SELECT model_name, created_at, updated_at FROM device_models");
    $foreignKeyResult = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'device_model_id' AND REFERENCED_TABLE_NAME = 'device_models' LIMIT 1");
    if ($foreignKeyResult && ($foreignKey = $foreignKeyResult->fetch_assoc())) {
        $constraintName = $conn->real_escape_string($foreignKey['CONSTRAINT_NAME']);
        $conn->query("ALTER TABLE devices DROP FOREIGN KEY `$constraintName`");
    }
    $conn->query("UPDATE devices d INNER JOIN device_models old_models ON old_models.id = d.device_model_id INNER JOIN device_types types ON LOWER(types.device_type) = LOWER(old_models.model_name) SET d.device_model_id = types.id");
    $conn->query("ALTER TABLE devices ADD CONSTRAINT devices_device_type_fk FOREIGN KEY (device_model_id) REFERENCES device_types(id) ON DELETE RESTRICT");
    $conn->query("DROP TABLE device_models");
}

$permissionDefinitions = [
    ['dashboard.view', 'Dashboard View', 'dashboard', 'VIEW'],
    ['devices.view', 'Device Maintenance View', 'devices', 'VIEW'],
    ['devices.add', 'Device Maintenance Add', 'devices', 'ADD'],
    ['devices.edit', 'Device Maintenance Edit', 'devices', 'EDIT'],
    ['devices.delete', 'Device Maintenance Delete', 'devices', 'DELETE'],
    ['devices.export', 'Device Maintenance Export', 'devices', 'EXPORT'],
    ['sims.view', 'SIM Maintenance View', 'sims', 'VIEW'],
    ['sims.add', 'SIM Maintenance Add', 'sims', 'ADD'],
    ['sims.edit', 'SIM Maintenance Edit', 'sims', 'EDIT'],
    ['sims.delete', 'SIM Maintenance Delete', 'sims', 'DELETE'],
    ['sims.export', 'SIM Maintenance Export', 'sims', 'EXPORT'],
    ['inward_reports.view', 'Inward Reports View', 'inward_reports', 'VIEW'],
    ['inward_reports.export', 'Inward Reports Export', 'inward_reports', 'EXPORT'],
    ['dealers.view', 'Dealer View', 'dealers', 'VIEW'],
    ['dealers.add', 'Dealer Add', 'dealers', 'ADD'],
    ['dealers.edit', 'Dealer Edit', 'dealers', 'EDIT'],
    ['dealers.delete', 'Dealer Delete', 'dealers', 'DELETE'],
    ['dealers.import', 'Dealer Import', 'dealers', 'IMPORT'],
    ['dealers.export', 'Dealer Export', 'dealers', 'EXPORT'],
    ['technicians.view', 'Technician View', 'technicians', 'VIEW'],
    ['technicians.add', 'Technician Add', 'technicians', 'ADD'],
    ['technicians.edit', 'Technician Edit', 'technicians', 'EDIT'],
    ['technicians.delete', 'Technician Delete', 'technicians', 'DELETE'],
    ['technicians.import', 'Technician Import', 'technicians', 'IMPORT'],
    ['technicians.export', 'Technician Export', 'technicians', 'EXPORT'],
    ['outward_reports.view', 'Outward Reports View', 'outward_reports', 'VIEW'],
    ['outward_reports.export', 'Outward Reports Export', 'outward_reports', 'EXPORT'],
    ['stock.view', 'Stock Management View', 'stock', 'VIEW'],
    ['stock.update', 'Stock Management Update', 'stock', 'UPDATE'],
    ['stock.export', 'Stock Management Export', 'stock', 'EXPORT'],
    ['stock_transfer.view', 'Stock Transfer View', 'stock_transfer', 'VIEW'],
    ['stock_transfer.add', 'Stock Transfer Add', 'stock_transfer', 'ADD'],
    ['customers.view', 'Customer Details View', 'customers', 'VIEW'],
    ['customers.add', 'Customer Details Add', 'customers', 'ADD'],
    ['customers.edit', 'Customer Details Edit', 'customers', 'EDIT'],
    ['customers.delete', 'Customer Details Delete', 'customers', 'DELETE'],
    ['customers.export', 'Customer Details Export', 'customers', 'EXPORT'],
    ['customers.update', 'Customer Details Update', 'customers', 'UPDATE'],
    ['customer_reports.view', 'Customer Reports View', 'customer_reports', 'VIEW'],
    ['customer_renewals.view', 'Customer Renewals View', 'customer_renewals', 'VIEW'],
    ['customer_renewals.edit', 'Customer Renewals Edit', 'customer_renewals', 'EDIT'],
    ['customer_renewals.renew', 'Customer Renewals Renew', 'customer_renewals', 'RENEW'],
    ['customer_renewals.history', 'Customer Renewals History', 'customer_renewals', 'HISTORY'],
    ['sim_lifecycle.view', 'SIM Lifecycle View', 'sim_lifecycle', 'VIEW'],
    ['sim_lifecycle.edit', 'SIM Lifecycle Edit', 'sim_lifecycle', 'EDIT'],
    ['history.view', 'History View', 'history', 'VIEW'],
    ['roles.view', 'Roles View', 'roles', 'VIEW'],
    ['roles.add', 'Roles Add', 'roles', 'ADD'],
    ['roles.edit', 'Roles Edit', 'roles', 'EDIT'],
    ['roles.delete', 'Roles Delete', 'roles', 'DELETE'],
    ['permissions.view', 'Permissions View', 'permissions', 'VIEW'],
    ['permissions.assign', 'Permissions Assign', 'permissions', 'ASSIGN'],
    ['users.view', 'Users View', 'users', 'VIEW'],
    ['users.add', 'Users Add', 'users', 'ADD'],
    ['users.edit', 'Users Edit', 'users', 'EDIT'],
    ['users.delete', 'Users Delete', 'users', 'DELETE'],
    ['users.status', 'Users Status', 'users', 'STATUS'],
    ['device_alert.view', 'Device Alert View', 'device_alert', 'VIEW'],
    ['device_alert.add', 'Device Alert Add', 'device_alert', 'ADD'],
    ['device_alert.edit', 'Device Alert Edit', 'device_alert', 'EDIT'],
    ['device_alert.delete', 'Device Alert Delete', 'device_alert', 'DELETE'],
    ['device_types.view', 'Device Types View', 'device_types', 'VIEW'],
    ['device_types.add', 'Device Types Add', 'device_types', 'ADD'],
    ['device_types.edit', 'Device Types Edit', 'device_types', 'EDIT'],
    ['device_types.delete', 'Device Types Delete', 'device_types', 'DELETE'],
    ['sim_validity.view', 'SIM Validity View', 'sim_validity', 'VIEW'],
    ['sim_validity.add', 'SIM Validity Add', 'sim_validity', 'ADD'],
    ['sim_validity.edit', 'SIM Validity Edit', 'sim_validity', 'EDIT'],
    ['sim_validity.delete', 'SIM Validity Delete', 'sim_validity', 'DELETE'],
    ['password.change', 'Change Password', 'password', 'CHANGE']
    ,['platforms.view', 'Platform View', 'platforms', 'VIEW']
    ,['platforms.add', 'Platform Add', 'platforms', 'ADD']
    ,['platforms.edit', 'Platform Edit', 'platforms', 'EDIT']
    ,['platforms.delete', 'Platform Delete', 'platforms', 'DELETE']
    ,['vehicle_types.view', 'Vehicle Types View', 'vehicle_types', 'VIEW']
    ,['vehicle_types.add', 'Vehicle Types Add', 'vehicle_types', 'ADD']
    ,['vehicle_types.edit', 'Vehicle Types Edit', 'vehicle_types', 'EDIT']
    ,['vehicle_types.delete', 'Vehicle Types Delete', 'vehicle_types', 'DELETE']
    ,['lead_closures.view', 'Lead Closure View', 'lead_closures', 'VIEW']
    ,['lead_closures.add', 'Lead Closure Add', 'lead_closures', 'ADD']
    ,['lead_closures.edit', 'Lead Closure Edit', 'lead_closures', 'EDIT']
    ,['lead_closures.delete', 'Lead Closure Delete', 'lead_closures', 'DELETE']
    ,['sale_amounts.view', 'Sale Amount View', 'sale_amounts', 'VIEW']
    ,['sale_amounts.add', 'Sale Amount Add', 'sale_amounts', 'ADD']
    ,['sale_amounts.edit', 'Sale Amount Edit', 'sale_amounts', 'EDIT']
    ,['sale_amounts.delete', 'Sale Amount Delete', 'sale_amounts', 'DELETE']
    ,['support.view', 'Support View', 'support', 'VIEW']
    ,['support.add', 'Support Add', 'support', 'ADD']
    ,['support.edit', 'Support Edit', 'support', 'EDIT']
    ,['support.delete', 'Support Delete', 'support', 'DELETE']
    ,['support.assign', 'Support Assign', 'support', 'ASSIGN']
    ,['support.close', 'Support Close', 'support', 'CLOSE']
    ,['support.qa.view', 'Support Questions View', 'support', 'QA_VIEW']
    ,['support.qa.manage', 'Support Questions Manage', 'support', 'QA_MANAGE']
];

foreach ($permissionDefinitions as $permission) {
    [$key, $name, $module, $action] = $permission;
    $safeKey = $conn->real_escape_string($key);
    $safeName = $conn->real_escape_string($name);
    $safeModule = $conn->real_escape_string($module);
    $safeAction = $conn->real_escape_string($action);
    $conn->query("INSERT INTO permissions (permission_key, permission_name, module, action) VALUES ('$safeKey', '$safeName', '$safeModule', '$safeAction') ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), module = VALUES(module), action = VALUES(action)");
}

$roleCheck = $conn->query("SELECT id FROM roles WHERE role_name = 'Super Admin' LIMIT 1");
if ($roleCheck && $roleCheck->num_rows === 0) {
    $conn->query("INSERT INTO roles (role_name, description, status, is_system_role) VALUES ('Super Admin', 'System-level administrator with unrestricted access.', 'active', 1)");
}

$superRoleId = $conn->query("SELECT id FROM roles WHERE role_name = 'Super Admin' LIMIT 1")->fetch_assoc()['id'];
if ($superRoleId) {
    $permResult = $conn->query("SELECT id FROM permissions");
    while ($row = $permResult->fetch_assoc()) {
        $permId = (int)$row['id'];
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($superRoleId, $permId)");
    }
}

$adminUserId = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? null;
if ($adminUserId) {
    $conn->query("UPDATE users SET employee_name = 'Admin User', mobile_no = '9876543210', role = 'super_admin', role_id = $superRoleId, status = 'active' WHERE id = $adminUserId");
}

$adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
$conn->query("INSERT INTO users (username, password, employee_name, mobile_no, role, role_id, status) VALUES ('admin', '$adminPassword', 'Admin User', '9876543210', 'super_admin', $superRoleId, 'active') ON DUPLICATE KEY UPDATE password = VALUES(password), employee_name = VALUES(employee_name), mobile_no = VALUES(mobile_no), role = VALUES(role), role_id = VALUES(role_id), status = VALUES(status)");

$deviceTypes = ['Basic', 'Voice', 'AC', 'Dashcam', 'S20', 'S15', 'G60', 'OBD', 'AIS140', '12V Relay', '24V Relay'];
foreach ($deviceTypes as $model) {
    $safeModel = $conn->real_escape_string($model);
    $conn->query("INSERT INTO device_types (device_type) VALUES ('$safeModel') ON DUPLICATE KEY UPDATE device_type = VALUES(device_type)");
}

$conn->close();
echo "RBAC bootstrap completed.\n";
?>
