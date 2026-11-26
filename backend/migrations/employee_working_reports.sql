CREATE TABLE IF NOT EXISTS employee_working_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    employee_code VARCHAR(24) NOT NULL,
    employee_name VARCHAR(150) NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    timezone_name VARCHAR(64) NOT NULL,
    status ENUM('queued', 'processing', 'generated', 'failed') NOT NULL DEFAULT 'queued',
    file_path VARCHAR(255) DEFAULT NULL,
    generated_at DATETIME DEFAULT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_details TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_report_period (employee_id, period_start, period_end),
    KEY idx_employee_report_period (period_end, employee_id),
    KEY idx_employee_report_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_working_report_schedule (
    id TINYINT UNSIGNED NOT NULL,
    last_period_end DATETIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO employee_working_report_schedule (id, last_period_end)
VALUES (1, NULL);

INSERT INTO permissions (permission_key, permission_name, module, action)
VALUES
    ('employee_working_reports.view', 'View Employee Working Reports', 'employee_working_reports', 'VIEW'),
    ('employee_working_reports.own.view', 'View Own Employee Working Reports', 'employee_working_reports', 'OWN_VIEW'),
    ('employee_working_reports.download', 'Download Employee Working Reports', 'employee_working_reports', 'DOWNLOAD')
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);
