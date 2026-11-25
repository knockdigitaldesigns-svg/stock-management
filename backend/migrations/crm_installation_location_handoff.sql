-- Run manually against stock_management after crm_installation_assignment_workflow.sql.
-- The application does not execute this migration automatically.
-- Written for the project's MariaDB 10.4 schema and can be rerun after partial execution.
-- Do not backfill service areas from personnel base locations. Enter verified
-- service coverage on technician/dealer records using their existing edit forms.

USE stock_management;

ALTER TABLE technicians
    ADD COLUMN IF NOT EXISTS installation_service_areas TEXT DEFAULT NULL AFTER location;

ALTER TABLE dealers
    ADD COLUMN IF NOT EXISTS installation_service_areas TEXT DEFAULT NULL AFTER location;

ALTER TABLE crm_lead_closure_forms
    MODIFY COLUMN submission_status ENUM(
        'Draft',
        'Submitted',
        'Pending Approval',
        'Approved',
        'Rejected',
        'Installation In Progress',
        'Installation Completed',
        'Customer Handoff Completed'
    ) NOT NULL DEFAULT 'Draft',
    ADD COLUMN IF NOT EXISTS customer_vehicle_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS customer_installation_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS device_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_datetime DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_remarks TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS completion_evidence TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS installation_completed_by_user_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_completed_at DATETIME DEFAULT NULL;

ALTER TABLE customer_installations
    ADD COLUMN IF NOT EXISTS installation_datetime DATETIME DEFAULT NULL AFTER installation_date,
    ADD COLUMN IF NOT EXISTS installation_remarks TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS completion_evidence TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS crm_closure_id INT UNSIGNED DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_customer_installation_crm_closure (crm_closure_id);

DROP PROCEDURE IF EXISTS crm_handoff_ensure_foreign_key;
DELIMITER //
CREATE PROCEDURE crm_handoff_ensure_foreign_key(
    IN p_child_table VARCHAR(64),
    IN p_child_column VARCHAR(64),
    IN p_parent_table VARCHAR(64),
    IN p_parent_column VARCHAR(64),
    IN p_constraint_base VARCHAR(48)
)
BEGIN
    DECLARE v_exact_fk_count INT DEFAULT 0;
    DECLARE v_child_fk_count INT DEFAULT 0;
    DECLARE v_child_engine VARCHAR(64);
    DECLARE v_parent_engine VARCHAR(64);
    DECLARE v_child_type VARCHAR(255);
    DECLARE v_parent_type VARCHAR(255);
    DECLARE v_child_nullable VARCHAR(3);
    DECLARE v_child_index_count INT DEFAULT 0;
    DECLARE v_parent_unique_index_count INT DEFAULT 0;
    DECLARE v_name_in_use INT DEFAULT 0;
    DECLARE v_suffix INT DEFAULT 0;
    DECLARE v_constraint_name VARCHAR(64);
    DECLARE v_index_name VARCHAR(64);

    IF DATABASE() <> 'stock_management' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Select stock_management before running this migration.';
    END IF;

    SELECT COUNT(*) INTO v_exact_fk_count
    FROM information_schema.KEY_COLUMN_USAGE k
    INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
       AND r.TABLE_NAME = k.TABLE_NAME
       AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
    WHERE k.CONSTRAINT_SCHEMA = DATABASE()
      AND k.TABLE_NAME = p_child_table
      AND k.COLUMN_NAME = p_child_column
      AND k.REFERENCED_TABLE_NAME = p_parent_table
      AND k.REFERENCED_COLUMN_NAME = p_parent_column
      AND r.DELETE_RULE = 'SET NULL'
      AND (
          SELECT COUNT(*)
          FROM information_schema.KEY_COLUMN_USAGE k2
          WHERE k2.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND k2.TABLE_NAME = k.TABLE_NAME
            AND k2.CONSTRAINT_NAME = k.CONSTRAINT_NAME
      ) = 1;

    IF v_exact_fk_count = 0 THEN
        SELECT COUNT(*) INTO v_child_fk_count
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_child_table
          AND COLUMN_NAME = p_child_column
          AND REFERENCED_TABLE_NAME IS NOT NULL;

        IF v_child_fk_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'A foreign key already uses the handoff column but does not match the required reference and delete rule; inspect it manually.';
        END IF;

        SELECT MAX(ENGINE) INTO v_child_engine
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_child_table;
        SELECT MAX(ENGINE) INTO v_parent_engine
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_parent_table;
        SELECT MAX(COLUMN_TYPE), MAX(IS_NULLABLE)
          INTO v_child_type, v_child_nullable
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_child_table
          AND COLUMN_NAME = p_child_column;
        SELECT MAX(COLUMN_TYPE) INTO v_parent_type
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_parent_table
          AND COLUMN_NAME = p_parent_column;

        IF v_child_engine <> 'InnoDB' OR v_parent_engine <> 'InnoDB'
           OR v_child_type IS NULL OR v_parent_type IS NULL
           OR v_child_type <> v_parent_type OR v_child_nullable <> 'YES' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Handoff foreign key tables/columns are missing or incompatible; inspect engines, exact column types, and nullable child columns.';
        END IF;

        SELECT COUNT(*) INTO v_parent_unique_index_count
        FROM information_schema.STATISTICS s
        WHERE s.TABLE_SCHEMA = DATABASE()
          AND s.TABLE_NAME = p_parent_table
          AND s.COLUMN_NAME = p_parent_column
          AND s.SEQ_IN_INDEX = 1
          AND s.NON_UNIQUE = 0
          AND (
              SELECT COUNT(*)
              FROM information_schema.STATISTICS s2
              WHERE s2.TABLE_SCHEMA = s.TABLE_SCHEMA
                AND s2.TABLE_NAME = s.TABLE_NAME
                AND s2.INDEX_NAME = s.INDEX_NAME
          ) = 1;
        IF v_parent_unique_index_count = 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Referenced handoff column has no single-column PRIMARY/UNIQUE index; inspect the parent schema before continuing.';
        END IF;

        SELECT COUNT(*) INTO v_child_index_count
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_child_table
          AND COLUMN_NAME = p_child_column
          AND SEQ_IN_INDEX = 1;
        IF v_child_index_count = 0 THEN
            SET v_index_name = CONCAT(
                'idx_hf_', LEFT(SHA2(CONCAT(p_child_table, '.', p_child_column), 256), 16)
            );
            SET v_suffix = 0;
            index_name_loop: LOOP
                SELECT COUNT(*) INTO v_name_in_use
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = p_child_table
                  AND INDEX_NAME = v_index_name;
                IF v_name_in_use = 0 THEN
                    LEAVE index_name_loop;
                END IF;
                SET v_suffix = v_suffix + 1;
                SET v_index_name = CONCAT(
                    'idx_hf_', p_child_table, '_', p_child_column, '_', v_suffix
                );
                IF CHAR_LENGTH(v_index_name) > 64 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Unable to select a unique supporting index name; inspect the table indexes manually.';
                END IF;
            END LOOP;

            SET @crm_handoff_ddl = CONCAT(
                'ALTER TABLE `', DATABASE(), '`.`', p_child_table,
                '` ADD INDEX `', v_index_name, '` (`', p_child_column, '`)'
            );
            PREPARE crm_handoff_stmt FROM @crm_handoff_ddl;
            EXECUTE crm_handoff_stmt;
            DEALLOCATE PREPARE crm_handoff_stmt;
        END IF;

        SET @crm_handoff_orphan_count = NULL;
        SET @crm_handoff_ddl = CONCAT(
            'SELECT COUNT(*) INTO @crm_handoff_orphan_count FROM `',
            DATABASE(), '`.`', p_child_table, '` c LEFT JOIN `',
            DATABASE(), '`.`', p_parent_table, '` p ON p.`',
            p_parent_column, '` = c.`', p_child_column,
            '` WHERE c.`', p_child_column, '` IS NOT NULL AND p.`',
            p_parent_column, '` IS NULL'
        );
        PREPARE crm_handoff_stmt FROM @crm_handoff_ddl;
        EXECUTE crm_handoff_stmt;
        DEALLOCATE PREPARE crm_handoff_stmt;

        IF @crm_handoff_orphan_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Orphan handoff references exist; resolve them without deleting records before adding the foreign key.';
        END IF;

        SET v_constraint_name = p_constraint_base;
        SET v_suffix = 0;
        constraint_name_loop: LOOP
            SELECT COUNT(*) INTO v_name_in_use
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND CONSTRAINT_NAME = v_constraint_name;
            IF v_name_in_use = 0 THEN
                LEAVE constraint_name_loop;
            END IF;
            SET v_suffix = v_suffix + 1;
            SET v_constraint_name = CONCAT(p_constraint_base, '_', v_suffix);
            IF CHAR_LENGTH(v_constraint_name) > 64 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Unable to select a unique foreign key name; inspect constraint names manually.';
            END IF;
        END LOOP;

        SET @crm_handoff_ddl = CONCAT(
            'ALTER TABLE `', DATABASE(), '`.`', p_child_table,
            '` ADD CONSTRAINT `', v_constraint_name, '` FOREIGN KEY (`',
            p_child_column, '`) REFERENCES `', DATABASE(), '`.`',
            p_parent_table, '` (`', p_parent_column, '`) ON DELETE SET NULL'
        );
        PREPARE crm_handoff_stmt FROM @crm_handoff_ddl;
        EXECUTE crm_handoff_stmt;
        DEALLOCATE PREPARE crm_handoff_stmt;
    END IF;
END//
DELIMITER ;

CALL crm_handoff_ensure_foreign_key(
    'crm_lead_closure_forms', 'installation_completed_by_user_id',
    'users', 'id', 'fk_crm_closure_completion_user'
);
CALL crm_handoff_ensure_foreign_key(
    'crm_lead_closure_forms', 'customer_vehicle_id',
    'customer_vehicle_details', 'id', 'fk_crm_closure_handoff_vehicle'
);
CALL crm_handoff_ensure_foreign_key(
    'crm_lead_closure_forms', 'customer_installation_id',
    'customer_installations', 'id', 'fk_crm_closure_handoff_installation'
);
CALL crm_handoff_ensure_foreign_key(
    'crm_lead_closure_forms', 'device_id',
    'devices', 'id', 'fk_crm_closure_handoff_device'
);
CALL crm_handoff_ensure_foreign_key(
    'customer_installations', 'crm_closure_id',
    'crm_lead_closure_forms', 'id', 'fk_customer_installation_crm_closure'
);

DROP PROCEDURE crm_handoff_ensure_foreign_key;
