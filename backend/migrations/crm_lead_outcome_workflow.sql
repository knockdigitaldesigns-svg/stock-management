ALTER TABLE crm_leads
    ADD COLUMN closure_reason VARCHAR(100) DEFAULT NULL AFTER remarks;

ALTER TABLE crm_lead_followups
    ADD COLUMN closure_reason VARCHAR(100) DEFAULT NULL AFTER completed_at;

ALTER TABLE crm_lead_closure_forms
    ADD COLUMN platform_id INT DEFAULT NULL AFTER lead_id,
    ADD COLUMN customer_id INT DEFAULT NULL AFTER platform_id,
    ADD COLUMN submitted_at DATETIME DEFAULT NULL AFTER submitted_by_user_id,
    ADD COLUMN reviewed_by_user_id INT DEFAULT NULL AFTER submitted_at,
    ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER approved_at,
    ADD UNIQUE KEY uq_crm_closure_customer (customer_id),
    ADD KEY idx_crm_closure_platform (platform_id),
    ADD CONSTRAINT fk_crm_closure_platform
        FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_crm_closure_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT;
