CREATE TABLE crm_lead_stage_master (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    stage_name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 0,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_lead_stage_name (stage_name),
    KEY idx_crm_lead_stage_active_order (status, display_order),
    CONSTRAINT fk_crm_lead_stage_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE crm_leads
    ADD COLUMN lead_stage_id INT UNSIGNED DEFAULT NULL AFTER lead_status_id,
    ADD KEY idx_crm_leads_stage (lead_stage_id),
    ADD CONSTRAINT fk_crm_lead_stage
        FOREIGN KEY (lead_stage_id) REFERENCES crm_lead_stage_master(id) ON DELETE RESTRICT;

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('crm_lead_stage.view', 'CRM Lead Stage View', 'crm_lead_stage', 'VIEW'),
    ('crm_lead_stage.add', 'CRM Lead Stage Add', 'crm_lead_stage', 'ADD'),
    ('crm_lead_stage.edit', 'CRM Lead Stage Edit', 'crm_lead_stage', 'EDIT'),
    ('crm_lead_stage.activate', 'CRM Lead Stage Activate', 'crm_lead_stage', 'ACTIVATE')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'crm_lead_stage.view',
    'crm_lead_stage.add',
    'crm_lead_stage.edit',
    'crm_lead_stage.activate'
)
WHERE r.role_name = 'Super Admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
