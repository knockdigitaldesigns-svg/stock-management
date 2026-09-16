import { useCallback, useEffect, useRef, useState } from 'react';
import { Download, RotateCcw, Search } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { exportToExcel, exportToPDF } from '../../utils/export';
import { formatDate } from '../../utils/date';
import { showGlobalError } from '../../context/ErrorContext';

const emptyFilters = () => ({
    search: '',
    location: '',
    month: '',
    year: '',
    installationPerson: '',
    leadClosure: '',
    payment: '',
    deviceType: '',
    validity: ''
});

const SearchableDropdown = ({ label, value, options, onChange }) => {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const containerRef = useRef(null);
    const searchRef = useRef(null);
    const normalizedOptions = options.map(option => ({
        value: String(option.value ?? ''),
        label: String(option.label ?? '')
    }));
    const selectedOption = normalizedOptions.find(option => option.value === String(value));
    const filteredOptions = normalizedOptions.filter(option =>
        option.label.toLowerCase().includes(search.trim().toLowerCase())
    );

    useEffect(() => {
        if (!open) return undefined;
        const handleOutsideClick = event => {
            if (!containerRef.current?.contains(event.target)) {
                setOpen(false);
                setSearch('');
            }
        };
        document.addEventListener('mousedown', handleOutsideClick);
        searchRef.current?.focus();
        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, [open]);

    const selectOption = option => {
        onChange(option.value);
        setOpen(false);
        setSearch('');
    };

    return (
        <div ref={containerRef} style={{ position: 'relative', zIndex: open ? 20 : 1 }}>
            <label className="form-label">{label}</label>
            <button
                type="button"
                className="form-control"
                onClick={() => setOpen(current => !current)}
                aria-haspopup="listbox"
                aria-expanded={open}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    textAlign: 'left',
                    cursor: 'pointer',
                    background: '#fff',
                    minHeight: '38px'
                }}
            >
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {selectedOption?.label || 'All'}
                </span>
                <span aria-hidden="true" style={{ marginLeft: '8px', color: '#64748b' }}>▾</span>
            </button>
            {open && (
                <div
                    role="listbox"
                    style={{
                        position: 'absolute',
                        top: 'calc(100% + 4px)',
                        left: 0,
                        right: 0,
                        minWidth: '100%',
                        padding: '8px',
                        background: '#fff',
                        border: '1px solid #cbd5e1',
                        borderRadius: '6px',
                        boxShadow: '0 8px 20px rgba(15, 23, 42, 0.14)',
                        zIndex: 100,
                        boxSizing: 'border-box'
                    }}
                >
                    <input
                        ref={searchRef}
                        type="search"
                        className="form-control"
                        placeholder="Search..."
                        value={search}
                        onChange={event => setSearch(event.target.value)}
                        onKeyDown={event => {
                            if (event.key === 'Escape') {
                                setOpen(false);
                                setSearch('');
                            }
                        }}
                        style={{ marginBottom: '8px' }}
                    />
                    <div style={{ maxHeight: '300px', overflowY: 'auto' }}>
                        {filteredOptions.length > 0 ? filteredOptions.map(option => (
                            <button
                                type="button"
                                role="option"
                                aria-selected={option.value === String(value)}
                                key={`${option.value}-${option.label}`}
                                onClick={() => selectOption(option)}
                                style={{
                                    display: 'block',
                                    width: '100%',
                                    padding: '8px 10px',
                                    border: 0,
                                    borderRadius: '4px',
                                    background: option.value === String(value) ? '#eff6ff' : '#fff',
                                    color: '#1e293b',
                                    textAlign: 'left',
                                    cursor: 'pointer'
                                }}
                            >
                                {option.label}
                            </button>
                        )) : (
                            <div style={{ padding: '8px 10px', color: '#64748b', fontSize: '13px' }}>
                                No matches found
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

const CustomerReportsPage = () => {
    const [filters, setFilters] = useState(emptyFilters());
    const [reports, setReports] = useState([]);
    const [options, setOptions] = useState({ locations: [], months: [], years: [], people: [], leadClosures: [], deviceTypes: [], validities: [] });
    const [loading, setLoading] = useState(true);

    const fetchReports = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            Object.entries(filters).forEach(([key, value]) => {
                if (value !== '') params.set(key === 'installationPerson' ? 'installation_person' : key, value);
            });
            const response = await api.get(`/customers/reports.php?${params.toString()}`, { skipGlobalError: true });
            if (!response.data?.success) {
                showGlobalError(response.data?.message || 'Failed to load customer reports.');
                return;
            }
            setReports(response.data.data?.reports || []);
            setOptions(response.data.data?.options || options);
        } catch (error) {
            showGlobalError(error.response?.data?.message || 'Failed to load customer reports.');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => { fetchReports(); }, [fetchReports]);

    const pagination = usePagination(reports, 10, [filters]);

    const updateFilter = (name, value) => {
        setFilters(current => ({ ...current, [name]: value }));
    };

    const exportRows = reports.map(row => ({
        Username: row.username || '-',
        'Mobile No': row.primary_mobile_no || '-',
        Location: row.location || '-',
        'Vehicle No': row.vehicle_no || '-',
        'Device Type': row.device_type || '-',
        'IMEI No': row.imei_no || '-',
        'SIM No 1': row.sim_no_1 || '-',
        'SIM No 2': row.sim_no_2 || '-',
        Validity: row.validity_months ? `${row.validity_months} Months` : '-',
        'Installation Person': row.installation_person || '-',
        'Installation Date': row.installation_date ? formatDate(row.installation_date) : '-',
        'Lead Closure': row.lead_closure || '-',
        Payment: row.payment_status || '-',
        'Amount Paid': row.amount_paid ?? '-',
        'Amount Pending': row.amount_pending ?? '-'
    }));

    const exportColumns = Object.keys(exportRows[0] || {
        Username: '', 'Mobile No': '', Location: '', 'Vehicle No': '', 'Device Type': '',
        'IMEI No': '', 'SIM No 1': '', 'SIM No 2': '', Validity: '', 'Installation Person': '',
        'Installation Date': '', 'Lead Closure': '', Payment: '', 'Amount Paid': '', 'Amount Pending': ''
    }).map(header => ({ header, key: header }));

    const handleExportExcel = () => {
        exportToExcel(exportRows, 'Customer_Reports', 'Customer Reports', { columns: exportColumns });
    };

    const handleExportPDF = () => {
        exportToPDF(exportRows, 'Customer_Reports', 'CUSTOMER REPORTS', exportColumns);
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Customer Reports</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={handleExportExcel} disabled={loading || reports.length === 0}>
                        <Download size={16} /> Export Excel
                    </button>
                    <button className="btn btn-outline" onClick={handleExportPDF} disabled={loading || reports.length === 0}>
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            <div className="card" style={{ marginBottom: '16px', padding: '16px' }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '12px', alignItems: 'end' }}>
                    <div style={{ gridColumn: 'span 2' }}>
                        <label className="form-label">Search</label>
                        <div style={{ position: 'relative' }}>
                            <Search size={16} style={{ position: 'absolute', left: '10px', top: '11px', color: '#64748b' }} />
                            <input className="form-control" style={{ paddingLeft: '32px' }} placeholder="Username, mobile, vehicle, IMEI or SIM" value={filters.search} onChange={e => updateFilter('search', e.target.value)} />
                        </div>
                    </div>
                    <SearchableDropdown label="Location" value={filters.location} options={[{ value: '', label: 'All' }, ...options.locations.map(location => ({ value: location, label: location }))]} onChange={value => updateFilter('location', value)} />
                    <SearchableDropdown label="Month" value={filters.month} options={[{ value: '', label: 'All' }, ...options.months.map(month => ({ value: month.value, label: month.label }))]} onChange={value => updateFilter('month', value)} />
                    <SearchableDropdown label="Year" value={filters.year} options={[{ value: '', label: 'All' }, ...options.years.map(year => ({ value: year, label: year }))]} onChange={value => updateFilter('year', value)} />
                    <SearchableDropdown label="Installation Person" value={filters.installationPerson} options={[{ value: '', label: 'All' }, ...options.people.map(person => ({ value: person.value, label: `${person.label} (${person.type})` }))]} onChange={value => updateFilter('installationPerson', value)} />
                    <SearchableDropdown label="Lead Closure" value={filters.leadClosure} options={[{ value: '', label: 'All' }, ...options.leadClosures.map(item => ({ value: item.id, label: item.name }))]} onChange={value => updateFilter('leadClosure', value)} />
                    <SearchableDropdown label="Payment" value={filters.payment} options={[{ value: '', label: 'All' }, { value: 'paid', label: 'Paid' }, { value: 'partially_paid', label: 'Partially Paid' }, { value: 'not_paid', label: 'Not Paid' }]} onChange={value => updateFilter('payment', value)} />
                    <SearchableDropdown label="Device Type" value={filters.deviceType} options={[{ value: '', label: 'All' }, ...options.deviceTypes.map(item => ({ value: item.id, label: item.name }))]} onChange={value => updateFilter('deviceType', value)} />
                    <SearchableDropdown label="Validity" value={filters.validity} options={[{ value: '', label: 'All' }, ...options.validities.map(value => ({ value, label: `${value} Months` }))]} onChange={value => updateFilter('validity', value)} />
                    <button className="btn btn-secondary" onClick={() => setFilters(emptyFilters())}><RotateCcw size={16} /> Reset Filters</button>
                </div>
            </div>

            <div className="card">
                <div className="table-container">
                    <table>
                        <thead><tr><th>#</th><th>Username</th><th>Mobile No</th><th>Location</th><th>Vehicle No</th><th>Device / IMEI</th><th>SIM No</th><th>Installation Person</th><th>Installation Date</th><th>Lead Closure</th><th>Payment</th><th>Validity</th></tr></thead>
                        <tbody>
                            {loading ? <tr><td colSpan="12" className="text-center">Loading customer reports...</td></tr> : pagination.paginatedItems.length === 0 ? <tr><td colSpan="12" className="text-center empty-state">No customer reports found.</td></tr> : pagination.paginatedItems.map((row, index) => <tr key={row.id}><td>{(pagination.page - 1) * pagination.pageSize + index + 1}</td><td>{row.username || '-'}</td><td>{row.primary_mobile_no || '-'}</td><td>{row.location || '-'}</td><td>{row.vehicle_no || '-'}</td><td>{[row.device_type, row.imei_no].filter(Boolean).join(' / ') || '-'}</td><td>{[row.sim_no_1, row.sim_no_2].filter(Boolean).join(' / ') || '-'}</td><td>{row.installation_person || '-'}</td><td>{row.installation_date ? formatDate(row.installation_date) : '-'}</td><td>{row.lead_closure || '-'}</td><td>{row.payment_status || '-'}</td><td>{row.validity_months ? `${row.validity_months} Months` : '-'}</td></tr>)}
                        </tbody>
                    </table>
                </div>
                <Pagination page={pagination.page} setPage={pagination.setPage} pageSize={pagination.pageSize} setPageSize={pagination.setPageSize} totalItems={pagination.totalItems} />
            </div>
        </div>
    );
};

export default CustomerReportsPage;
