import { useState } from 'react';
import { Outlet, NavLink, useNavigate } from 'react-router-dom';
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
    Cpu
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import './AdminLayout.css';

const AdminLayout = () => {
    const navigate = useNavigate();
    const { user, hasPermission, logout } = useAuth();
    const [inwardOpen, setInwardOpen] = useState(false);
    const [outwardOpen, setOutwardOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);

    const handleLogout = () => {
        logout();
        navigate('/login');
    };

    const canViewDashboard = hasPermission('dashboard.view');
    const canViewDeviceMaintenance = hasPermission('devices.view');
    const canViewSimMaintenance = hasPermission('sims.view');
    const canViewInwardReports = hasPermission('inward_reports.view');
    const canViewDealer = hasPermission('dealers.view');
    const canViewTechnician = hasPermission('technicians.view');
    const canViewOutwardReports = hasPermission('outward_reports.view');
    const canViewStock = hasPermission('stock.view');
    const canViewRoles = hasPermission('roles.view');
    const canViewUsers = hasPermission('users.view');
    const canViewDeviceAlert = hasPermission('device_alert.view');
    const canViewDeviceTypes = hasPermission('device_types.view');
    const canChangePassword = hasPermission('password.change');
    const canViewSimValidity = hasPermission('sim_validity.view');

    const showSettings = canViewRoles || canViewUsers || canViewDeviceAlert || canViewSimValidity || canChangePassword;

    return (
        <div className="app-container">
            <aside className="sidebar">
                <div className="sidebar-header">
                    <h2>StockAdmin</h2>
                </div>

                <nav className="sidebar-nav">
                    {canViewDashboard && (
                        <NavLink to="/dashboard" className={({isActive}) => `nav-item ${isActive ? 'active' : ''}`}>
                            <LayoutDashboard size={20} />
                            <span>Dashboard</span>
                        </NavLink>
                    )}

                    {(canViewDeviceMaintenance || canViewSimMaintenance || canViewInwardReports) && (
                        <div className="nav-group">
                            <div
                                className={`nav-item ${inwardOpen ? 'open' : ''}`}
                                onClick={() => setInwardOpen(!inwardOpen)}
                            >
                                <Box size={20} />
                                <span>Inward</span>
                                {inwardOpen ? <ChevronDown size={16} className="ml-auto" /> : <ChevronRight size={16} className="ml-auto" />}
                            </div>

                            {inwardOpen && (
                                <div className="nav-sub">
                                    {canViewDeviceMaintenance && (
                                        <NavLink to="/inward/devices" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Server size={18} />
                                            <span>Device Maintenance</span>
                                        </NavLink>
                                    )}
                                    {canViewSimMaintenance && (
                                        <NavLink to="/inward/sims" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Server size={18} />
                                            <span>SIM Maintenance</span>
                                        </NavLink>
                                    )}
                                    {canViewInwardReports && (
                                        <NavLink to="/inward/reports" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <FileText size={18} />
                                            <span>Inward Reports</span>
                                        </NavLink>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {(canViewDealer || canViewTechnician || canViewOutwardReports) && (
                        <div className="nav-group">
                            <div
                                className={`nav-item ${outwardOpen ? 'open' : ''}`}
                                onClick={() => setOutwardOpen(!outwardOpen)}
                            >
                                <PackageSearch size={20} />
                                <span>Outward</span>
                                {outwardOpen ? <ChevronDown size={16} className="ml-auto" /> : <ChevronRight size={16} className="ml-auto" />}
                            </div>

                            {outwardOpen && (
                                <div className="nav-sub">
                                    {canViewDealer && (
                                        <NavLink to="/outward/dealers" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Users size={18} />
                                            <span>Dealer</span>
                                        </NavLink>
                                    )}
                                    {canViewTechnician && (
                                        <NavLink to="/outward/technicians" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Wrench size={18} />
                                            <span>Technician</span>
                                        </NavLink>
                                    )}
                                    {canViewOutwardReports && (
                                        <NavLink to="/outward/reports" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <FileText size={18} />
                                            <span>Outward Reports</span>
                                        </NavLink>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {canViewStock && (
                        <NavLink to="/stock" className={({isActive}) => `nav-item ${isActive ? 'active' : ''}`}>
                            <PackageSearch size={20} />
                            <span>Stock Management</span>
                        </NavLink>
                    )}

                    {canViewDeviceAlert && (
                        <NavLink to="/device-alert" className={({isActive}) => `nav-item ${isActive ? 'active' : ''}`}>
                            <Bell size={20} />
                            <span>Device Alert</span>
                        </NavLink>
                    )}

                    {canViewDeviceTypes && (
                        <NavLink to="/device-types" className={({isActive}) => `nav-item ${isActive ? 'active' : ''}`}>
                            <Cpu size={20} />
                            <span>Device Types</span>
                        </NavLink>
                    )}

                    {showSettings && (
                        <div className="nav-group">
                            <div
                                className={`nav-item ${settingsOpen ? 'open' : ''}`}
                                onClick={() => setSettingsOpen(!settingsOpen)}
                            >
                                <Settings size={20} />
                                <span>Settings</span>
                                {settingsOpen ? <ChevronDown size={16} className="ml-auto" /> : <ChevronRight size={16} className="ml-auto" />}
                            </div>

                            {settingsOpen && (
                                <div className="nav-sub">
                                    {canViewRoles && (
                                        <NavLink to="/settings/roles-permissions" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Shield size={18} />
                                            <span>Roles & Permissions</span>
                                        </NavLink>
                                    )}
                                    {canViewUsers && (
                                        <NavLink to="/settings/users" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <UserPlus size={18} />
                                            <span>Add User</span>
                                        </NavLink>
                                    )}
                                    {canChangePassword && (
                                        <NavLink to="/settings/change-password" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Lock size={18} />
                                            <span>Change Password</span>
                                        </NavLink>
                                    )}
                                    {canViewSimValidity && (
                                        <NavLink to="/settings/sim-validity" className={({isActive}) => `nav-sub-item ${isActive ? 'active' : ''}`}>
                                            <Server size={18} />
                                            <span>SIM Validity</span>
                                        </NavLink>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </nav>

                <div className="sidebar-footer">
                    <button className="logout-btn" onClick={handleLogout}>
                        <LogOut size={20} />
                        <span>Logout</span>
                    </button>
                </div>
            </aside>

            <main className="main-content">
                <header className="topbar">
                    <div className="user-profile">
                        <div className="avatar">{(user?.employee_name || user?.username || 'U').charAt(0).toUpperCase()}</div>
                        <span>{user?.employee_name || user?.username || 'User'}</span>
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
