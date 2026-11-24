import { useEffect, useState } from 'react';
import { CalendarDays, Clock3, Eye, RefreshCw, Search, UserCheck, UserX } from 'lucide-react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import './AttendancePage.css';

const today = () => {
    const date = new Date();
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
};

const formatDuration = (seconds) => {
    const totalMinutes = Math.floor(Number(seconds || 0) / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${hours}h ${String(minutes).padStart(2, '0')}m`;
};

const formatClock = (value) => value ? String(value).slice(11, 16) : '-';
const formatDelay = (seconds) => {
    if (!seconds) return '-';
    const minutes = Math.ceil(Number(seconds) / 60);
    return `${minutes} min`;
};

const SummaryCards = ({ summary = {} }) => (
    <div className="attendance-summary-grid">
        <div className="attendance-summary-card"><span className="attendance-summary-icon present"><UserCheck size={19} /></span><div><span>Present Days</span><strong>{summary.present_days || 0}</strong></div></div>
        <div className="attendance-summary-card"><span className="attendance-summary-icon half"><Clock3 size={19} /></span><div><span>Half Days</span><strong>{summary.half_days || 0}</strong></div></div>
        <div className="attendance-summary-card"><span className="attendance-summary-icon absent"><UserX size={19} /></span><div><span>Absent Days</span><strong>{summary.absent_days || 0}</strong></div></div>
        <div className="attendance-summary-card"><span className="attendance-summary-icon hours"><CalendarDays size={19} /></span><div><span>Total Work Time</span><strong>{formatDuration(summary.total_work_seconds)}</strong></div></div>
        <div className="attendance-summary-card"><span className="attendance-summary-icon late"><Clock3 size={19} /></span><div><span>Late Arrivals</span><strong>{summary.late_arrivals || 0}</strong></div></div>
        <div className="attendance-summary-card"><span className="attendance-summary-icon early"><Clock3 size={19} /></span><div><span>Early Logouts</span><strong>{summary.early_logouts || 0}</strong></div></div>
    </div>
);

const PeriodPicker = ({ period, date, onPeriodChange, onDateChange }) => {
    const currentYear = new Date().getFullYear();
    const selectedDate = period === 'daily'
        ? (date.length === 10 ? date : today())
        : period === 'monthly'
            ? (date.length >= 7 ? date.slice(0, 7) : today().slice(0, 7))
            : (date.length >= 4 ? date.slice(0, 4) : String(currentYear));
    const inputType = period === 'daily' ? 'date' : period === 'monthly' ? 'month' : 'number';

    return (
        <>
            <div className="attendance-filter-field">
                <label htmlFor="attendance-period">Report Period</label>
                <select
                    id="attendance-period"
                    className="form-control"
                    value={period}
                    onChange={(event) => {
                        const nextPeriod = event.target.value;
                        const nextDate = nextPeriod === 'daily'
                            ? (/^\d{4}-\d{2}-\d{2}$/.test(date) ? date : today())
                            : nextPeriod === 'monthly'
                                ? (/^\d{4}-\d{2}/.test(date) ? date.slice(0, 7) : today().slice(0, 7))
                                : (/^\d{4}/.test(date) ? date.slice(0, 4) : String(currentYear));
                        onPeriodChange(nextPeriod, nextDate);
                    }}
                >
                    <option value="daily">Daily</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </div>
            <div className="attendance-filter-field">
                <label htmlFor="attendance-period-date">{period === 'daily' ? 'Date' : period === 'monthly' ? 'Month' : 'Year'}</label>
                <input
                    id="attendance-period-date"
                    className="form-control"
                    type={inputType}
                    min={period === 'yearly' ? 2000 : undefined}
                    max={period === 'yearly' ? currentYear + 1 : undefined}
                    value={selectedDate}
                    onChange={(event) => onDateChange(event.target.value)}
                />
            </div>
        </>
    );
};

const AttendanceDetailsModal = ({ selected, onClose, loading, error }) => (
    <Modal isOpen={Boolean(selected)} onClose={onClose} title="Attendance Details" maxWidth="760px">
        {loading && <p>Loading attendance details...</p>}
        {error && <div className="alert alert-danger">{error}</div>}
        {selected && !loading && !error && <>
            <div className="attendance-detail-heading">
                <div><strong>{selected.employee_name}</strong><span>{selected.role} · {selected.attendance_date}</span></div>
                <span className={`attendance-status status-${String(selected.status).toLowerCase().replace(/\s+/g, '-')}`}>{selected.status}</span>
            </div>
            <div className="attendance-detail-stats">
                <div><span>Total work time</span><strong>{formatDuration(selected.total_work_seconds)}</strong></div>
                <div><span>First login</span><strong>{formatClock(selected.first_login_at)}</strong></div>
                <div><span>Final logout</span><strong>{formatClock(selected.final_logout_at)}</strong></div>
                <div><span>Late arrival</span><strong>{formatDelay(selected.late_seconds)}</strong></div>
                <div><span>Early logout</span><strong>{formatDelay(selected.early_logout_seconds)}</strong></div>
                <div><span>Office hours</span><strong>{selected.settings?.office_start_time}–{selected.settings?.office_end_time}</strong></div>
            </div>
            <h4 className="attendance-session-title">Login Sessions</h4>
            <div className="table-container">
                <table className="attendance-table">
                    <thead><tr><th>Login</th><th>Logout</th><th>Duration</th><th>Logout Type</th></tr></thead>
                    <tbody>
                        {selected.sessions?.length ? selected.sessions.map((session, index) => (
                            <tr key={`${session.login_at}-${index}`}>
                                <td>{formatClock(session.login_at)}</td>
                                <td>{formatClock(session.logout_at)}</td>
                                <td>{formatDuration(session.duration_seconds)}</td>
                                <td>{session.logout_type || 'In progress'}</td>
                            </tr>
                        )) : <tr><td colSpan="4" className="text-center">No login sessions found.</td></tr>}
                    </tbody>
                </table>
            </div>
        </>}
        <div className="attendance-modal-footer"><button type="button" className="btn btn-outline" onClick={onClose}>Close</button></div>
    </Modal>
);

const AdminAttendance = () => {
    const [period, setPeriod] = useState('daily');
    const [date, setDate] = useState(today());
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [applied, setApplied] = useState({ search: '', status: '' });
    const [rows, setRows] = useState([]);
    const [summary, setSummary] = useState({});
    const [pagination, setPagination] = useState({ page: 1, page_size: 25, total: 0, total_pages: 0 });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');

    const load = async (page = 1, nextPeriod = period, nextDate = date, filters = applied, pageSize = pagination.page_size) => {
        setLoading(true);
        try {
            const response = await api.get('/attendance/admin_list.php', {
                params: { period: nextPeriod, date: nextDate, search: filters.search, status: filters.status, page, page_size: pageSize }
            });
            const data = response.data?.data || {};
            setRows(data.rows || []);
            setSummary(data.summary || {});
            setPagination(data.pagination || { page, page_size: pageSize, total: 0, total_pages: 0 });
            setError('');
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to load attendance report.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        let active = true;
        api.get('/attendance/admin_list.php', {
            params: { period: 'daily', search: '', status: '', page: 1, page_size: 25 }
        }).then((response) => {
            if (!active) return;
            const data = response.data?.data || {};
            setDate(data.date_from || today());
            setRows(data.rows || []);
            setSummary(data.summary || {});
            setPagination(data.pagination || { page: 1, page_size: 25, total: 0, total_pages: 0 });
        }).catch((requestError) => {
            if (active) setError(requestError.response?.data?.message || 'Unable to load attendance report.');
        }).finally(() => {
            if (active) setLoading(false);
        });
        return () => { active = false; };
    }, []);

    const viewDetails = async (row) => {
        if (!row.id) return;
        setSelected({ employee_name: row.employee_name });
        setDetailLoading(true);
        setDetailError('');
        try {
            const response = await api.get('/attendance/details.php', { params: { day_id: row.id } });
            setSelected(response.data?.data?.attendance || null);
        } catch (requestError) {
            setDetailError(requestError.response?.data?.message || 'Unable to load attendance details.');
        } finally {
            setDetailLoading(false);
        }
    };

    const applyFilters = (event) => {
        event.preventDefault();
        const filters = { search, status };
        setApplied(filters);
        load(1, period, date, filters);
    };

    return (
        <div className="page-container attendance-page">
            <div className="page-header">
                <div><h2>Attendance</h2><p className="page-subtitle">Employee and Team Lead attendance reports</p></div>
                <button className="btn btn-outline" type="button" onClick={() => load()} disabled={loading}><RefreshCw size={16} /> Refresh</button>
            </div>
            {error && <div className="alert alert-danger">{error}</div>}
            <SummaryCards summary={summary} />
            <div className="card">
                <form className="attendance-filters" onSubmit={applyFilters}>
                    <PeriodPicker
                        period={period}
                        date={date}
                        onPeriodChange={(nextPeriod, nextDate) => { setPeriod(nextPeriod); setDate(nextDate); }}
                        onDateChange={setDate}
                    />
                    <div className="attendance-filter-field attendance-search-field">
                        <label htmlFor="attendance-search">Search employee</label>
                        <div className="attendance-search-control"><Search size={16} /><input id="attendance-search" className="form-control" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name or username" /></div>
                    </div>
                    <div className="attendance-filter-field">
                        <label htmlFor="attendance-status">Status</label>
                        <select id="attendance-status" className="form-control" value={status} onChange={(event) => setStatus(event.target.value)}>
                            <option value="">All statuses</option>
                            <option value="Present">Present</option>
                            <option value="Half Day">Half Day</option>
                            <option value="Absent">Absent</option>
                        </select>
                    </div>
                    <button className="btn btn-primary attendance-apply" type="submit" disabled={loading}><Search size={16} /> Apply</button>
                </form>
                <div className="table-container attendance-table-wrap">
                    <table className="attendance-table">
                        <thead><tr><th>Date</th><th>Employee</th><th>Role</th><th>First Login</th><th>Final Logout</th><th>Work Time</th><th>Status</th><th>Late / Early</th><th>Actions</th></tr></thead>
                        <tbody>
                            {loading ? <tr><td colSpan="9" className="text-center">Loading attendance...</td></tr>
                                : rows.length === 0 ? <tr><td colSpan="9" className="text-center empty-state">No attendance records found.</td></tr>
                                    : rows.map((row) => <tr key={`${row.id || row.user_id}-${row.attendance_date}`}>
                                        <td>{row.attendance_date}</td>
                                        <td><strong>{row.employee_name}</strong><span className="attendance-username">{row.username}</span></td>
                                        <td>{row.role}</td>
                                        <td>{formatClock(row.first_login_at)}</td>
                                        <td>{formatClock(row.final_logout_at)}</td>
                                        <td>{formatDuration(row.total_work_seconds)}</td>
                                        <td><span className={`attendance-status status-${row.status.toLowerCase().replace(/\s+/g, '-')}`}>{row.status}</span></td>
                                        <td><span>{row.late_seconds ? `Late ${formatDelay(row.late_seconds)}` : '-'}</span>{row.early_logout_seconds > 0 && <span className="attendance-username">Early {formatDelay(row.early_logout_seconds)}</span>}</td>
                                        <td>{row.id && <button className="icon-btn view" type="button" title="View attendance details" aria-label="View attendance details" onClick={() => viewDetails(row)}><Eye size={16} /></button>}</td>
                                    </tr>)}
                        </tbody>
                    </table>
                </div>
                <Pagination currentPage={pagination.page} totalItems={pagination.total} pageSize={pagination.page_size} onPageChange={(page) => load(page)} onPageSizeChange={(size) => load(1, period, date, applied, size)} itemName="attendance records" />
            </div>
            <AttendanceDetailsModal selected={selected} onClose={() => setSelected(null)} loading={detailLoading} error={detailError} />
        </div>
    );
};

const EmployeeAttendance = () => {
    const [period, setPeriod] = useState('monthly');
    const [date, setDate] = useState(today().slice(0, 7));
    const [rows, setRows] = useState([]);
    const [summary, setSummary] = useState({});
    const [employee, setEmployee] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');

    const load = async (nextPeriod = period, nextDate = date) => {
        setLoading(true);
        try {
            const response = await api.get('/attendance/self.php', { params: { period: nextPeriod, date: nextDate } });
            const data = response.data?.data || {};
            setRows(data.rows || []);
            setSummary(data.summary || {});
            setEmployee(data.employee || null);
            setError('');
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to load your attendance history.');
        } finally {
            setLoading(false);
        }
    };
    useEffect(() => {
        let active = true;
        api.get('/attendance/self.php', { params: { period: 'monthly' } })
            .then((response) => {
                if (!active) return;
                const data = response.data?.data || {};
                setDate((data.date_to || today()).slice(0, 7));
                setRows(data.rows || []);
                setSummary(data.summary || {});
                setEmployee(data.employee || null);
            })
            .catch((requestError) => {
                if (active) setError(requestError.response?.data?.message || 'Unable to load your attendance history.');
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => { active = false; };
    }, []);

    const viewDetails = async (row) => {
        if (!row.id) return;
        setSelected({ employee_name: row.employee_name });
        setDetailLoading(true);
        setDetailError('');
        try {
            const response = await api.get('/attendance/details.php', { params: { day_id: row.id } });
            setSelected(response.data?.data?.attendance || null);
        } catch (requestError) {
            setDetailError(requestError.response?.data?.message || 'Unable to load attendance details.');
        } finally {
            setDetailLoading(false);
        }
    };

    return (
        <div className="page-container attendance-page">
            <div className="page-header">
                <div><h2>My Attendance</h2><p className="page-subtitle">{employee ? `${employee.name} · ${employee.role}` : 'Your attendance summary and daily history'}</p></div>
                <button className="btn btn-outline" type="button" onClick={() => load()} disabled={loading}><RefreshCw size={16} /> Refresh</button>
            </div>
            {error && <div className="alert alert-danger">{error}</div>}
            <SummaryCards summary={summary} />
            <div className="card">
                <div className="attendance-filters attendance-self-filters">
                    <PeriodPicker
                        period={period}
                        date={date}
                        onPeriodChange={(nextPeriod, nextDate) => { setPeriod(nextPeriod); setDate(nextDate); load(nextPeriod, nextDate); }}
                        onDateChange={(nextDate) => { setDate(nextDate); load(period, nextDate); }}
                    />
                </div>
                <div className="table-container attendance-table-wrap">
                    <table className="attendance-table">
                        <thead><tr><th>Date</th><th>First Login</th><th>Final Logout</th><th>Work Time</th><th>Status</th><th>Late / Early</th><th>Actions</th></tr></thead>
                        <tbody>
                            {loading ? <tr><td colSpan="7" className="text-center">Loading attendance...</td></tr>
                                : rows.length === 0 ? <tr><td colSpan="7" className="text-center empty-state">No attendance records found.</td></tr>
                                    : rows.map((row) => <tr key={`${row.id || row.attendance_date}-${row.attendance_date}`}>
                                        <td>{row.attendance_date}</td>
                                        <td>{formatClock(row.first_login_at)}</td>
                                        <td>{formatClock(row.final_logout_at)}</td>
                                        <td>{formatDuration(row.total_work_seconds)}</td>
                                        <td><span className={`attendance-status status-${row.status.toLowerCase().replace(/\s+/g, '-')}`}>{row.status}</span></td>
                                        <td><span>{row.late_seconds ? `Late ${formatDelay(row.late_seconds)}` : '-'}</span>{row.early_logout_seconds > 0 && <span className="attendance-username">Early {formatDelay(row.early_logout_seconds)}</span>}</td>
                                        <td>{row.id && <button className="icon-btn view" type="button" title="View attendance details" aria-label="View attendance details" onClick={() => viewDetails(row)}><Eye size={16} /></button>}</td>
                                    </tr>)}
                        </tbody>
                    </table>
                </div>
            </div>
            <AttendanceDetailsModal selected={selected} onClose={() => setSelected(null)} loading={detailLoading} error={detailError} />
        </div>
    );
};

const AttendancePage = () => {
    const { hasPermission } = useAuth();
    return hasPermission('attendance.view') ? <AdminAttendance /> : <EmployeeAttendance />;
};

export default AttendancePage;
