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

ALTER TABLE attendance_days
    ADD COLUMN IF NOT EXISTS role_name_snapshot VARCHAR(100) NOT NULL DEFAULT '' AFTER username_snapshot;

UPDATE attendance_days d
LEFT JOIN users u ON u.id = d.user_id
LEFT JOIN roles r ON r.id = u.role_id
SET d.role_name_snapshot = COALESCE(NULLIF(r.role_name, ''), NULLIF(u.role, ''), 'Employee')
WHERE d.role_name_snapshot = '';

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

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('attendance.view', 'Attendance Reports View', 'attendance', 'VIEW'),
    ('attendance.settings', 'Attendance Settings Manage', 'attendance', 'SETTINGS'),
    ('attendance.self.view', 'My Attendance View', 'attendance', 'SELF_VIEW')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key = 'attendance.view'
WHERE LOWER(TRIM(r.role_name)) = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key = 'attendance.settings'
WHERE LOWER(TRIM(r.role_name)) = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key = 'attendance.self.view'
WHERE LOWER(TRIM(r.role_name)) IN ('employee', 'tl');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE LOWER(TRIM(r.role_name)) = 'super admin'
  AND p.permission_key LIKE 'attendance.%';
