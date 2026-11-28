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
    ShieldCheck,
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
    X,
    Truck
} from 'lucide-react';

import { useAuth } from '../../context/AuthContext';
import api from '../../services/api';
import { showGlobalError } from '../../context/ErrorContext';
import './AdminLayout.css';

const AdminLayout = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { user, hasPermission, logout, isImpersonating, returnToOriginalAccount } = useAuth();

    const [sidebarOpen, setSidebarOpen] = useState(false);

    const [inwardOpen, setInwardOpen] = useState(false);
    const [outwardOpen, setOutwardOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [customerOpen, setCustomerOpen] = useState(false);
    const [crmOpen, setCrmOpen] = useState(false);
    const [masterSettingsOpen, setMasterSettingsOpen] = useState(false);
    const [notifications, setNotifications] = useState([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const [notificationOpen, setNotificationOpen] = useState(false);

    const loadNotifications = async () => {
        try {
            const [listResult, countResult] = await Promise.all([
                api.get('/notifications/list.php', { params: { page: 1, page_size: 10 } }),
                api.get('/notifications/unread_count.php')
            ]);
            if (listResult.data?.success) {
                setNotifications(listResult.data.data?.notifications || []);
            }
            if (countResult.data?.success) {
                setUnreadCount(Number(countResult.data.data?.unread_count || 0));
            }
        } catch {
            // The authenticated API interceptor handles session/logout errors.
        }
    };

    useEffect(() => {
        if (!user) return undefined;
        loadNotifications();
        const intervalId = window.setInterval(loadNotifications, 30000);
        return () => window.clearInterval(intervalId);
    }, [user?.id, user?.user_id]);

    const markAllNotificationsRead = async () => {
        try {
            const result = await api.post('/notifications/mark_all_read.php');
            if (result.data?.success) {
                setUnreadCount(0);
                setNotifications((current) => current.map((item) => ({ ...item, is_read: true })));
            }
        } catch {
            // The authenticated API interceptor handles request errors.
        }
    };

    const openNotification = async (notification) => {
        if (!notification.is_read) {
            try {
                await api.post('/notifications/mark_read.php', { id: notification.id });
                setUnreadCount((current) => Math.max(0, current - 1));
                setNotifications((current) => current.map((item) =>
                    item.id === notification.id ? { ...item, is_read: true } : item
                ));
            } catch {
                return;
            }
        }

        setNotificationOpen(false);
        const entityType = notification.related_entity_type;
        if (entityType === 'crm_followup') {
            navigate('/crm/follow-ups');
        } else if (entityType === 'lead_closure') {
            navigate('/crm/form-approval');
        } else if (entityType === 'crm_installation') {
            navigate('/crm/my-installations');
        } else {
            navigate(notification.related_lead_id ? '/crm/my-leads' : '/dashboard');
        }
    };

    useEffect(() => {
        setSidebarOpen(false);
        if (location.pathname.startsWith('/customer-management')) {
            setCustomerOpen(true);
        }
        if (location.pathname.startsWith('/crm')) {
            setCrmOpen(true);
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

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const handleReturnToSuperAdmin = async () => {
        try {
            await returnToOriginalAccount();
            navigate('/dashboard');
        } catch (error) {
            showGlobalError(error.response?.data?.message || error.message || 'Unable to restore the Super Admin account.');
        }
    };

    const currentRole = String(user?.role?.name || user?.role || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
    const isSuperAdmin = currentRole === 'superadmin';

    // =========================
    // MAIN PERMISSIONS
    // =========================

    const canViewDashboard =
        hasPermission('dashboard.view');

    const canViewHistory =
        hasPermission('history.view');

    const canViewSupport =
        hasPermission('support.view');

    const canViewAttendance =
        hasPermission('attendance.view');

    const canViewMyAttendance =
        hasPermission('attendance.self.view');

    const canViewEmployeeWorkingReports =
        hasPermission('employee_working_reports.view') ||
        hasPermission('employee_working_reports.own.view');

    const canViewDeviceMaintenance =
        hasPermission('devices.view');

    const canViewSimMaintenance =
        hasPermission('sims.view');

    const canViewInwardReports =
        hasPermission('inward_reports.view');

    const canViewDealer =
        hasPermission('dealers.view');

    const canViewDealerSimActivation =
        hasPermission('dealer_sim_activation.view') || hasPermission('dealers.view');

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

    const canViewCourier =
        hasPermission('courier.view');

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

    const canViewPipelineManagement =
        hasPermission('crm_pipelines.view') ||
        hasPermission('crm_pipelines.add') ||
        hasPermission('crm_pipelines.edit') ||
        hasPermission('crm_pipelines.delete');

    const canViewCampaignManagement =
        hasPermission('crm_campaigns.view');

    const canViewLeadStatusMaster =
        hasPermission('crm_lead_status.view');

    const canViewLeadStageMaster =
        hasPermission('crm_lead_stage.view') ||
        hasPermission('crm_lead_stage.add') ||
        hasPermission('crm_lead_stage.edit') ||
        hasPermission('crm_lead_stage.activate');

    const canViewLeads =
        hasPermission('crm_leads.view');

    const canViewMyLeads =
        hasPermission('crm_leads.my_view') || canViewLeads;

    const canDistributeLeads =
        hasPermission('crm_leads.assign');

    const canViewFollowups =
        hasPermission('crm_followups.view');

    const canConvertLeads =
        hasPermission('crm_closures.add');

    const canApproveClosures =
        hasPermission('crm_closures.approve');

    const canViewInstallations =
        hasPermission('crm_installations.view');
        
const canViewCustomerDetails =
    hasPermission('customers.view');

const canViewCustomerReports =
    hasPermission('customer_reports.view');

const canViewCustomerRenewals =
    hasPermission('customer_renewals.view');

const canViewSimLifecycle =
    hasPermission('sim_lifecycle.view');

const canViewCustomerVerification =
    hasPermission('customer_payment_verification.review') &&
    ['admin', 'super admin', 'super_admin'].includes(
        String(user?.role?.name || user?.role || '').trim().toLowerCase()
    );

const canViewMyCustomerVerifications =
    hasPermission('customers.add') || hasPermission('customers.edit') || hasPermission('courier.add');

const showCustomerManagement =
    canViewCustomerDetails ||
    canViewCustomerReports ||
    canViewCustomerRenewals ||
    canViewSimLifecycle;
    canViewCustomerVerification ||
    canViewMyCustomerVerifications;

const showCRM =
    canViewPipelineManagement ||
    canViewCampaignManagement ||
    canViewLeadStatusMaster ||
    canViewLeadStageMaster ||
    canViewLeads ||
    canViewMyLeads ||
    canDistributeLeads ||
    canViewFollowups ||
    canConvertLeads ||
    canApproveClosures ||
    canViewInstallations;
    // =========================
    // SETTINGS
    // =========================

    const canViewRoles =
        hasPermission('roles.view');

    const canViewUsers =
        hasPermission('users.view');

    const canLookupTransactionIds =
        hasPermission('transaction_ids.lookup');

    const canChangePassword =
        hasPermission('password.change');

    const canManageAttendanceSettings =
        hasPermission('attendance.settings');

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
        canLookupTransactionIds ||
        canChangePassword ||
        canManageAttendanceSettings;

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
                        canViewDealerSimActivation ||
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

                                    {canViewDealerSimActivation && (
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

                    {(canViewDealer || canViewTechnician) && (
                        <NavLink
                            to="/installation-persons"
                            className={({ isActive }) =>
                                `nav-item ${isActive ? 'active' : ''}`
                            }
                        >
                            <Users size={20} />
                            <span>Installation Persons</span>
                        </NavLink>
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

                    {/* =========================================
                        COURIER
                    ========================================= */}

                    {canViewCourier && (
                        <NavLink
                            to="/courier"
                            className={({ isActive }) =>
                                `nav-item ${
                                    isActive ? 'active' : ''
                                }`
                            }
                        >
                            <Truck size={20} />
                            <span>Courier</span>
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

                {canViewCustomerVerification && (
                    <NavLink
                        to="/customer-management/verification"
                        className={({ isActive }) =>
                            `nav-sub-item ${isActive ? 'active' : ''}`
                        }
                    >
                        <ShieldCheck size={18} />
                        <span>Verification</span>
                    </NavLink>
                )}

                {canViewMyCustomerVerifications && (
                    <NavLink
                        to="/customer-management/my-verifications"
                        className={({ isActive }) =>
                            `nav-sub-item ${isActive ? 'active' : ''}`
                        }
                    >
                        <FileText size={18} />
                        <span>My Verification Requests</span>
                    </NavLink>
                )}

            </div>
        )}

    </div>
)}

                    {showCRM && (
                        <div className="nav-group">
                            <div
                                className={`nav-item ${crmOpen ? 'open' : ''}`}
                                onClick={() => setCrmOpen(!crmOpen)}
                            >
                                <SlidersHorizontal size={20} />
                                <span>CRM</span>
                                {crmOpen ? (
                                    <ChevronDown size={16} className="ml-auto" />
                                ) : (
                                    <ChevronRight size={16} className="ml-auto" />
                                )}
                            </div>

                            {crmOpen && (
                                <div className="nav-sub">
                                    {canViewPipelineManagement && (
                                        <NavLink
                                            to="/crm/pipelines"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <SlidersHorizontal size={18} />
                                            <span>Pipeline Management</span>
                                        </NavLink>
                                    )}
                                    {canViewCampaignManagement && (
                                        <NavLink
                                            to="/crm/campaigns"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <FileText size={18} />
                                            <span>Campaign Management</span>
                                        </NavLink>
                                    )}
                                    {canViewLeadStatusMaster && (
                                        <NavLink
                                            to="/crm/lead-statuses"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <SlidersHorizontal size={18} />
                                            <span>Lead Status Master</span>
                                        </NavLink>
                                    )}
                                    {canViewLeadStageMaster && (
                                        <NavLink
                                            to="/crm/lead-stages"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <SlidersHorizontal size={18} />
                                            <span>Lead Stage Master</span>
                                        </NavLink>
                                    )}
                                    {canViewLeads && (
                                        <NavLink
                                            to="/crm/leads"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <Users size={18} />
                                            <span>All Leads</span>
                                        </NavLink>
                                    )}
                                    {canViewMyLeads && (
                                        <NavLink
                                            to="/crm/my-leads"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <UserPlus size={18} />
                                            <span>My Leads</span>
                                        </NavLink>
                                    )}
                                    {canDistributeLeads && (
                                        <NavLink
                                            to="/crm/lead-distribution"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <UserCheck size={18} />
                                            <span>Lead Distribution</span>
                                        </NavLink>
                                    )}
                                    {canViewFollowups && (
                                        <NavLink
                                            to="/crm/follow-ups"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <Clock size={18} />
                                            <span>Follow-ups</span>
                                        </NavLink>
                                    )}
                                    {canConvertLeads && (
                                        <NavLink
                                            to="/crm/lead-converter"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <FileText size={18} />
                                            <span>Lead Converter</span>
                                        </NavLink>
                                    )}
                                    {canApproveClosures && (
                                        <NavLink
                                            to="/crm/form-approval"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <Shield size={18} />
                                            <span>Form Approval</span>
                                        </NavLink>
                                    )}
                                    {canViewInstallations && (
                                        <NavLink
                                            to="/crm/my-installations"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <Wrench size={18} />
                                            <span>My Installations</span>
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

                    {(canViewAttendance || canViewMyAttendance) && (
                        <NavLink
                            to="/attendance"
                            className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                        >
                            <Clock size={20} />
                            <span>{canViewAttendance ? 'Attendance' : 'My Attendance'}</span>
                        </NavLink>
                    )}

                    {canViewEmployeeWorkingReports && (
                        <NavLink
                            to="/employee-working-reports"
                            className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                        >
                            <FileText size={20} />
                            <span>Employee Working Reports</span>
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

                                    {canLookupTransactionIds && (
                                        <NavLink
                                            to="/settings/transaction-id-lookup"
                                            className={({ isActive }) =>
                                                `nav-sub-item ${isActive ? 'active' : ''}`
                                            }
                                        >
                                            <FileText size={18} />
                                            <span>Transaction ID Lookup</span>
                                        </NavLink>
                                    )}

                                    {canManageAttendanceSettings && (
                                        <NavLink
                                            to="/settings/attendance"
                                            className={({ isActive }) => `nav-sub-item ${isActive ? 'active' : ''}`}
                                        >
                                            <Clock size={18} />
                                            <span>Attendance Settings</span>
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
                    <div className="topbar-actions">
                        {isImpersonating ? (
                            <div className="account-mode-indicator" role="status">
                                <span>Logged in as: {user?.employee_name || user?.username || 'Employee'}</span>
                                <button type="button" className="account-mode-return" onClick={handleReturnToSuperAdmin}>
                                    Back to Super Admin
                                </button>
                            </div>
                        ) : isSuperAdmin ? (
                            <button
                                type="button"
                                className="account-switch-button"
                                onClick={() => navigate('/switch-account')}
                            >
                                Switch Account
                            </button>
                        ) : null}
                        <div className="notification-menu">
                            <button
                                type="button"
                                className="notification-bell"
                                onClick={() => setNotificationOpen((current) => !current)}
                                aria-label={`${unreadCount} unread notifications`}
                            >
                                <Bell size={20} />
                                {unreadCount > 0 && (
                                    <span className="notification-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
                                )}
                            </button>
                            {notificationOpen && (
                                <div className="notification-dropdown">
                                    <div className="notification-dropdown-header">
                                        <strong>Notifications</strong>
                                        {unreadCount > 0 && (
                                            <button type="button" onClick={markAllNotificationsRead}>
                                                Mark all as read
                                            </button>
                                        )}
                                    </div>
                                    <div className="notification-list">
                                        {notifications.length === 0 ? (
                                            <div className="notification-empty">No notifications</div>
                                        ) : notifications.map((notification) => (
                                            <button
                                                type="button"
                                                className={`notification-item ${notification.is_read ? 'read' : 'unread'}`}
                                                key={notification.id}
                                                onClick={() => openNotification(notification)}
                                            >
                                                <span className="notification-title-row">
                                                    <strong>{notification.title}</strong>
                                                    <span className={`priority-badge ${String(notification.priority).toLowerCase()}`}>
                                                        {notification.priority || 'Normal'}
                                                    </span>
                                                </span>
                                                <span className="notification-message">{notification.message}</span>
                                                <span className="notification-time">
                                                    {notification.created_at ? new Date(notification.created_at).toLocaleString() : ''}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
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