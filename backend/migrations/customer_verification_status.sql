SET @verification_status_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'customers'
      AND column_name = 'verification_status'
);
SET @verification_status_column_ddl = IF(
    @verification_status_column_exists = 0,
    'ALTER TABLE customers ADD COLUMN verification_status ENUM(''Pending'', ''Approved'', ''Rejected'') NOT NULL DEFAULT ''Approved''',
    'SELECT 1'
);
PREPARE verification_status_column_stmt FROM @verification_status_column_ddl;
EXECUTE verification_status_column_stmt;
DEALLOCATE PREPARE verification_status_column_stmt;

SET @verification_status_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'customers'
      AND index_name = 'idx_customer_verification_status'
);
SET @verification_status_index_ddl = IF(
    @verification_status_index_exists = 0,
    'ALTER TABLE customers ADD KEY idx_customer_verification_status (verification_status, id)',
    'SELECT 1'
);
PREPARE verification_status_index_stmt FROM @verification_status_index_ddl;
EXECUTE verification_status_index_stmt;
DEALLOCATE PREPARE verification_status_index_stmt;

INSERT INTO customer_payment_verification_history
    (submission_id, old_status, new_status, reason, changed_by_user_id)
SELECT s.id, s.status, 'Pending Verification',
       'Existing customer queued for Admin verification during migration.',
       s.submitter_user_id
FROM customer_payment_verification_submissions s
INNER JOIN customers c ON c.verification_submission_id = s.id
WHERE s.status IN ('Draft', 'Committed')
  AND s.existing_customer_id IS NULL
  AND JSON_VALID(s.submission_data)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.submission_data, '$.source')), '') <> 'courier'
  AND NOT EXISTS (
      SELECT 1
      FROM customer_payment_verification_history h
      WHERE h.submission_id = s.id
        AND h.old_status = s.status
        AND h.new_status = 'Pending Verification'
        AND h.reason = 'Existing customer queued for Admin verification during migration.'
  );

UPDATE customer_payment_verification_submissions s
INNER JOIN customers c ON c.verification_submission_id = s.id
SET s.status = 'Pending Verification',
    s.linked_customer_id = COALESCE(s.linked_customer_id, c.id),
    s.linked_vehicle_id = COALESCE(
        s.linked_vehicle_id,
        (
            SELECT cv.id
            FROM customer_vehicle_details cv
            WHERE cv.customer_id = c.id
              AND cv.verification_submission_id = s.id
            ORDER BY cv.id DESC
            LIMIT 1
        )
    ),
    s.linked_payment_id = COALESCE(
        s.linked_payment_id,
        (
            SELECT cp.id
            FROM customer_payments cp
            WHERE cp.customer_id = c.id
            ORDER BY cp.created_at DESC, cp.id DESC
            LIMIT 1
        )
    ),
    s.submission_data = JSON_SET(s.submission_data, '$.legacy_customer_review', TRUE),
    s.submitted_at = COALESCE(s.submitted_at, s.created_at),
    s.reviewer_user_id = NULL,
    s.decided_at = NULL
WHERE s.status IN ('Draft', 'Committed')
  AND s.existing_customer_id IS NULL
  AND JSON_VALID(s.submission_data)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.submission_data, '$.source')), '') <> 'courier';

UPDATE customers c
INNER JOIN customer_payment_verification_submissions s
    ON s.id = c.verification_submission_id
SET c.verification_status = CASE
    WHEN s.status = 'Rejected' THEN 'Rejected'
    WHEN s.status IN ('Pending Verification', 'Applying') THEN 'Pending'
    ELSE 'Approved'
END;
