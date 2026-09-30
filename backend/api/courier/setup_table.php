<?php
require_once __DIR__ . '/../../config/database.php';

function setupCourierTables() {
    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        return false;
    }

    // Update devices table status ENUM to include 'reserved'
    $conn->query("ALTER TABLE devices MODIFY COLUMN status ENUM('available', 'allocated', 'used', 'reserved') DEFAULT 'available'");

    // Update sims table status ENUM to include 'reserved'
    $conn->query("ALTER TABLE sims MODIFY COLUMN status ENUM('available', 'allocated', 'used', 'reserved') DEFAULT 'available'");

    $conn->query("ALTER TABLE stock_allocations MODIFY COLUMN owner_type ENUM('dealer', 'technician', 'customer') NOT NULL");
    $conn->query("ALTER TABLE stock_allocations MODIFY COLUMN allocation_type ENUM('ET', 'dealer', 'technician', 'customer') DEFAULT 'ET'");

    // Create courier_requests table
    $createTableSql = "CREATE TABLE IF NOT EXISTS courier_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_code VARCHAR(50) DEFAULT NULL,
        courier_to_person ENUM('Dealer', 'Technician', 'Customer') NOT NULL DEFAULT 'Dealer',
        dealer_id INT DEFAULT NULL,
        technician_id INT DEFAULT NULL,
        customer_id INT DEFAULT NULL,
        asset_type ENUM('device', 'sim', 'both') NOT NULL,
        device_model_id INT DEFAULT NULL,
        device_id INT DEFAULT NULL,
        sim_type VARCHAR(100) DEFAULT NULL,
        sim_id INT DEFAULT NULL,
        software VARCHAR(100) DEFAULT NULL,
        request_date DATE NOT NULL,
        notes TEXT DEFAULT NULL,
        courier_date DATE NOT NULL,
        tracking_id VARCHAR(100) DEFAULT NULL,
        courier_status ENUM('Pending', 'Reached', 'Not Reached') NOT NULL DEFAULT 'Pending',
        courier_reason TEXT DEFAULT NULL,
        approval_status ENUM('Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending Approval',
        requested_by_user_id INT DEFAULT NULL,
        requested_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_courier_dealer (dealer_id),
        INDEX idx_courier_technician (technician_id),
        INDEX idx_courier_approval (approval_status),
        INDEX idx_courier_device (device_id),
        INDEX idx_courier_sim (sim_id),
        FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL,
        FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
        FOREIGN KEY (device_model_id) REFERENCES device_types(id) ON DELETE SET NULL,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
        FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query($createTableSql);

    // Ensure courier_status includes 'Pending'
    $conn->query("ALTER TABLE courier_requests MODIFY COLUMN courier_status ENUM('Pending', 'Reached', 'Not Reached') NOT NULL DEFAULT 'Pending'");

    $chkFk = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'courier_requests' AND CONSTRAINT_NAME = 'fk_courier_customer' AND TABLE_SCHEMA = DATABASE()");
    if ($chkFk && $chkFk->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD CONSTRAINT fk_courier_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL");
    }

    // Add courier_send_via column if missing
    $chkVia = $conn->query("SHOW COLUMNS FROM courier_requests LIKE 'courier_send_via'");
    if ($chkVia && $chkVia->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD COLUMN courier_send_via VARCHAR(100) DEFAULT NULL AFTER tracking_id");
    }

    // Add device_count column if missing
    $chkDevCnt = $conn->query("SHOW COLUMNS FROM courier_requests LIKE 'device_count'");
    if ($chkDevCnt && $chkDevCnt->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD COLUMN device_count INT DEFAULT 1 AFTER asset_type");
    }

    // Add sim_count column if missing
    $chkSimCnt = $conn->query("SHOW COLUMNS FROM courier_requests LIKE 'sim_count'");
    if ($chkSimCnt && $chkSimCnt->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD COLUMN sim_count INT DEFAULT 1 AFTER device_id");
    }

    // Add is_new_customer column if missing
    $chkNewCust = $conn->query("SHOW COLUMNS FROM courier_requests LIKE 'is_new_customer'");
    if ($chkNewCust && $chkNewCust->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD COLUMN is_new_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER customer_id");
    }

    // Add new_customer_data column if missing
    $chkNewData = $conn->query("SHOW COLUMNS FROM courier_requests LIKE 'new_customer_data'");
    if ($chkNewData && $chkNewData->num_rows === 0) {
        $conn->query("ALTER TABLE courier_requests ADD COLUMN new_customer_data LONGTEXT DEFAULT NULL AFTER is_new_customer");
    }

    $conn->close();
    return true;
}

// Auto-run when file is included or requested directly
setupCourierTables();
?>
