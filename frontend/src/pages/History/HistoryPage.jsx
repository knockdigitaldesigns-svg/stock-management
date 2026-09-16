import { useEffect, useState } from 'react';
import { Eye, RefreshCw, Search } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import './HistoryPage.css';

const emptyFilters = { search: '', module: '', action: '', date_from: '', date_to: '', changed_by: '' };
const FIELD_LABELS = {
    mobile_no: 'Mobile No', employee_name: 'Employee Name', primary_mobile_no: 'Primary Mobile No', secondary_mobile_no: 'Secondary Mobile No',
    role_id: 'Role', vehicle_no: 'Vehicle No', vehicle_type_id: 'Vehicle Type', imei_no: 'IMEI No', device_model_id: 'Device Model',
    sim_no_1: 'SIM No 1', sim_no_2: 'SIM No 2', installation_date: 'Installation Date', validity_months: 'Validity Months',
    platform_id: 'Platform', owner_id: 'Owner', owner: 'Owner', customer_id: 'Customer', customer: 'Customer', device_id: 'Device', device: 'Device', sim: 'SIM',
    lead_closure_id: 'Lead Closure', installation_person_id: 'Installation Person', record_snapshot: 'Record Snapshot'
};

const formatDateTime = (value) => {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const hours = date.getHours();
    return `${day}-${month}-${date.getFullYear()} ${String(hours % 12 || 12).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')} ${hours >= 12 ? 'PM' : 'AM'}`;
};
const fieldLabel = (field) => FIELD_LABELS[field] || String(field || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const parseValue = (value) => {
    if (value === null || value === undefined || value === '') return null;
    try { return JSON.parse(value); } catch { return value; }
};
const displayValue = (value) => {
    const parsed = parseValue(value);
    if (parsed === null) return '-';
    return typeof parsed === 'object' ? JSON.stringify(parsed, null, 2) : String(parsed);
};
const cleanSnapshot = (snapshot) => Object.fromEntries(Object.entries(snapshot || {}).filter(([key]) => key !== 'id' && !key.endsWith('_id')));
const auditDisplayValue = (record, key) => record?.[key] !== undefined ? displayValue(record[key]) : displayValue(record?.[key === 'old_display_value' ? 'old_value' : 'new_value']);
const arrayValue = (value) => {
    const parsed = parseValue(value);
    return Array.isArray(parsed) ? parsed : [];
};
const summary = (group) => {
    if (group.action === 'Delete') return `${group.module} record deleted`;
    if (group.action === 'Create') return `New ${group.module.toLowerCase()} created`;
    if (group.module === 'Role Permissions') return 'Permissions updated';
    const fields = (group.records || []).map((record) => fieldLabel(record.field_changed));
    return `${fields.slice(0, 2).join(', ')}${fields.length > 2 ? ` +${fields.length - 2} more` : ''} changed`;
};

const HistoryPage = () => {
    const [history, setHistory] = useState([]);
    const [modules, setModules] = useState([]);
    const [users, setUsers] = useState([]);
    const [filters, setFilters] = useState(emptyFilters);
    const [appliedFilters, setAppliedFilters] = useState(emptyFilters);
    const [pagination, setPagination] = useState({ page: 1, page_size: 25, total: 0, total_pages: 1 });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(null);

    const loadHistory = async (page = 1, nextFilters = appliedFilters, pageSize = pagination.page_size) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page, page_size: pageSize });
            Object.entries(nextFilters).forEach(([key, value]) => value && params.set(key, value));
            const response = await api.get(`/history/list.php?${params.toString()}`);
            const data = response.data?.data || {};
            setHistory(data.history || []);
            setModules(data.modules || []);
            setUsers(data.users || []);
            setPagination(data.pagination || { page, page_size: pageSize, total: 0, total_pages: 1 });
            setError('');
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to load audit history.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { loadHistory(); }, []);
    const updateFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const applyFilters = (event) => { event.preventDefault(); setAppliedFilters(filters); loadHistory(1, filters, pagination.page_size); };
    const resetFilters = () => { setFilters(emptyFilters); setAppliedFilters(emptyFilters); loadHistory(1, emptyFilters, pagination.page_size); };

    return (
        <div className="page-container history-page">
            <div className="page-header"><div><h2>History</h2><p className="page-subtitle">Global application audit history</p></div><button className="btn btn-outline" type="button" onClick={() => loadHistory()} disabled={loading}><RefreshCw size={16} /> Refresh</button></div>
            {error && <div className="alert alert-danger">{error}</div>}
            <div className="card">
                <form className="history-filters" onSubmit={applyFilters}>
                    <label className="history-search"><Search size={16} /><input value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder="Search history..." /></label>
                    <select value={filters.module} onChange={(event) => updateFilter('module', event.target.value)} aria-label="Filter by module"><option value="">All modules</option>{modules.map((module) => <option key={module}>{module}</option>)}</select>
                    <select value={filters.action} onChange={(event) => updateFilter('action', event.target.value)} aria-label="Filter by action"><option value="">All actions</option><option>Create</option><option>Edit</option><option>Delete</option></select>
                    <input type="date" value={filters.date_from} onChange={(event) => updateFilter('date_from', event.target.value)} aria-label="Date from" />
                    <input type="date" value={filters.date_to} onChange={(event) => updateFilter('date_to', event.target.value)} aria-label="Date to" />
                    <select
    value={filters.changed_by}
    onChange={(event) => updateFilter('changed_by', event.target.value)}
    aria-label="Filter by changed user"
>
    <option value="">All Users</option>

    {users.map((user) => (
        <option key={user.id} value={user.id}>
            {user.username}
        </option>
    ))}
</select>
                    <button className="btn btn-primary" type="submit">Apply</button><button className="btn btn-outline" type="button" onClick={resetFilters}>Reset</button>
                </form>
                <div className="table-container history-table-wrap">
                    <table className="history-table"><thead><tr><th>Date &amp; Time</th><th>Module</th><th>Action</th><th>Summary</th><th>Changed By</th><th>Actions</th></tr></thead>
                        <tbody>{loading ? <tr><td colSpan="6" className="text-center">Loading history...</td></tr> : history.length === 0 ? <tr><td colSpan="6" className="text-center empty-state">No history records found.</td></tr> : history.map((group) => <tr key={group.id}><td className="history-date">{formatDateTime(group.changed_at)}</td><td>{group.module}</td><td><span className={`badge badge-${String(group.action).toLowerCase()}`}>{group.action}</span></td><td className="history-summary">{summary(group)}</td><td>{group.changed_by_username || group.changed_by_name || group.changed_by_user_id || '-'}</td><td><button className="icon-btn view" type="button" title="View details" aria-label="View history details" onClick={() => setSelected(group)}><Eye size={16} /></button></td></tr>)}</tbody>
                    </table>
                </div>
                <Pagination currentPage={pagination.page} totalItems={pagination.total} pageSize={pagination.page_size} onPageChange={(page) => loadHistory(page)} onPageSizeChange={(size) => loadHistory(1, appliedFilters, size)} itemName="history entries" />
            </div>
            <Modal isOpen={Boolean(selected)} onClose={() => setSelected(null)} title="History Details" maxWidth="900px">
                {selected && <div className="history-details"><div className="history-detail-meta"><div><strong>Date &amp; Time</strong><span>{formatDateTime(selected.changed_at)}</span></div><div><strong>Module</strong><span>{selected.module}</span></div><div><strong>Action</strong><span className={`badge badge-${String(selected.action).toLowerCase()}`}>{selected.action}</span></div><div><strong>Changed By</strong><span>{selected.changed_by_username || selected.changed_by_name || selected.changed_by_user_id || '-'}</span></div></div>
                    {selected.action === 'Delete' ? <div className="history-detail-section"><h4>Deleted Record</h4><dl>{Object.entries(selected.records?.[0]?.old_display_snapshot || cleanSnapshot(parseValue(selected.records?.[0]?.old_value))).map(([key, value]) => <div key={key}><dt>{fieldLabel(key)}</dt><dd>{displayValue(value)}</dd></div>)}</dl></div> : <div className="history-detail-section"><h4>Changed Fields</h4><table className="history-detail-table"><thead><tr><th>Field</th><th>Old Value</th><th>New Value</th></tr></thead><tbody>{selected.records?.map((record) => { let oldValue = auditDisplayValue(record, 'old_display_value'); let newValue = auditDisplayValue(record, 'new_display_value'); if (selected.module === 'Role Permissions') { const oldItems = arrayValue(record.old_display_value); const newItems = arrayValue(record.new_display_value); const added = newItems.filter((item) => !oldItems.includes(item)); const removed = oldItems.filter((item) => !newItems.includes(item)); oldValue = removed.length ? `Permissions Removed:\n${removed.join('\n')}` : '-'; newValue = added.length ? `Permissions Added:\n${added.join('\n')}` : '-'; } return <tr key={record.id}><td>{fieldLabel(record.field_changed)}</td><td><pre>{oldValue}</pre></td><td><pre>{newValue}</pre></td></tr>; })}</tbody></table></div>}
                </div>}
            </Modal>
        </div>
    );
};

export default HistoryPage;
