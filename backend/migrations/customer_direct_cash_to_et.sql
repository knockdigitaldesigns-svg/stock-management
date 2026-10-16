-- Run manually after review. This migration is intentionally not applied by the application.
-- ET cash is settled directly to Eagletrazer, so it has no technician/dealer recipient ID.
ALTER TABLE customer_cash_collections
    MODIFY COLUMN recipient_type ENUM('Technician', 'Dealer', 'ET') NOT NULL,
    MODIFY COLUMN recipient_id INT DEFAULT NULL;
