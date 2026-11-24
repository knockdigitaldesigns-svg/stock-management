-- Apply after crm_installation_location_handoff.sql.
-- Adds per-device progress without changing the existing customer/vehicle model.
USE stock_management;

CREATE TABLE IF NOT EXISTS crm_closure_installation_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    closure_id INT UNSIGNED NOT NULL,
    customer_id INT DEFAULT NULL,
    device_sequence SMALLINT UNSIGNED NOT NULL,
    installation_status ENUM('Pending', 'Completed') NOT NULL DEFAULT 'Pending',
    vehicle_number VARCHAR(30) DEFAULT NULL,
    vehicle_type_id INT UNSIGNED DEFAULT NULL,
    device_model_id INT DEFAULT NULL,
    sale_amount DECIMAL(12,2) DEFAULT NULL,
    device_id INT DEFAULT NULL,
    imei_no VARCHAR(15) DEFAULT NULL,
    sim_id_1 INT DEFAULT NULL,
    sim_no_1 VARCHAR(13) DEFAULT NULL,
    sim_id_2 INT DEFAULT NULL,
    sim_no_2 VARCHAR(13) DEFAULT NULL,
    validity_months INT DEFAULT NULL,
    installation_datetime DATETIME DEFAULT NULL,
    installation_remarks TEXT DEFAULT NULL,
    completion_evidence TEXT DEFAULT NULL,
    installation_verified TINYINT(1) NOT NULL DEFAULT 0,
    customer_vehicle_id INT DEFAULT NULL,
    customer_installation_id INT DEFAULT NULL,
    completed_by_user_id INT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_installation_device_sequence (closure_id, device_sequence),
    UNIQUE KEY uq_crm_installation_device_vehicle (closure_id, vehicle_number),
    UNIQUE KEY uq_crm_installation_device_id (device_id),
    UNIQUE KEY uq_crm_installation_device_imei (imei_no),
    UNIQUE KEY uq_crm_installation_device_sim_1 (sim_id_1),
    UNIQUE KEY uq_crm_installation_device_sim_2 (sim_id_2),
    UNIQUE KEY uq_crm_installation_device_customer_vehicle (customer_vehicle_id),
    UNIQUE KEY uq_crm_installation_device_customer_installation (customer_installation_id),
    KEY idx_crm_installation_device_status (closure_id, installation_status),
    KEY idx_crm_installation_device_customer (customer_id),
    CONSTRAINT fk_crm_installation_device_closure
        FOREIGN KEY (closure_id) REFERENCES crm_lead_closure_forms(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_crm_installation_device_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_crm_installation_device_vehicle_type
        FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_model
        FOREIGN KEY (device_model_id) REFERENCES device_types(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_inventory
        FOREIGN KEY (device_id) REFERENCES devices(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_sim_1
        FOREIGN KEY (sim_id_1) REFERENCES sims(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_sim_2
        FOREIGN KEY (sim_id_2) REFERENCES sims(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_customer_vehicle
        FOREIGN KEY (customer_vehicle_id) REFERENCES customer_vehicle_details(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_crm_installation_device_customer_installation
        FOREIGN KEY (customer_installation_id) REFERENCES customer_installations(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_crm_installation_device_completer
        FOREIGN KEY (completed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

ALTER TABLE crm_closure_installation_devices
    ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL AFTER closure_id,
    ADD KEY IF NOT EXISTS idx_crm_installation_device_customer (customer_id);

SET @add_crm_installation_device_customer_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_closure_installation_devices'
          AND constraint_name = 'fk_crm_installation_device_customer'
    ) = 0,
    'ALTER TABLE crm_closure_installation_devices ADD CONSTRAINT fk_crm_installation_device_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_installation_device_customer_fk_stmt FROM @add_crm_installation_device_customer_fk;
EXECUTE crm_installation_device_customer_fk_stmt;
DEALLOCATE PREPARE crm_installation_device_customer_fk_stmt;

UPDATE crm_closure_installation_devices item
INNER JOIN crm_lead_closure_forms cf ON cf.id = item.closure_id
SET item.customer_id = NULL
WHERE item.installation_status = 'Pending'
  AND item.customer_id = cf.customer_id;

UPDATE crm_closure_installation_devices item
INNER JOIN crm_lead_closure_forms cf ON cf.id = item.closure_id
SET item.customer_id = cf.customer_id
WHERE item.customer_id IS NULL
  AND item.installation_status = 'Completed'
  AND item.customer_vehicle_id IS NOT NULL
  AND cf.customer_id IS NOT NULL;

INSERT IGNORE INTO crm_closure_installation_devices (
    closure_id,
    customer_id,
    device_sequence,
    installation_status,
    vehicle_number,
    vehicle_type_id,
    device_model_id,
    sale_amount,
    device_id,
    imei_no,
    sim_id_1,
    sim_no_1,
    sim_id_2,
    sim_no_2,
    validity_months,
    installation_datetime,
    installation_remarks,
    completion_evidence,
    installation_verified,
    customer_vehicle_id,
    customer_installation_id,
    completed_by_user_id,
    completed_at
)
SELECT
    cf.id,
    cf.customer_id,
    1,
    'Completed',
    cv.vehicle_no,
    cv.vehicle_type_id,
    cv.device_model_id,
    COALESCE(payment.total_sale_amount, cf.amount),
    cv.device_id,
    cv.imei_no,
    cv.sim_id_1,
    cv.sim_no_1,
    cv.sim_id_2,
    cv.sim_no_2,
    cv.validity_months,
    ci.installation_datetime,
    ci.installation_remarks,
    ci.completion_evidence,
    ci.installation_verified,
    cv.id,
    ci.id,
    cf.installation_completed_by_user_id,
    cf.installation_completed_at
FROM crm_lead_closure_forms cf
INNER JOIN customer_vehicle_details cv ON cv.id = cf.customer_vehicle_id
INNER JOIN customer_installations ci ON ci.id = cf.customer_installation_id
LEFT JOIN customer_payments payment ON payment.vehicle_id = cv.id
WHERE cf.customer_id IS NOT NULL
  AND cf.customer_vehicle_id IS NOT NULL
  AND cf.customer_installation_id IS NOT NULL
  AND cf.device_id IS NOT NULL;

DROP PROCEDURE IF EXISTS crm_seed_multi_device_progress;
DELIMITER //
CREATE PROCEDURE crm_seed_multi_device_progress()
BEGIN
    DECLARE v_done TINYINT DEFAULT 0;
    DECLARE v_closure_id INT UNSIGNED;
    DECLARE v_device_count INT UNSIGNED;
    DECLARE v_vehicle_number VARCHAR(50);
    DECLARE v_vehicle_type_name VARCHAR(100);
    DECLARE v_device_type_name VARCHAR(100);
    DECLARE v_vehicle_type_id INT UNSIGNED;
    DECLARE v_device_type_id INT;
    DECLARE v_sequence INT UNSIGNED;
    DECLARE closure_cursor CURSOR FOR
        SELECT id, device_count, vehicle_number, vehicle_type, device_type
        FROM crm_lead_closure_forms
        WHERE device_count > 1
          AND submission_status IN ('Installation In Progress', 'Customer Handoff Completed');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN closure_cursor;
    seed_loop: LOOP
        FETCH closure_cursor INTO
            v_closure_id,
            v_device_count,
            v_vehicle_number,
            v_vehicle_type_name,
            v_device_type_name;
        IF v_done = 1 THEN
            LEAVE seed_loop;
        END IF;
        IF v_device_count > 65535 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'A CRM installation order exceeds the supported per-device sequence range.';
        END IF;

        SET v_vehicle_type_id = (
            SELECT id FROM vehicle_types
            WHERE vehicle_type = v_vehicle_type_name
              AND (status = 'Active' OR status IS NULL OR status = '')
            LIMIT 1
        );
        SET v_device_type_id = (
            SELECT id FROM device_types
            WHERE device_type = v_device_type_name
            LIMIT 1
        );
        SET v_sequence = 1;
        WHILE v_sequence <= v_device_count DO
            INSERT IGNORE INTO crm_closure_installation_devices (
                closure_id,
                device_sequence,
                vehicle_number,
                vehicle_type_id,
                device_model_id
            )
            VALUES (
                v_closure_id,
                v_sequence,
                IF(v_sequence = 1 AND CHAR_LENGTH(v_vehicle_number) <= 30, NULLIF(v_vehicle_number, ''), NULL),
                v_vehicle_type_id,
                v_device_type_id
            );
            SET v_sequence = v_sequence + 1;
        END WHILE;
    END LOOP;
    CLOSE closure_cursor;
END//
DELIMITER ;

CALL crm_seed_multi_device_progress();
DROP PROCEDURE crm_seed_multi_device_progress;

UPDATE crm_lead_closure_forms cf
SET cf.submission_status = 'Installation In Progress'
WHERE cf.device_count > 1
  AND cf.submission_status = 'Customer Handoff Completed'
  AND cf.customer_id IS NOT NULL
  AND cf.customer_vehicle_id IS NOT NULL
  AND cf.customer_installation_id IS NOT NULL
  AND cf.device_id IS NOT NULL
  AND (
      SELECT COUNT(*)
      FROM crm_closure_installation_devices item
      WHERE item.closure_id = cf.id
        AND item.installation_status = 'Completed'
  ) < cf.device_count;
