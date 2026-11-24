CREATE TABLE IF NOT EXISTS crm_lead_transfer_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id INT UNSIGNED NOT NULL,
    previous_employee_id INT NOT NULL,
    new_employee_id INT NOT NULL,
    transferred_by_user_id INT NOT NULL,
    transfer_reason VARCHAR(1000) NOT NULL,
    transfer_type ENUM('Single', 'Bulk') NOT NULL,
    transferred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_transfer_lead_date (lead_id, transferred_at, id),
    KEY idx_crm_transfer_previous_employee (previous_employee_id),
    KEY idx_crm_transfer_new_employee (new_employee_id),
    KEY idx_crm_transfer_actor (transferred_by_user_id),
    CONSTRAINT fk_crm_transfer_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('crm_leads.transfer', 'CRM Lead Transfer', 'crm_leads', 'TRANSFER'),
    ('crm_leads.transfer_bulk', 'CRM Bulk Lead Transfer', 'crm_leads', 'BULK_TRANSFER'),
    ('crm_leads.transfer_history', 'CRM Lead Transfer History View', 'crm_leads', 'TRANSFER_HISTORY')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);
