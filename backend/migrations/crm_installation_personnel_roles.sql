-- Run after the CRM installation permissions have been installed.
-- These roles receive only installation viewing access.
INSERT INTO roles (role_name, description, status, is_system_role)
VALUES
    ('Technician', 'Installation login role for technician personnel.', 'active', 0),
    ('Dealer', 'Installation login role for dealer personnel.', 'active', 0)
ON DUPLICATE KEY UPDATE
    role_name = VALUES(role_name),
    description = VALUES(description),
    status = VALUES(status);

DELETE FROM role_permissions
WHERE role_id IN (
    SELECT id
    FROM roles
    WHERE role_name IN ('Technician', 'Dealer')
)
AND permission_id NOT IN (
    SELECT id
    FROM permissions
    WHERE permission_key = 'crm_installations.view'
)
AND EXISTS (
    SELECT 1
    FROM permissions
    WHERE permission_key = 'crm_installations.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON p.permission_key = 'crm_installations.view'
WHERE r.role_name IN ('Technician', 'Dealer');
