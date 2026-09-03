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
    role VARCHAR(50) DEFAULT 'super_admin',
    role_id INT DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
-- DEVICE ALERT SETTINGS
-- =====================================================
CREATE TABLE IF NOT EXISTS stock_alert_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_type ENUM('dealer', 'technician') NOT NULL,
    owner_id INT NOT NULL,
    minimum_device_count INT NOT NULL DEFAULT 0,
    minimum_sim_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_stock_alert_owner (owner_type, owner_id)
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

INSERT INTO sim_validities (months, status) VALUES (5, 'active'), (12, 'active'), (24, 'active'), (36, 'active')
ON DUPLICATE KEY UPDATE status = VALUES(status);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_date DATE NOT NULL,
    device_model_id INT NOT NULL,
    imei_no VARCHAR(15) NOT NULL UNIQUE,
    notes TEXT NULL,
    status ENUM('available', 'allocated', 'used') DEFAULT 'available',
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sim_validity_id) REFERENCES sim_validities(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS dealers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_name VARCHAR(100) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
    location VARCHAR(255) NOT NULL,
    enrolled_date DATE NOT NULL,
    installation_status ENUM('Onsite', 'Offsite', 'Not Willing') NOT NULL,
    software VARCHAR(50) DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    , UNIQUE KEY dealer_mobile_unique (mobile_no)
    , UNIQUE KEY dealer_name_unique (dealer_name)
);

CREATE TABLE IF NOT EXISTS technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_name VARCHAR(100) NOT NULL,
    mobile_no VARCHAR(15) NOT NULL,
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
    device_amount DECIMAL(10,2) DEFAULT 0.00,
    sim_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    pending_amount DECIMAL(10,2) DEFAULT 0.00,
    software VARCHAR(50) DEFAULT NULL,
    payment_status ENUM('Paid', 'Partially Paid', 'Not Paid') DEFAULT 'Not Paid',
    payment_mode ENUM('Cash', 'UPI', 'Bank Transfer', 'Card', 'Other') DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
    FOREIGN KEY (sim_id) REFERENCES sims(id) ON DELETE SET NULL
);

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
    ('dealers.export', 'Dealer Export', 'dealers', 'EXPORT'),
    ('technicians.view', 'Technician View', 'technicians', 'VIEW'),
    ('technicians.add', 'Technician Add', 'technicians', 'ADD'),
    ('technicians.edit', 'Technician Edit', 'technicians', 'EDIT'),
    ('technicians.delete', 'Technician Delete', 'technicians', 'DELETE'),
    ('technicians.export', 'Technician Export', 'technicians', 'EXPORT'),
    ('outward_reports.view', 'Outward Reports View', 'outward_reports', 'VIEW'),
    ('outward_reports.export', 'Outward Reports Export', 'outward_reports', 'EXPORT'),
    ('stock.view', 'Stock Management View', 'stock', 'VIEW'),
    ('stock.update', 'Stock Management Update', 'stock', 'UPDATE'),
    ('stock.export', 'Stock Management Export', 'stock', 'EXPORT'),
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
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    module = VALUES(module),
    action = VALUES(action);

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
