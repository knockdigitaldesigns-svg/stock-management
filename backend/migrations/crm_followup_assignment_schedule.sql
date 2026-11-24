ALTER TABLE crm_lead_followups
    ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER completed_at,
    ADD COLUMN cancellation_reason VARCHAR(150) DEFAULT NULL AFTER cancelled_at,
    ADD KEY idx_crm_followup_active_schedule (lead_id, completed_at, cancelled_at, next_followup_date);
