-- Apply after customer_payment_verification.sql and crm_multi_device_installations.sql.
-- Tracks each installed device's customer-verification lifecycle.
USE stock_management;

ALTER TABLE crm_closure_installation_devices
    ADD COLUMN IF NOT EXISTS verification_submission_id BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS verification_status ENUM(
        'Not Submitted',
        'Pending Verification',
        'Rejected',
        'Approved'
    ) NOT NULL DEFAULT 'Not Submitted',
    ADD COLUMN IF NOT EXISTS verification_rejection_reason TEXT DEFAULT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_crm_installation_verification_submission (verification_submission_id),
    ADD KEY IF NOT EXISTS idx_crm_installation_verification_status (closure_id, verification_status);
