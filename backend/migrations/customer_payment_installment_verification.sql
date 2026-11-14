-- Apply after customer_payment_transactions.sql and customer_payment_verification.sql.
CREATE TABLE IF NOT EXISTS customer_payment_installment_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    verification_submission_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    customer_payment_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode VARCHAR(50) NOT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    payment_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('Pending Verification', 'Applying', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending Verification',
    rejection_reason TEXT DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    reviewed_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_installment_submission (verification_submission_id),
    UNIQUE KEY uq_customer_installment_request_key (request_key),
    INDEX idx_customer_installment_payment_status (customer_payment_id, status),
    UNIQUE KEY uq_customer_installment_transaction (transaction_id),
    CONSTRAINT fk_customer_installment_verification_submission
        FOREIGN KEY (verification_submission_id)
        REFERENCES customer_payment_verification_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_installment_payment
        FOREIGN KEY (customer_payment_id) REFERENCES customer_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_installment_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_installment_submitter
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_installment_reviewer
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
