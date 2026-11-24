-- Review before applying. This migration is intentionally not executed by the app.
-- Installer login accounts are linked explicitly during an authorized assignment.
INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('crm_installations.view', 'CRM Installations View', 'crm_installations', 'VIEW'),
    ('crm_installations.assign', 'CRM Installations Assign', 'crm_installations', 'ASSIGN')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

ALTER TABLE technicians
    ADD COLUMN user_id INT DEFAULT NULL,
    ADD UNIQUE KEY uq_technician_user (user_id),
    ADD CONSTRAINT fk_technician_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE dealers
    ADD COLUMN user_id INT DEFAULT NULL,
    ADD UNIQUE KEY uq_dealer_user (user_id),
    ADD CONSTRAINT fk_dealer_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE crm_lead_closure_forms
    ADD COLUMN installation_person_type
        ENUM('Technician', 'Onsite Dealer', 'Offsite Dealer') DEFAULT NULL,
    ADD COLUMN installation_person_id INT DEFAULT NULL,
    ADD COLUMN installation_person_user_id INT DEFAULT NULL,
    ADD COLUMN installation_assigned_by_user_id INT DEFAULT NULL,
    ADD COLUMN installation_assigned_at DATETIME DEFAULT NULL,
    ADD KEY idx_crm_closure_installation_person
        (installation_person_type, installation_person_id),
    ADD KEY idx_crm_closure_installation_user_status
        (installation_person_user_id, submission_status),
    ADD CONSTRAINT fk_crm_closure_installation_user
        FOREIGN KEY (installation_person_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_crm_closure_installation_assigner
        FOREIGN KEY (installation_assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
