import { useState, useEffect } from 'react';
import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom';

import {
    LayoutDashboard,
    Box,
    Server,
    Users,
    Wrench,
    FileText,
    LogOut,
    ChevronDown,
    ChevronRight,
    PackageSearch,
    Shield,
    UserPlus,
    Bell,
    Lock,
    Settings,
    Cpu,
    Car,
    UserCheck,
    IndianRupee,RefreshCw,
    SlidersHorizontal,
    Clock,
    History as HistoryIcon,
    LifeBuoy,
    Menu,
    X
} from 'lucide-react';

import { useAuth } from '../../context/AuthContext';
import './AdminLayout.css';

const AdminLayout = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { user, hasPermission, logout } = useAuth();

    const [sidebarOpen, setSidebarOpen] = useState(false);

    const [inwardOpen, setInwardOpen] = useState(false);
    const [outwardOpen, setOutwardOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [customerOpen, setCustomerOpen] = useState(false);
    const [masterSettingsOpen, setMasterSettingsOpen] = useState(false);

    useEffect(() => {
        setSidebarOpen(false);
        if (location.pathname.startsWith('/customer-management')) {
            setCustomerOpen(true);
        }
        if (location.pathname.startsWith('/inward')) {
            setInwardOpen(true);
        }
        if (location.pathname.startsWith('/outward')) {
            setOutwardOpen(true);
        }
        if (
            location.pathname.includes('/settings') ||
            location.pathname.includes('/platforms') ||
            location.pathname.includes('/device-types') ||
            location.pathname.includes('/vehicle-types') ||
            location.pathname.includes('/lead-closures') ||
            location.pathname.includes('/sale-amounts') ||
            location.pathname.includes('/sim-validities') ||
            location.pathname.includes('/roles') ||
            location.pathname.includes('/users') ||
            location.pathname.includes('/change-password')
        ) {
            setSettingsOpen(true);
            if (
                location.pathname.includes('/platforms') ||
                location.pathname.includes('/device-types') ||
                location.pathname.includes('/vehicle-types') ||
                location.pathname.includes('/lead-closures') ||
                location.pathname.includes('/sale-amounts') ||
                location.pathname.includes('/sim-validities')
            ) {
                setMasterSettingsOpen(true);
            }
        }
    }, [location.pathname]);

    const handleLogout = () => {
        logout();
        navigate('/login');
    };

    // =========================
    // MAIN PERMISSIONS
    // =========================

    const canViewDashboard =
        hasPermission('dashboard.view');

    const canViewHistory =
        hasPermission('history.view');

    const canViewSupport =
        hasPermission('support.view');

    const canViewDeviceMaintenance =
        hasPermission('devices.view');

    const canViewSimMaintenance =
        hasPermission('sims.view');

    const canViewInwardReports =
        hasPermission('inward_reports.view');

    const canViewDealer =
        hasPermission('dealers.view');

    const canViewTechnician =
        hasPermission('technicians.view');

    const canViewOutwardReports =
        hasPermission('outward_reports.view');

    const canViewStock =
        hasPermission('stock.view');

    const canViewStockTransfer =
        hasPermission('stock_transfer.view');

    const canViewDeviceAlert =
        hasPermission('device_alert.view');

    // =========================
    // MASTER SETTINGS
    // =========================

    const canViewPlatform =
        hasPermission('platforms.view');

    const canViewDeviceTypes =
        hasPermission('device_types.view');

    const canViewVehicleTypes =
        hasPermission('vehicle_types.view');

    const canViewLeadClosures =
        hasPermission('lead_closures.view');

    const canViewSaleAmounts =
        hasPermission('sale_amounts.view');

    const canViewSimValidity =
        hasPermission('sim_validity.view');
        
const canViewCustomerDetails =
    hasPermission('customers.view');

const canViewCustomerReports =
    hasPermission('customer_reports.view');

const canViewCustomerRenewals =
    hasPermission('customer_renewals.view');

const canViewSimLifecycle =
    hasPermission('sim_lifecycle.view');

const showCustomerManagement =
    canViewCustomerDetails ||
    canViewCustomerReports ||
    canViewCustomerRenewals ||
    canViewSimLifecycle;
    // =========================
    // SETTINGS
    // =========================

    const canViewRoles =
        hasPermission('roles.view');

    const canViewUsers =
        hasPermission('users.view');

    const canChangePassword =
        hasPermission('password.change');

    // Master Settings visibility
    const showMasterSettings =
        canViewPlatform ||
        canViewDeviceTypes ||
        canViewVehicleTypes ||
        canViewLeadClosures ||
        canViewSaleAmounts ||
        canViewSimValidity;

    // Main Settings visibility
    const showSettings =
        showMasterSettings ||
        canViewRoles ||
        canViewUsers ||
        canChangePassword;

    return (
        <div className="app-container">

            {/* =========================================
                SIDEBAR
            ========================================= */}
            <div className={`sidebar-backdrop ${sidebarOpen ? 'active' : ''}`} onClick={() => setSidebarOpen(false)} />
            <aside className={`sidebar ${sidebarOpen ? 'open' : ''}`}>

                {/* Sidebar Header */}
                <div className="sidebar-header">
                    <h2>EagleTrazer</h2>
                </div>

                <nav className="sidebar-nav">

                    {/* =========================================
                        DASHBOARD
                    ========================================= */}

                    {canViewDashboard && (
                        <NavLink
                            to="/dashboard"
                            className={({ isActive }) =>
                                `nav-item ${isActive ? 'active' : ''}`
                            }
                        >
                            <LayoutDashboard size={20} />
                            <span>Dashboard</span>
                        </NavLink>
                    )}

                    {/* =========================================
                        INWARD
                    ========================================= */}

                    {(canViewDeviceMaintenance ||
                        canViewSimMaintenance ||
                        canViewInwardReports) && (

                        <div className="nav-group">

                            <div
                                className={`nav-item ${
                                    inwardOpen ? 'open' : ''
                                }`}
                                onClick={() =>
                                    setInwardOpen(!inwardOpen)
                                }
                            >
                                <Box size={20} />

                                <span>Inward</span>

                                {inwardOpen ? (
                                    <ChevronDown
                                        size={16}
                                        className="ml-auto"
                                    />
                                ) : (
                                    <ChevronRight
                                        size={16}
                                        className="ml-auto"
                                    />
                                )}
                            </div>

                            {inwardOpen && (
                                <div className="nav-sub">

                                    {canViewDeviceMaintenance && (
                                        <NavLink
                                            to="/inward/devices"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <Server size={18} />
                                            <span>
                                                Device Maintenance
                                            </span>
                                        </NavLink>
                                    )}

                                    {canViewSimMaintenance && (
                                        <NavLink
                                            to="/inward/sims"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <Server size={18} />
                                            <span>
                                                SIM Maintenance
                                            </span>
                                        </NavLink>
                                    )}

                                    {canViewInwardReports && (
                                        <NavLink
                                            to="/inward/reports"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <FileText size={18} />
                                            <span>
                                                Inward Reports
                                            </span>
                                        </NavLink>
                                    )}

                                </div>
                            )}

                        </div>
                    )}

                    {/* =========================================
                        OUTWARD
                    ========================================= */}

                    {(canViewDealer ||
                        canViewTechnician ||
                        canViewOutwardReports) && (

                        <div className="nav-group">

                            <div
                                className={`nav-item ${
                                    outwardOpen ? 'open' : ''
                                }`}
                                onClick={() =>
                                    setOutwardOpen(!outwardOpen)
                                }
                            >
                                <PackageSearch size={20} />

                                <span>Outward</span>

                                {outwardOpen ? (
                                    <ChevronDown
                                        size={16}
                                        className="ml-auto"
                                    />
                                ) : (
                                    <ChevronRight
                                        size={16}
                                        className="ml-auto"
                                    />
                                )}
                            </div>

                            {outwardOpen && (
                                <div className="nav-sub">

                                    {canViewDealer && (
                                        <NavLink
                                            to="/outward/dealers"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <Users size={18} />
                                            <span>Dealer</span>
                                        </NavLink>
                                    )}

                                    {canViewDealer && (
                                        <NavLink
                                            to="/outward/dealer-sim-activation"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <Users size={18} />
                                            <span>Dealer SIM Activation</span>
                                        </NavLink>
                                    )}

                                    {canViewTechnician && (
                                        <NavLink
                                            to="/outward/technicians"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <Wrench size={18} />
                                            <span>Technician</span>
                                        </NavLink>
                                    )}

                                    {canViewOutwardReports && (
                                        <NavLink
                                            to="/outward/reports"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive ? 'active' : ''
                                                }`
                                            }
                                        >
                                            <FileText size={18} />
                                            <span>
                                                Outward Reports
                                            </span>
                                        </NavLink>
                                    )}

                                </div>
                            )}

                        </div>
                    )}

                    {/* =========================================
                        STOCK MANAGEMENT
                    ========================================= */}

                    {canViewStock && (
                        <NavLink
                            to="/stock"
                            className={({ isActive }) =>
                                `nav-item ${
                                    isActive ? 'active' : ''
                                }`
                            }
                        >
                            <PackageSearch size={20} />
                            <span>Stock Management</span>
                        </NavLink>
                    )}

                    {canViewStockTransfer && (
                        <NavLink
                            to="/stock-transfer"
                            className={({ isActive }) =>
                                `nav-item ${isActive ? 'active' : ''}`
                            }
                        >
                            <RefreshCw size={20} />
                            <span>Stock Transfer</span>
                        </NavLink>
                    )}

                    {/* =========================================
                        DEVICE ALERT
                    ========================================= */}

                    {canViewDeviceAlert && (
                        <NavLink
                            to="/device-alert"
                            className={({ isActive }) =>
                                `nav-item ${
                                    isActive ? 'active' : ''
                                }`
                            }
                        >
                            <Bell size={20} />
                            <span>Device Alert</span>
                        </NavLink>
                    )}
                    {showCustomerManagement && (
    <div className="nav-group">

        <div
            className={`nav-item ${customerOpen ? 'open' : ''}`}
            onClick={() =>
                setCustomerOpen(!customerOpen)
            }
        >
            <Users size={20} />

            <span>Customer Management</span>

            {customerOpen
                ? <ChevronDown
                    size={16}
                    className="ml-auto"
                  />
                : <ChevronRight
                    size={16}
                    className="ml-auto"
                  />
            }
        </div>


        {customerOpen && (
            <div className="nav-sub">

                {canViewCustomerDetails && (
                    <NavLink
                        to="/customer-management/details"
                        className={({ isActive }) =>
                            `nav-sub-item ${
                                isActive ? 'active' : ''
                            }`
                        }
                    >
                        <Users size={18} />
                        <span>Customer Details</span>
                    </NavLink>
                )}


                {canViewCustomerReports && (
                    <NavLink
                        to="/customer-management/reports"
                        className={({ isActive }) =>
                            `nav-sub-item ${
                                isActive ? 'active' : ''
                            }`
                        }
                    >
                        <FileText size={18} />
                        <span>Reports</span>
                    </NavLink>
                )}


                {canViewCustomerRenewals && (
                    <NavLink
                        to="/customer-management/renewals"
                        className={({ isActive }) =>
                            `nav-sub-item ${
                                isActive ? 'active' : ''
                            }`
                        }
                    >
                        <RefreshCw size={18} />
                        <span>Renewals</span>
                    </NavLink>
                )}

                {canViewSimLifecycle && (
                    <NavLink
                        to="/customer-management/sim-lifecycle"
                        className={({ isActive }) =>
                            `nav-sub-item ${
                                isActive ? 'active' : ''
                            }`
                        }
                    >
                        <Clock size={18} />
                        <span>SIM Lifecycle</span>
                    </NavLink>
                )}

            </div>
        )}

    </div>
)}

                    {canViewHistory && (
                        <NavLink
                            to="/history"
                            className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                        >
                            <HistoryIcon size={20} />
                            <span>History</span>
                        </NavLink>
                    )}

                    {canViewSupport && (
                        <NavLink
                            to="/support"
                            className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                        >
                            <LifeBuoy size={20} />
                            <span>Support</span>
                        </NavLink>
                    )}

                    {/* =========================================
                        SETTINGS
                    ========================================= */}

                    {showSettings && (
                        <div className="nav-group">

                            {/* Settings Main Button */}
                            <div
                                className={`nav-item ${
                                    settingsOpen ? 'open' : ''
                                }`}
                                onClick={() =>
                                    setSettingsOpen(!settingsOpen)
                                }
                            >
                                <Settings size={20} />

                                <span>Settings</span>

                                {settingsOpen ? (
                                    <ChevronDown
                                        size={16}
                                        className="ml-auto"
                                    />
                                ) : (
                                    <ChevronRight
                                        size={16}
                                        className="ml-auto"
                                    />
                                )}
                            </div>

                            {settingsOpen && (
                                <div className="nav-sub">

                                    {/* =================================
                                        MASTER SETTINGS
                                    ================================= */}

                                    {showMasterSettings && (
                                        <div className="nav-group master-settings-group">

                                            {/* Master Settings Header */}
                                            <div
                                                className={`nav-sub-item ${
                                                    masterSettingsOpen
                                                        ? 'open'
                                                        : ''
                                                }`}
                                                onClick={() =>
                                                    setMasterSettingsOpen(
                                                        !masterSettingsOpen
                                                    )
                                                }
                                            >
                                                <SlidersHorizontal
                                                    size={18}
                                                />

                                                <span>
                                                    Master Settings
                                                </span>

                                                {masterSettingsOpen ? (
                                                    <ChevronDown
                                                        size={15}
                                                        className="ml-auto"
                                                    />
                                                ) : (
                                                    <ChevronRight
                                                        size={15}
                                                        className="ml-auto"
                                                    />
                                                )}
                                            </div>

                                            {/* Master Settings Children */}
                                            {masterSettingsOpen && (
                                                <div className="nav-sub master-sub">

                                                    {/* Platform */}
                                                    {canViewPlatform && (
                                                        <NavLink
                                                            to="/platforms"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <Server size={17} />
                                                            <span>
                                                                Platform
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                    {/* Device Types */}
                                                    {canViewDeviceTypes && (
                                                        <NavLink
                                                            to="/device-types"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <Cpu size={17} />
                                                            <span>
                                                                Device Types
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                    {/* Vehicle Types */}
                                                    {canViewVehicleTypes && (
                                                        <NavLink
                                                            to="/vehicle-types"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <Car size={17} />
                                                            <span>
                                                                Vehicle Types
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                    {/* Lead Closure */}
                                                    {canViewLeadClosures && (
                                                        <NavLink
                                                            to="/lead-closures"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <UserCheck size={17} />
                                                            <span>
                                                                Lead Closure
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                    {/* Sale Amount */}
                                                    {canViewSaleAmounts && (
                                                        <NavLink
                                                            to="/sale-amounts"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <IndianRupee
                                                                size={17}
                                                            />
                                                            <span>
                                                                Sale Amount
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                    {/* SIM Validity */}
                                                    {canViewSimValidity && (
                                                        <NavLink
                                                            to="/settings/sim-validity"
                                                            className={({ isActive }) =>
                                                                `nav-sub-item ${
                                                                    isActive
                                                                        ? 'active'
                                                                        : ''
                                                                }`
                                                            }
                                                        >
                                                            <Server size={17} />
                                                            <span>
                                                                SIM Validity
                                                            </span>
                                                        </NavLink>
                                                    )}

                                                </div>
                                            )}

                                        </div>
                                    )}

                                    {/* =================================
                                        ROLES & PERMISSIONS
                                    ================================= */}

                                    {canViewRoles && (
                                        <NavLink
                                            to="/settings/roles-permissions"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive
                                                        ? 'active'
                                                        : ''
                                                }`
                                            }
                                        >
                                            <Shield size={18} />
                                            <span>
                                                Roles & Permissions
                                            </span>
                                        </NavLink>
                                    )}

                                    {/* =================================
                                        ADD USER
                                    ================================= */}

                                    {canViewUsers && (
                                        <NavLink
                                            to="/settings/users"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive
                                                        ? 'active'
                                                        : ''
                                                }`
                                            }
                                        >
                                            <UserPlus size={18} />
                                            <span>
                                                Add User
                                            </span>
                                        </NavLink>
                                    )}

                                    {/* =================================
                                        CHANGE PASSWORD
                                    ================================= */}

                                    {canChangePassword && (
                                        <NavLink
                                            to="/settings/change-password"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${
                                                    isActive
                                                        ? 'active'
                                                        : ''
                                                }`
                                            }
                                        >
                                            <Lock size={18} />
                                            <span>
                                                Change Password
                                            </span>
                                        </NavLink>
                                    )}

                                </div>
                            )}

                        </div>
                    )}

                </nav>

                {/* =========================================
                    SIDEBAR FOOTER
                ========================================= */}

                <div className="sidebar-footer">

                    <button
                        className="logout-btn"
                        onClick={handleLogout}
                    >
                        <LogOut size={20} />
                        <span>Logout</span>
                    </button>

                </div>

            </aside>

            {/* =========================================
                MAIN CONTENT
            ========================================= */}

            <main className="main-content">

                <header className="topbar">
                    <button className="sidebar-toggle" onClick={() => setSidebarOpen(!sidebarOpen)} aria-label="Toggle menu">
                        {sidebarOpen ? <X size={24} /> : <Menu size={24} />}
                    </button>
                    <div className="user-profile">

                        <div className="avatar">
                            {(
                                user?.employee_name ||
                                user?.username ||
                                'U'
                            )
                                .charAt(0)
                                .toUpperCase()}
                        </div>

                        <span>
                            {user?.employee_name ||
                                user?.username ||
                                'User'}
                        </span>

                    </div>

                </header>

                <div className="content-area">
                    <Outlet />
                </div>

            </main>

        </div>
    );
};

export default AdminLayout;