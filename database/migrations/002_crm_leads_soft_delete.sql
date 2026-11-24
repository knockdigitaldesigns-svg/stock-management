ALTER TABLE crm_leads
    ADD COLUMN deleted_at DATETIME DEFAULT NULL,
    ADD COLUMN deleted_by_user_id INT DEFAULT NULL,
    ADD KEY idx_crm_leads_deleted_at (deleted_at),
    ADD CONSTRAINT fk_crm_lead_deleted_by
        FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
