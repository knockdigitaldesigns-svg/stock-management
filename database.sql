CREATE DATABASE IF NOT EXISTS stock_management;
USE stock_management;

-- =====================================================
-- USERS
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_name VARCHAR(150) DEFAULT NULL,
    mobile_no VARCHAR(20) DEFAULT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    credential_ciphertext TEXT DEFAULT NULL,
    credential_nonce VARCHAR(24) DEFAULT NULL,
    credential_tag VARCHAR(24) DEFAULT NULL,
    role VARCHAR(50) DEFAULT 'super_admin',
    role_id INT DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    active_session_id CHAR(64) DEFAULT NULL,
    active_session_created_at DATETIME DEFAULT NULL,
    active_session_expires_at BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS active_session_id CHAR(64) DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS active_session_created_at DATETIME DEFAULT NULL AFTER active_session_id,
    ADD COLUMN IF NOT EXISTS active_session_expires_at BIGINT UNSIGNED DEFAULT NULL AFTER active_session_created_at,
    ADD COLUMN IF NOT EXISTS credential_ciphertext TEXT DEFAULT NULL AFTER password,
    ADD COLUMN IF NOT EXISTS credential_nonce VARCHAR(24) DEFAULT NULL AFTER credential_ciphertext,
    ADD COLUMN IF NOT EXISTS credential_tag VARCHAR(24) DEFAULT NULL AFTER credential_nonce;

-- =====================================================
-- OTP LOGIN
-- =====================================================
CREATE TABLE IF NOT EXISTS login_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    otp_encrypted TEXT NOT NULL,
    pending_token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_login_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_login_otps_user (user_id),
    INDEX idx_login_otps_pending (pending_token_hash),
    INDEX idx_login_otps_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- EMPLOYEE ATTENDANCE
-- Attendance is tracked only for Employee and TL roles.
-- Workdays are Monday through Saturday; Sunday is excluded.
-- =====================================================
CREATE TABLE IF NOT EXISTS attendance_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    office_start_time TIME NOT NULL,
    office_end_time TIME NOT NULL,
    full_day_hours DECIMAL(4,2) NOT NULL,
    half_day_hours DECIMAL(4,2) NOT NULL,
    inactivity_minutes SMALLINT UNSIGNED NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    updated_by_user_id INT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_settings_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_settings_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    office_start_time TIME NOT NULL,
    office_end_time TIME NOT NULL,
    full_day_hours DECIMAL(4,2) NOT NULL,
    half_day_hours DECIMAL(4,2) NOT NULL,
    inactivity_minutes SMALLINT UNSIGNED NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    changed_by_user_id INT DEFAULT NULL,
    effective_at_utc DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attendance_settings_effective (effective_at_utc, id),
    CONSTRAINT fk_attendance_settings_history_user
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_days (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    attendance_date DATE NOT NULL,
    employee_name_snapshot VARCHAR(150) NOT NULL,
    username_snapshot VARCHAR(50) NOT NULL,
    role_name_snapshot VARCHAR(100) NOT NULL,
    settings_history_id BIGINT UNSIGNED DEFAULT NULL,
    office_start_time TIME NOT NULL,
    office_end_time TIME NOT NULL,
    full_day_hours DECIMAL(4,2) NOT NULL,
    half_day_hours DECIMAL(4,2) NOT NULL,
    inactivity_minutes SMALLINT UNSIGNED NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    total_work_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    attendance_status ENUM('Present', 'Half Day', 'Absent') NOT NULL DEFAULT 'Absent',
    first_login_at_utc DATETIME DEFAULT NULL,
    final_logout_at_utc DATETIME DEFAULT NULL,
    late_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    early_logout_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_user_date (user_id, attendance_date),
    KEY idx_attendance_date_status (attendance_date, attendance_status),
    KEY idx_attendance_name (employee_name_snapshot),
    CONSTRAINT fk_attendance_days_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_attendance_days_settings
        FOREIGN KEY (settings_history_id) REFERENCES attendance_settings_history(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    day_id BIGINT UNSIGNED NOT NULL,
    user_id INT DEFAULT NULL,
    session_id CHAR(64) NOT NULL,
    login_at_utc DATETIME NOT NULL,
    logout_at_utc DATETIME DEFAULT NULL,
    session_duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    logout_type ENUM('Manual', 'Auto', 'System') DEFAULT NULL,
    inactivity_timeout_seconds INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_session_id (session_id),
    KEY idx_attendance_sessions_day (day_id, login_at_utc),
    KEY idx_attendance_sessions_user (user_id),
    CONSTRAINT fk_attendance_sessions_day
        FOREIGN KEY (day_id) REFERENCES attendance_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO attendance_settings
    (id, office_start_time, office_end_time, full_day_hours, half_day_hours, inactivity_minutes, timezone)
VALUES
    (1, '09:30:00', '18:30:00', 8.00, 4.00, 5, 'Asia/Kolkata');

INSERT INTO attendance_settings_history
    (office_start_time, office_end_time, full_day_hours, half_day_hours, inactivity_minutes, timezone, changed_by_user_id, effective_at_utc)
SELECT office_start_time, office_end_time, full_day_hours, half_day_hours, inactivity_minutes, timezone, updated_by_user_id, UTC_TIMESTAMP()
FROM attendance_settings
WHERE id = 1
  AND NOT EXISTS (SELECT 1 FROM attendance_settings_history);

-- =====================================================
-- RBAC TABLES
-- =====================================================
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    is_system_role TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    permission_name VARCHAR(150) NOT NULL,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_permission (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- =====================================================
-- CRM PHASE 1 FOUNDATION
-- Fresh database setup only. This section is additive and is
-- intended for a new schema install. Do not run the full file
-- on an existing database that already contains the operational
-- tables and CRM objects.
-- =====================================================
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
    priority ENUM('Normal','High') NOT NULL DEFAULT 'Normal',
    related_entity_type VARCHAR(50) DEFAULT NULL,
    related_entity_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    event_key VARCHAR(150) DEFAULT NULL,
    related_lead_id INT UNSIGNED DEFAULT NULL,
    related_customer_id INT UNSIGNED DEFAULT NULL,
    related_closure_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_notification_user (user_id, is_read, created_at),
    UNIQUE KEY uq_crm_notification_event (event_key),
    CONSTRAINT fk_crm_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DEVICE ALERT SETTINGS
-- =====================================================
CREATE TABLE IF NOT EXISTS stock_alert_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_type ENUM('dealer', 'technician') NOT NULL,
    owner_id INT NOT NULL,
    asset_type ENUM('device', 'sim', 'both') NOT NULL DEFAULT 'device',
    device_model_id INT DEFAULT NULL,
    sim_type_id INT DEFAULT NULL,
    min_count INT NOT NULL DEFAULT 0,
    minimum_device_count INT NOT NULL DEFAULT 0,
    minimum_sim_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_alert_config (owner_type, owner_id, asset_type, device_model_id, sim_type_id)
);

-- =====================================================
-- MASTER TABLES
-- =====================================================
CREATE TABLE IF NOT EXISTS device_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_type VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sim_validities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    months INT NOT NULL UNIQUE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO sim_validities (months, status) VALUES (3, 'active'), (5, 'active'), (6, 'active'), (12, 'active'), (24, 'active'), (36, 'active')
ON DUPLICATE KEY UPDATE status = VALUES(status);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_date DATE NOT NULL,
    device_model_id INT NOT NULL,
    imei_no VARCHAR(15) NOT NULL UNIQUE,
    notes TEXT NULL,
    status ENUM('available', 'allocated', 'used') DEFAULT 'available',
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_model_id) REFERENCES device_types(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS sims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_date DATE NOT NULL,
    sim_no VARCHAR(13) NOT NULL UNIQUE,
    sim_type VARCHAR(20) DEFAULT NULL,
    sim_validity_id INT DEFAULT NULL,
    notes TEXT NULL,
    status ENUM('available', 'allocated', 'used') DEFAULT 'available',
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sim_validity_id) REFERENCES sim_validities(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS dealers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_name VARCHAR(100) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
    alternate_mobile_no VARCHAR(10) DEFAULT NULL,
    location VARCHAR(255) NOT NULL,
    enrolled_date DATE NOT NULL,
    installation_status ENUM('Onsite', 'Offsite', 'Not Willing') NOT NULL,
    software VARCHAR(50) DEFAULT NULL,
    threshold_amount DECIMAL(10,2) DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    , UNIQUE KEY dealer_mobile_unique (mobile_no)
    , UNIQUE KEY dealer_name_unique (dealer_name)
);

CREATE TABLE IF NOT EXISTS dealer_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    software VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dealer_software (dealer_id, software),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_name VARCHAR(100) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
    alternate_mobile_no VARCHAR(10) DEFAULT NULL,
    location VARCHAR(255) NOT NULL,
    enrolled_date DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    , UNIQUE KEY technician_mobile_unique (mobile_no)
    , UNIQUE KEY technician_name_unique (technician_name)
);

-- =====================================================
-- STOCK TABLES
-- =====================================================
CREATE TABLE IF NOT EXISTS stock_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_type ENUM('dealer', 'technician') NOT NULL,
    owner_id INT NOT NULL,
    device_id INT DEFAULT NULL,
    sim_id INT DEFAULT NULL,
    allocation_type ENUM('ET', 'dealer', 'technician') DEFAULT 'ET',
    allocation_date DATE NOT NULL,
    sim_given_date DATE DEFAULT NULL,
    sim_activation_date DATE DEFAULT NULL,
    sim_validity_id INT DEFAULT NULL,
    sim_expiry_date DATE DEFAULT NULL,
    sim_deactivation_date DATE DEFAULT NULL,
    sim_reactivation_date DATE DEFAULT NULL,
    sim_status ENUM('Available', 'Active', 'Deactive', 'Expired', 'Safe Custody') DEFAULT 'Available',
    device_amount DECIMAL(10,2) DEFAULT 0.00,
    sim_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    pending_amount DECIMAL(10,2) DEFAULT 0.00,
    software VARCHAR(50) DEFAULT NULL,
    payment_status ENUM('Paid', 'Partially Paid', 'Not Paid') DEFAULT 'Not Paid',
    payment_date DATE DEFAULT NULL,
    payment_mode ENUM('ET Gpay', 'ET Phonepe', 'ET Paytm', 'ET Account', '8002 Gpay', '8002 Phonepe', '8002 Paytm', 'Wati Gpay', 'Wati Phonepe', 'Wati Paytm', 'PG Gateway', 'Cash', 'UPI', 'Bank Transfer', 'Card', 'Other') DEFAULT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stock_allocations_owner (owner_type, owner_id),
    INDEX idx_stock_allocations_owner_device (owner_type, owner_id, device_id),
    INDEX idx_stock_allocations_owner_sim (owner_type, owner_id, sim_id),
    INDEX idx_stock_allocations_owner_created (owner_type, owner_id, created_at),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
    FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL,
    FOREIGN KEY (sim_validity_id) REFERENCES sim_validities(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS dealer_sim_allocation_payments (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    total_amount_due DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    amount_pending DECIMAL(10,2) NOT NULL,
    payment_mode VARCHAR(50) DEFAULT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    payment_date DATE DEFAULT NULL,
    payment_status VARCHAR(30) NOT NULL,
    remarks TEXT NULL,
    is_legacy_snapshot TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dealer_sim_payment_request (allocation_id, idempotency_key),
    INDEX idx_dealer_sim_payments_allocation (allocation_id, id),
    CONSTRAINT fk_dealer_sim_payment_allocation FOREIGN KEY (allocation_id) REFERENCES stock_allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dealer_allocation_payment_requests (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL DEFAULT '',
    transaction_id CHAR(6) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dealer_allocation_payment_request (allocation_id, request_key),
    INDEX idx_dealer_allocation_payment_request_key (request_key),
    CONSTRAINT fk_dealer_allocation_payment_request_allocation
        FOREIGN KEY (allocation_id) REFERENCES stock_allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT DEFAULT NULL,
    sim_id INT DEFAULT NULL,
    from_owner_type ENUM('dealer', 'technician') DEFAULT NULL,
    from_owner_id INT DEFAULT NULL,
    to_owner_type ENUM('dealer', 'technician') DEFAULT NULL,
    to_owner_id INT DEFAULT NULL,
    transaction_type ENUM('ALLOCATE', 'USE', 'RETURN') NOT NULL,
    usage_type ENUM('ET', 'TECHNICIAN', 'DEALER') DEFAULT NULL,
    transaction_date DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_transactions_device_owner (device_id, from_owner_type, from_owner_id, transaction_type),
    INDEX idx_stock_transactions_sim_owner (sim_id, from_owner_type, from_owner_id, transaction_type),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
    FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT DEFAULT NULL,
    device_id INT DEFAULT NULL,
    sim_id INT DEFAULT NULL,
    from_owner_type ENUM('dealer', 'technician') NOT NULL,
    from_owner_id INT NOT NULL,
    from_owner_name VARCHAR(150) NOT NULL,
    to_owner_type ENUM('dealer', 'technician') NOT NULL,
    to_owner_id INT NOT NULL,
    to_owner_name VARCHAR(150) NOT NULL,
    transfer_date DATE NOT NULL,
    transferred_by_user_id INT DEFAULT NULL,
    previous_status VARCHAR(50) DEFAULT 'Allocated',
    new_status VARCHAR(50) DEFAULT 'Allocated',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
    FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
);

-- =====================================================
-- PERMISSIONS SEED
-- =====================================================
INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('dashboard.view', 'Dashboard View', 'dashboard', 'VIEW'),
    ('devices.view', 'Device Maintenance View', 'devices', 'VIEW'),
    ('devices.add', 'Device Maintenance Add', 'devices', 'ADD'),
    ('devices.edit', 'Device Maintenance Edit', 'devices', 'EDIT'),
    ('devices.delete', 'Device Maintenance Delete', 'devices', 'DELETE'),
    ('devices.export', 'Device Maintenance Export', 'devices', 'EXPORT'),
    ('sims.view', 'SIM Maintenance View', 'sims', 'VIEW'),
    ('sims.add', 'SIM Maintenance Add', 'sims', 'ADD'),
    ('sims.edit', 'SIM Maintenance Edit', 'sims', 'EDIT'),
    ('sims.delete', 'SIM Maintenance Delete', 'sims', 'DELETE'),
    ('sims.export', 'SIM Maintenance Export', 'sims', 'EXPORT'),
    ('inward_reports.view', 'Inward Reports View', 'inward_reports', 'VIEW'),
    ('inward_reports.export', 'Inward Reports Export', 'inward_reports', 'EXPORT'),
    ('dealers.view', 'Dealer View', 'dealers', 'VIEW'),
    ('dealers.add', 'Dealer Add', 'dealers', 'ADD'),
    ('dealers.edit', 'Dealer Edit', 'dealers', 'EDIT'),
    ('dealers.delete', 'Dealer Delete', 'dealers', 'DELETE'),
    ('dealers.import', 'Dealer Import', 'dealers', 'IMPORT'),
    ('dealers.export', 'Dealer Export', 'dealers', 'EXPORT'),
    ('dealer_sim_activation.view', 'Dealer SIM Activation View', 'dealer_sim_activation', 'VIEW'),
    ('dealer_sim_activation.edit', 'Dealer SIM Activation Edit', 'dealer_sim_activation', 'EDIT'),
    ('dealer_sim_activation.delete', 'Dealer SIM Activation Delete', 'dealer_sim_activation', 'DELETE'),
    ('dealer_sim_activation.import', 'Dealer SIM Activation Import', 'dealer_sim_activation', 'IMPORT'),
    ('technicians.view', 'Technician View', 'technicians', 'VIEW'),
    ('technicians.add', 'Technician Add', 'technicians', 'ADD'),
    ('technicians.edit', 'Technician Edit', 'technicians', 'EDIT'),
    ('technicians.delete', 'Technician Delete', 'technicians', 'DELETE'),
    ('technicians.import', 'Technician Import', 'technicians', 'IMPORT'),
    ('technicians.export', 'Technician Export', 'technicians', 'EXPORT'),
    ('outward_reports.view', 'Outward Reports View', 'outward_reports', 'VIEW'),
    ('outward_reports.export', 'Outward Reports Export', 'outward_reports', 'EXPORT'),
    ('stock.view', 'Stock Management View', 'stock', 'VIEW'),
    ('stock.update', 'Stock Management Update', 'stock', 'UPDATE'),
    ('stock.export', 'Stock Management Export', 'stock', 'EXPORT'),
    ('stock_transfer.view', 'Stock Transfer View', 'stock_transfer', 'VIEW'),
    ('stock_transfer.add', 'Stock Transfer Add', 'stock_transfer', 'ADD'),
    ('customers.view', 'Customer Details View', 'customers', 'VIEW'),
    ('customers.add', 'Customer Details Add', 'customers', 'ADD'),
    ('customers.edit', 'Customer Details Edit', 'customers', 'EDIT'),
    ('customers.delete', 'Customer Details Delete', 'customers', 'DELETE'),
    ('customers.export', 'Customer Details Export', 'customers', 'EXPORT'),
    ('customers.update', 'Customer Details Update', 'customers', 'UPDATE'),
    ('customer_reports.view', 'Customer Reports View', 'customer_reports', 'VIEW'),
    ('customer_renewals.view', 'Customer Renewals View', 'customer_renewals', 'VIEW'),
    ('customer_renewals.edit', 'Customer Renewals Edit', 'customer_renewals', 'EDIT'),
    ('customer_renewals.renew', 'Customer Renewals Renew', 'customer_renewals', 'RENEW'),
    ('customer_renewals.history', 'Customer Renewals History', 'customer_renewals', 'HISTORY'),
    ('history.view', 'History View', 'history', 'VIEW'),
    ('roles.view', 'Roles View', 'roles', 'VIEW'),
    ('roles.add', 'Roles Add', 'roles', 'ADD'),
    ('roles.edit', 'Roles Edit', 'roles', 'EDIT'),
    ('roles.delete', 'Roles Delete', 'roles', 'DELETE'),
    ('permissions.view', 'Permissions View', 'permissions', 'VIEW'),
    ('permissions.assign', 'Permissions Assign', 'permissions', 'ASSIGN'),
    ('users.view', 'Users View', 'users', 'VIEW'),
    ('users.add', 'Users Add', 'users', 'ADD'),
    ('users.edit', 'Users Edit', 'users', 'EDIT'),
    ('users.delete', 'Users Delete', 'users', 'DELETE'),
    ('users.status', 'Users Status', 'users', 'STATUS'),
    ('device_alert.view', 'Device Alert View', 'device_alert', 'VIEW'),
    ('device_alert.add', 'Device Alert Add', 'device_alert', 'ADD'),
    ('device_alert.edit', 'Device Alert Edit', 'device_alert', 'EDIT'),
    ('device_alert.delete', 'Device Alert Delete', 'device_alert', 'DELETE'),
    ('device_types.view', 'Device Types View', 'device_types', 'VIEW'),
    ('device_types.add', 'Device Types Add', 'device_types', 'ADD'),
    ('device_types.edit', 'Device Types Edit', 'device_types', 'EDIT'),
    ('device_types.delete', 'Device Types Delete', 'device_types', 'DELETE'),
    ('sim_validity.view', 'SIM Validity View', 'sim_validity', 'VIEW'),
    ('sim_validity.add', 'SIM Validity Add', 'sim_validity', 'ADD'),
    ('sim_validity.edit', 'SIM Validity Edit', 'sim_validity', 'EDIT'),
    ('sim_validity.delete', 'SIM Validity Delete', 'sim_validity', 'DELETE'),
    ('password.change', 'Change Password', 'password', 'CHANGE')
    ,('platforms.view', 'Platform View', 'platforms', 'VIEW')
    ,('platforms.add', 'Platform Add', 'platforms', 'ADD')
    ,('platforms.edit', 'Platform Edit', 'platforms', 'EDIT')
    ,('platforms.delete', 'Platform Delete', 'platforms', 'DELETE')
    ,('vehicle_types.view', 'Vehicle Types View', 'vehicle_types', 'VIEW')
    ,('vehicle_types.add', 'Vehicle Types Add', 'vehicle_types', 'ADD')
    ,('vehicle_types.edit', 'Vehicle Types Edit', 'vehicle_types', 'EDIT')
    ,('vehicle_types.delete', 'Vehicle Types Delete', 'vehicle_types', 'DELETE')
    ,('lead_closures.view', 'Lead Closure View', 'lead_closures', 'VIEW')
    ,('lead_closures.add', 'Lead Closure Add', 'lead_closures', 'ADD')
    ,('lead_closures.edit', 'Lead Closure Edit', 'lead_closures', 'EDIT')
    ,('lead_closures.delete', 'Lead Closure Delete', 'lead_closures', 'DELETE')
    ,('sale_amounts.view', 'Sale Amount View', 'sale_amounts', 'VIEW')
    ,('sale_amounts.add', 'Sale Amount Add', 'sale_amounts', 'ADD')
    ,('sale_amounts.edit', 'Sale Amount Edit', 'sale_amounts', 'EDIT')
    ,('sale_amounts.delete', 'Sale Amount Delete', 'sale_amounts', 'DELETE')
    ,('crm_pipelines.view', 'CRM Pipelines View', 'crm_pipelines', 'VIEW')
    ,('crm_pipelines.add', 'CRM Pipelines Add', 'crm_pipelines', 'ADD')
    ,('crm_pipelines.edit', 'CRM Pipelines Edit', 'crm_pipelines', 'EDIT')
    ,('crm_pipelines.delete', 'CRM Pipelines Delete', 'crm_pipelines', 'DELETE')
    ,('crm_campaigns.view', 'CRM Campaigns View', 'crm_campaigns', 'VIEW')
    ,('crm_campaigns.add', 'CRM Campaigns Add', 'crm_campaigns', 'ADD')
    ,('crm_campaigns.edit', 'CRM Campaigns Edit', 'crm_campaigns', 'EDIT')
    ,('crm_campaigns.delete', 'CRM Campaigns Delete', 'crm_campaigns', 'DELETE')
    ,('crm_lead_status.view', 'CRM Lead Status View', 'crm_lead_status', 'VIEW')
    ,('crm_lead_status.add', 'CRM Lead Status Add', 'crm_lead_status', 'ADD')
    ,('crm_lead_status.edit', 'CRM Lead Status Edit', 'crm_lead_status', 'EDIT')
    ,('crm_lead_status.activate', 'CRM Lead Status Activate', 'crm_lead_status', 'ACTIVATE')
    ,('crm_lead_stage.view', 'CRM Lead Stage View', 'crm_lead_stage', 'VIEW')
    ,('crm_lead_stage.add', 'CRM Lead Stage Add', 'crm_lead_stage', 'ADD')
    ,('crm_lead_stage.edit', 'CRM Lead Stage Edit', 'crm_lead_stage', 'EDIT')
    ,('crm_lead_stage.activate', 'CRM Lead Stage Activate', 'crm_lead_stage', 'ACTIVATE')
    ,('crm_leads.view', 'CRM Leads View', 'crm_leads', 'VIEW')
    ,('crm_leads.add', 'CRM Leads Add', 'crm_leads', 'ADD')
    ,('crm_leads.edit', 'CRM Leads Edit', 'crm_leads', 'EDIT')
    ,('crm_leads.delete', 'CRM Leads Delete', 'crm_leads', 'DELETE')
    ,('crm_leads.assign', 'CRM Leads Assign', 'crm_leads', 'ASSIGN')
    ,('crm_leads.my_view', 'CRM My Leads View', 'crm_leads', 'MY_VIEW')
    ,('crm_leads.transfer', 'CRM Lead Transfer', 'crm_leads', 'TRANSFER')
    ,('crm_leads.transfer_bulk', 'CRM Bulk Lead Transfer', 'crm_leads', 'BULK_TRANSFER')
    ,('crm_leads.transfer_history', 'CRM Lead Transfer History View', 'crm_leads', 'TRANSFER_HISTORY')
    ,('crm_followups.view', 'CRM Follow-ups View', 'crm_followups', 'VIEW')
    ,('crm_followups.add', 'CRM Follow-ups Add', 'crm_followups', 'ADD')
    ,('crm_followups.edit', 'CRM Follow-ups Edit', 'crm_followups', 'EDIT')
    ,('crm_closures.view', 'CRM Closures View', 'crm_closures', 'VIEW')
    ,('crm_closures.add', 'CRM Closures Add', 'crm_closures', 'ADD')
    ,('crm_closures.edit', 'CRM Closures Edit', 'crm_closures', 'EDIT')
    ,('crm_closures.approve', 'CRM Closures Approve', 'crm_closures', 'APPROVE')
    ,('crm_installations.view', 'CRM Installations View', 'crm_installations', 'VIEW')
    ,('crm_installations.assign', 'CRM Installations Assign', 'crm_installations', 'ASSIGN')
    ,('crm_installations.manage', 'CRM Installations Manage', 'crm_installations', 'MANAGE')
    ,('crm_notifications.view', 'CRM Notifications View', 'crm_notifications', 'VIEW')
    ,('attendance.view', 'Attendance Reports View', 'attendance', 'VIEW')
    ,('attendance.settings', 'Attendance Settings Manage', 'attendance', 'SETTINGS')
    ,('attendance.self.view', 'My Attendance View', 'attendance', 'SELF_VIEW')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN ('attendance.view', 'attendance.settings')
WHERE LOWER(TRIM(r.role_name)) = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key = 'attendance.self.view'
WHERE LOWER(TRIM(r.role_name)) IN ('employee', 'tl');

-- =====================================================
-- SUPER ADMIN ROLE + ASSIGN ALL PERMISSIONS
-- =====================================================
INSERT INTO roles (role_name, description, status, is_system_role)
VALUES ('Super Admin', 'System-level administrator with unrestricted access.', 'active', 1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    status = VALUES(status),
    is_system_role = VALUES(is_system_role);

SET @super_role_id = (SELECT id FROM roles WHERE role_name = 'Super Admin' LIMIT 1);

INSERT INTO role_permissions (role_id, permission_id)
SELECT @super_role_id, id
FROM permissions
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- DEFAULT SUPER ADMIN USER
-- =====================================================
-- Password: admin123
-- Hash generated with PHP password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (username, password, employee_name, mobile_no, role, role_id, status)
VALUES (
    'admin',
    '$2y$10$Ngqd.WXfub7yJru9P.ib9.wPb/upsRYLibyc9YvvZkXBn1y09YODi',
    'Admin User',
    '9876543210',
    'super_admin',
    @super_role_id,
    'active'
)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    employee_name = VALUES(employee_name),
    mobile_no = VALUES(mobile_no),
    role = VALUES(role),
    role_id = VALUES(role_id),
    status = VALUES(status);

-- =====================================================
-- SAMPLE MASTER DATA
-- =====================================================
INSERT INTO device_types (device_type)
VALUES
    ('Basic'),
    ('Voice'),
    ('AC'),
    ('Dashcam'),
    ('S20'),
    ('S15'),
    ('G60'),
    ('OBD'),
    ('AIS140'),
    ('12V Relay'),
    ('24V Relay')
ON DUPLICATE KEY UPDATE
    device_type = VALUES(device_type);

-- platform add

CREATE TABLE IF NOT EXISTS platforms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_name VARCHAR(100) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_platform_name (platform_name)
);

INSERT INTO platforms (platform_name, status)
VALUES
('Tracoo', 'Active'),
('Eagle India', 'Active'),
('Navilap', 'Active'),
('Oneqlick', 'Active'),
('Trackzee', 'Active'),
('Gps Monitor', 'Active');

-- vehicle types 

CREATE TABLE IF NOT EXISTS vehicle_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicle_type (vehicle_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO vehicle_types (vehicle_type)
VALUES
('Car'),
('Bike'),
('Truck'),
('Bus'),
('Van'),
('Auto'),
('Lorry');
ALTER TABLE vehicle_types
ADD COLUMN status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active'
AFTER vehicle_type;

-- lead closure 

CREATE TABLE IF NOT EXISTS lead_closures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_closure_name VARCHAR(100) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
    location VARCHAR(150) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_lead_closure_mobile (mobile_no),
    UNIQUE KEY uq_lead_closure_name (lead_closure_name)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions
(permission_key, permission_name, module, action)
VALUES
('lead_closures.view', 'View Lead Closures', 'Lead Closures', 'view'),
('lead_closures.add', 'Add Lead Closure', 'Lead Closures', 'add'),
('lead_closures.edit', 'Edit Lead Closure', 'Lead Closures', 'edit'),
('lead_closures.delete', 'Delete Lead Closure', 'Lead Closures', 'delete');

-- sale amount

CREATE TABLE IF NOT EXISTS sale_amounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_amount DECIMAL(12,2) NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_amount (sale_amount)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions
(permission_key, permission_name, module, action)
VALUES
('sale_amounts.view', 'View Sale Amounts', 'Sale Amounts', 'view'),
('sale_amounts.add', 'Add Sale Amount', 'Sale Amounts', 'add'),
('sale_amounts.edit', 'Edit Sale Amount', 'Sale Amounts', 'edit'),
('sale_amounts.delete', 'Delete Sale Amount', 'Sale Amounts', 'delete');

-- =========================================================
-- CUSTOMER MANAGEMENT
-- FINAL DATABASE STRUCTURE
-- =========================================================


-- =========================================================
-- 1. CUSTOMERS
-- =========================================================

CREATE TABLE IF NOT EXISTS customers (
    id INT NOT NULL AUTO_INCREMENT,

    platform_id INT NOT NULL,

    username VARCHAR(100) NOT NULL,
    primary_mobile_no VARCHAR(15) NOT NULL,
    secondary_mobile_no VARCHAR(15) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,

    location VARCHAR(150) NOT NULL,
    pincode VARCHAR(10) NOT NULL,

    status ENUM('Active', 'Inactive')
        NOT NULL DEFAULT 'Active',

    verification_status ENUM('Pending', 'Approved', 'Rejected')
        NOT NULL DEFAULT 'Approved',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_platform_username (platform_id, username),
    INDEX idx_customer_platform (platform_id),
    INDEX idx_customer_verification_status (verification_status, id),

    CONSTRAINT fk_customer_platform
        FOREIGN KEY (platform_id)
        REFERENCES platforms(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 2. CUSTOMER VEHICLE / DEVICE / SIM
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_vehicle_details (
    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,

    vehicle_no VARCHAR(30) NOT NULL,
    vehicle_type_id INT NOT NULL,

    device_id INT DEFAULT NULL,
    device_model_id INT DEFAULT NULL,

    imei_no VARCHAR(15) NOT NULL,

    sim_id_1 INT DEFAULT NULL,
    sim_no_1 VARCHAR(13) NOT NULL,

    sim_id_2 INT DEFAULT NULL,
    sim_no_2 VARCHAR(13) DEFAULT NULL,

    validity_months INT NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_vehicle_no (vehicle_no),
    UNIQUE KEY uq_customer_imei (imei_no),

    UNIQUE KEY uq_customer_device_id (device_id),

    UNIQUE KEY uq_customer_sim_id_1 (sim_id_1),
    UNIQUE KEY uq_customer_sim_id_2 (sim_id_2),

    UNIQUE KEY uq_customer_sim_no_1 (sim_no_1),
    UNIQUE KEY uq_customer_sim_no_2 (sim_no_2),

    INDEX idx_customer_vehicle_customer (customer_id),
    INDEX idx_customer_vehicle_type (vehicle_type_id),
    INDEX idx_customer_vehicle_device (device_id),
    INDEX idx_customer_vehicle_model (device_model_id),
    INDEX idx_customer_vehicle_sim1 (sim_id_1),
    INDEX idx_customer_vehicle_sim2 (sim_id_2),

    CONSTRAINT fk_customer_vehicle_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 3. CUSTOMER INSTALLATION
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_installations (
    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,

    installation_person_type VARCHAR(50) NOT NULL,

    installation_person_id INT DEFAULT NULL,

    lead_closure_id INT UNSIGNED DEFAULT NULL,

    installation_date DATE NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_installation_vehicle
        (vehicle_id),

    INDEX idx_customer_installation_customer
        (customer_id),

    INDEX idx_installation_person_type
        (installation_person_type),

    INDEX idx_installation_person_id
        (installation_person_id),

    INDEX idx_installation_lead_closure
        (lead_closure_id),

    CONSTRAINT fk_installation_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_installation_lead_closure
        FOREIGN KEY (lead_closure_id)
        REFERENCES lead_closures(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CRM INSTALLATION DEVICE PROGRESS
-- =========================================================

CREATE TABLE IF NOT EXISTS crm_closure_installation_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    closure_id INT UNSIGNED NOT NULL,
    device_sequence SMALLINT UNSIGNED NOT NULL,
    installation_status ENUM('Pending', 'Completed') NOT NULL DEFAULT 'Pending',
    vehicle_number VARCHAR(30) DEFAULT NULL,
    vehicle_type_id INT UNSIGNED DEFAULT NULL,
    device_model_id INT DEFAULT NULL,
    sale_amount DECIMAL(12,2) DEFAULT NULL,
    device_id INT DEFAULT NULL,
    imei_no VARCHAR(15) DEFAULT NULL,
    sim_id_1 INT DEFAULT NULL,
    sim_no_1 VARCHAR(13) DEFAULT NULL,
    sim_id_2 INT DEFAULT NULL,
    sim_no_2 VARCHAR(13) DEFAULT NULL,
    validity_months INT DEFAULT NULL,
    installation_datetime DATETIME DEFAULT NULL,
    installation_remarks TEXT DEFAULT NULL,
    completion_evidence TEXT DEFAULT NULL,
    installation_verified TINYINT(1) NOT NULL DEFAULT 0,
    customer_vehicle_id INT DEFAULT NULL,
    customer_installation_id INT DEFAULT NULL,
    completed_by_user_id INT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    verification_submission_id BIGINT UNSIGNED DEFAULT NULL,
    verification_status ENUM(
        'Not Submitted',
        'Pending Verification',
        'Rejected',
        'Approved'
    ) NOT NULL DEFAULT 'Not Submitted',
    verification_rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_installation_device_sequence (closure_id, device_sequence),
    UNIQUE KEY uq_crm_installation_device_vehicle (closure_id, vehicle_number),
    UNIQUE KEY uq_crm_installation_device_id (device_id),
    UNIQUE KEY uq_crm_installation_device_imei (imei_no),
    UNIQUE KEY uq_crm_installation_device_sim_1 (sim_id_1),
    UNIQUE KEY uq_crm_installation_device_sim_2 (sim_id_2),
    UNIQUE KEY uq_crm_installation_device_customer_vehicle (customer_vehicle_id),
    UNIQUE KEY uq_crm_installation_device_customer_installation (customer_installation_id),
    UNIQUE KEY uq_crm_installation_verification_submission (verification_submission_id),
    KEY idx_crm_installation_device_status (closure_id, installation_status),
    KEY idx_crm_installation_verification_status (closure_id, verification_status),
    CONSTRAINT fk_crm_installation_device_closure
        FOREIGN KEY (closure_id) REFERENCES crm_lead_closure_forms(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_crm_installation_device_vehicle_type
        FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_model
        FOREIGN KEY (device_model_id) REFERENCES device_types(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_inventory
        FOREIGN KEY (device_id) REFERENCES devices(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_sim_1
        FOREIGN KEY (sim_id_1) REFERENCES sims(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_sim_2
        FOREIGN KEY (sim_id_2) REFERENCES sims(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_crm_installation_device_customer_vehicle
        FOREIGN KEY (customer_vehicle_id) REFERENCES customer_vehicle_details(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_crm_installation_device_customer_installation
        FOREIGN KEY (customer_installation_id) REFERENCES customer_installations(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_crm_installation_device_completer
        FOREIGN KEY (completed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 4. CUSTOMER PAYMENT
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_payments (
    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,

    total_sale_amount DECIMAL(12,2) NOT NULL,

    transaction_id CHAR(6) DEFAULT NULL,

    payment_mode VARCHAR(50) DEFAULT NULL,

    device_charge DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    software_charge DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    technician_charge DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    sim_charge DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    courier_charge DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    total_amount DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    amount_paid DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    amount_pending DECIMAL(12,2)
        NOT NULL DEFAULT 0.00,

    payment_status ENUM(
        'Paid',
        'Not Paid',
        'Partially Paid',
        'Pending'
    ) NOT NULL DEFAULT 'Pending',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_payment_vehicle
        (vehicle_id),

    INDEX idx_customer_payment_customer
        (customer_id),

    INDEX idx_customer_payment_status
        (payment_status),

    CONSTRAINT fk_customer_payment_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_payment_transactions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    payment_id INT NOT NULL,
    customer_id INT NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode VARCHAR(50) NOT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    payment_date DATE NOT NULL,
    payment_status VARCHAR(30) NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_payment_transaction_request (request_key),
    INDEX idx_customer_payment_transactions_payment (payment_id, id),
    INDEX idx_customer_payment_transactions_customer (customer_id, id),
    INDEX idx_customer_payment_transactions_transaction_id (transaction_id),
    CONSTRAINT fk_customer_payment_transaction_payment
        FOREIGN KEY (payment_id) REFERENCES customer_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_payment_transaction_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 5. CUSTOMER CASH COLLECTIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_cash_collections (
    id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    payment_id INT NOT NULL,
    installation_id INT NOT NULL,
    recipient_type ENUM('Technician', 'Dealer', 'ET') NOT NULL,
    recipient_id INT DEFAULT NULL,
    amount_collected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_remitted DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    settlement_status ENUM('Pending', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Pending',
    settlement_date DATE DEFAULT NULL,
    payment_mode VARCHAR(50) DEFAULT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_cash_collection_payment (customer_id, payment_id),
    INDEX idx_cash_collection_recipient (recipient_type, recipient_id),
    INDEX idx_cash_collection_customer (customer_id),
    CONSTRAINT fk_cash_collection_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_collection_payment FOREIGN KEY (payment_id) REFERENCES customer_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_collection_installation FOREIGN KEY (installation_id) REFERENCES customer_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_collection_settlements (
    id BIGINT NOT NULL AUTO_INCREMENT,
    recipient_type ENUM('Technician', 'Dealer') NOT NULL,
    recipient_id INT NOT NULL,
    settlement_amount DECIMAL(12,2) NOT NULL,
    outstanding_before DECIMAL(12,2) NOT NULL,
    outstanding_after DECIMAL(12,2) NOT NULL,
    settlement_date DATE NOT NULL,
    payment_mode VARCHAR(50) NOT NULL,
    transaction_id CHAR(6) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    settled_by_user_id INT DEFAULT NULL,
    settled_by_name VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_cash_settlement_recipient (recipient_type, recipient_id),
    INDEX idx_cash_settlement_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_id_registry (
    transaction_id CHAR(6) NOT NULL PRIMARY KEY,
    source_type VARCHAR(64) NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transaction_registry_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_collection_settlement_allocations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    settlement_id BIGINT NOT NULL,
    collection_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount_allocated DECIMAL(12,2) NOT NULL,
    outstanding_before DECIMAL(12,2) NOT NULL,
    outstanding_after DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_settlement_collection (settlement_id, collection_id),
    INDEX idx_cash_allocation_collection (collection_id),
    CONSTRAINT fk_cash_allocation_settlement FOREIGN KEY (settlement_id) REFERENCES cash_collection_settlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_allocation_collection FOREIGN KEY (collection_id) REFERENCES customer_cash_collections(id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_allocation_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 5. CUSTOMER RENEWAL
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_renewals (
    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,

    installation_date DATE NOT NULL,

    next_renewal_date DATE DEFAULT NULL,

    validity_months INT NOT NULL,

    sim_status ENUM(
        'Active',
        'Deactive',
        'Expired',
        'Safe Custody'
    ) NOT NULL DEFAULT 'Active',

    expired_to_safe_days INT DEFAULT NULL,

    safe_to_deactive_days INT DEFAULT NULL,

    last_renewed_date DATE DEFAULT NULL,

    -- Date when the SIM entered Safe Custody (auto or manual).
    -- Used to calculate the Safe Custody -> Deactive transition:
    --   deactive_date = safe_custody_date + safe_to_deactive_days days
    -- Cleared (set to NULL) when SIM is Renewed or Reactivated.
    safe_custody_date DATE DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_renewal_vehicle
        (vehicle_id),

    INDEX idx_customer_renewal_customer (customer_id),

    INDEX idx_customer_next_renewal
        (next_renewal_date),

    INDEX idx_customer_sim_status
        (sim_status),

    CONSTRAINT fk_customer_renewal_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_customer_renewal_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES customer_vehicle_details(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 6. GLOBAL APPLICATION HISTORY
-- =========================================================

CREATE TABLE IF NOT EXISTS history (
    id BIGINT NOT NULL AUTO_INCREMENT,

    customer_id INT DEFAULT NULL,

    module VARCHAR(100) NOT NULL,

    action VARCHAR(50) NOT NULL,

    field_changed VARCHAR(150) DEFAULT NULL,

    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,

    changed_by_user_id INT DEFAULT NULL,

    changed_by_name VARCHAR(150) DEFAULT NULL,

    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_history_customer
        (customer_id),

    INDEX idx_history_action
        (action),

    INDEX idx_history_changed_at
        (changed_at)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 7. SUPPORT
-- =========================================================

CREATE TABLE IF NOT EXISTS support_questions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_support_questions_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id VARCHAR(30) DEFAULT NULL UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,
    issue TEXT NOT NULL,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
    assigned_to_user_id INT DEFAULT NULL,
    status ENUM('Open', 'Assigned', 'In Progress', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open',
    resolution_notes TEXT DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    closed_by_user_id INT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_support_ticket_customer (customer_id),
    INDEX idx_support_ticket_assigned (assigned_to_user_id),
    INDEX idx_support_ticket_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 7. RENEWAL SETTINGS
-- =========================================================

CREATE TABLE IF NOT EXISTS renewal_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expired_to_safe_days INT NOT NULL DEFAULT 10,
    safe_to_deactive_days INT NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO renewal_settings (id, expired_to_safe_days, safe_to_deactive_days) VALUES (1, 10, 10);