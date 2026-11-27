ALTER TABLE customer_installations
    ADD COLUMN IF NOT EXISTS lead_closure_by_user_id INT DEFAULT NULL AFTER lead_closure_id,
    ADD KEY IF NOT EXISTS idx_customer_installation_closure_user (lead_closure_by_user_id);

SET @add_customer_installation_closure_user_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'customer_installations'
          AND constraint_name = 'fk_customer_installation_closure_user'
    ) = 0,
    'ALTER TABLE customer_installations ADD CONSTRAINT fk_customer_installation_closure_user FOREIGN KEY (lead_closure_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE customer_installation_closure_user_fk_stmt FROM @add_customer_installation_closure_user_fk;
EXECUTE customer_installation_closure_user_fk_stmt;
DEALLOCATE PREPARE customer_installation_closure_user_fk_stmt;
