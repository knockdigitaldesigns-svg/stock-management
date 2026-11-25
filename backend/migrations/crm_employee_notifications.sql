-- Employee notification extensions for the existing CRM notification table.
USE stock_management;

ALTER TABLE crm_notifications
    ADD COLUMN IF NOT EXISTS priority ENUM('Normal','High') NOT NULL DEFAULT 'Normal' AFTER notification_type,
    ADD COLUMN IF NOT EXISTS read_at DATETIME DEFAULT NULL AFTER is_read,
    ADD COLUMN IF NOT EXISTS event_key VARCHAR(150) DEFAULT NULL AFTER read_at,
    ADD COLUMN IF NOT EXISTS related_lead_id INT UNSIGNED DEFAULT NULL AFTER related_entity_id,
    ADD COLUMN IF NOT EXISTS related_customer_id INT UNSIGNED DEFAULT NULL AFTER related_lead_id,
    ADD COLUMN IF NOT EXISTS related_closure_id INT UNSIGNED DEFAULT NULL AFTER related_customer_id;

ALTER TABLE crm_notifications
    ADD INDEX IF NOT EXISTS idx_crm_notification_recipient_read_created (user_id, is_read, created_at),
    ADD UNIQUE INDEX IF NOT EXISTS uq_crm_notification_event (event_key);
