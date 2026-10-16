import { useCallback, useEffect, useState } from 'react';
import { Check, Eye, Search, ShieldCheck, X } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
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
    ['vehicle', 'Vehicle Details'],
    ['installation', 'Installation Details'],
    ['payment_part1', 'Payment Part 1'],
    ['payment_part2', 'Payment Part 2']
];

const displayValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
};

const formatDetailValue = (label, value) => {
    if (
        ['Total Sale Amount', 'Device Charge', 'Software Charge', 'Technician Charge',
            'SIM Charge', 'Courier Charge', 'Total Amount', 'Amount Paid', 'Amount Pending'].includes(label) &&
        value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
    ) {
        return Number(value).toLocaleString('en-IN', {
            style: 'currency',
            currency: 'INR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    return displayValue(value);
};

const normalizeVerificationStatus = (status) => String(status || '').trim().toLowerCase();

const CustomerPaymentVerificationPage = () => {
    const [status, setStatus] = useState(tabs[0].key);
    const [search, setSearch] = useState('');
    const [submissions, setSubmissions] = useState([]);
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
                params: { status, search: search.trim() }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load verification queue.');
            }
            setSubmissions(response.data.data?.submissions || []);
            setCounts(response.data.data?.counts || {});
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load verification queue.');
        } finally {
            setLoading(false);
        }
    }, [status, search]);

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
                    <h1><ShieldCheck size={22} /> Payment Verification</h1>
                    <p>Review submitted customer and payment changes before they are committed.</p>
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
                        onClick={() => setStatus(tab.key)}
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
                        onChange={(event) => setSearch(event.target.value)}
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
                            <tr><td colSpan="10" className="empty-state">No submissions found.</td></tr>
                        )}
                    </tbody>
                </table>
                </div>
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
                title="Payment Verification Details"
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
                                {(() => {
                                    const sectionData = data[key];
                                    if (!sectionData) return <p>Not provided.</p>;
                                    return Array.isArray(sectionData) ? sectionData.map((entry, index) => (
                                        <div key={`${key}-${index}`}>
                                            <dl>
                                                {Object.entries(entry).map(([field, value]) => (
                                                    <div key={field}>
                                                        <dt>{field.replaceAll('_', ' ')}</dt>
                                                        <dd>{formatDetailValue(field, value)}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </div>
                                    )) : typeof sectionData === 'object' ? (
                                    <dl>
                                        {Object.entries(sectionData).map(([field, value]) => (
                                            <div key={field}>
                                                <dt>{field.replaceAll('_', ' ')}</dt>
                                                <dd>{formatDetailValue(field, value)}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                    ) : <p>Not provided.</p>;
                                })()}
                            </section>
                        ))}
                    </div>
                )}
            </Modal>
        </div>
    );
};

export default CustomerPaymentVerificationPage;
