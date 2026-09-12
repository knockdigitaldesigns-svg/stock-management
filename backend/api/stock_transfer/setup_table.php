<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
authenticate();

$db = new Database();
$conn = $db->getConnection();
if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);

$createSQL = "
    CREATE TABLE IF NOT EXISTS stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        allocation_id INT DEFAULT NULL,
        device_id INT DEFAULT NULL,
        sim_id INT DEFAULT NULL,
        from_owner_type ENUM('dealer','technician') NOT NULL,
        from_owner_id INT NOT NULL,
        from_owner_name VARCHAR(150) NOT NULL,
        to_owner_type ENUM('dealer','technician') NOT NULL,
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
    )
";

if ($conn->query($createSQL) === TRUE) {
    sendResponse(true, 'stock_transfers table created or already exists.');
} else {
    sendResponse(false, 'Error creating table: ' . $conn->error, [], [], 500);
}
$conn->close();
?>
