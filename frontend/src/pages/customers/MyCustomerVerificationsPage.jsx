import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, RefreshCw, RotateCcw, Search, Trash2, X } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import { useAuth } from '../../context/AuthContext';
import { formatDateValue } from '../../utils/date';
import './CustomerPaymentVerificationPage.css';

const detailSections = [
    ['customer', 'Customer Details'],
    ['courier', 'Courier Request'],
    ['vehicle', 'Vehicle Details'],
    ['installation', 'Installation Details'],
    ['payment_part1', 'Payment Part 1'],
    ['payment_part2', 'Payment Part 2']
];
const MY_VERIFICATION_RETURN_STATE_KEY = 'my_verification_return_state';

const getSectionData = (data, key) => {
    if (key === 'customer') return data.customer || data.step1;
    const vehicles = Array.isArray(data.vehicles)
        ? data.vehicles
        : data.vehicle
            ? [data.vehicle]
            : [];
    if (key === 'vehicle') return data.vehicle || data.vehicles || data.step2;
    if (key === 'installation') {
        const installations = vehicles.map((vehicle) => vehicle.installation).filter(Boolean);
        return data.installation || data.step3 || (installations.length ? installations : null);
    }
    if (key === 'payment_part1') {
        const payments = vehicles.map((vehicle) => vehicle.payment_part1).filter(Boolean);
        return data.payment_part1 || data.step4 || (payments.length ? payments : null);
    }
    if (key === 'payment_part2') {
        const payments = vehicles.map((vehicle) => vehicle.payment_part2).filter(Boolean);
        return data.payment_part2 || data.paymentPart2 || data.step5 ||
            (payments.length ? payments : null);
    }
    return data[key];
};

const formatValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const getCorrectionStep = (reason) => {
    const text = String(reason || '').toLowerCase();
    if (/\b(payment\s*(part\s*)?2|second payment|amount paid|amount pending|cash collection|charge|payment mode|payment status)\b/.test(text)) return 5;
    if (/\b(payment\s*(part\s*)?1|first payment|sale amount|transaction id)\b/.test(text)) return 4;
    if (/\b(installation|technician|dealer|closure|installer)\b/.test(text)) return 3;
    if (/\b(vehicle|registration|imei|sim|device|validity)\b/.test(text)) return 2;
    if (/\b(customer|username|mobile|phone|email|platform|location|pincode|pin code)\b/.test(text)) return 1;
    return 5;
};

const MyCustomerVerificationsPage = () => {
    const navigate = useNavigate();
    const { user } = useAuth();
    const roleName = typeof user?.role === 'string'
        ? user.role
        : user?.role?.name || user?.role_name || '';
    const isSuperAdmin = String(roleName).trim().toLowerCase().replace(/[^a-z0-9]/g, '') === 'superadmin';
    const [listReturnState] = useState(() => {
        try {
            const savedState = JSON.parse(sessionStorage.getItem(MY_VERIFICATION_RETURN_STATE_KEY) || '{}');
            return savedState && typeof savedState === 'object' ? savedState : {};
        } catch {
            return {};
        }
    });
    const [submissions, setSubmissions] = useState([]);
    const [statusCounts, setStatusCounts] = useState({
        Draft: 0,
        'Pending Verification': 0,
        Approved: 0,
        Rejected: 0
    });
    const [page, setPage] = useState(() => Number.isInteger(Number(listReturnState.page)) && Number(listReturnState.page) > 0 ? Number(listReturnState.page) : 1);
    const [pageSize, setPageSize] = useState(() => Number.isInteger(Number(listReturnState.pageSize)) && Number(listReturnState.pageSize) > 0 ? Number(listReturnState.pageSize) : 10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [selected, setSelected] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteError, setDeleteError] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState(() => listReturnState.search || '');
    const [debouncedSearch, setDebouncedSearch] = useState(() => listReturnState.debouncedSearch || '');
    const [statusFilter, setStatusFilter] = useState(() => listReturnState.statusFilter || '');
    const [requestTypeFilter, setRequestTypeFilter] = useState(() => listReturnState.requestTypeFilter || '');
    const requestSequence = useRef(0);
    const searchTimer = useRef(null);

    const loadSubmissions = useCallback(async () => {
        const requestId = ++requestSequence.current;
        try {
            setLoading(true);
            setError('');
            const response = await api.get('/customers/verification/mine.php', {
                params: {
                    page,
                    page_size: pageSize,
                    search: debouncedSearch.trim(),
                    status: statusFilter,
                    request_type: requestTypeFilter
                }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load your submissions.');
            }
            if (requestId !== requestSequence.current) return;
            const rows = response.data.data?.submissions || [];
            setSubmissions([...new Map(rows.map((item) => [Number(item.id), item])).values()]);
            setStatusCounts({
                Draft: Number(response.data.data?.status_counts?.Draft || 0),
                'Pending Verification': Number(response.data.data?.status_counts?.['Pending Verification'] || 0),
                Approved: Number(response.data.data?.status_counts?.Approved || 0),
                Rejected: Number(response.data.data?.status_counts?.Rejected || 0)
            });
            const nextPagination = response.data.data?.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
            setPagination(nextPagination);
            if (page > nextPagination.total_pages) setPage(Math.max(1, nextPagination.total_pages));
        } catch (loadError) {
            if (requestId === requestSequence.current) {
                setError(loadError.response?.data?.message || loadError.message || 'Failed to load your submissions.');
            }
        } finally {
            if (requestId === requestSequence.current) setLoading(false);
        }
    }, [page, pageSize, debouncedSearch, statusFilter, requestTypeFilter]);

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
    };

    const updateFilter = (setter, value) => {
        setter(value);
        setPage(1);
    };

    const updateSearch = (value) => {
        setSearch(value);
        setPage(1);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => setDebouncedSearch(value.trim()), 300);
    };

    useEffect(() => {
        const timer = window.setTimeout(loadSubmissions, 0);
        return () => window.clearTimeout(timer);
    }, [loadSubmissions]);

    useEffect(() => {
        sessionStorage.removeItem(MY_VERIFICATION_RETURN_STATE_KEY);
    }, []);

    useEffect(() => () => window.clearTimeout(searchTimer.current), []);

    const resumeDraft = async (id) => {
        try {
            setError('');
            const response = await api.get('/customers/verification/own_draft.php', { params: { id } });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load rejected submission.');
            }
            const item = response.data.data?.submission;
            const data = item?.submission_data || {};
            const correctionStep = getCorrectionStep(item?.rejection_reason);
            const existingCustomerId = Number(item.existing_customer_id || data.existing_customer_id || 0);
            const linkedCustomerId = Number(item.linked_customer_id || data.linked_customer_id || 0);
            const linkedVehicleId = Number(item.linked_vehicle_id || data.linked_vehicle_id || 0);
            const linkedPaymentId = Number(item.linked_payment_id || data.linked_payment_id || 0);
            const customerRouteId = existingCustomerId || linkedCustomerId;
            sessionStorage.setItem(MY_VERIFICATION_RETURN_STATE_KEY, JSON.stringify({
                page,
                pageSize,
                search,
                debouncedSearch,
                statusFilter,
                requestTypeFilter
            }));
            if (
                customerRouteId > 0 &&
                (Array.isArray(data.vehicles) || linkedCustomerId > 0)
            ) {
                sessionStorage.setItem(`customer_edit_verification_${customerRouteId}`, JSON.stringify({
                    id: Number(item.id),
                    linked_customer_id: linkedCustomerId || null,
                    linked_vehicle_id: linkedVehicleId || null,
                    linked_payment_id: linkedPaymentId || null,
                    rejection_reason: item.rejection_reason || '',
                    submission_data: data
                }));
                navigate(`/customer-management/details/${customerRouteId}/edit?step=${correctionStep}`);
                return;
            }
            const normalized = Boolean(data.customer || data.step1);
            const vehicle = data.vehicle || data.step2 ||
                (Array.isArray(data.vehicles) ? data.vehicles[0] : null);
            const installation = data.installation || data.step3 || vehicle?.installation || {};
            const paymentPart1 = data.payment_part1 || data.step4 || vehicle?.payment_part1 || null;
            const paymentPart2 = data.payment_part2 || data.paymentPart2 || data.step5 ||
                vehicle?.payment_part2 || null;
            const sessionData = normalized
                ? {
                    draft_id: Number(item.id),
                    resumed_verification: true,
                    existing_customer_id: existingCustomerId || null,
                    linked_customer_id: linkedCustomerId || null,
                    linked_vehicle_id: linkedVehicleId || null,
                    linked_payment_id: linkedPaymentId || null,
                    customer_id: existingCustomerId || -Number(item.id),
                    step1: data.customer || data.step1 || {},
                    step2: vehicle ? {
                        ...vehicle,
                        vehicle_no: vehicle.vehicle_no || '',
                        vehicle_type_id: vehicle.vehicle_type_id || '',
                        imei_no: vehicle.imei_no || '',
                        sim_no_1: vehicle.sim_no_1 || '',
                        sim_no_2: vehicle.sim_no_2 || '',
                        device_model_id: vehicle.device_model_id || '',
                        validity_id: vehicle.validity_id || vehicle.validity_months || ''
                    } : undefined,
                    vehicle_record_id: vehicle?.vehicle_record_id || vehicle?.id ||
                        data.vehicle_record_id || data.vehicle_id ||
                        (Array.isArray(data.vehicles) && data.vehicles.length === 1 ? linkedVehicleId : 0) || null,
                    append_vehicle: Boolean(vehicle?.append_vehicle),
                    new_vehicle_flow: true,
                    step3: installation,
                    step4: paymentPart1 ? {
                        ...paymentPart1,
                        totalSaleAmount: paymentPart1.totalSaleAmount ?? paymentPart1.total_sale_amount ?? '',
                        transactionId: paymentPart1.transactionId || paymentPart1.transaction_id || '',
                        paymentMode: paymentPart1.paymentMode || paymentPart1.payment_mode || ''
                    } : {},
                    payment_part2: paymentPart2
                        ? {
                            ...paymentPart2,
                            payment_id: paymentPart2.payment_id || paymentPart2.paymentId || linkedPaymentId || null
                        }
                        : null
                }
                : {
                    ...data,
                    draft_id: Number(item.id),
                    resumed_verification: true,
                    existing_customer_id: existingCustomerId || null,
                    linked_customer_id: linkedCustomerId || null,
                    linked_vehicle_id: linkedVehicleId || null,
                    linked_payment_id: linkedPaymentId || null,
                    customer_id: existingCustomerId || -Number(item.id),
                    new_vehicle_flow: true
                };
            const creationRouteId = existingCustomerId || -Number(item.id);
            sessionStorage.setItem(`customer_creation_${creationRouteId}`, JSON.stringify(sessionData));
            const correctionRoutes = {
                1: `/customer-management/details/${creationRouteId}`,
                2: `/customer-management/details/vehicle/${creationRouteId}`,
                3: `/customer-management/details/installation/${creationRouteId}`,
                4: `/customer-management/details/payment/${creationRouteId}`,
                5: `/customer-management/details/payment-details/${creationRouteId}`
            };
            navigate(correctionRoutes[correctionStep]);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to resume submission.');
        }
    };

    const viewDetails = async (id) => {
        try {
            setError('');
            const response = await api.get('/customers/verification/own_details.php', { params: { id } });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load submission details.');
            }
            setSelected(response.data.data?.submission || null);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load submission details.');
        }
    };

    const closeDeleteModal = () => {
        if (deleting) return;
        setDeleteTarget(null);
        setDeleteError('');
    };

    const deleteDraft = async () => {
        if (!deleteTarget || deleting) return;
        try {
            setDeleting(true);
            setDeleteError('');
            setError('');
            setSuccess('');
            const response = await api.post('/customers/verification/delete_draft.php', {
                submission_id: Number(deleteTarget.id)
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to delete the draft verification request.');
            }
            setDeleteTarget(null);
            setSuccess(response.data.message || 'Draft verification request deleted successfully.');
            await loadSubmissions();
        } catch (deleteError) {
            setDeleteError(
                deleteError.response?.data?.message ||
                deleteError.message ||
                'Failed to delete the draft verification request.'
            );
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="page-container verification-page my-verification-page">
            <div className="page-header">
                <div>
                    <h1>My Verification Requests</h1>
                    <p>Review your customer and courier verification requests.</p>
                </div>
                <button type="button" className="btn btn-secondary" onClick={loadSubmissions} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {error && <div className="alert alert-danger">{error}</div>}
            {success && <div className="alert alert-success">{success}</div>}
            <div className="my-verification-status-counts" aria-label="Verification request counts">
                {[
                    ['Pending Verification', statusCounts['Pending Verification'], 'pending'],
                    ['Approved', statusCounts.Approved, 'approved'],
                    ['Rejected', statusCounts.Rejected, 'rejected'],
                    ['Draft', statusCounts.Draft, 'draft']
                ].map(([label, count, status]) => {
                    const filterValue = status === 'pending' ? 'Pending Verification' : label;
                    const active = statusFilter === filterValue;
                    return (
                        <button
                            type="button"
                            key={status}
                            className={`my-verification-status-count my-verification-status-count-${status}${active ? ' active' : ''}`}
                            onClick={() => updateFilter(setStatusFilter, active ? '' : filterValue)}
                            aria-pressed={active}
                        >
                            <span>{label}</span>
                            <strong>{count}</strong>
                        </button>
                    );
                })}
            </div>
            <div className="verification-toolbar my-verification-toolbar">
                <label className="verification-search my-verification-search">
                    <Search size={17} aria-hidden="true" />
                    <input
                        type="search"
                        className="form-control"
                        value={search}
                        onChange={(event) => updateSearch(event.target.value)}
                        placeholder="Search customer, vehicle, IMEI or SIM"
                        aria-label="Search verification requests"
                    />
                    {search && (
                        <button
                            type="button"
                            className="my-verification-clear"
                            onClick={() => {
                                window.clearTimeout(searchTimer.current);
                                setSearch('');
                                setDebouncedSearch('');
                                setPage(1);
                            }}
                            aria-label="Clear search"
                            title="Clear search"
                        >
                            <X size={16} />
                        </button>
                    )}
                </label>
                <select
                    className="form-control my-verification-filter"
                    value={statusFilter}
                    onChange={(event) => updateFilter(setStatusFilter, event.target.value)}
                    aria-label="Filter by verification status"
                >
                    <option value="">All statuses</option>
                    <option value="Draft">Draft</option>
                    <option value="Pending Verification">Pending Verification</option>
                    <option value="Applying">Applying</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                </select>
                <select
                    className="form-control my-verification-filter"
                    value={requestTypeFilter}
                    onChange={(event) => updateFilter(setRequestTypeFilter, event.target.value)}
                    aria-label="Filter by request type"
                >
                    <option value="">All request types</option>
                    <option value="customer_payment">Customer Payment</option>
                    <option value="courier">Courier Request</option>
                </select>
            </div>
            <div className="verification-table-card my-verification-table-card">
                <div className="table-responsive my-verification-table-scroll">
                <table className="data-table verification-table my-verification-table">
                    <thead>
                        <tr>
                            <th>Request Type</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th>Location</th>
                            <th>Vehicle Number</th>
                            <th>IMEI Number</th>
                            <th>SIM Number</th>
                            <th>Payment Status</th>
                            <th>Submitted At</th>
                            <th>Verification Status</th>
                            <th>Rejection Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {submissions.map((item) => (
                            <tr key={item.id}>
                                <td>{item.submission_type || 'Customer Payment'}</td>
                                <td>{item.customer_name}</td>
                                <td>{item.primary_mobile_no || '—'}</td>
                                <td>{item.location || '—'}</td>
                                <td>{item.vehicle_numbers?.join(', ') || '—'}</td>
                                <td>{item.imei_numbers?.join(', ') || '—'}</td>
                                <td>{item.sim_numbers?.join(', ') || '—'}</td>
                                <td>{item.payment_status || '—'}</td>
                                <td>{formatDateValue(item.submitted_at)}</td>
                                <td>{item.status}</td>
                                <td>{item.rejection_reason || '—'}</td>
                                <td>
                                    <div className="my-verification-actions">
                                    {item.status === 'Draft' ? (
                                        <>
                                            <button type="button" className="btn btn-secondary" onClick={() => resumeDraft(item.id)}>
                                                <RotateCcw size={16} /> Continue
                                            </button>
                                            {isSuperAdmin && (
                                                <button
                                                    type="button"
                                                    className="btn btn-danger"
                                                    onClick={() => {
                                                        setDeleteError('');
                                                        setDeleteTarget(item);
                                                    }}
                                                    aria-label="Delete draft verification request"
                                                    title="Delete draft verification request"
                                                >
                                                    <Trash2 size={16} /> Delete
                                                </button>
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            <button type="button" className="btn btn-secondary" onClick={() => viewDetails(item.id)}>
                                                <Eye size={16} /> View Details
                                            </button>
                                            {item.status === 'Rejected' && item.submission_type !== 'Courier Request' && (
                                                <button type="button" className="btn btn-secondary" onClick={() => resumeDraft(item.id)}>
                                                    <RotateCcw size={16} /> Correct &amp; Resubmit
                                                </button>
                                            )}
                                            {item.status === 'Rejected' && item.submission_type === 'Courier Request' && (
                                                <span>Submit a new courier request with corrected information.</span>
                                            )}
                                        </>
                                    )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {!loading && submissions.length === 0 && (
                            <tr>
                                <td className="empty-state" colSpan="12">
                                    {search.trim() || statusFilter || requestTypeFilter
                                        ? 'No matching verification requests found.'
                                        : 'No verification requests yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
                </div>
            </div>
            <Pagination
                currentPage={page}
                pageSize={pageSize}
                totalItems={pagination.total}
                onPageChange={setPage}
                onPageSizeChange={updatePageSize}
                itemName="verification requests"
            />
            <Modal
                isOpen={Boolean(selected)}
                onClose={() => setSelected(null)}
                title="Verification Request Details"
                maxWidth="1000px"
                footer={<button type="button" className="btn btn-secondary" onClick={() => setSelected(null)}>Close</button>}
            >
                {selected && (
                    <div className="verification-details">
                        <div className="verification-meta">
                            <span><strong>Status:</strong> {selected.status}</span>
                            <span><strong>Submitted At:</strong> {formatDateValue(selected.submitted_at)}</span>
                            {selected.decided_at && <span><strong>Reviewed At:</strong> {formatDateValue(selected.decided_at)}</span>}
                            {selected.rejection_reason && (
                                <div className="my-verification-rejection">
                                    <strong>Rejection reason</strong>
                                    <p>{selected.rejection_reason}</p>
                                    {selected.status === 'Rejected' && (
                                        <span>Correct the submitted details and resubmit this request.</span>
                                    )}
                                </div>
                            )}
                        </div>
                        {detailSections.map(([key, title]) => {
                            const sectionData = getSectionData(selected.submission_data || {}, key);
                            if (!sectionData) return null;
                            const entries = Array.isArray(sectionData) ? sectionData : [sectionData];
                            return (
                                <section className="verification-section" key={key}>
                                    <h3>{title}</h3>
                                    {entries.map((entry, index) => (
                                        <dl key={`${key}-${index}`}>
                                            {Object.entries(entry).map(([field, value]) => (
                                                <div key={field}>
                                                    <dt>{field.replaceAll('_', ' ')}</dt>
                                                    <dd>{formatValue(value)}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    ))}
                                </section>
                            );
                        })}
                    </div>
                )}
            </Modal>
            <Modal
                isOpen={Boolean(deleteTarget)}
                onClose={closeDeleteModal}
                title="Delete Draft Verification Request"
                maxWidth="520px"
                footer={
                    <>
                        <button type="button" className="btn btn-secondary" onClick={closeDeleteModal} disabled={deleting}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger" onClick={deleteDraft} disabled={deleting}>
                            {deleting ? 'Deleting...' : 'Delete'}
                        </button>
                    </>
                }
            >
                <p>Are you sure you want to permanently delete this draft verification request?</p>
                {deleteError && <div className="alert alert-danger">{deleteError}</div>}
            </Modal>
        </div>
    );
};

export default MyCustomerVerificationsPage;
