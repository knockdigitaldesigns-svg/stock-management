import { useCallback, useEffect, useState } from 'react';
import { Check, Eye, Search, ShieldCheck, X } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import {
    commitCustomerSubmission
} from '../../utils/customerSubmission';
import { formatDateValue } from '../../utils/date';
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

const formatDetailValue = (label, value) => {
    const parsed = parseStructuredValue(value);
    if (parsed === null || parsed === undefined || parsed === '') return '—';
    if (typeof parsed === 'boolean') return parsed ? 'Yes' : 'No';
    if (typeof parsed === 'object') return null;
    if (/date|at|time/i.test(label)) return formatDateValue(parsed);
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
    const [selectedSubmissionIds, setSelectedSubmissionIds] = useState(() => new Set());
    const [bulkConfirmOpen, setBulkConfirmOpen] = useState(false);
    const [bulkProcessing, setBulkProcessing] = useState(false);
    const [bulkSummary, setBulkSummary] = useState(null);
    const [canBulkDecide, setCanBulkDecide] = useState(false);

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
            const nextSubmissions = response.data.data?.submissions || [];
            setSubmissions(nextSubmissions);
            const actionableIds = new Set(
                nextSubmissions
                    .filter((submission) => submission.can_decide)
                    .map((submission) => Number(submission.id))
            );
            setSelectedSubmissionIds((previous) => {
                const next = new Set([...previous].filter((id) => actionableIds.has(id)));
                return next.size === previous.size ? previous : next;
            });
            setCounts(response.data.data?.counts || {});
            setCanBulkDecide(Boolean(response.data.data?.can_decide));
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
        setSelectedSubmissionIds(new Set());
    };

    const updateSearch = (nextSearch) => {
        setSearch(nextSearch);
        setPage(1);
        setSelectedSubmissionIds(new Set());
    };

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
        setPage(1);
        setSelectedSubmissionIds(new Set());
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

    const approveSubmission = async (submission) => {
        let approvalStarted = false;
        try {
            const decision = await api.post('/customers/verification/decide.php', {
                submission_id: submission.id,
                action: 'approve'
            });
            if (!decision.data?.success) {
                throw new Error(decision.data?.message || 'Unable to start approval.');
            }
            approvalStarted = decision.data?.data?.status === 'Applying';
            const commit = submission.submission_data?.legacy_customer_review
                ? {
                    customerId: Number(submission.linked_customer_id || 0),
                    vehicleId: Number(submission.linked_vehicle_id || 0),
                    paymentId: Number(submission.linked_payment_id || 0)
                }
                : await commitCustomerSubmission(
                    submission.submission_data,
                    submission.id,
                    async (ids) => {
                        const progress = await api.post('/customers/verification/progress.php', {
                            submission_id: submission.id,
                            ...ids
                        });
                        if (!progress.data?.success) {
                            throw new Error(progress.data?.message || 'Unable to save approval progress.');
                        }
                    }
                );
            if (!commit.customerId || !commit.paymentId) {
                throw new Error('The saved customer records could not be resolved for approval.');
            }
            if (submission.submission_data?.source === 'courier') {
                const courierRequestIds = submission.submission_data.courier_request_ids || [];
                if (!courierRequestIds.length) {
                    throw new Error('Courier verification is missing its linked courier request.');
                }
                for (const requestId of courierRequestIds) {
                    const approved = await api.post('/courier/approve.php', {
                        id: requestId,
                        verification_submission_id: submission.id
                    });
                    if (!approved.data?.success) {
                        throw new Error(approved.data?.message || 'Unable to allocate the linked courier assets.');
                    }
                }
            }
            const completed = await api.post('/customers/verification/complete.php', {
                submission_id: submission.id,
                customer_id: commit.customerId,
                vehicle_id: commit.vehicleId,
                payment_id: commit.paymentId
            });
            if (!completed.data?.success) {
                throw new Error(completed.data?.message || 'Customer changes were saved, but approval could not be finalized. Retry approval to recover.');
            }
            return { outcome: 'approved' };
        } catch (approvalError) {
            const failureReason = approvalError.response?.data?.message
                || approvalError.message
                || 'Unable to complete verification approval.';
            if (!approvalStarted || !failureReason.trim()) {
                return { outcome: 'failed', reason: failureReason };
            }
            try {
                const rejection = await api.post('/customers/verification/decide.php', {
                    submission_id: submission.id,
                    action: 'auto_reject',
                    reason: failureReason
                });
                if (!rejection.data?.success) {
                    throw new Error(rejection.data?.message || 'The failed application could not be rejected.');
                }
                return { outcome: 'rejected', reason: failureReason };
            } catch (rejectionError) {
                return {
                    outcome: 'failed',
                    reason: `${failureReason} Automatic rejection could not be completed: ${
                        rejectionError.response?.data?.message
                        || rejectionError.message
                        || 'Please retry approval.'
                    }`
                };
            }
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
                const result = await approveSubmission(selected);
                if (result.outcome === 'failed') {
                    throw new Error(result.reason);
                }
                setNotice(result.outcome === 'rejected'
                    ? `Approval failed and the request was automatically rejected: ${result.reason}`
                    : 'Submission approved and customer records updated.');
            }
            setSelected(null);
            setRejecting(false);
            setReason('');
            setDecisionError('');
            await loadQueue();
        } catch (decisionError) {
            const failureReason = decisionError.response?.data?.message
                || decisionError.message
                || 'Unable to complete verification decision.';
            setDecisionError(failureReason);
            await loadQueue();
        } finally {
            setSaving(false);
        }
    };

    const toggleSubmissionSelection = (submissionId) => {
        setSelectedSubmissionIds((previous) => {
            const next = new Set(previous);
            if (next.has(submissionId)) next.delete(submissionId);
            else next.add(submissionId);
            return next;
        });
    };

    const pendingPageSubmissions = status === 'Pending Verification' && canBulkDecide
        ? submissions.filter((submission) =>
            ['Pending Verification', 'Applying'].includes(submission.status)
        )
        : [];
    const pendingPageIds = pendingPageSubmissions.map((submission) => Number(submission.id));
    const allPendingPageSelected = pendingPageIds.length > 0 &&
        pendingPageIds.every((submissionId) => selectedSubmissionIds.has(submissionId));

    const toggleSelectAllPendingPage = () => {
        setSelectedSubmissionIds((previous) => {
            const next = new Set(previous);
            if (allPendingPageSelected) {
                pendingPageIds.forEach((submissionId) => next.delete(submissionId));
            } else {
                pendingPageIds.forEach((submissionId) => next.add(submissionId));
            }
            return next;
        });
    };

    const changePage = (nextPage) => {
        setSelectedSubmissionIds(new Set());
        setPage(nextPage);
    };

    const processBulkApproval = async () => {
        const submissionIds = [...selectedSubmissionIds];
        setBulkConfirmOpen(false);
        setBulkProcessing(true);
        setError('');
        setNotice('');
        const summary = { approved: [], rejected: [], failed: [] };
        for (const submissionId of submissionIds) {
            try {
                const response = await api.get('/customers/verification/details.php', {
                    params: { id: submissionId }
                });
                if (!response.data?.success) {
                    throw new Error(response.data?.message || 'Failed to load verification details.');
                }
                const submission = response.data.data?.submission;
                if (!submission?.can_decide) {
                    summary.failed.push({
                        id: submissionId,
                        customer: submission?.customer_name || `Request ${submissionId}`,
                        reason: submission?.decision_unavailable_reason || 'This request is no longer available for approval.'
                    });
                    continue;
                }
                const result = await approveSubmission(submission);
                const item = { id: submissionId, customer: submission.customer_name || `Request ${submissionId}` };
                if (result.outcome === 'approved') summary.approved.push(item);
                else if (result.outcome === 'rejected') summary.rejected.push({ ...item, reason: result.reason });
                else summary.failed.push({ ...item, reason: result.reason });
            } catch (bulkError) {
                summary.failed.push({
                    id: submissionId,
                    customer: `Request ${submissionId}`,
                    reason: bulkError.response?.data?.message || bulkError.message || 'Approval failed.'
                });
            }
        }
        setSelectedSubmissionIds(new Set());
        setBulkSummary(summary);
        setBulkProcessing(false);
        await loadQueue();
    };

    const activeCount = (key) => Number(counts[key] || 0);
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
                {status === 'Pending Verification' && selectedSubmissionIds.size > 0 && (
                    <div className="verification-bulk-actions">
                        <span>Selected: {selectedSubmissionIds.size}</span>
                        <button
                            type="button"
                            className="btn btn-primary"
                            disabled={bulkProcessing}
                            onClick={() => {
                                setBulkSummary(null);
                                setBulkConfirmOpen(true);
                            }}
                        >
                            <Check size={16} /> Bulk Approve
                        </button>
                    </div>
                )}
            </div>

            <div className="verification-table-card">
                <div className="table-responsive">
                <table className="data-table verification-table">
                    <thead>
                        <tr>
                            {status === 'Pending Verification' && (
                                <th>
                                    <label className="verification-select-all">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all pending verification requests on this page"
                                            checked={allPendingPageSelected}
                                            disabled={pendingPageIds.length === 0 || bulkProcessing}
                                            onChange={toggleSelectAllPendingPage}
                                        />
                                        <span>Select All ({pendingPageIds.length})</span>
                                    </label>
                                </th>
                            )}
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
                                {status === 'Pending Verification' && (
                                    <td>
                                        <input
                                            type="checkbox"
                                            aria-label={`Select ${item.customer_name || `request ${item.id}`}`}
                                            checked={selectedSubmissionIds.has(Number(item.id))}
                                            disabled={!item.can_decide || bulkProcessing}
                                            onChange={() => toggleSubmissionSelection(Number(item.id))}
                                        />
                                    </td>
                                )}
                                <td>{item.submission_type || 'Customer Payment'}</td>
                                <td>{item.customer_name || '—'}</td>
                                <td>{item.primary_mobile_no || '—'}</td>
                                <td>{item.platform_name || '—'}</td>
                                <td>{item.location || '—'}</td>
                                <td>{Number(item.total_sale_amount || 0).toLocaleString('en-IN', { style: 'currency', currency: 'INR' })}</td>
                                <td>{item.payment_status || '—'}</td>
                                <td>{item.submitted_by || '—'}</td>
                                <td>{formatDateValue(item.submitted_at)}</td>
                                <td>{item.status}</td>
                                <td>
                                    <button type="button" className="btn btn-secondary" onClick={() => viewDetails(item.id)} disabled={bulkProcessing}>
                                        <Eye size={16} /> View Details
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {!loading && submissions.length === 0 && (
                            <tr><td colSpan={status === 'Pending Verification' ? 12 : 11} className="empty-state">No submissions found.</td></tr>
                        )}
                    </tbody>
                </table>
                </div>
                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={pagination.total}
                    onPageChange={changePage}
                    onPageSizeChange={updatePageSize}
                    itemName="verification requests"
                />
            </div>

            <Modal
                isOpen={bulkConfirmOpen}
                onClose={() => !bulkProcessing && setBulkConfirmOpen(false)}
                title="Confirm Bulk Approval"
                maxWidth="520px"
                footer={(
                    <>
                        <button
                            type="button"
                            className="btn btn-secondary"
                            disabled={bulkProcessing}
                            onClick={() => setBulkConfirmOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary"
                            disabled={bulkProcessing || selectedSubmissionIds.size === 0}
                            onClick={processBulkApproval}
                        >
                            {bulkProcessing ? 'Processing…' : `Approve ${selectedSubmissionIds.size} Requests`}
                        </button>
                    </>
                )}
            >
                <p>
                    Approve {selectedSubmissionIds.size} selected request{selectedSubmissionIds.size === 1 ? '' : 's'}?
                    Each request will go through the existing approval checks independently.
                </p>
            </Modal>

            <Modal
                isOpen={Boolean(bulkSummary)}
                onClose={() => setBulkSummary(null)}
                title="Bulk Approval Results"
                maxWidth="700px"
                footer={(
                    <button type="button" className="btn btn-primary" onClick={() => setBulkSummary(null)}>Close</button>
                )}
            >
                {bulkSummary && (
                    <div className="verification-bulk-summary">
                        <p><strong>Successfully Approved:</strong> {bulkSummary.approved.length}</p>
                        <p><strong>Automatically Rejected:</strong> {bulkSummary.rejected.length}</p>
                        <p><strong>Failed / Recoverable:</strong> {bulkSummary.failed.length}</p>
                        {[['Automatically Rejected', bulkSummary.rejected], ['Failed / Recoverable', bulkSummary.failed]]
                            .filter(([, items]) => items.length > 0)
                            .map(([title, items]) => (
                                <section key={title}>
                                    <h3>{title}</h3>
                                    <ul>
                                        {items.map((item) => (
                                            <li key={item.id}>
                                                <strong>{item.customer}</strong>: {item.reason}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                    </div>
                )}
            </Modal>

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
                            <span><strong>Submitted At:</strong> {formatDateValue(selected.submitted_at)}</span>
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
