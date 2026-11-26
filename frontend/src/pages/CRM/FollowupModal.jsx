import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { formatDate, parseDate } from '../../utils/date';
import { useAuth } from '../../context/AuthContext';
import './FollowupsPage.css';

const NOT_CONNECTED_REASONS = [
    'Did not pick',
    'Busy in another call',
    'User disconnected the call',
    'Switch off',
    'Out of coverage area / Network issue',
    'Call not connected / Cannot be completed',
    'Incorrect / Invalid number',
    'Incoming calls not available',
    'Number not in use / Does not exist / Out of service',
    'Other reason'
];

const TABS = ['Lead Information', 'Follow-up', 'Other'];

const initialForm = {
    current_status_id: '',
    customer_need: '',
    call_outcome: '',
    closure_reason: '',
    remarks: '',
    next_followup_date: '',
    next_followup_time: '',
    connection: 'connected',
    other_reason: ''
};

const formatDateTime = (value) => {
    if (!value) return '—';
    const text = String(value);
    const date = parseDate(text);
    const time = text.match(/[T ](\d{2}:\d{2})/)?.[1];
    return `${date ? formatDate(date) : text}${time ? ` ${time}` : ''}`;
};

const makeLocalSchedule = (businessNow, offsetHours) => {
    const parts = String(businessNow || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!parts) return null;
    const [, year, month, day, hour, minute] = parts;
    const value = new Date(Date.UTC(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour) + offsetHours,
        Number(minute)
    ));
    const pad = (number) => String(number).padStart(2, '0');
    return {
        date: `${value.getUTCFullYear()}-${pad(value.getUTCMonth() + 1)}-${pad(value.getUTCDate())}`,
        time: `${pad(value.getUTCHours())}:${pad(value.getUTCMinutes())}`
    };
};

const Detail = ({ label, value }) => (
    <div className="followup-information-item">
        <span>{label}</span>
        <strong>{value || '—'}</strong>
    </div>
);

const FollowupModal = ({ lead, mode, onClose, onSaved }) => {
    const { hasPermission } = useAuth();
    const [statuses, setStatuses] = useState([]);
    const [history, setHistory] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(true);
    const [details, setDetails] = useState(null);
    const [form, setForm] = useState(initialForm);
    const [activeTab, setActiveTab] = useState('Follow-up');
    const [loading, setLoading] = useState(true);
    const [statusOptionsLoaded, setStatusOptionsLoaded] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const savingRef = useRef(false);
    const initialFormRef = useRef(initialForm);
    const detailsLoaded = Boolean(details);
    const schedule = details?.current_schedule;
    const {
        page: historyPage,
        setPage: setHistoryPage,
        pageSize: historyPageSize,
        setPageSize: setHistoryPageSize,
        setTotalItems: setHistoryTotal,
        totalItems: historyTotal
    } = usePagination(history, 10, [lead.id], true);

    useEffect(() => {
        let active = true;
        const loadFormData = async () => {
            setLoading(true);
            setError('');
            setDetails(null);
            if (mode === 'view') {
                if (active) {
                    setDetails({ lead_name: lead.lead_name });
                    setActiveTab('Other');
                    setLoading(false);
                }
                return;
            }
            try {
                const [optionsResult, detailsResult] = await Promise.allSettled([
                    api.get('/crm_followups/options.php'),
                    api.get('/crm_followups/details.php', {
                        params: {
                            lead_id: lead.id,
                            mode,
                            ...(mode === 'update' ? { followup_id: lead.followup_id } : {})
                        }
                    })
                ]);
                if (!active) return;
                const loadErrors = [];
                let loadedStatuses = [];

                if (optionsResult.status === 'fulfilled' && optionsResult.value.data?.success) {
                    loadedStatuses = optionsResult.value.data.data?.statuses;
                    if (Array.isArray(loadedStatuses)) {
                        setStatuses(loadedStatuses);
                        setStatusOptionsLoaded(true);
                    } else {
                        loadErrors.push('The active lead statuses response was invalid.');
                    }
                } else {
                    const failure = optionsResult.status === 'rejected' ? optionsResult.reason : optionsResult.value.data;
                    loadErrors.push(failure?.response?.data?.message || failure?.message || 'Unable to load active lead statuses.');
                }

                if (detailsResult.status === 'fulfilled' && detailsResult.value.data?.success) {
                    const loadedLead = detailsResult.value.data.data?.lead;
                    if (loadedLead) setDetails(loadedLead);
                    else loadErrors.push('The lead details response was invalid.');
                } else {
                    const failure = detailsResult.status === 'rejected' ? detailsResult.reason : detailsResult.value.data;
                    loadErrors.push(failure?.response?.data?.message || failure?.message || 'Unable to load lead details.');
                }

                if (loadErrors.length > 0) {
                    setDetails(null);
                    setError(loadErrors.join(' '));
                    return;
                }

                const loadedLead = detailsResult.value.data.data.lead;
                const selectedActivity = loadedLead.selected_followup;
                const initialStatusId = mode === 'update'
                    ? selectedActivity?.current_status_id
                    : loadedLead.lead_status_id;
                const savedOutcome = mode === 'update' ? selectedActivity?.call_outcome || '' : '';
                const isNotConnected = NOT_CONNECTED_REASONS.includes(savedOutcome);
                const nextForm = {
                    ...initialForm,
                    current_status_id: loadedStatuses.some((status) => String(status.id) === String(initialStatusId))
                        ? String(initialStatusId)
                        : '',
                    customer_need: mode === 'update'
                        ? selectedActivity?.customer_need || ''
                        : loadedLead.customer_need || '',
                    call_outcome: savedOutcome,
                    closure_reason: selectedActivity?.closure_reason || '',
                    remarks: mode === 'update' ? selectedActivity?.remarks || '' : '',
                    next_followup_date: mode === 'record' ? '' : parseDate(loadedLead.current_schedule?.next_followup_date),
                    next_followup_time: mode === 'record' ? '' : loadedLead.current_schedule?.next_followup_time
                        ? String(loadedLead.current_schedule.next_followup_time).slice(0, 5)
                        : '',
                    connection: isNotConnected ? 'not-connected' : 'connected',
                    other_reason: isNotConnected && savedOutcome === 'Other reason'
                        ? (selectedActivity?.remarks || '')
                        : ''
                };
                initialFormRef.current = nextForm;
                setForm(nextForm);
            } catch (err) {
                if (active) setError(err.response?.data?.message || err.message || 'Unable to load follow-up details.');
            } finally {
                if (active) setLoading(false);
            }
        };
        loadFormData();
        return () => {
            active = false;
        };
    }, [lead.id, lead.lead_name, lead.followup_id, mode]);

    useEffect(() => {
        let active = true;
        const loadHistory = async () => {
            setHistoryLoading(true);
            try {
                const response = await api.get('/crm_followups/history.php', {
                    params: { lead_id: lead.id, page: historyPage, page_size: historyPageSize }
                });
                if (!response.data?.success) {
                    setError(response.data?.message || 'Unable to load follow-up history.');
                    return;
                }
                if (active) {
                    setHistory(response.data.data?.history || []);
                    setHistoryTotal(Number(response.data.data?.pagination?.total || 0));
                }
            } catch (err) {
                if (active) setError(err.response?.data?.message || 'Unable to load follow-up history.');
            } finally {
                if (active) setHistoryLoading(false);
            }
        };
        loadHistory();
        return () => {
            active = false;
        };
    }, [lead.id, historyPage, historyPageSize, setHistoryTotal]);

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => {
            if (name === 'next_followup_date' && !value) {
                return { ...current, next_followup_date: '', next_followup_time: '' };
            }
            if (name === 'connection') {
                return { ...current, connection: value, call_outcome: '', closure_reason: '', other_reason: '' };
            }
            return { ...current, [name]: value };
        });
    };

    const scheduleQuickly = (hours) => {
        const next = makeLocalSchedule(details?.business_now, hours);
        if (!next) {
            setError('Unable to determine the application business time. Refresh and try again.');
            return;
        }
        setError('');
        setForm((current) => ({
            ...current,
            next_followup_date: next.date,
            next_followup_time: next.time
        }));
    };

    const saveFollowup = async (event) => {
        event.preventDefault();
        if (savingRef.current) return;
        if (!form.current_status_id) {
            setError('Select an active lead status.');
            return;
        }
        if (form.connection === 'not-connected' && !form.call_outcome) {
            setError('Select a reason for the call not connecting.');
            return;
        }
        const callOutcome = form.connection === 'not-connected'
            ? form.call_outcome
            : (mode === 'update' ? form.call_outcome.trim() : '');
        const remarks = form.connection === 'not-connected' && form.call_outcome === 'Other reason'
            ? form.other_reason.trim()
            : form.remarks.trim();
        if (!remarks) {
            setError(form.connection === 'not-connected' && form.call_outcome === 'Other reason'
                ? 'Enter the other reason.'
                : 'Enter remarks for this follow-up.');
            return;
        }
        if (!detailsLoaded || !['record', 'update'].includes(mode)) {
            setError('Follow-up details are not ready. Refresh and try again.');
            return;
        }
        if (mode === 'update' && (!Number.isInteger(Number(lead.followup_id)) || Number(lead.followup_id) <= 0)) {
            setError('The selected follow-up is missing its record ID. Refresh and try again.');
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError('');
        try {
            const payload = {
                mode,
                lead_id: Number(lead.id),
                current_status_id: Number(form.current_status_id),
                connection_type: form.connection,
                followup_id: mode === 'update' ? Number(lead.followup_id) : null,
                scheduled_followup_id: mode === 'record'
                    ? Number(lead.followup_id || schedule?.id) || null
                    : null
            };
            const values = {
                customer_need: form.customer_need.trim(),
                call_outcome: callOutcome,
                closure_reason: form.closure_reason,
                remarks
            };
            Object.entries(values).forEach(([field, value]) => {
                if (
                    mode !== 'update' ||
                    String(value ?? '') !== String(initialFormRef.current[field] ?? '')
                ) {
                    payload[field] = value;
                }
            });
            const nextDate = form.next_followup_date || null;
            const nextTime = form.next_followup_date ? form.next_followup_time || null : null;
            const scheduleChanged = mode !== 'update' ||
                String(form.next_followup_date || '') !== String(initialFormRef.current.next_followup_date || '') ||
                String(form.next_followup_time || '') !== String(initialFormRef.current.next_followup_time || '');
            if (scheduleChanged) {
                payload.next_followup_date = nextDate;
                payload.next_followup_time = nextTime;
            }
            const response = await api.post('/crm_followups/save.php', payload);
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to save follow-up.');
                return;
            }
            window.dispatchEvent(new Event('crm:lead-updated'));
            onSaved?.(response.data.message || 'Follow-up saved successfully.');
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save follow-up.');
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    };

    const headerContent = (
        <div className="followup-modal-header">
            <div className="followup-modal-title">
                <span>{mode === 'view' ? 'Follow-up history' : mode === 'update' ? 'Edit follow-up' : 'Record follow-up'}</span>
                <strong>{details?.lead_name || lead.lead_name}</strong>
            </div>
            <nav className="followup-modal-tabs" aria-label="Follow-up details">
                {(mode === 'view' ? ['Other'] : TABS).map((tab) => {
                    const tabLabel = mode === 'view' && tab === 'Other' ? 'Follow-up History' : tab;
                    return (
                        <button
                            key={tab}
                            type="button"
                            className={`followup-modal-tab${activeTab === tab ? ' active' : ''}`}
                            onClick={() => setActiveTab(tab)}
                            aria-current={activeTab === tab ? 'page' : undefined}
                        >
                            {tabLabel}
                        </button>
                    );
                })}
            </nav>
            <button type="button" className="close-btn" onClick={onClose} disabled={saving} aria-label="Close follow-up">
                &times;
            </button>
        </div>
    );

    return (
        <Modal
            isOpen
            onClose={saving ? undefined : onClose}
            title={`${mode === 'view' ? 'Follow-up History' : mode === 'update' ? 'Edit Follow-up' : 'Follow-up'}: ${lead.lead_name}`}
            headerContent={headerContent}
            maxWidth="980px"
            className="followup-modal"
            footer={(
                mode === 'view' ? (
                    <button type="button" className="btn btn-outline" onClick={onClose}>Close</button>
                ) : (
                    <>
                        <button type="button" className="btn btn-outline" onClick={onClose} disabled={saving}>Cancel</button>
                        <button
                            type="submit"
                            form="followup-form"
                            className="btn btn-primary followup-save-button"
                            disabled={saving || loading || !detailsLoaded || statuses.length === 0 || activeTab !== 'Follow-up'}
                        >
                            {saving ? 'Saving...' : mode === 'update' ? 'Save Follow-up' : 'Save Follow-up'}
                        </button>
                    </>
                )
            )}
        >
            {error && <div className="alert alert-danger followup-message" role="alert">{error}</div>}
            {loading ? (
                <div className="followup-loading">Loading lead details and follow-up history...</div>
            ) : detailsLoaded ? (
                <>
                    {statusOptionsLoaded && statuses.length === 0 && (
                        <div className="alert alert-warning" role="alert">
                            No active lead statuses are configured. Ask an authorized user to add one in Lead Status Master.
                            {hasPermission('crm_lead_status.view') && <> <Link to="/crm/lead-statuses">Open Lead Status Master</Link>.</>}
                        </div>
                    )}

                    {activeTab === 'Lead Information' && (
                        <section className="followup-modal-panel" aria-label="Lead information">
                            <div className="followup-section-heading">
                                <div><span>Lead Information</span><small>Read-only details from the selected lead</small></div>
                            </div>
                            <div className="followup-information-grid">
                                <Detail label="Lead Name" value={details.lead_name} />
                                <Detail label="Mobile Number" value={details.mobile_no} />
                                {details.email && <Detail label="Email" value={details.email} />}
                                <Detail label="Campaign Name" value={details.campaign_name} />
                                <Detail label="Pipeline" value={details.pipeline_name} />
                                <Detail label="Lead Source" value={details.lead_source} />
                                <Detail label="Lead Stage" value={details.lead_stage_name} />
                                <Detail label="Lead Status" value={details.lead_status} />
                                <Detail label="Assigned Employee" value={details.assigned_employee} />
                            </div>
                        </section>
                    )}

                    {activeTab === 'Follow-up' && (
                        <form id="followup-form" className="followup-form" onSubmit={saveFollowup}>
                            <section className="followup-modal-panel">
                                <div className="followup-section-heading">
                                    <div><span>Call connection</span><small>Select the result of the call you just made</small></div>
                                </div>
                                <div className="followup-connection-options" role="radiogroup" aria-label="Call connection">
                                    <label className={form.connection === 'not-connected' ? 'selected not-connected' : ''}>
                                        <input type="radio" name="connection" value="not-connected" checked={form.connection === 'not-connected'} onChange={handleChange} />
                                        <span className="connection-indicator" />
                                        <span><strong>Not Connected</strong><small>Call did not reach the lead</small></span>
                                    </label>
                                    <label className={form.connection === 'connected' ? 'selected connected' : ''}>
                                        <input type="radio" name="connection" value="connected" checked={form.connection === 'connected'} onChange={handleChange} />
                                        <span className="connection-indicator" />
                                        <span><strong>Yes Connected</strong><small>Call connected with the lead</small></span>
                                    </label>
                                </div>
                            </section>

                            {form.connection === 'connected' ? (
                                <section className="followup-modal-panel">
                                    <div className="followup-section-heading"><div><span>Follow-up Details</span><small>Record the lead status and notes</small></div></div>
                                    <div className="followup-form-grid">
                                        <div className="followup-form-group">
                                            <label htmlFor="followup-status">Lead Status <span className="required">*</span></label>
                                            <select id="followup-status" name="current_status_id" className="form-control" value={form.current_status_id} onChange={handleChange} required>
                                                <option value="">Select active status</option>
                                                {statuses.filter((status) => form.call_outcome === 'Not Interested'
                                                    ? status.status_type === 'Lost'
                                                    : status.status_type !== 'Lost').map((status) => (
                                                    <option key={status.id} value={status.id}>{status.status_name}</option>
                                                ))}
                                            </select>
                                        </div>
                                        {form.call_outcome === 'Not Interested' && (
                                            <div className="followup-form-group followup-form-wide">
                                                <label htmlFor="followup-closure-reason">Closure Reason <span className="required">*</span></label>
                                                <select
                                                    id="followup-closure-reason"
                                                    name="closure_reason"
                                                    className="form-control"
                                                    value={form.closure_reason}
                                                    onChange={handleChange}
                                                    required
                                                >
                                                    <option value="">Select reason</option>
                                                    {['Not Interested', 'Price Too High', 'Already Purchased', 'Other'].map((reason) => (
                                                        <option key={reason} value={reason}>{reason}</option>
                                                    ))}
                                                </select>
                                            </div>
                                        )}
                                        <div className="followup-form-group followup-form-wide">
                                            <label htmlFor="followup-customer-need">Customer Need / Requirement</label>
                                            <textarea id="followup-customer-need" name="customer_need" className="form-control" rows="2" maxLength={65535} value={form.customer_need} onChange={handleChange} />
                                        </div>
                                        <div className="followup-form-group followup-form-wide">
                                            <label htmlFor="followup-remarks">Remarks <span className="required">*</span></label>
                                            <textarea id="followup-remarks" name="remarks" className="form-control" rows="3" maxLength={65535} value={form.remarks} onChange={handleChange} required />
                                        </div>
                                    </div>
                                </section>
                            ) : (
                                <section className="followup-modal-panel followup-reason-panel">
                                    <div className="followup-section-heading">
                                        <div><span>Reason for Not Connected <em>*</em></span><small>Not connected calls do not automatically change lead status</small></div>
                                    </div>
                                    <div className="followup-reason-grid" role="radiogroup" aria-label="Reason for call not connected">
                                        {NOT_CONNECTED_REASONS.map((reason) => (
                                            <label key={reason} className={form.call_outcome === reason ? 'selected' : ''}>
                                                <input type="radio" name="not-connected-reason" value={reason} checked={form.call_outcome === reason} onChange={() => setForm((current) => ({ ...current, call_outcome: reason }))} />
                                                <span>{reason}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {form.call_outcome === 'Other reason' && (
                                        <div className="followup-form-group followup-form-wide">
                                            <label htmlFor="followup-other-reason">Other reason details <span className="required">*</span></label>
                                            <textarea id="followup-other-reason" className="form-control" rows="2" value={form.other_reason} onChange={(event) => setForm((current) => ({ ...current, other_reason: event.target.value }))} required />
                                        </div>
                                    )}
                                    <div className="followup-form-grid followup-not-connected-status">
                                        <div className="followup-form-group">
                                            <label htmlFor="followup-status">Lead Status <span className="required">*</span></label>
                                            <select id="followup-status" name="current_status_id" className="form-control" value={form.current_status_id} onChange={handleChange} required>
                                                <option value="">Select active status</option>
                                                {statuses.filter((status) => status.status_type !== 'Lost').map((status) => (
                                                    <option key={status.id} value={status.id}>{status.status_name}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="followup-form-group">
                                            <label htmlFor="followup-customer-need">Customer Need / Requirement</label>
                                            <textarea id="followup-customer-need" name="customer_need" className="form-control" rows="2" maxLength={65535} value={form.customer_need} onChange={handleChange} />
                                        </div>
                                        {form.call_outcome !== 'Other reason' && (
                                            <div className="followup-form-group followup-form-wide">
                                                <label htmlFor="followup-remarks">Remarks <span className="required">*</span></label>
                                                <textarea id="followup-remarks" name="remarks" className="form-control" rows="2" maxLength={65535} value={form.remarks} onChange={handleChange} required />
                                            </div>
                                        )}
                                    </div>
                                </section>
                            )}

                            <section className="followup-modal-panel">
                                <div className="followup-section-heading">
                                    <div><span>Next Follow-up</span><small>{schedule ? 'Current pending appointment' : 'Optional: schedule another appointment'}</small></div>
                                    {schedule && <span className="followup-current-schedule">{formatDate(schedule.next_followup_date)}{schedule.next_followup_time ? ` · ${String(schedule.next_followup_time).slice(0, 5)}` : ''}</span>}
                                </div>
                                <div className="followup-quick-options">
                                    <button type="button" onClick={() => scheduleQuickly(1)}>1 hour</button>
                                    <button type="button" onClick={() => scheduleQuickly(6)}>6 hours</button>
                                    <button type="button" onClick={() => scheduleQuickly(24)}>1 day</button>
                                </div>
                                <div className="followup-form-grid followup-schedule-fields">
                                    <div className="followup-form-group">
                                        <label htmlFor="next-followup-date">Pick date &amp; time</label>
                                        <input id="next-followup-date" name="next_followup_date" type="date" className="form-control" value={form.next_followup_date} onChange={handleChange} />
                                    </div>
                                    <div className="followup-form-group">
                                        <label htmlFor="next-followup-time">Time</label>
                                        <input id="next-followup-time" name="next_followup_time" type="time" className="form-control" value={form.next_followup_time} onChange={handleChange} disabled={!form.next_followup_date} />
                                    </div>
                                    {form.next_followup_date && (
                                        <button
                                            type="button"
                                            className="followup-clear-schedule"
                                            onClick={() => setForm((current) => ({ ...current, next_followup_date: '', next_followup_time: '' }))}
                                        >
                                            No next follow-up
                                        </button>
                                    )}
                                </div>
                            </section>
                        </form>
                    )}

                    {activeTab === 'Other' && (
                        <section className="followup-modal-panel followup-history">
                            <div className="followup-section-heading"><div><span>Follow-up History</span><small>Genuine activities recorded for this lead</small></div></div>
                            {historyLoading ? (
                                <p className="followup-history-empty">Loading follow-up history...</p>
                            ) : history.length === 0 ? (
                                <p className="followup-history-empty">No previous follow-ups for this lead.</p>
                            ) : (
                                <ol>
                                    {history.map((entry) => (
                                        <li key={entry.id}>
                                            <div className="followup-history-heading">
                                                <strong>{formatDateTime(entry.created_at)}</strong>
                                                <span className="followup-status-badge" style={{ '--lead-status-color': entry.status_color || '#64748b' }}>
                                                    {entry.status_name || 'Status unavailable'}
                                                </span>
                                            </div>
                                            <p><b>Call connection:</b> {entry.connection_result || '—'}</p>
                                            {entry.call_outcome && <p><b>Outcome / reason:</b> {entry.call_outcome}</p>}
                                            <p><b>Customer need:</b> {entry.customer_need || '—'}</p>
                                            <p><b>Remarks:</b> {entry.remarks || '—'}</p>
                                            <p><b>Next follow-up:</b> {entry.next_followup_date
                                                ? `${formatDate(entry.next_followup_date)}${entry.next_followup_time ? ` ${String(entry.next_followup_time).slice(0, 5)}` : ''}`
                                                : 'Not scheduled'}</p>
                                            {entry.updated_by && <small>Recorded by {entry.updated_by}</small>}
                                            {entry.completed_at && <small>Completed {formatDateTime(entry.completed_at)}</small>}
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <Pagination
                                currentPage={historyPage}
                                pageSize={historyPageSize}
                                totalItems={historyTotal}
                                onPageChange={setHistoryPage}
                                onPageSizeChange={setHistoryPageSize}
                                itemName="follow-ups"
                            />
                        </section>
                    )}
                </>
            ) : null}
        </Modal>
    );
};

export default FollowupModal;
