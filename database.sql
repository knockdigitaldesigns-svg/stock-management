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
    sim_status ENUM('Available', 'Active', 'Deactive', 'Expired', 'Safe Custody') DEFAULT 'Available',
    device_amount DECIMAL(10,2) DEFAULT 0.00,
    sim_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    pending_amount DECIMAL(10,2) DEFAULT 0.00,
    software VARCHAR(50) DEFAULT NULL,
    payment_status ENUM('Paid', 'Partially Paid', 'Not Paid') DEFAULT 'Not Paid',
    payment_mode ENUM('Cash', 'UPI', 'Bank Transfer', 'Card', 'Other') DEFAULT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
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

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_customer_platform_username (platform_id, username),
    INDEX idx_customer_platform (platform_id),

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

    installation_person_type
        ENUM('Technician', 'Dealer')
        NOT NULL,

    installation_person_id INT NOT NULL,

    lead_closure_id INT UNSIGNED NOT NULL,

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
-- 4. CUSTOMER PAYMENT
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_payments (
    id INT NOT NULL AUTO_INCREMENT,

    customer_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,

    total_sale_amount DECIMAL(12,2) NOT NULL,

    transaction_id VARCHAR(6) DEFAULT NULL,

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


-- =========================================================
-- 5. CUSTOMER CASH COLLECTIONS
-- =========================================================

CREATE TABLE IF NOT EXISTS customer_cash_collections (
    id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    payment_id INT NOT NULL,
    installation_id INT NOT NULL,
    recipient_type ENUM('Technician', 'Dealer') NOT NULL,
    recipient_id INT NOT NULL,
    amount_collected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_remitted DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    settlement_status ENUM('Pending', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Pending',
    settlement_date DATE DEFAULT NULL,
    payment_mode VARCHAR(50) DEFAULT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
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
    transaction_id VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    settled_by_user_id INT DEFAULT NULL,
    settled_by_name VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_cash_settlement_recipient (recipient_type, recipient_id),
    INDEX idx_cash_settlement_created_at (created_at)
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

    UNIQUE KEY uq_customer_renewal_customer
        (customer_id),

    INDEX idx_customer_next_renewal
        (next_renewal_date),

    INDEX idx_customer_sim_status
        (sim_status),

    CONSTRAINT fk_customer_renewal_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
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