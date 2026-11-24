ALTER TABLE crm_leads
    MODIFY COLUMN lead_status_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE crm_lead_followups
    MODIFY COLUMN current_status_id INT UNSIGNED DEFAULT NULL;
