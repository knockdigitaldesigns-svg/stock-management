<?php
require 'backend/config/database.php';
$db = new Database();
$conn = $db->getConnection();
$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM stock_alert_settings");
while ($columnResult && ($column = $columnResult->fetch_assoc())) {
	$columns[$column['Field']] = true;
}
if (!isset($columns['asset_type'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device' AFTER owner_id");
}
if (!isset($columns['device_model_id'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN device_model_id INT DEFAULT NULL AFTER asset_type");
}
if (!isset($columns['sim_type_id'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN sim_type_id INT DEFAULT NULL AFTER device_model_id");
}
if (!isset($columns['min_count'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN min_count INT NOT NULL DEFAULT 0 AFTER sim_type_id");
}
if (!isset($columns['minimum_device_count'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN minimum_device_count INT NOT NULL DEFAULT 0 AFTER min_count");
}
if (!isset($columns['minimum_sim_count'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD COLUMN minimum_sim_count INT NOT NULL DEFAULT 0 AFTER minimum_device_count");
}
$conn->query("ALTER TABLE stock_alert_settings MODIFY COLUMN asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device'");
$conn->query("UPDATE stock_alert_settings SET asset_type = CASE WHEN minimum_device_count > 0 AND minimum_sim_count > 0 THEN 'both' WHEN minimum_sim_count > 0 THEN 'sim' ELSE 'device' END WHERE (asset_type IS NULL OR asset_type = '' OR (asset_type = 'device' AND device_model_id IS NULL AND sim_type_id IS NULL AND minimum_sim_count > 0))");
$conn->query("UPDATE stock_alert_settings SET minimum_device_count = CASE WHEN asset_type = 'device' AND minimum_device_count = 0 THEN min_count ELSE minimum_device_count END, minimum_sim_count = CASE WHEN asset_type = 'sim' AND minimum_sim_count = 0 THEN min_count ELSE minimum_sim_count END");
$conn->query("CREATE TABLE IF NOT EXISTS cash_collection_settlements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS cash_collection_settlement_allocations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("ALTER TABLE customer_installations ADD COLUMN IF NOT EXISTS vehicle_id INT DEFAULT NULL AFTER customer_id");
$conn->query("ALTER TABLE customer_payments ADD COLUMN IF NOT EXISTS vehicle_id INT DEFAULT NULL AFTER customer_id");
$conn->query("ALTER TABLE customer_installations DROP INDEX IF EXISTS uq_customer_installation_customer");
$conn->query("ALTER TABLE customer_payments DROP INDEX IF EXISTS uq_customer_payment_customer");
$conn->query("ALTER TABLE customer_installations ADD UNIQUE KEY IF NOT EXISTS uq_customer_installation_vehicle (vehicle_id)");
$conn->query("ALTER TABLE customer_payments ADD UNIQUE KEY IF NOT EXISTS uq_customer_payment_vehicle (vehicle_id)");
$stockAllocationIndexes = [];
$stockAllocationIndexResult = $conn->query("SHOW INDEX FROM stock_allocations");
while ($stockAllocationIndexResult && ($index = $stockAllocationIndexResult->fetch_assoc())) {
	$stockAllocationIndexes[$index['Key_name']] = true;
}
foreach ([
	'idx_stock_allocations_owner' => 'ALTER TABLE stock_allocations ADD INDEX idx_stock_allocations_owner (owner_type, owner_id)',
	'idx_stock_allocations_owner_device' => 'ALTER TABLE stock_allocations ADD INDEX idx_stock_allocations_owner_device (owner_type, owner_id, device_id)',
	'idx_stock_allocations_owner_sim' => 'ALTER TABLE stock_allocations ADD INDEX idx_stock_allocations_owner_sim (owner_type, owner_id, sim_id)',
	'idx_stock_allocations_owner_created' => 'ALTER TABLE stock_allocations ADD INDEX idx_stock_allocations_owner_created (owner_type, owner_id, created_at)'
] as $indexName => $statement) {
	if (!isset($stockAllocationIndexes[$indexName])) {
		$conn->query($statement);
	}
}
$stockTransactionIndexes = [];
$stockTransactionIndexResult = $conn->query("SHOW INDEX FROM stock_transactions");
while ($stockTransactionIndexResult && ($index = $stockTransactionIndexResult->fetch_assoc())) {
	$stockTransactionIndexes[$index['Key_name']] = true;
}
foreach ([
	'idx_stock_transactions_device_owner' => 'ALTER TABLE stock_transactions ADD INDEX idx_stock_transactions_device_owner (device_id, from_owner_type, from_owner_id, transaction_type)',
	'idx_stock_transactions_sim_owner' => 'ALTER TABLE stock_transactions ADD INDEX idx_stock_transactions_sim_owner (sim_id, from_owner_type, from_owner_id, transaction_type)'
] as $indexName => $statement) {
	if (!isset($stockTransactionIndexes[$indexName])) {
		$conn->query($statement);
	}
}
$customerMobileIndex = $conn->query("SHOW INDEX FROM customers WHERE Key_name = 'uq_customer_primary_mobile'");
if ($customerMobileIndex && $customerMobileIndex->num_rows > 0) {
	$conn->query("ALTER TABLE customers DROP INDEX uq_customer_primary_mobile");
}
$indexResult = $conn->query("SHOW INDEX FROM stock_alert_settings");
$indexes = [];
while ($indexResult && ($index = $indexResult->fetch_assoc())) {
	$indexes[$index['Key_name']] = true;
}
if (isset($indexes['unique_stock_alert_owner'])) {
	$conn->query("ALTER TABLE stock_alert_settings DROP INDEX unique_stock_alert_owner");
}
if (!isset($indexes['unique_alert_config'])) {
	$conn->query("ALTER TABLE stock_alert_settings ADD UNIQUE KEY unique_alert_config (owner_type, owner_id, asset_type, device_model_id, sim_type_id)");
}
echo "Done";
