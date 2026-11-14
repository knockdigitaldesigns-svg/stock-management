CREATE TABLE IF NOT EXISTS customer_payment_transactions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    payment_id INT NOT NULL,
    customer_id INT NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode VARCHAR(50) NOT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    payment_date DATE NOT NULL,
    payment_status VARCHAR(30) NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_payment_transaction_request (request_key),
    INDEX idx_customer_payment_transactions_payment (payment_id, id),
    INDEX idx_customer_payment_transactions_customer (customer_id, id),
    INDEX idx_customer_payment_transactions_transaction_id (transaction_id),
    CONSTRAINT fk_customer_payment_transaction_payment
        FOREIGN KEY (payment_id) REFERENCES customer_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_payment_transaction_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO customer_payment_transactions (
    payment_id,
    customer_id,
    request_key,
    amount,
    payment_mode,
    transaction_id,
    payment_date,
    payment_status,
    notes
)
SELECT
    cp.id,
    cp.customer_id,
    CONCAT('legacy-', cp.id),
    cp.amount_paid,
    COALESCE(NULLIF(cp.payment_mode, ''), 'Cash'),
    cp.transaction_id,
    DATE(cp.created_at),
    cp.payment_status,
    'Imported existing customer payment'
FROM customer_payments cp
WHERE cp.amount_paid > 0
  AND NOT EXISTS (
      SELECT 1
      FROM customer_payment_transactions cpt
      WHERE cpt.payment_id = cp.id
  );
