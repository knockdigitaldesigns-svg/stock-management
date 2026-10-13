import { useState, useCallback, useEffect } from 'react';
import { Truck, Eye, Edit, Trash2, CheckCircle, XCircle, Plus, RefreshCw, AlertCircle } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';
import { formatDate } from '../../utils/date';
import CourierModal from './CourierModal';
import CourierUpdateModal from './CourierUpdateModal';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import Modal from '../../components/Modal/Modal';
import './CourierPage.css';

const CourierPage = () => {
    const { hasPermission } = useAuth();
    const [canApprove, setCanApprove] = useState(() => hasPermission('courier.approve'));
    const [approvalPermissionResolved, setApprovalPermissionResolved] = useState(false);
    const canAdd = hasPermission('courier.add');
    const canEdit = hasPermission('courier.edit');
    const canDelete = hasPermission('courier.delete');

    // --------------------------------------------------
    // STATE
    // --------------------------------------------------
    const [requests, setRequests]               = useState([]);
    const [loading, setLoading]                 = useState(true);
    const [activeTab, setActiveTab]             = useState('all'); // 'all' or 'pending'

    // Filters
    const [search, setSearch]                   = useState('');
    const [courierToFilter, setCourierToFilter] = useState('');
    const [approvalStatusFilter, setApprovalStatusFilter] = useState('');
    const [courierStatusFilter, setCourierStatusFilter]   = useState('');
    const [dateFrom, setDateFrom]               = useState('');
    const [dateTo, setDateTo]                   = useState('');

    // Modal state
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [editTarget, setEditTarget]           = useState(null);
    const [courierUpdateTarget, setCourierUpdateTarget] = useState(null);
    const [viewTarget, setViewTarget]           = useState(null);

    // Confirm Action Modals
    const [approveTarget, setApproveTarget]     = useState(null);
    const [rejectTarget, setRejectTarget]       = useState(null);
    const [deleteTarget, setDeleteTarget]       = useState(null);

    const [processing, setProcessing]           = useState(false);

    // --------------------------------------------------
    // FETCH DATA
    // --------------------------------------------------
    const fetchRequests = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (courierToFilter) params.append('courier_to_person', courierToFilter);
            
            if (activeTab === 'pending') {
                params.append('approval_status', 'Pending Approval');
            } else if (approvalStatusFilter) {
                params.append('approval_status', approvalStatusFilter);
            }

            if (courierStatusFilter) params.append('courier_status', courierStatusFilter);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);

            const res = await api.get(`/courier/list.php?${params.toString()}`, { skipGlobalError: true });
            if (res.data?.success) {
                setRequests(res.data.data?.requests || []);
                setCanApprove(Boolean(res.data.data?.can_approve));
                setApprovalPermissionResolved(true);
            }
        } catch (err) {
            if (err.response?.status === 403) {
                setCanApprove(false);
                setApprovalPermissionResolved(true);
            }
            console.error('Failed to fetch courier requests', err);
            showGlobalError(err.response?.data?.message || 'Failed to fetch courier requests');
        } finally {
            setLoading(false);
        }
    }, [search, courierToFilter, activeTab, approvalStatusFilter, courierStatusFilter, dateFrom, dateTo]);

    useEffect(() => {
        if (approvalPermissionResolved && !canApprove && activeTab === 'pending') {
            setActiveTab('all');
            setApprovalStatusFilter('');
        }
    }, [canApprove, approvalPermissionResolved, activeTab]);

    useEffect(() => {
        fetchRequests();
    }, [fetchRequests]);

    // --------------------------------------------------
    // HANDLERS FOR APPROVE, REJECT, DELETE
    // --------------------------------------------------
    const handleConfirmApprove = async () => {
        if (!approveTarget) return;
        setProcessing(true);
        try {
            const res = await api.post('/courier/approve.php', { id: approveTarget.id });
            if (res.data?.success) {
                setApproveTarget(null);
                fetchRequests();
            } else {
                showGlobalError(res.data?.message || 'Failed to approve courier request');
            }
        } catch (err) {
            showGlobalError(err.response?.data?.message || 'Error approving courier request');
        } finally {
            setProcessing(false);
        }
    };

    const handleConfirmReject = async () => {
        if (!rejectTarget) return;
        setProcessing(true);
        try {
            const res = await api.post('/courier/reject.php', { id: rejectTarget.id });
            if (res.data?.success) {
                setRejectTarget(null);
                fetchRequests();
            } else {
                showGlobalError(res.data?.message || 'Failed to reject courier request');
            }
        } catch (err) {
            showGlobalError(err.response?.data?.message || 'Error rejecting courier request');
        } finally {
            setProcessing(false);
        }
    };

    const handleConfirmDelete = async () => {
        if (!deleteTarget) return;
        setProcessing(true);
        try {
            const res = await api.post('/courier/delete.php', { id: deleteTarget.id });
            if (res.data?.success) {
                setDeleteTarget(null);
                fetchRequests();
            } else {
                showGlobalError(res.data?.message || 'Failed to delete courier request');
            }
        } catch (err) {
            showGlobalError(err.response?.data?.message || 'Error deleting courier request');
        } finally {
            setProcessing(false);
        }
    };

    // --------------------------------------------------
    // PAGINATION
    // --------------------------------------------------
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        totalPages,
        paginatedItems
    } = usePagination(requests, 10, [search, courierToFilter, activeTab, approvalStatusFilter, courierStatusFilter, dateFrom, dateTo]);

    // Pending count for tab badge
    const pendingCount = requests.filter(r => r.approval_status === 'Pending Approval').length;

    const resetFilters = () => {
        setSearch('');
        setCourierToFilter('');
        setApprovalStatusFilter('');
        setCourierStatusFilter('');
        setDateFrom('');
        setDateTo('');
    };

    // Helper to determine and format asset type display (DEVICE, SIM, BOTH)
    const getAssetTypeDisplay = (row) => {
        if (!row) return '-';
        const raw = String(row.asset_type || '').trim().toLowerCase();
        if (raw === 'both' || raw.includes('both')) return 'BOTH';
        if (raw === 'device') return 'DEVICE';
        if (raw === 'sim') return 'SIM';

        const deviceCount = Number(row.device_count || 0);
        const simCount = Number(row.sim_count || 0);
        const hasDevice = Boolean(deviceCount > 0 || row.device_id || row.imei_no || row.device_model || row.device_model_id);
        const hasSim = Boolean(simCount > 0 || row.sim_id || row.sim_no || row.sim_type);

        if (hasDevice && hasSim) return 'BOTH';
        if (hasDevice) return 'DEVICE';
        if (hasSim) return 'SIM';

        return '-';
    };

    return (
        <div className="page-container">
            
            {/* Header */}
            <div className="page-header">
                <div>
                    <h2>Courier</h2>
                    <p className="page-subtitle">Review courier requests and manage approvals and dispatch.</p>
                </div>
                <div className="header-actions">
                    <button
                        className="btn btn-outline"
                        onClick={fetchRequests}
                        title="Refresh"
                    >
                        <RefreshCw size={16} /> Refresh
                    </button>

                    {canAdd && (
                        <button
                            className="btn btn-primary"
                            onClick={() => setShowCreateModal(true)}
                        >
                            <Plus size={16} /> Create Courier Request
                        </button>
                    )}
                </div>
            </div>

            {/* Navigation Tabs (All vs Pending Approvals) */}
            <div className="tabs-container">
                <button
                    className={`tab-btn ${activeTab === 'all' ? 'active' : ''}`}
                    onClick={() => { setActiveTab('all'); setApprovalStatusFilter(''); }}
                >
                    All Courier Requests
                </button>
                {canApprove && (
                    <button
                        className={`tab-btn ${activeTab === 'pending' ? 'active' : ''}`}
                        onClick={() => { setActiveTab('pending'); setApprovalStatusFilter('Pending Approval'); }}
                    >
                        Pending Admin Approvals
                        {pendingCount > 0 && activeTab !== 'pending' && (
                            <span className="badge badge-warning" style={{ marginLeft: '6px' }}>{pendingCount}</span>
                        )}
                    </button>
                )}
            </div>

            {/* Filter Bar */}
            <div className="card courier-filter-card">
                <div className="courier-filter-section">
                    
                    {/* ROW 1: [ Search ] [ Courier To ] [ Approval Status ] */}
                    <div className="courier-filter-row">
                        {/* Search */}
                        <div className="courier-filter-col">
                            <label className="courier-filter-label">Search</label>
                            <input
                                type="text"
                                className="courier-filter-control"
                                placeholder="Search dealer, technician, IMEI, SIM, tracking ID..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>

                        {/* Courier To Filter */}
                        <div className="courier-filter-col">
                            <label className="courier-filter-label">Courier To</label>
                            <select
                                className="courier-filter-control"
                                value={courierToFilter}
                                onChange={(e) => setCourierToFilter(e.target.value)}
                            >
                                <option value="">All Recipients</option>
                                <option value="Dealer">Dealer</option>
                                <option value="Technician">Technician</option>
                                <option value="Customer">Customer</option>
                            </select>
                        </div>

                        {/* Approval Status Filter */}
                        <div className="courier-filter-col">
                            {activeTab === 'all' ? (
                                <>
                                    <label className="courier-filter-label">Approval Status</label>
                                    <select
                                        className="courier-filter-control"
                                        value={approvalStatusFilter}
                                        onChange={(e) => setApprovalStatusFilter(e.target.value)}
                                    >
                                        <option value="">All Approval Statuses</option>
                                        {canApprove && <option value="Pending Approval">Pending Approval</option>}
                                        <option value="Approved">Approved</option>
                                        <option value="Rejected">Rejected</option>
                                    </select>
                                </>
                            ) : (
                                <div className="courier-filter-col-placeholder" aria-hidden="true" />
                            )}
                        </div>
                    </div>

                    {/* ROW 2: [ Courier Status ] [ Courier From Date ] [ Courier To Date ] */}
                    <div className="courier-filter-row">
                        {/* Courier Status Filter */}
                        <div className="courier-filter-col">
                            <label className="courier-filter-label">Courier Status</label>
                            <select
                                className="courier-filter-control"
                                value={courierStatusFilter}
                                onChange={(e) => setCourierStatusFilter(e.target.value)}
                            >
                                <option value="">All Courier Statuses</option>
                                <option value="Pending">Pending</option>
                                <option value="Reached">Reached</option>
                                <option value="Not Reached">Not Reached</option>
                            </select>
                        </div>

                        {/* Date From */}
                        <div className="courier-filter-col">
                            <label className="courier-filter-label">Courier From Date</label>
                            <input
                                type="date"
                                className="courier-filter-control"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                        </div>

                        {/* Date To */}
                        <div className="courier-filter-col">
                            <label className="courier-filter-label">Courier To Date</label>
                            <input
                                type="date"
                                className="courier-filter-control"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                        </div>
                    </div>

                    {/* ROW 3: [ Reset Filters ] */}
                    <div className="courier-filter-row-reset" style={{ display: 'flex', justifyContent: 'flex-end' }}>
                        <button
                            type="button"
                            className="courier-filter-reset-btn"
                            onClick={resetFilters}
                        >
                            Reset Filters
                        </button>
                    </div>

                </div>
            </div>

            {/* Main Table Container */}
            <div className="card courier-table-card">
                <div className="table-container courier-table-container">
                    <table className="courier-table">
                        <thead>
                            <tr>
                                <th className="col-req-id">Request ID</th>
                                <th className="col-courier-to">Courier To</th>
                                <th className="col-name">Name</th>
                                <th className="col-status">Status</th>
                                <th className="col-asset-type">Asset Type</th>
                                <th className="col-device-model">Device Model</th>
                                <th className="col-imei">IMEI No</th>
                                <th className="col-sim-type">SIM Type</th>
                                <th className="col-sim-no">SIM No</th>
                                <th className="col-software">Software</th>
                                <th className="col-send-via">Courier Send Via</th>
                                <th className="col-courier-date">Courier Date</th>
                                <th className="col-tracking-id">Tracking ID</th>
                                <th className="col-courier-status">Courier Status</th>
                                <th className="col-approval-status">Approval Status</th>
                                <th className="col-requested-by">Requested By</th>
                                <th className="col-datetime">Date &amp; Time</th>
                                <th className="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="18" className="text-center" style={{ padding: '2rem' }}>
                                        Loading courier requests...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="18" className="text-center empty-state" style={{ padding: '2rem' }}>
                                        No courier requests found.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((row) => (
                                    <tr key={row.id}>
                                        <td className="col-req-id"><strong>{row.request_code}</strong></td>
                                        <td className="col-courier-to">
                                            <span className={`badge ${
                                                row.courier_to_person === 'Technician' ? 'badge-primary' : 
                                                row.courier_to_person === 'Customer' ? 'badge-warning' : 
                                                'badge-info'
                                            }`}>
                                                {row.courier_to_person || 'Dealer'}
                                                {row.is_new_customer ? ' (New)' : ''}
                                            </span>
                                        </td>
                                        <td className="col-name">
                                            <div className="courier-cell-wrap" title={row.recipient_name || row.dealer_name || row.technician_name || ''} style={{ fontWeight: 500 }}>
                                                {row.recipient_name || row.dealer_name || row.technician_name || '-'}
                                            </div>
                                        </td>
                                        <td className="col-status">
                                            <span className={`badge ${row.is_new_customer ? 'badge-warning' : 'badge-info'}`}>
                                                {row.recipient_status || row.dealer_status || '-'}
                                            </span>
                                        </td>
                                        <td className="col-asset-type">{getAssetTypeDisplay(row)}</td>
                                        <td className="col-device-model">
                                            <div className="courier-cell-wrap" title={row.device_model || ''}>
                                                {row.device_model || '-'}
                                            </div>
                                        </td>
                                        <td className="col-imei">
                                            <div className="courier-cell-code" title={row.imei_no || ''}>
                                                {row.imei_no || '-'}
                                            </div>
                                        </td>
                                        <td className="col-sim-type">{row.sim_type || '-'}</td>
                                        <td className="col-sim-no">
                                            <div className="courier-cell-code" title={row.sim_no || ''}>
                                                {row.sim_no || '-'}
                                            </div>
                                        </td>
                                        <td className="col-software">
                                            <div className="courier-cell-wrap" title={row.software || ''}>
                                                {row.software || '-'}
                                            </div>
                                        </td>
                                        <td className="col-send-via">
                                            <span className="badge badge-secondary">{row.courier_send_via || '-'}</span>
                                        </td>
                                        <td className="col-courier-date">{formatDate(row.courier_date)}</td>
                                        <td className="col-tracking-id">
                                            <div className="courier-cell-code" title={row.tracking_id || ''}>
                                                {row.tracking_id || '-'}
                                            </div>
                                        </td>
                                        <td className="col-courier-status">
                                            <span className={`badge ${
                                                row.courier_status === 'Reached' ? 'badge-success' :
                                                row.courier_status === 'Not Reached' ? 'badge-warning' :
                                                'badge-info'
                                            }`}>
                                                {row.courier_status || 'Pending'}
                                            </span>
                                        </td>
                                        <td className="col-approval-status">
                                            <span className={`badge ${
                                                row.approval_status === 'Approved' ? 'badge-success' :
                                                row.approval_status === 'Rejected' ? 'badge-danger' :
                                                'badge-warning'
                                            }`}>
                                                {row.approval_status || 'Pending Approval'}
                                            </span>
                                        </td>
                                        <td className="col-requested-by">
                                            <div className="courier-cell-wrap" title={row.requested_by_name || ''}>
                                                {row.requested_by_name || '-'}
                                            </div>
                                        </td>
                                        <td className="col-datetime">
                                            <span className="courier-datetime">{row.created_at ? formatDate(row.created_at) : '-'}</span>
                                        </td>
                                        
                                        {/* Action Icons (Horizontal) */}
                                        <td className="col-actions">
                                            <div className="courier-actions-cell">
                                                
                                                {/* View Icon */}
                                                <button
                                                    type="button"
                                                    className="courier-action-btn view"
                                                    title="View Details"
                                                    onClick={() => setViewTarget(row)}
                                                >
                                                    <Eye size={16} />
                                                </button>

                                                {/* Admin Approve Button (Visible for Admin when Pending) */}
                                                {canApprove && row.approval_status === 'Pending Approval' && (
                                                    <button
                                                        type="button"
                                                        className="courier-action-btn edit"
                                                        title="Approve Request"
                                                        onClick={() => setApproveTarget(row)}
                                                        style={{ color: '#10b981', borderColor: '#a7f3d0', background: '#ecfdf5' }}
                                                    >
                                                        <CheckCircle size={16} />
                                                    </button>
                                                )}

                                                {/* Admin Reject Button (Visible for Admin when Pending) */}
                                                {canApprove && row.approval_status === 'Pending Approval' && (
                                                    <button
                                                        type="button"
                                                        className="courier-action-btn delete"
                                                        title="Reject Request"
                                                        onClick={() => setRejectTarget(row)}
                                                    >
                                                        <XCircle size={16} />
                                                    </button>
                                                )}

                                                {/* Courier Update Action Icon (Available ONLY for Approved requests) */}
                                                {row.approval_status === 'Approved' && (canEdit || canApprove) && (
                                                    <button
                                                        type="button"
                                                        className="courier-action-btn edit"
                                                        title="Courier Update"
                                                        onClick={() => setCourierUpdateTarget(row)}
                                                        style={{ color: '#2563eb', borderColor: '#bfdbfe', background: '#eff6ff' }}
                                                    >
                                                        <Truck size={16} />
                                                    </button>
                                                )}

                                                {/* Edit Icon (For Pending Approval and Rejected requests) */}
                                                {canEdit && row.approval_status !== 'Approved' && (
                                                    <button
                                                        type="button"
                                                        className="courier-action-btn edit"
                                                        title="Edit Request"
                                                        onClick={() => setEditTarget(row)}
                                                    >
                                                        <Edit size={16} />
                                                    </button>
                                                )}

                                                {/* Delete Icon */}
                                                {canDelete && (
                                                    <button
                                                        type="button"
                                                        className="courier-action-btn delete"
                                                        title="Delete Request"
                                                        onClick={() => setDeleteTarget(row)}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                )}

                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {requests.length > 0 && (
                    <Pagination
                        currentPage={page}
                        totalPages={totalPages}
                        pageSize={pageSize}
                        totalItems={totalItems}
                        onPageChange={setPage}
                        onPageSizeChange={setPageSize}
                    />
                )}
            </div>

            {/* ======================================================
                MODALS
            ====================================================== */}

            {/* Create Courier Request Modal */}
            {showCreateModal && (
                <CourierModal
                    isOpen={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    onSuccess={fetchRequests}
                />
            )}

            {/* Edit Courier Request Modal */}
            {editTarget && (
                <CourierModal
                    isOpen={Boolean(editTarget)}
                    editData={editTarget}
                    onClose={() => setEditTarget(null)}
                    onSuccess={fetchRequests}
                />
            )}

            {/* Courier Update Modal */}
            {courierUpdateTarget && (
                <CourierUpdateModal
                    isOpen={Boolean(courierUpdateTarget)}
                    courierData={courierUpdateTarget}
                    onClose={() => setCourierUpdateTarget(null)}
                    onSuccess={fetchRequests}
                />
            )}

            {/* Record View Modal */}
            {viewTarget && (viewTarget.is_new_customer || viewTarget.new_customer_data) ? (
                <Modal
                    isOpen={Boolean(viewTarget)}
                    onClose={() => setViewTarget(null)}
                    title={`New Customer Courier Request (${viewTarget.request_code})`}
                    maxWidth="760px"
                >
                    {(() => {
                        let data = {};
                        try {
                            data = typeof viewTarget.new_customer_data === 'string'
                                ? JSON.parse(viewTarget.new_customer_data || '{}')
                                : (viewTarget.new_customer_data || {});
                        } catch (e) {
                            data = {};
                        }
                        const s1 = data.step1 || {};
                        const s2 = data.step2 || {};
                        const s3 = data.step3 || {};
                        const s4 = data.step4 || {};
                        const s5 = data.step5 || {};

                        return (
                            <div style={{ maxHeight: '75vh', overflowY: 'auto', paddingRight: '4px' }}>
                                <div className="alert alert-info mb-3 d-flex align-items-center justify-content-between">
                                    <div>
                                        <strong>NEW CUSTOMER REQUEST</strong> &mdash; Approval Status: <span className="badge badge-warning">{viewTarget.approval_status}</span>
                                    </div>
                                    <div><strong>{viewTarget.request_code}</strong></div>
                                </div>

                                {/* Step 1: Customer Info */}
                                <div className="card p-3 mb-3 border bg-light">
                                    <h6 className="font-weight-bold text-primary mb-2">1. CUSTOMER INFORMATION</h6>
                                    <div className="row g-2 small">
                                        <div className="col-md-6"><strong>Username:</strong> {s1.username || viewTarget.recipient_name || '-'}</div>
                                        <div className="col-md-6"><strong>Primary Mobile:</strong> {s1.primary_mobile_no || '-'}</div>
                                        <div className="col-md-6"><strong>Secondary Mobile:</strong> {s1.secondary_mobile_no || '-'}</div>
                                        <div className="col-md-6"><strong>Email:</strong> {s1.email || '-'}</div>
                                        <div className="col-md-6"><strong>Location:</strong> {s1.location || '-'}</div>
                                        <div className="col-md-6"><strong>Pincode:</strong> {s1.pincode || '-'}</div>
                                    </div>
                                </div>

                                {/* Step 2: Vehicle Details */}
                                {(s2.vehicle_no || s2.imei_no || s2.sim_no_1) && (
                                    <div className="card p-3 mb-3 border bg-light">
                                        <h6 className="font-weight-bold text-primary mb-2">2. VEHICLE DETAILS</h6>
                                        <div className="row g-2 small">
                                            <div className="col-md-6"><strong>Vehicle No:</strong> {s2.vehicle_no || '-'}</div>
                                            <div className="col-md-6"><strong>IMEI No:</strong> {s2.imei_no || '-'}</div>
                                            <div className="col-md-6"><strong>SIM 1:</strong> {s2.sim_no_1 || '-'}</div>
                                            <div className="col-md-6"><strong>SIM 2:</strong> {s2.sim_no_2 || '-'}</div>
                                        </div>
                                    </div>
                                )}

                                {/* Step 3: Installation Details */}
                                {(s3.installation_person_type || s3.installation_date) && (
                                    <div className="card p-3 mb-3 border bg-light">
                                        <h6 className="font-weight-bold text-primary mb-2">3. INSTALLATION DETAILS</h6>
                                        <div className="row g-2 small">
                                            <div className="col-md-6"><strong>Installation Type:</strong> {s3.installation_person_type || '-'}</div>
                                            <div className="col-md-6"><strong>Installation Date:</strong> {s3.installation_date ? formatDate(s3.installation_date) : '-'}</div>
                                        </div>
                                    </div>
                                )}

                                {/* Step 4 & 5: Payment Details */}
                                {(s4.totalSaleAmount || s5.totalAmount || s5.amountPaid || s4.paymentMode) && (
                                    <div className="card p-3 mb-3 border bg-light">
                                        <h6 className="font-weight-bold text-primary mb-2">4. PAYMENT DETAILS</h6>
                                        <div className="row g-2 small">
                                            <div className="col-md-4"><strong>Total Amount:</strong> ₹{s5.totalAmount || s4.totalSaleAmount || '0.00'}</div>
                                            <div className="col-md-4"><strong>Amount Paid:</strong> ₹{s5.amountPaid || '0.00'}</div>
                                            <div className="col-md-4"><strong>Amount Pending:</strong> ₹{s5.amountPending || '0.00'}</div>
                                            <div className="col-md-4"><strong>Payment Status:</strong> {s5.paymentStatus || '-'}</div>
                                            <div className="col-md-4"><strong>Payment Mode:</strong> {s5.paymentMode || s4.paymentMode || '-'}</div>
                                            <div className="col-md-4"><strong>Transaction ID:</strong> {s5.transactionId || s4.transactionId || '-'}</div>
                                        </div>
                                    </div>
                                )}

                                {/* Courier & Allocation Details */}
                                <div className="card p-3 mb-3 border bg-light">
                                    <h6 className="font-weight-bold text-primary mb-2">5. COURIER &amp; ALLOCATION DETAILS</h6>
                                    <div className="row g-2 small">
                                        <div className="col-md-6"><strong>Asset Type:</strong> {getAssetTypeDisplay(viewTarget)}</div>
                                        <div className="col-md-6"><strong>Software:</strong> {viewTarget.software || '-'}</div>
                                        <div className="col-md-6"><strong>Device Model:</strong> {viewTarget.device_model || '-'}</div>
                                        <div className="col-md-6"><strong>Allocated IMEI:</strong> {viewTarget.imei_no || '-'}</div>
                                        <div className="col-md-6"><strong>SIM Type:</strong> {viewTarget.sim_type || '-'}</div>
                                        <div className="col-md-6"><strong>Allocated SIM No:</strong> {viewTarget.sim_no || '-'}</div>
                                        <div className="col-md-6"><strong>Courier Date:</strong> {formatDate(viewTarget.courier_date)}</div>
                                        <div className="col-md-6"><strong>Tracking ID:</strong> {viewTarget.tracking_id || '-'}</div>
                                        <div className="col-md-6"><strong>Send Via:</strong> {viewTarget.courier_send_via || '-'}</div>
                                        <div className="col-md-6"><strong>Courier Status:</strong> {viewTarget.courier_status || '-'}</div>
                                        <div className="col-md-6"><strong>Requested By:</strong> {viewTarget.requested_by_name || '-'}</div>
                                        <div className="col-md-6"><strong>Date Created:</strong> {viewTarget.created_at ? formatDate(viewTarget.created_at) : '-'}</div>
                                        <div className="col-md-12 mt-2"><strong>Notes:</strong> {viewTarget.notes || '-'}</div>
                                    </div>
                                </div>

                                <div className="d-flex justify-content-end mt-3">
                                    <button className="btn btn-secondary" onClick={() => setViewTarget(null)}>
                                        Close
                                    </button>
                                </div>
                            </div>
                        );
                    })()}
                </Modal>
            ) : viewTarget ? (
                <RecordViewModal
                    isOpen={Boolean(viewTarget)}
                    onClose={() => setViewTarget(null)}
                    title={`Courier Request Details (${viewTarget.request_code})`}
                    record={viewTarget}
                    fields={[
                        { label: 'Request ID', key: 'request_code' },
                        { label: 'Courier To Person', key: 'courier_to_person' },
                        { label: 'Recipient Name', key: 'recipient_name', format: (val, r) => val || r.dealer_name || r.technician_name || '-' },
                        { label: 'Recipient Status', key: 'recipient_status', format: (val, r) => val || r.dealer_status || '-' },
                        { label: 'Asset Type', key: 'asset_type', format: (val, r) => getAssetTypeDisplay(r) },
                        { label: 'Device Model', key: 'device_model' },
                        { label: 'IMEI No', key: 'imei_no' },
                        { label: 'SIM Type', key: 'sim_type' },
                        { label: 'SIM No', key: 'sim_no' },
                        { label: 'Software', key: 'software' },
                        { label: 'Request Date', key: 'request_date', format: formatDate },
                        { label: 'Courier Date', key: 'courier_date', format: formatDate },
                        { label: 'Tracking ID', key: 'tracking_id' },
                        { label: 'Courier Send Via', key: 'courier_send_via' },
                        { label: 'Courier Status', key: 'courier_status' },
                        { label: 'Courier Reason', key: 'courier_reason' },
                        { label: 'Approval Status', key: 'approval_status' },
                        { label: 'Requested By', key: 'requested_by_name' },
                        { label: 'Date & Time Created', key: 'created_at', format: formatDate },
                        { label: 'Notes', key: 'notes' }
                    ]}
                />
            ) : null}

            {/* Approve Confirmation Modal */}
            {approveTarget && (
                <Modal
                    isOpen={Boolean(approveTarget)}
                    onClose={() => setApproveTarget(null)}
                    title="Approve Courier Request"
                >
                    <div style={{ padding: '0.5rem' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--success-color)', marginBottom: '1rem' }}>
                            <CheckCircle size={24} />
                            <h4 style={{ margin: 0 }}>Confirm Approval</h4>
                        </div>
                        <p style={{ marginBottom: '0.5rem' }}>
                            Are you sure you want to approve Courier Request <strong>{approveTarget.request_code}</strong> for {approveTarget.is_new_customer ? 'NEW CUSTOMER' : (approveTarget.courier_to_person || 'Dealer')} <strong>{approveTarget.recipient_name || approveTarget.dealer_name || approveTarget.technician_name}</strong>?
                        </p>
                        <p style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', marginBottom: '1.5rem' }}>
                            {approveTarget.is_new_customer
                                ? `Approving will create the customer record (${approveTarget.recipient_name}), link their vehicle/installation/payment details, finalize the Device (${approveTarget.imei_no || 'N/A'}) / SIM (${approveTarget.sim_no || 'N/A'}) allocation, and approve this courier request.`
                                : `Approving will permanently allocate the reserved Device (${approveTarget.imei_no || 'N/A'}) / SIM (${approveTarget.sim_no || 'N/A'}) to the selected ${approveTarget.courier_to_person || 'Dealer'} and update their stock count.`}
                        </p>
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
                            <button className="btn btn-outline" onClick={() => setApproveTarget(null)} disabled={processing}>
                                Cancel
                            </button>
                            <button className="btn btn-success" onClick={handleConfirmApprove} disabled={processing}>
                                {processing ? 'Approving...' : 'Confirm Approve'}
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

            {/* Reject Confirmation Modal */}
            {rejectTarget && (
                <Modal
                    isOpen={Boolean(rejectTarget)}
                    onClose={() => setRejectTarget(null)}
                    title="Reject Courier Request"
                >
                    <div style={{ padding: '0.5rem' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--danger-color)', marginBottom: '1rem' }}>
                            <XCircle size={24} />
                            <h4 style={{ margin: 0 }}>Confirm Rejection</h4>
                        </div>
                        <p style={{ marginBottom: '0.5rem' }}>
                            Are you sure you want to reject Courier Request <strong>{rejectTarget.request_code}</strong>?
                        </p>
                        <p style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', marginBottom: '1.5rem' }}>
                            Rejecting will release the reserved stock (IMEI: {rejectTarget.imei_no || 'N/A'}, SIM: {rejectTarget.sim_no || 'N/A'}) and make it Available again for future allocations.
                        </p>
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
                            <button className="btn btn-outline" onClick={() => setRejectTarget(null)} disabled={processing}>
                                Cancel
                            </button>
                            <button className="btn btn-danger" onClick={handleConfirmReject} disabled={processing}>
                                {processing ? 'Rejecting...' : 'Confirm Reject'}
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

            {/* Delete Confirmation Modal */}
            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete Courier Request"
                >
                    <div style={{ padding: '0.5rem' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--danger-color)', marginBottom: '1rem' }}>
                            <AlertCircle size={24} />
                            <h4 style={{ margin: 0 }}>Confirm Delete</h4>
                        </div>
                        <p style={{ marginBottom: '0.5rem' }}>
                            Are you sure you want to delete Courier Request <strong>{deleteTarget.request_code}</strong>?
                        </p>
                        {deleteTarget.approval_status === 'Pending Approval' && (
                            <p style={{ fontSize: '0.85rem', color: 'var(--warning-color)', marginBottom: '1.5rem' }}>
                                Deleting this pending request will release the reserved asset back to Available stock.
                            </p>
                        )}
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
                            <button className="btn btn-outline" onClick={() => setDeleteTarget(null)} disabled={processing}>
                                Cancel
                            </button>
                            <button className="btn btn-danger" onClick={handleConfirmDelete} disabled={processing}>
                                {processing ? 'Deleting...' : 'Confirm Delete'}
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

        </div>
    );
};

export default CourierPage;
