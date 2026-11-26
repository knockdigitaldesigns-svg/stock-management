import { useCallback, useEffect, useState } from 'react';
import { Download, RefreshCw, Search, RotateCcw } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import { showGlobalError } from '../../context/ErrorContext';
import { useAuth } from '../../context/AuthContext';
import './EmployeeWorkingReportsPage.css';

const EMPTY_FILTERS = { employee_id: '', date_from: '', date_to: '' };
const INITIAL_PAGINATION = { page: 1, page_size: 25, total: 0, total_pages: 1 };

const errorMessageFromBlob = async (blob, fallback) => {
    if (!(blob instanceof Blob)) return fallback;
    try {
        const body = JSON.parse(await blob.text());
        return body.message || fallback;
    } catch {
        return fallback;
    }
};

const EmployeeWorkingReportsPage = () => {
    const { hasPermission } = useAuth();
    const canViewAll = hasPermission('employee_working_reports.view');
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [appliedFilters, setAppliedFilters] = useState(EMPTY_FILTERS);
    const [employees, setEmployees] = useState([]);
    const [reports, setReports] = useState([]);
    const [pagination, setPagination] = useState(INITIAL_PAGINATION);
    const [canDownload, setCanDownload] = useState(false);
    const [loading, setLoading] = useState(true);
    const [downloadingId, setDownloadingId] = useState(null);
    const [error, setError] = useState('');
    const [refreshVersion, setRefreshVersion] = useState(0);

    const requestReports = useCallback((page, currentFilters) => (
        api.get('/employee_working_reports/list.php', {
            params: {
                ...currentFilters,
                page,
                page_size: pagination.page_size
            }
        })
    ), [pagination.page_size]);

    useEffect(() => {
        let active = true;
        requestReports(pagination.page, appliedFilters)
            .then((response) => {
                if (!active) return;
                const data = response.data?.data || {};
                setReports(data.reports || []);
                setPagination(data.pagination || { ...INITIAL_PAGINATION, page: pagination.page });
                setCanDownload(Boolean(data.can_download));
                setError('');
            })
            .catch((requestError) => {
                if (active) {
                    setError(requestError.response?.data?.message || 'Unable to load employee working reports.');
                }
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => { active = false; };
    }, [requestReports, pagination.page, appliedFilters, refreshVersion]);

    useEffect(() => {
        if (!canViewAll) return undefined;
        let active = true;
        api.get('/employee_working_reports/employees.php')
            .then((response) => {
                if (active) setEmployees(response.data?.data?.employees || []);
            })
            .catch((requestError) => {
                if (active) {
                    setError(requestError.response?.data?.message || 'Unable to load employee filters.');
                }
            });
        return () => { active = false; };
    }, [canViewAll]);

    const updateFilter = (key, value) => {
        setFilters((current) => ({ ...current, [key]: value }));
    };

    const applyFilters = (event) => {
        event.preventDefault();
        if (filters.date_from && filters.date_to && filters.date_from > filters.date_to) {
            setError('From date cannot be later than To date.');
            return;
        }
        setError('');
        setLoading(true);
        setPagination((current) => ({ ...current, page: 1 }));
        setAppliedFilters({ ...filters });
    };

    const resetFilters = () => {
        setFilters(EMPTY_FILTERS);
        setAppliedFilters(EMPTY_FILTERS);
        setLoading(true);
        setPagination((current) => ({ ...current, page: 1 }));
        setError('');
    };

    const downloadReport = async (report) => {
        setDownloadingId(report.id);
        try {
            const response = await api.get('/employee_working_reports/download.php', {
                params: { id: report.id },
                responseType: 'blob'
            });
            if (response.data?.type === 'application/json') {
                throw new Error(await errorMessageFromBlob(response.data, 'Unable to download report.'));
            }
            const url = window.URL.createObjectURL(
                new Blob([response.data], { type: 'application/pdf' })
            );
            const link = document.createElement('a');
            link.href = url;
            link.download = report.file_path;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (requestError) {
            const message = requestError.response?.data instanceof Blob
                ? await errorMessageFromBlob(requestError.response.data, 'Unable to download report.')
                : requestError.response?.data?.message || requestError.message || 'Unable to download report.';
            showGlobalError(message);
        } finally {
            setDownloadingId(null);
        }
    };

    const statusClass = (status) => `employee-report-status status-${String(status || '').toLowerCase()}`;

    return (
        <div className="page-container employee-working-reports-page">
            <div className="page-header">
                <div>
                    <h2>Employee Working Reports</h2>
                    <p className="page-subtitle">Automatically generated reports for fixed two-hour periods.</p>
                </div>
                <button
                    className="btn btn-outline"
                    type="button"
                    onClick={() => {
                        setLoading(true);
                        setRefreshVersion((current) => current + 1);
                    }}
                    disabled={loading}
                >
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            <div className="employee-report-note">
                Call figures use CRM-recorded follow-up interactions. The CRM does not store telephony duration.
            </div>

            <div className="card">
                <form className="employee-report-filters" onSubmit={applyFilters}>
                    {canViewAll && (
                        <div className="filter-group">
                            <label className="filter-label" htmlFor="report-employee-filter">Employee</label>
                            <select
                                id="report-employee-filter"
                                className="form-control"
                                value={filters.employee_id}
                                onChange={(event) => updateFilter('employee_id', event.target.value)}
                            >
                                <option value="">All employees</option>
                                {employees.map((employee) => (
                                    <option key={employee.employee_id} value={employee.employee_id}>
                                        {employee.employee_name} · {employee.role_name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="filter-group">
                        <label className="filter-label" htmlFor="report-date-from">From date</label>
                        <input
                            id="report-date-from"
                            className="form-control"
                            type="date"
                            value={filters.date_from}
                            onChange={(event) => updateFilter('date_from', event.target.value)}
                        />
                    </div>
                    <div className="filter-group">
                        <label className="filter-label" htmlFor="report-date-to">To date</label>
                        <input
                            id="report-date-to"
                            className="form-control"
                            type="date"
                            value={filters.date_to}
                            onChange={(event) => updateFilter('date_to', event.target.value)}
                        />
                    </div>
                    <div className="employee-report-filter-actions">
                        <button className="btn btn-primary" type="submit" disabled={loading}>
                            <Search size={16} /> Apply
                        </button>
                        <button className="btn btn-secondary" type="button" onClick={resetFilters} disabled={loading}>
                            <RotateCcw size={16} /> Reset
                        </button>
                    </div>
                </form>

                <div className="table-container">
                    <table className="employee-report-table">
                        <thead>
                            <tr>
                                {canViewAll && <th>Employee</th>}
                                <th>Reporting period</th>
                                <th>Generated at</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                {canViewAll && <th>Error details</th>}
                                {canDownload && <th>Action</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan={canViewAll ? (canDownload ? 7 : 6) : (canDownload ? 5 : 4)} className="text-center">Loading reports...</td></tr>
                            ) : reports.length === 0 ? (
                                <tr><td colSpan={canViewAll ? (canDownload ? 7 : 6) : (canDownload ? 5 : 4)} className="text-center">No reports found.</td></tr>
                            ) : reports.map((report) => (
                                <tr key={report.id}>
                                    {canViewAll && (
                                        <td>
                                            <strong>{report.employee_name}</strong>
                                            <small>{report.employee_code} · {report.role_name}</small>
                                        </td>
                                    )}
                                    <td>
                                        <span>{report.period_start}</span>
                                        <small>to {report.period_end} ({report.timezone_name})</small>
                                    </td>
                                    <td>{report.generated_at || '-'}</td>
                                    <td><span className={statusClass(report.status)}>{report.status}</span></td>
                                    <td>{report.attempt_count}</td>
                                    {canViewAll && <td className="employee-report-error">{report.error_details || '-'}</td>}
                                    {canDownload && (
                                        <td>
                                            <button
                                                className="btn btn-outline employee-report-download"
                                                type="button"
                                                disabled={report.status !== 'generated' || downloadingId === report.id}
                                                onClick={() => downloadReport(report)}
                                            >
                                                <Download size={15} />
                                                {downloadingId === report.id ? 'Downloading…' : 'Download'}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination
                    currentPage={pagination.page}
                    totalItems={pagination.total}
                    pageSize={pagination.page_size}
                    onPageChange={(page) => {
                        setLoading(true);
                        setPagination((current) => ({ ...current, page }));
                    }}
                    onPageSizeChange={(pageSize) => {
                        setLoading(true);
                        setPagination((current) => ({ ...current, page: 1, page_size: pageSize }));
                    }}
                    itemName="employee reports"
                />
            </div>
        </div>
    );
};

export default EmployeeWorkingReportsPage;
