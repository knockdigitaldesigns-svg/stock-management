-- Complete CRM Phase 1 schema bundle for MariaDB 10.4. Includes the Phase 1 foundation,
-- follow-up completion/scheduling, lead outcome/closure, and installation assignment updates.
-- Safe to rerun: table/permission/column/index/FK additions are duplicate-guarded.
-- Review/apply only after selecting the intended database; DDL commits implicitly.

-- CRM Phase 1 foundation migration
-- Safe, non-destructive DDL for dynamic pipelines, status master, and lead foundation.
-- Apply only to an existing stock_management database that has already been initialized.
-- This file uses CREATE TABLE IF NOT EXISTS and duplicate-safe permission inserts to
-- avoid destructive changes and repeated migration errors.

CREATE TABLE IF NOT EXISTS crm_pipelines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pipeline_name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    display_order INT NOT NULL DEFAULT 0,
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_pipeline_name (pipeline_name),
    KEY idx_crm_pipeline_status (status, display_order),
    CONSTRAINT fk_crm_pipeline_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_campaigns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pipeline_id INT UNSIGNED NOT NULL,
    manager_user_id INT DEFAULT NULL,
    campaign_name VARCHAR(150) NOT NULL,
    campaign_type VARCHAR(100) DEFAULT NULL,
    lead_source VARCHAR(50) NOT NULL DEFAULT 'Manual',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_campaign_name (pipeline_id, campaign_name),
    KEY idx_crm_campaign_pipeline (pipeline_id, status),
    KEY idx_crm_campaign_manager (manager_user_id),
    CONSTRAINT fk_crm_campaign_pipeline FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_campaign_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_campaign_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE crm_campaigns
    ADD COLUMN IF NOT EXISTS manager_user_id INT DEFAULT NULL AFTER pipeline_id;

SET @add_crm_campaign_manager_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_campaigns'
          AND constraint_name = 'fk_crm_campaign_manager'
    ) = 0,
    'ALTER TABLE crm_campaigns ADD CONSTRAINT fk_crm_campaign_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_campaign_manager_fk_stmt FROM @add_crm_campaign_manager_fk;
EXECUTE crm_campaign_manager_fk_stmt;
DEALLOCATE PREPARE crm_campaign_manager_fk_stmt;

CREATE TABLE IF NOT EXISTS crm_lead_status_master (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    status_name VARCHAR(100) NOT NULL,
    status_type ENUM('Open', 'Active', 'Resolved', 'Lost') NOT NULL DEFAULT 'Open',
    color VARCHAR(20) NOT NULL DEFAULT '#6c757d',
    display_order INT NOT NULL DEFAULT 0,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_status_name (status_name),
    KEY idx_crm_status_active_order (status, display_order),
    CONSTRAINT fk_crm_status_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_lead_stage_master (
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

CREATE TABLE IF NOT EXISTS crm_leads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pipeline_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    lead_name VARCHAR(150) NOT NULL,
    mobile_no VARCHAR(20) NOT NULL,
    alternate_mobile_no VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    location VARCHAR(150) NOT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    lead_source VARCHAR(50) NOT NULL DEFAULT 'Manual',
    customer_need TEXT DEFAULT NULL,
    priority VARCHAR(50) NOT NULL DEFAULT 'Normal',
    lead_status_id INT UNSIGNED DEFAULT NULL,
    lead_stage_id INT UNSIGNED DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    closure_reason VARCHAR(100) DEFAULT NULL,
    assigned_user_id INT DEFAULT NULL,
    assigned_by_user_id INT DEFAULT NULL,
    assigned_at TIMESTAMP NULL DEFAULT NULL,
    created_by_user_id INT DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    deleted_by_user_id INT DEFAULT NULL,
    mobile_no_normalized VARCHAR(20)
        AS (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')) PERSISTENT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_leads_pipeline (pipeline_id),
    KEY idx_crm_leads_campaign (campaign_id),
    KEY idx_crm_leads_status (lead_status_id),
    KEY idx_crm_leads_stage (lead_stage_id),
    KEY idx_crm_leads_assignee (assigned_user_id),
    KEY idx_crm_leads_creator (created_by_user_id),
    KEY idx_crm_leads_deleted_at (deleted_at),
    KEY idx_crm_leads_source (lead_source),
    UNIQUE KEY uq_crm_leads_campaign_mobile (campaign_id, mobile_no_normalized),
    CONSTRAINT fk_crm_lead_pipeline FOREIGN KEY (pipeline_id) REFERENCES crm_pipelines(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_lead_campaign FOREIGN KEY (campaign_id) REFERENCES crm_campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_lead_status FOREIGN KEY (lead_status_id) REFERENCES crm_lead_status_master(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_lead_stage FOREIGN KEY (lead_stage_id) REFERENCES crm_lead_stage_master(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_lead_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_lead_assigner FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_lead_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_lead_deleted_by FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_lead_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id INT UNSIGNED NOT NULL,
    assigned_to_user_id INT NOT NULL,
    assigned_by_user_id INT NOT NULL,
    assignment_type ENUM('Distribution', 'Reassign') NOT NULL DEFAULT 'Distribution',
    assignment_notes TEXT DEFAULT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_assignment_lead (lead_id),
    KEY idx_crm_assignment_user (assigned_to_user_id),
    CONSTRAINT fk_crm_assignment_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_assignment_to_user FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_assignment_by_user FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_lead_followups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id INT UNSIGNED NOT NULL,
    current_status_id INT UNSIGNED DEFAULT NULL,
    customer_need TEXT DEFAULT NULL,
    call_outcome VARCHAR(100) DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    next_followup_date DATE DEFAULT NULL,
    next_followup_time TIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancellation_reason VARCHAR(150) DEFAULT NULL,
    closure_reason VARCHAR(100) DEFAULT NULL,
    updated_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_followup_lead (lead_id),
    KEY idx_crm_followup_status (current_status_id),
    KEY idx_crm_followup_due_date (next_followup_date),
    KEY idx_crm_followup_active_schedule (lead_id, completed_at, cancelled_at, next_followup_date),
    CONSTRAINT fk_crm_followup_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_followup_status FOREIGN KEY (current_status_id) REFERENCES crm_lead_status_master(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crm_followup_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_lead_closure_forms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id INT UNSIGNED NOT NULL,
    platform_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    customer_number VARCHAR(50) DEFAULT NULL,
    username VARCHAR(100) DEFAULT NULL,
    vehicle_type VARCHAR(100) DEFAULT NULL,
    vehicle_number VARCHAR(50) DEFAULT NULL,
    commitment_date DATE DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    device_type VARCHAR(100) DEFAULT NULL,
    device_count INT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) DEFAULT NULL,
    amount_status VARCHAR(50) DEFAULT NULL,
    app_name VARCHAR(100) DEFAULT NULL,
    submission_status ENUM('Draft', 'Submitted', 'Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Draft',
    submitted_by_user_id INT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    reviewed_by_user_id INT DEFAULT NULL,
    approved_by_user_id INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_closure_lead (lead_id),
    UNIQUE KEY uq_crm_closure_customer (customer_id),
    KEY idx_crm_closure_platform (platform_id),
    KEY idx_crm_closure_status (submission_status),
    CONSTRAINT fk_crm_closure_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_closure_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_closure_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'info',
    related_entity_type VARCHAR(50) DEFAULT NULL,
    related_entity_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_notification_user (user_id, is_read, created_at),
    CONSTRAINT fk_crm_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('crm_pipelines.view', 'CRM Pipelines View', 'crm_pipelines', 'VIEW'),
    ('crm_pipelines.add', 'CRM Pipelines Add', 'crm_pipelines', 'ADD'),
    ('crm_pipelines.edit', 'CRM Pipelines Edit', 'crm_pipelines', 'EDIT'),
    ('crm_pipelines.delete', 'CRM Pipelines Delete', 'crm_pipelines', 'DELETE'),
    ('crm_campaigns.view', 'CRM Campaigns View', 'crm_campaigns', 'VIEW'),
    ('crm_campaigns.add', 'CRM Campaigns Add', 'crm_campaigns', 'ADD'),
    ('crm_campaigns.edit', 'CRM Campaigns Edit', 'crm_campaigns', 'EDIT'),
    ('crm_campaigns.delete', 'CRM Campaigns Delete', 'crm_campaigns', 'DELETE'),
    ('crm_lead_status.view', 'CRM Lead Status View', 'crm_lead_status', 'VIEW'),
    ('crm_lead_status.add', 'CRM Lead Status Add', 'crm_lead_status', 'ADD'),
    ('crm_lead_status.edit', 'CRM Lead Status Edit', 'crm_lead_status', 'EDIT'),
    ('crm_lead_status.activate', 'CRM Lead Status Activate', 'crm_lead_status', 'ACTIVATE'),
    ('crm_lead_stage.view', 'CRM Lead Stage View', 'crm_lead_stage', 'VIEW'),
    ('crm_lead_stage.add', 'CRM Lead Stage Add', 'crm_lead_stage', 'ADD'),
    ('crm_lead_stage.edit', 'CRM Lead Stage Edit', 'crm_lead_stage', 'EDIT'),
    ('crm_lead_stage.activate', 'CRM Lead Stage Activate', 'crm_lead_stage', 'ACTIVATE'),
    ('crm_leads.view', 'CRM Leads View', 'crm_leads', 'VIEW'),
    ('crm_leads.add', 'CRM Leads Add', 'crm_leads', 'ADD'),
    ('crm_leads.edit', 'CRM Leads Edit', 'crm_leads', 'EDIT'),
    ('crm_leads.delete', 'CRM Leads Delete', 'crm_leads', 'DELETE'),
    ('crm_leads.assign', 'CRM Leads Assign', 'crm_leads', 'ASSIGN'),
    ('crm_leads.my_view', 'CRM My Leads View', 'crm_leads', 'MY_VIEW'),
    ('crm_followups.view', 'CRM Follow-ups View', 'crm_followups', 'VIEW'),
    ('crm_followups.add', 'CRM Follow-ups Add', 'crm_followups', 'ADD'),
    ('crm_followups.edit', 'CRM Follow-ups Edit', 'crm_followups', 'EDIT'),
    ('crm_closures.view', 'CRM Closures View', 'crm_closures', 'VIEW'),
    ('crm_closures.add', 'CRM Closures Add', 'crm_closures', 'ADD'),
    ('crm_closures.edit', 'CRM Closures Edit', 'crm_closures', 'EDIT'),
    ('crm_closures.approve', 'CRM Closures Approve', 'crm_closures', 'APPROVE'),
    ('crm_installations.view', 'CRM Installations View', 'crm_installations', 'VIEW'),
    ('crm_installations.assign', 'CRM Installations Assign', 'crm_installations', 'ASSIGN'),
    ('crm_installations.manage', 'CRM Installations Manage', 'crm_installations', 'MANAGE'),
    ('crm_notifications.view', 'CRM Notifications View', 'crm_notifications', 'VIEW')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

-- Campaign-scoped mobile uniqueness and lead archive/audit fields.
-- Inspect this preflight result first; resolve any duplicate groups before continuing.
SELECT
    campaign_id,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') AS normalized_mobile,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(id ORDER BY id) AS lead_ids
FROM crm_leads
WHERE campaign_id IS NOT NULL
GROUP BY
    campaign_id,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')
HAVING COUNT(*) > 1;

ALTER TABLE crm_leads
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deleted_by_user_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mobile_no_normalized VARCHAR(20)
        AS (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')')) PERSISTENT,
    ADD INDEX IF NOT EXISTS idx_crm_leads_deleted_at (deleted_at),
    ADD UNIQUE INDEX IF NOT EXISTS uq_crm_leads_campaign_mobile (campaign_id, mobile_no_normalized);

SET @add_crm_lead_deleted_by_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_leads'
          AND constraint_name = 'fk_crm_lead_deleted_by'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE crm_leads
        ADD CONSTRAINT fk_crm_lead_deleted_by
        FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_lead_deleted_by_fk_stmt FROM @add_crm_lead_deleted_by_fk;
EXECUTE crm_lead_deleted_by_fk_stmt;
DEALLOCATE PREPARE crm_lead_deleted_by_fk_stmt;


-- Consolidated CRM follow-up and lead-outcome schema updates.
-- Requires the CRM Phase 1 tables from crm_phase1_foundation.sql / setup_db.php.
-- Safe to rerun on MariaDB: existing columns, indexes, and constraints are skipped.

ALTER TABLE crm_leads
    ADD COLUMN IF NOT EXISTS closure_reason VARCHAR(100) DEFAULT NULL AFTER remarks;

ALTER TABLE crm_lead_followups
    ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL AFTER next_followup_time,
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME DEFAULT NULL AFTER completed_at,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(150) DEFAULT NULL AFTER cancelled_at,
    ADD COLUMN IF NOT EXISTS closure_reason VARCHAR(100) DEFAULT NULL AFTER completed_at;

ALTER TABLE crm_lead_followups
    ADD INDEX IF NOT EXISTS idx_crm_followup_active_schedule
        (lead_id, completed_at, cancelled_at, next_followup_date);

ALTER TABLE crm_lead_closure_forms
    ADD COLUMN IF NOT EXISTS platform_id INT DEFAULT NULL AFTER lead_id,
    ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL AFTER platform_id,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT NULL AFTER submitted_by_user_id,
    ADD COLUMN IF NOT EXISTS reviewed_by_user_id INT DEFAULT NULL AFTER submitted_at,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL AFTER approved_at;

ALTER TABLE crm_lead_closure_forms
    ADD UNIQUE INDEX IF NOT EXISTS uq_crm_closure_customer (customer_id),
    ADD INDEX IF NOT EXISTS idx_crm_closure_platform (platform_id);

SET @add_crm_closure_platform_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_lead_closure_forms'
          AND constraint_name = 'fk_crm_closure_platform'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE crm_lead_closure_forms
        ADD CONSTRAINT fk_crm_closure_platform
        FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE crm_closure_platform_fk_stmt FROM @add_crm_closure_platform_fk;
EXECUTE crm_closure_platform_fk_stmt;
DEALLOCATE PREPARE crm_closure_platform_fk_stmt;

SET @add_crm_closure_customer_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_lead_closure_forms'
          AND constraint_name = 'fk_crm_closure_customer'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE crm_lead_closure_forms
        ADD CONSTRAINT fk_crm_closure_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE crm_closure_customer_fk_stmt FROM @add_crm_closure_customer_fk;
EXECUTE crm_closure_customer_fk_stmt;
DEALLOCATE PREPARE crm_closure_customer_fk_stmt;

-- Allow leads and follow-up records to retain a missing status without inventing a value.
ALTER TABLE crm_leads
    MODIFY COLUMN lead_status_id INT UNSIGNED DEFAULT NULL;

ALTER TABLE crm_lead_followups
    MODIFY COLUMN current_status_id INT UNSIGNED DEFAULT NULL;

-- Installer assignment workflow. Existing technician/dealer personnel are linked to
-- existing users explicitly by an authorized administrator; no personnel or users are created here.
INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('crm_installations.view', 'CRM Installations View', 'crm_installations', 'VIEW'),
    ('crm_installations.assign', 'CRM Installations Assign', 'crm_installations', 'ASSIGN')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

ALTER TABLE technicians
    ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_technician_user (user_id);

ALTER TABLE dealers
    ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_dealer_user (user_id);

ALTER TABLE crm_lead_closure_forms
    ADD COLUMN IF NOT EXISTS installation_person_type
        ENUM('Technician', 'Onsite Dealer', 'Offsite Dealer') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_person_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_person_user_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_assigned_by_user_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS installation_assigned_at DATETIME DEFAULT NULL,
    ADD INDEX IF NOT EXISTS idx_crm_closure_installation_person
        (installation_person_type, installation_person_id),
    ADD INDEX IF NOT EXISTS idx_crm_closure_installation_user_status
        (installation_person_user_id, submission_status);

SET @add_crm_technician_user_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'technicians'
          AND constraint_name = 'fk_technician_user'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE technicians
        ADD CONSTRAINT fk_technician_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_technician_user_fk_stmt FROM @add_crm_technician_user_fk;
EXECUTE crm_technician_user_fk_stmt;
DEALLOCATE PREPARE crm_technician_user_fk_stmt;

SET @add_crm_dealer_user_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'dealers'
          AND constraint_name = 'fk_dealer_user'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE dealers
        ADD CONSTRAINT fk_dealer_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_dealer_user_fk_stmt FROM @add_crm_dealer_user_fk;
EXECUTE crm_dealer_user_fk_stmt;
DEALLOCATE PREPARE crm_dealer_user_fk_stmt;

SET @add_crm_closure_installation_user_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_lead_closure_forms'
          AND constraint_name = 'fk_crm_closure_installation_user'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE crm_lead_closure_forms
        ADD CONSTRAINT fk_crm_closure_installation_user
        FOREIGN KEY (installation_person_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_closure_installation_user_fk_stmt FROM @add_crm_closure_installation_user_fk;
EXECUTE crm_closure_installation_user_fk_stmt;
DEALLOCATE PREPARE crm_closure_installation_user_fk_stmt;

SET @add_crm_closure_installation_assigner_fk = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'crm_lead_closure_forms'
          AND constraint_name = 'fk_crm_closure_installation_assigner'
          AND constraint_type = 'FOREIGN KEY'
    ) = 0,
    'ALTER TABLE crm_lead_closure_forms
        ADD CONSTRAINT fk_crm_closure_installation_assigner
        FOREIGN KEY (installation_assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE crm_closure_installation_assigner_fk_stmt FROM @add_crm_closure_installation_assigner_fk;
EXECUTE crm_closure_installation_assigner_fk_stmt;
DEALLOCATE PREPARE crm_closure_installation_assigner_fk_stmt;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'crm_lead_stage.view',
    'crm_lead_stage.add',
    'crm_lead_stage.edit',
    'crm_lead_stage.activate',
    'crm_installations.view',
    'crm_installations.assign'
)
WHERE r.role_name = 'Super Admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
