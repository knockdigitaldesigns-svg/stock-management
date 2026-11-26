import { useCallback, useEffect, useState } from 'react';
import { Check, Clock3, RefreshCw, FileCheck2, Eye } from 'lucide-react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { formatDate } from '../../utils/date';
import FollowupModal from './FollowupModal';
import './FollowupsPage.css';

const views = ['Today', 'Overdue', 'Upcoming', 'Completed'];
const emptyFilters = {
    followup_from: '',
    followup_to: '',
    created_from: '',
    created_to: '',
    search: '',
    campaign_id: '',
    pipeline_id: '',
    status_id: '',
    assigned_user_id: ''
};
const emptySummary = {
    assigned_today: 0,
    due_today: 0,
    completed_today: 0,
    overdue: 0
};

const formatTime = (value) => value ? String(value).slice(0, 5) : '—';
const formatFilterOption = (item, label) =>
    `${label}${String(item.state || '').toLowerCase() === 'inactive' ? ' (Inactive)' : ''}`;

const FollowupsPage = () => {
    const { hasPermission } = useAuth();
    const [view, setView] = useState('Today');
    const [followups, setFollowups] = useState([]);
    const [filters, setFilters] = useState(emptyFilters);
    const [appliedFilters, setAppliedFilters] = useState(emptyFilters);
    const [filterOptions, setFilterOptions] = useState({
        campaigns: [],
        pipelines: [],
        statuses: [],
        employees: []
    });
    const [canFilterAssignedEmployee, setCanFilterAssignedEmployee] = useState(false);
    const [summary, setSummary] = useState(emptySummary);
    const [businessDate, setBusinessDate] = useState('');
    const [selectedLead, setSelectedLead] = useState(null);
    const [loading, setLoading] = useState(true);
    const [filterOptionsLoading, setFilterOptionsLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(followups, 10, [view, appliedFilters], true);

    const loadFollowups = useCallback(async () => {
        try {
            const params = { view, page, page_size: pageSize };
            Object.entries(appliedFilters).forEach(([key, value]) => {
                if (value) params[key] = value;
            });
            const response = await api.get('/crm_followups/list.php', { params });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load follow-ups.');
                return;
            }
            setFollowups(response.data.data?.followups || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
            setSummary({ ...emptySummary, ...response.data.data?.summary });
            setBusinessDate(response.data.data?.business_date || '');
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load follow-ups.');
        } finally {
            setLoading(false);
        }
    }, [view, appliedFilters, page, pageSize, setTotalItems]);

    useEffect(() => {
        loadFollowups();
    }, [loadFollowups]);

    useEffect(() => {
        const refreshVisiblePage = () => {
            if (document.visibilityState === 'visible') loadFollowups();
        };
        const intervalId = window.setInterval(refreshVisiblePage, 30000);
        document.addEventListener('visibilitychange', refreshVisiblePage);
        return () => {
            window.clearInterval(intervalId);
            document.removeEventListener('visibilitychange', refreshVisiblePage);
        };
    }, [loadFollowups]);

    useEffect(() => {
        let active = true;
        const loadFilterOptions = async () => {
            try {
                const response = await api.get('/crm_followups/filters.php');
                if (!active) return;
                if (!response.data?.success) {
                    setError(response.data?.message || 'Unable to load follow-up filter options.');
                    return;
                }
                setFilterOptions({
                    campaigns: response.data.data?.filters?.campaigns || [],
                    pipelines: response.data.data?.filters?.pipelines || [],
                    statuses: response.data.data?.filters?.statuses || [],
                    employees: response.data.data?.filters?.employees || []
                });
                setCanFilterAssignedEmployee(Boolean(response.data.data?.can_filter_assigned_employee));
            } catch (err) {
                if (active) setError(err.response?.data?.message || 'Unable to load follow-up filter options.');
            } finally {
                if (active) setFilterOptionsLoading(false);
            }
        };
        loadFilterOptions();
        return () => {
            active = false;
        };
    }, []);

    const canAdd = hasPermission('crm_followups.add');
    const canView = hasPermission('crm_followups.view');
    const canEdit = hasPermission('crm_followups.edit');
    const canConvert = hasPermission('crm_closures.add');

    const applyFilters = (event) => {
        event.preventDefault();
        if (filters.followup_from && filters.followup_to && filters.followup_from > filters.followup_to) {
            setError('Follow-up Date From must be on or before Follow-up Date To.');
            return;
        }
        if (filters.created_from && filters.created_to && filters.created_from > filters.created_to) {
            setError('Lead Created Date From must be on or before Lead Created Date To.');
            return;
        }
        setError('');
        setLoading(true);
        setAppliedFilters({ ...filters });
    };

    const resetFilters = () => {
        setError('');
        setFilters({ ...emptyFilters });
        setLoading(true);
        setAppliedFilters({ ...emptyFilters });
    };

    const refreshFollowups = () => {
        setError('');
        setLoading(true);
        loadFollowups();
    };

    const completeFollowup = async (item) => {
        if (saving) return;
        setSaving(true);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_followups/complete.php', { id: Number(item.id) });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to complete follow-up.');
                return;
            }
            window.dispatchEvent(new Event('crm:lead-updated'));
            setMessage(response.data.message || 'Follow-up marked completed.');
            setLoading(true);
            await loadFollowups();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to complete follow-up.');
        } finally {
            setSaving(false);
        }
    };

    const onFollowupSaved = async (successMessage) => {
        setSelectedLead(null);
        setMessage(successMessage);
        setLoading(true);
        await loadFollowups();
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Follow-ups</h2>
                    <p className="page-subtitle">Track scheduled and completed lead follow-ups within your authorized scope.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={refreshFollowups} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>

            {message && <div className="alert alert-success followup-message">{message}</div>}
            {error && <div className="alert alert-danger followup-message" role="alert">{error}</div>}

            <section className="followup-summary-grid" aria-label="Daily follow-up summary">
                {businessDate && <p className="followup-summary-date">Daily totals use the application business date: {formatDate(businessDate)}</p>}
                <article className="followup-summary-card">
                    <span>Leads Assigned Today</span>
                    <strong>{summary.assigned_today}</strong>
                    <small>Distinct leads assigned to you today</small>
                </article>
                <article className="followup-summary-card">
                    <span>Follow-ups Due Today</span>
                    <strong>{summary.due_today}</strong>
                    <small>Pending schedules due today</small>
                </article>
                <article className="followup-summary-card">
                    <span>Completed Follow-ups Today</span>
                    <strong>{summary.completed_today}</strong>
                    <small>Genuine activities completed today</small>
                </article>
                <article className="followup-summary-card followup-summary-overdue">
                    <span>Overdue Follow-ups</span>
                    <strong>{summary.overdue}</strong>
                    <small>Pending schedules past their due date</small>
                </article>
            </section>

            <div className="card followups-card">
                <form className="followup-filter-panel" onSubmit={applyFilters}>
                    <p className="followup-filter-note">
                        {view === 'Completed'
                            ? 'Follow-up date filters use the activity completion date in Completed. Daily summary cards remain based on today.'
                            : 'Follow-up date filters use the pending scheduled date. Daily summary cards remain based on today.'}
                    </p>
                    <div className="followup-filter-grid">
                        <label className="followup-filter-field">
                            <span>Follow-up Date From{view === 'Completed' ? ' (Completed)' : ''}</span>
                            <input type="date" className="form-control" value={filters.followup_from} onChange={(event) => setFilters((current) => ({ ...current, followup_from: event.target.value }))} />
                        </label>
                        <label className="followup-filter-field">
                            <span>Follow-up Date To{view === 'Completed' ? ' (Completed)' : ''}</span>
                            <input type="date" className="form-control" value={filters.followup_to} onChange={(event) => setFilters((current) => ({ ...current, followup_to: event.target.value }))} />
                        </label>
                        <label className="followup-filter-field">
                            <span>Lead Created Date From</span>
                            <input type="date" className="form-control" value={filters.created_from} onChange={(event) => setFilters((current) => ({ ...current, created_from: event.target.value }))} />
                        </label>
                        <label className="followup-filter-field">
                            <span>Lead Created Date To</span>
                            <input type="date" className="form-control" value={filters.created_to} onChange={(event) => setFilters((current) => ({ ...current, created_to: event.target.value }))} />
                        </label>
                        <label className="followup-filter-field followup-filter-search">
                            <span>Search by Lead Name or Mobile</span>
                            <input
                                type="search"
                                className="form-control"
                                placeholder="Enter name or mobile number"
                                value={filters.search}
                                onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
                            />
                        </label>
                        <label className="followup-filter-field">
                            <span>Campaign</span>
                            <select className="form-control" value={filters.campaign_id} onChange={(event) => setFilters((current) => ({ ...current, campaign_id: event.target.value }))} disabled={filterOptionsLoading}>
                                <option value="">All Campaigns</option>
                                {filterOptions.campaigns.map((item) => <option key={item.id} value={item.id}>{formatFilterOption(item, item.name)}</option>)}
                            </select>
                        </label>
                        <label className="followup-filter-field">
                            <span>Pipeline</span>
                            <select className="form-control" value={filters.pipeline_id} onChange={(event) => setFilters((current) => ({ ...current, pipeline_id: event.target.value }))} disabled={filterOptionsLoading}>
                                <option value="">All Pipelines</option>
                                {filterOptions.pipelines.map((item) => <option key={item.id} value={item.id}>{formatFilterOption(item, item.name)}</option>)}
                            </select>
                        </label>
                        <label className="followup-filter-field">
                            <span>Lead Status</span>
                            <select className="form-control" value={filters.status_id} onChange={(event) => setFilters((current) => ({ ...current, status_id: event.target.value }))} disabled={filterOptionsLoading}>
                                <option value="">All Lead Statuses</option>
                                {filterOptions.statuses.map((item) => <option key={item.id} value={item.id}>{formatFilterOption(item, item.name)}</option>)}
                            </select>
                        </label>
                        {canFilterAssignedEmployee && (
                            <label className="followup-filter-field">
                                <span>Assigned Employee</span>
                                <select className="form-control" value={filters.assigned_user_id} onChange={(event) => setFilters((current) => ({ ...current, assigned_user_id: event.target.value }))} disabled={filterOptionsLoading}>
                                    <option value="">All Employees</option>
                                    {filterOptions.employees.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {formatFilterOption(item, `${item.employee_name} (${item.role_name})`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    <div className="followup-filter-actions">
                        <button type="submit" className="btn btn-primary" disabled={loading}>Search</button>
                        <button type="button" className="btn btn-outline" onClick={resetFilters} disabled={loading}>Reset</button>
                    </div>
                </form>
                <div className="followup-tabs" role="tablist" aria-label="Follow-up views">
                    {views.map((item) => (
                        <button
                            key={item}
                            type="button"
                            role="tab"
                            aria-selected={view === item}
                            className={`followup-tab ${view === item ? 'active' : ''}`}
                            onClick={() => {
                                if (item !== view) {
                                    setError('');
                                    setLoading(true);
                                    setView(item);
                                }
                            }}
                        >
                            {item}
                        </button>
                    ))}
                </div>
                <div className="followup-toolbar">
                    <span className="followup-count">{totalItems} {totalItems === 1 ? 'follow-up' : 'follow-ups'}</span>
                </div>
                <div className="table-container followup-table-container">
                    <table className="followup-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Lead</th>
                                <th>Mobile</th>
                                <th>Campaign / Pipeline</th>
                                <th>Lead Status</th>
                                <th>Call Outcome / Remarks</th>
                                <th>Follow-up Date</th>
                                <th>Assigned Employee</th>
                                <th>Follow-up Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="10" className="followup-table-empty">Loading follow-ups...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr><td colSpan="10" className="followup-table-empty">
                                    {`No ${view.toLowerCase()} follow-ups match these filters.`}
                                </td></tr>
                            ) : paginatedItems.map((item, index) => (
                                <tr key={item.id}>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td className="followup-lead-name">{item.lead_name}</td>
                                    <td>{item.mobile_no}</td>
                                    <td>
                                        <div>{item.campaign_name || '—'}</div>
                                        <small>{item.pipeline_name || '—'}</small>
                                    </td>
                                    <td>
                                        {item.lead_status ? (
                                            <span className="followup-status-badge" style={{ '--lead-status-color': item.lead_status_color || '#64748b' }}>
                                                {item.lead_status}
                                            </span>
                                        ) : '—'}
                                    </td>
                                    <td className="followup-outcome-cell">
                                        {Number(item.is_assignment_schedule) === 1 ? (
                                            <>
                                                <div>Scheduled follow-up</div>
                                                <small>Created during lead assignment</small>
                                            </>
                                        ) : (
                                            <>
                                                <div>{item.connection_result || item.call_outcome || '—'}</div>
                                                {item.call_outcome && item.connection_result && item.call_outcome !== item.connection_result && (
                                                    <small>Outcome / reason: {item.call_outcome}</small>
                                                )}
                                                {item.remarks && <small>{item.remarks}</small>}
                                                {item.latest_activity_updated_by && <small>Recorded by {item.latest_activity_updated_by}</small>}
                                            </>
                                        )}
                                    </td>
                                    <td>
                                        {item.completed_at ? (
                                            <span className="followup-date">
                                                <Clock3 size={14} />
                                                {formatDate(item.completed_at)} {String(item.completed_at).slice(11, 16)}
                                            </span>
                                        ) : item.next_followup_date ? (
                                            <span className="followup-date">
                                                <Clock3 size={14} />
                                                {formatDate(item.next_followup_date)} {formatTime(item.next_followup_time)}
                                            </span>
                                        ) : 'Not scheduled'}
                                    </td>
                                    <td>{item.assigned_user_id ? item.assigned_employee : 'Unassigned'}</td>
                                    <td>{item.completed_at ? `Completed ${formatDate(item.completed_at)}` : 'Pending'}</td>
                                    <td>
                                        <div className="followup-row-actions">
                                            {canAdd && item.lead_status_type !== 'Lost' && (
                                                <button
                                                    type="button"
                                                    className="followup-action-btn"
                                                    onClick={() => setSelectedLead({
                                                        ...item,
                                                        id: item.lead_id,
                                                        followup_id: item.id,
                                                        followup_mode: Number(item.is_scheduled_followup) === 1 ? 'record' : 'update'
                                                    })}
                                                    title={`${Number(item.is_scheduled_followup) === 1 ? 'Record' : 'Edit'} follow-up for ${item.lead_name}`}
                                                    aria-label={`${Number(item.is_scheduled_followup) === 1 ? 'Record' : 'Edit'} follow-up for ${item.lead_name}`}
                                                >
                                                    {Number(item.is_scheduled_followup) === 1 ? 'Record' : 'Edit'}
                                                </button>
                                            )}
                                            {canConvert && item.lead_status_type === 'Resolved' && item.lead_status_state === 'Active' && (
                                                <Link className="followup-action-btn" to="/crm/lead-converter" state={{ leadId: Number(item.lead_id) }} aria-label={`Convert ${item.lead_name}`}>
                                                    <FileCheck2 size={15} /> Convert
                                                </Link>
                                            )}
                                            {!item.completed_at && canEdit && (
                                                <button type="button" className="followup-action-icon" onClick={() => completeFollowup(item)} disabled={saving} title="Mark completed" aria-label={`Mark follow-up for ${item.lead_name} completed`}>
                                                    <Check size={17} />
                                                </button>
                                            )}
                                            {canView && (
                                                <button
                                                    type="button"
                                                    className="followup-action-btn"
                                                    onClick={() => setSelectedLead({
                                                        ...item,
                                                        id: item.lead_id,
                                                        followup_mode: 'view'
                                                    })}
                                                    title={`View follow-up history for ${item.lead_name}`}
                                                    aria-label={`View follow-up history for ${item.lead_name}`}
                                                >
                                                    <Eye size={15} /> View
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={totalItems}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="follow-ups"
                />
            </div>

            {selectedLead && (canAdd || (selectedLead.followup_mode === 'view' && canView)) && (
                <FollowupModal
                    lead={selectedLead}
                    mode={selectedLead.followup_mode}
                    onClose={() => setSelectedLead(null)}
                    onSaved={onFollowupSaved}
                />
            )}
        </div>
    );
};

export default FollowupsPage;
