-- Run after crm_lead_stage_master.sql.
-- Adds the lifecycle stages only when they do not already exist.

USE stock_management;

INSERT INTO crm_lead_stage_master (stage_name, description, display_order, status)
VALUES
    ('New Lead', 'Lead created and not yet followed up.', 10, 'Active'),
    ('Existing Customer', 'Lead has completed its first follow-up.', 20, 'Active'),
    ('Converted Lead', 'Lead conversion and customer handoff are complete.', 90, 'Active'),
    ('Lost Lead', 'Lead has been marked as lost.', 100, 'Active')
ON DUPLICATE KEY UPDATE
    stage_name = VALUES(stage_name);
