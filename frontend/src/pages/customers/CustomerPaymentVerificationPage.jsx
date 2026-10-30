import { useCallback, useEffect, useState } from 'react';
import { Check, Eye, Search, ShieldCheck, X } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import {
    commitCustomerSubmission
} from '../../utils/customerSubmission';
import './CustomerPaymentVerificationPage.css';

const tabs = [
    { key: 'Pending Verification', label: 'Pending Verification' },
    { key: 'Approved', label: 'Approved' },
    { key: 'Rejected', label: 'Rejected' }
];

const detailSections = [
    ['customer', 'Customer Details'],
    ['vehicle', 'Vehicle & Device Details'],
    ['installation', 'Installation Details'],
    ['payment_part1', 'Payment Details — Part 1'],
    ['payment_part2', 'Payment Details — Part 2']
];

const fieldLabels = {
    platform_name: 'Platform',
    platform_id: 'Platform',
    username: 'Username',
    primary_mobile_no: 'Primary Mobile No',
    secondary_mobile_no: 'Secondary Mobile No',
    email: 'Email',
    location: 'Location',
    pincode: 'Pincode',
    vehicle_no: 'Vehicle Number',
    vehicle_number: 'Vehicle Number',
    vehicle_type: 'Vehicle Type',
    vehicle_type_id: 'Vehicle Type',
    device_model: 'Device Model',
    device_model_id: 'Device Model',
    imei_no: 'IMEI Number',
    sim_no_1: 'SIM Number 1',
    sim_no_2: 'SIM Number 2',
    sim_no: 'SIM Number',
    validity_id: 'Validity',
    validity_months: 'Validity',
    installation_person_type: 'Installation Person Type',
    installation_person_id: 'Installer',
    installation_person: 'Installer',
    installation_date: 'Installation Date',
    lead_closure_id: 'Lead Closure',
    total_sale_amount: 'Total Sale Amount',
    totalSaleAmount: 'Total Sale Amount',
    total_amount: 'Total Amount',
    totalAmount: 'Total Amount',
    device_charge: 'Device Charge',
    deviceCharge: 'Device Charge',
    software_charge: 'Software Charge',
    softwareCharge: 'Software Charge',
    technician_charge: 'Technician Charge',
    technicianCharge: 'Technician Charge',
    sim_charge: 'SIM Charge',
    simCharge: 'SIM Charge',
    courier_charge: 'Courier Charge',
    courierCharge: 'Courier Charge',
    amount_paid: 'Amount Paid',
    amountPaid: 'Amount Paid',
    amount_pending: 'Amount Pending',
    amountPending: 'Amount Pending',
    payment_status: 'Payment Status',
    paymentStatus: 'Payment Status',
    payment_mode: 'Payment Mode',
    paymentMode: 'Payment Mode',
    'Payment Mode — Part 2': 'Payment Mode',
    transaction_id: 'Transaction ID',
    transactionId: 'Transaction ID',
    'Transaction ID — Part 2': 'Transaction ID',
    payment_date: 'Payment Date',
    step1: 'Customer Details',
    step2: 'Vehicle & Device Details',
    step3: 'Installation Details',
    step4: 'Payment Details — Part 1',
    step5: 'Payment Details — Part 2',
    notes: 'Notes',
    status: 'Status',
    cash_recipient_type: 'Cash Collection Recipient'
};

const parseStructuredValue = (value) => {
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed) return null;
    if (!trimmed.startsWith('{') && !trimmed.startsWith('[') && !trimmed.startsWith('"')) return value;
    try {
        return JSON.parse(trimmed);
    } catch {
        return null;
    }
};

const getFieldLabel = (field) => fieldLabels[field]
    || field.replace(/([a-z0-9])([A-Z])/g, '$1 $2').replaceAll('_', ' ');

const isInternalField = (field) => /^(?:_.*|id|.*_ids?|source|changed|append_vehicle|vehicle_record_id|customer_workflow|payment_workflow)$/i.test(field);

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
};

const formatDetailValue = (label, value) => {
    const parsed = parseStructuredValue(value);
    if (parsed === null || parsed === undefined || parsed === '') return '—';
    if (typeof parsed === 'boolean') return parsed ? 'Yes' : 'No';
    if (typeof parsed === 'object') return null;
    if (/date/i.test(label)) return formatDate(parsed);
    if (
        ['Total Sale Amount', 'Device Charge', 'Software Charge', 'Technician Charge',
            'SIM Charge', 'Courier Charge', 'Courier Payment Amount', 'Total Amount',
            'Amount Paid', 'Amount Pending'].includes(label) &&
        Number.isFinite(Number(parsed))
    ) {
        return Number(parsed).toLocaleString('en-IN', {
            style: 'currency',
            currency: 'INR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    return String(parsed);
};

const renderStructuredValue = (value, label = '') => {
    const parsed = parseStructuredValue(value);
    if (parsed === null || parsed === undefined || parsed === '') return <span>—</span>;
    if (Array.isArray(parsed)) {
        if (!parsed.length) return <span>—</span>;
        const parsedItems = parsed.map(parseStructuredValue);
        if (parsedItems.every((item) => item === null || typeof item !== 'object')) {
            return <span>{parsedItems.map((item) => formatDetailValue(label, item)).join(', ')}</span>;
        }
        return (
            <div className="verification-repeat-list">
                {parsedItems.map((item, index) => (
                    <div className="verification-repeat-item" key={`${label}-${index}`}>
                        <h4>{label ? `${label} ${index + 1}` : `Entry ${index + 1}`}</h4>
                        {renderStructuredValue(item)}
                    </div>
                ))}
            </div>
        );
    }
    if (typeof parsed !== 'object') return <span>{formatDetailValue(label, parsed)}</span>;

    const entries = Object.entries(parsed).filter(([field]) => !isInternalField(field));
    if (!entries.length) return <span>—</span>;
    const workflowStepTitles = {
        step1: 'Customer Details',
        step2: 'Vehicle & Device Details',
        step3: 'Installation Details',
        step4: 'Payment Details — Part 1',
        step5: 'Payment Details — Part 2'
    };

    return (
        <div className="verification-nested-fields">
            {entries.map(([field, fieldValue]) => {
                if (field === 'customer_workflow') {
                    return <div key={field}>{renderStructuredValue(fieldValue)}</div>;
                }
                const nestedLabel = workflowStepTitles[field] || getFieldLabel(field);
                const nestedValue = parseStructuredValue(fieldValue);
                if (nestedValue !== null && typeof nestedValue === 'object') {
                    return (
                        <div className="verification-nested-group" key={field}>
                            <h4>{nestedLabel}</h4>
                            {renderStructuredValue(nestedValue, nestedLabel)}
                        </div>
                    );
                }
                return (
                    <div className="verification-detail-field" key={field}>
                        <span className="verification-detail-label">{nestedLabel}</span>
                        <span className="verification-detail-value">{formatDetailValue(nestedLabel, nestedValue)}</span>
                    </div>
                );
            })}
        </div>
    );
};

const renderPaymentDetails = (payments) => {
    if (!payments.length) return <span>—</span>;
    if (payments.length === 1) {
        const payment = Object.fromEntries(
            Object.entries(payments[0]).filter(([field]) => field !== 'Vehicle')
        );
        return renderStructuredValue(payment);
    }
    return (
        <div className="verification-repeat-list">
            {payments.map((payment, index) => (
                <div className="verification-repeat-item" key={`payment-${index}`}>
                    <h4>Payment {index + 1}{payment.Vehicle ? ` — ${payment.Vehicle}` : ''}</h4>
                    {renderStructuredValue(payment)}
                </div>
            ))}
        </div>
    );
};

const normalizeVerificationStatus = (status) => String(status || '').trim().toLowerCase();

const CustomerPaymentVerificationPage = () => {
    const [status, setStatus] = useState(tabs[0].key);
    const [search, setSearch] = useState('');
    const [submissions, setSubmissions] = useState([]);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [counts, setCounts] = useState({});
    const [selected, setSelected] = useState(null);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [decisionError, setDecisionError] = useState('');
    const [notice, setNotice] = useState('');

    const loadQueue = useCallback(async () => {
        try {
            setLoading(true);
            setError('');
            const response = await api.get('/customers/verification/list.php', {
                params: { status, search: search.trim(), page, page_size: pageSize }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load verification queue.');
            }
            setSubmissions(response.data.data?.submissions || []);
            setCounts(response.data.data?.counts || {});
            const nextPagination = response.data.data?.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
            setPagination(nextPagination);
            if (page > nextPagination.total_pages) setPage(nextPagination.total_pages);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load verification queue.');
        } finally {
            setLoading(false);
        }
    }, [status, search, page, pageSize]);

    const updateStatus = (nextStatus) => {
        setStatus(nextStatus);
        setPage(1);
    };

    const updateSearch = (nextSearch) => {
        setSearch(nextSearch);
        setPage(1);
    };

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
        setPage(1);
    };

    useEffect(() => {
        loadQueue();
    }, [loadQueue]);

    const viewDetails = async (id) => {
        try {
            setError('');
            setDecisionError('');
            const response = await api.get('/customers/verification/details.php', { params: { id } });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load submission details.');
            }
            setSelected(response.data.data?.submission || null);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load submission details.');
        }
    };

    const decide = async (action) => {
        if (!selected || !selected.can_decide) return;
        if (action === 'reject' ? !canReject : !canApprove) return;
        try {
            setSaving(true);
            setError('');
            setDecisionError('');
            setNotice('');
            if (action === 'reject') {
                await api.post('/customers/verification/decide.php', {
                    submission_id: selected.id,
                    action,
                    reason
                });
                setNotice('Submission rejected. The submitter can correct and resubmit it.');
            } else {
                const decision = await api.post('/customers/verification/decide.php', {
                    submission_id: selected.id,
                    action
                });
                if (!decision.data?.success) {
                    throw new Error(decision.data?.message || 'Unable to start approval.');
                }
                const commit = await commitCustomerSubmission(
                    selected.submission_data,
                    selected.id,
                    async (ids) => {
                        await api.post('/customers/verification/progress.php', {
                            submission_id: selected.id,
                            ...ids
                        });
                    }
                );
                if (selected.submission_data?.source === 'courier') {
                    const courierRequestIds = selected.submission_data.courier_request_ids || [];
                    if (!courierRequestIds.length) {
                        throw new Error('Courier verification is missing its linked courier request.');
                    }
                    for (const id of courierRequestIds) {
                        const approved = await api.post('/courier/approve.php', {
                            id,
                            verification_submission_id: selected.id
                        });
                        if (!approved.data?.success) {
                            throw new Error(approved.data?.message || 'Unable to allocate the linked courier assets.');
                        }
                    }
                }
                const completed = await api.post('/customers/verification/complete.php', {
                    submission_id: selected.id,
                    customer_id: commit.customerId,
                    vehicle_id: commit.vehicleId,
                    payment_id: commit.paymentId
                });
                if (!completed.data?.success) {
                    throw new Error(completed.data?.message || 'Customer changes were saved, but approval could not be finalized. Retry approval to recover.');
                }
                setNotice('Submission approved and customer records updated.');
            }
            setSelected(null);
            setRejecting(false);
            setReason('');
            setDecisionError('');
            await loadQueue();
        } catch (decisionError) {
            setDecisionError(decisionError.response?.data?.message || decisionError.message || 'Unable to complete verification decision.');
            await loadQueue();
        } finally {
            setSaving(false);
        }
    };

    const activeCount = (key) => Number(counts[key] || 0) + (key === 'Pending Verification' ? Number(counts.Applying || 0) : 0);
    const data = selected?.display_data || {};
    const normalizedStatus = normalizeVerificationStatus(selected?.status);
    const isPendingSubmission = normalizedStatus === 'pending verification';
    const isApplyingSubmission = normalizedStatus === 'applying';
    const canApprove = Boolean(selected?.can_decide) && (isPendingSubmission || isApplyingSubmission);
    const canReject = Boolean(selected?.can_decide) && (isPendingSubmission || isApplyingSubmission);

    return (
        <div className="page-container verification-page">
            <div className="page-header">
                <div>
                    <h1><ShieldCheck size={22} /> Customer Verification</h1>
                    <p>Review submitted customer and payment information before approval.</p>
                </div>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {notice && <div className="alert alert-success">{notice}</div>}

            <div className="verification-tabs" role="tablist" aria-label="Verification status">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={status === tab.key}
                        className={`verification-tab ${status === tab.key ? 'active' : ''}`}
                        onClick={() => updateStatus(tab.key)}
                    >
                        {tab.label} <span className="verification-count">{activeCount(tab.key)}</span>
                    </button>
                ))}
            </div>

            <div className="verification-toolbar">
                <label className="verification-search">
                    <Search size={16} />
                    <input
                        type="search"
                        className="form-control"
                        value={search}
                        onChange={(event) => updateSearch(event.target.value)}
                        placeholder="Search customer, mobile, location, or submitter"
                    />
                </label>
                <button type="button" className="btn btn-secondary" onClick={loadQueue} disabled={loading}>
                    {loading ? 'Loading…' : 'Refresh'}
                </button>
            </div>

            <div className="verification-table-card">
                <div className="table-responsive">
                <table className="data-table verification-table">
                    <thead>
                        <tr>
                            <th>Request Type</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th>Platform</th>
                            <th>Location</th>
                            <th>Total Sale</th>
                            <th>Payment Status</th>
                            <th>Submitted By</th>
                            <th>Submitted At</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {submissions.map((item) => (
                            <tr key={item.id}>
                                <td>{item.submission_type || 'Customer Payment'}</td>
                                <td>{item.customer_name || '—'}</td>
                                <td>{item.primary_mobile_no || '—'}</td>
                                <td>{item.platform_name || '—'}</td>
                                <td>{item.location || '—'}</td>
                                <td>{Number(item.total_sale_amount || 0).toLocaleString('en-IN', { style: 'currency', currency: 'INR' })}</td>
                                <td>{item.payment_status || '—'}</td>
                                <td>{item.submitted_by || '—'}</td>
                                <td>{formatDate(item.submitted_at)}</td>
                                <td>{item.status}</td>
                                <td>
                                    <button type="button" className="btn btn-secondary" onClick={() => viewDetails(item.id)}>
                                        <Eye size={16} /> View Details
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {!loading && submissions.length === 0 && (
                            <tr><td colSpan="11" className="empty-state">No submissions found.</td></tr>
                        )}
                    </tbody>
                </table>
                </div>
                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={pagination.total}
                    onPageChange={setPage}
                    onPageSizeChange={updatePageSize}
                    itemName="verification requests"
                />
            </div>

            <Modal
                isOpen={Boolean(selected)}
                onClose={() => {
                    if (!saving) {
                        setSelected(null);
                        setRejecting(false);
                        setReason('');
                        setDecisionError('');
                    }
                }}
                title="Customer Verification Details"
                maxWidth="1000px"
                footer={(
                    <>
                        <button type="button" className="btn btn-secondary" disabled={saving} onClick={() => {
                            setSelected(null);
                            setRejecting(false);
                            setReason('');
                            setDecisionError('');
                        }}>Close</button>
                        {canApprove && !rejecting && (
                            <>
                                {canReject && (
                                    <button type="button" className="btn btn-danger" disabled={saving} onClick={() => {
                                        setDecisionError('');
                                        setRejecting(true);
                                    }}>
                                        <X size={16} /> Reject
                                    </button>
                                )}
                                <button type="button" className="btn btn-primary" disabled={saving} onClick={() => decide('approve')}>
                                    <Check size={16} /> {saving ? 'Applying…' : selected?.status === 'Applying' ? 'Resume Approval' : 'Approve'}
                                </button>
                            </>
                        )}
                        {canReject && rejecting && (
                            <>
                                <button type="button" className="btn btn-secondary" disabled={saving} onClick={() => setRejecting(false)}>Cancel</button>
                                <button type="button" className="btn btn-danger" disabled={saving || !reason.trim()} onClick={() => decide('reject')}>Confirm Rejection</button>
                            </>
                        )}
                    </>
                )}
            >
                {selected && (
                    <div className="verification-details">
                        {decisionError && <div className="alert alert-danger" role="alert">{decisionError}</div>}
                        <div className="verification-meta">
                            <span><strong>Status:</strong> {selected.status}</span>
                            <span><strong>Submitted By:</strong> {selected.submitted_by || '—'}</span>
                            <span><strong>Submitted At:</strong> {formatDate(selected.submitted_at)}</span>
                            {selected.reviewed_by && <span><strong>Reviewer:</strong> {selected.reviewed_by}</span>}
                            {selected.rejection_reason && <span><strong>Rejection Reason:</strong> {selected.rejection_reason}</span>}
                        </div>
                        {!selected.can_decide && (isPendingSubmission || isApplyingSubmission) && (
                            <div className="alert alert-info">
                                {selected.decision_unavailable_reason
                                    || 'Only a Super Admin can approve or reject customer verification requests.'}
                            </div>
                        )}
                        {isApplyingSubmission && (
                            <div className="alert alert-info">
                                Approval has started. If created customer records are still uncommitted and have no linked payment or external activity, rejection will safely remove those records before preserving the rejection.
                            </div>
                        )}
                        {rejecting && (
                            <label className="verification-rejection-form">
                                <span>Rejection reason <strong>(required)</strong></span>
                                <textarea
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="Explain what needs to be corrected"
                                    required
                                />
                            </label>
                        )}
                        {detailSections.map(([key, title]) => (
                            <section className="verification-section" key={key}>
                                <h3>{title}</h3>
                                {key.startsWith('payment_')
                                    ? renderPaymentDetails(data[key] || [])
                                    : renderStructuredValue(data[key])}
                            </section>
                        ))}
                    </div>
                )}
            </Modal>
        </div>
    );
};

export default CustomerPaymentVerificationPage;
