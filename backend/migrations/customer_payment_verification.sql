CREATE TABLE IF NOT EXISTS customer_payment_verification_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submitter_user_id INT NOT NULL,
    existing_customer_id INT DEFAULT NULL,
    linked_customer_id INT DEFAULT NULL,
    linked_vehicle_id INT DEFAULT NULL,
    linked_payment_id INT DEFAULT NULL,
    status ENUM(
        'Draft',
        'Pending Verification',
        'Applying',
        'Approved',
        'Rejected',
        'Committed'
    ) NOT NULL DEFAULT 'Draft',
    submission_data LONGTEXT NOT NULL,
    rejection_reason TEXT DEFAULT NULL,
    reviewer_user_id INT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_payment_verification_status_date (status, submitted_at),
    KEY idx_customer_payment_verification_existing_customer (existing_customer_id),
    KEY idx_customer_payment_verification_submitter (submitter_user_id),
    CONSTRAINT fk_customer_payment_verification_submitter
        FOREIGN KEY (submitter_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_payment_verification_reviewer
        FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_payment_verification_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(40) DEFAULT NULL,
    new_status VARCHAR(40) NOT NULL,
    reason TEXT DEFAULT NULL,
    changed_by_user_id INT NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_payment_verification_history_submission (submission_id, changed_at),
    CONSTRAINT fk_customer_payment_verification_history_submission
        FOREIGN KEY (submission_id)
        REFERENCES customer_payment_verification_submissions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_payment_verification_history_user
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES (
    'customer_payment_verification.review',
    'Customer Payment Verification Review',
    'customer_payment_verification',
    'REVIEW'
)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE LOWER(TRIM(r.role_name)) IN ('admin', 'super admin', 'super_admin')
  AND p.permission_key = 'customer_payment_verification.review';

ALTER TABLE customers
    ADD COLUMN verification_submission_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_customer_verification_submission (verification_submission_id);

ALTER TABLE customer_vehicle_details
    ADD COLUMN verification_submission_id BIGINT UNSIGNED DEFAULT NULL,
    ADD KEY idx_vehicle_verification_submission (verification_submission_id);
