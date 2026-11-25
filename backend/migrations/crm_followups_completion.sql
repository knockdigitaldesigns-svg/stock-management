ALTER TABLE crm_lead_followups
    ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER next_followup_time;
