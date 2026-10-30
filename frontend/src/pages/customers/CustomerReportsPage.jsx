import { useCallback, useEffect, useRef, useState } from 'react';
import { Download, RotateCcw, Search } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import { exportToExcel, exportToPDF } from '../../utils/export';
import { formatDate } from '../../utils/date';
import { showGlobalError } from '../../context/ErrorContext';
import { PAYMENT_STATUS_FILTERS } from '../../constants/paymentStatuses';

const emptyFilters = () => ({
    search: '',
    location: '',
    dateFrom: '',
    dateTo: '',
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
        <div ref={containerRef} className="filter-group" style={{ position: 'relative', zIndex: open ? 20 : 1 }}>
            <label className="filter-label">{label}</label>
            <button
                type="button"
                className="form-control filter-input"
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
                    <div style={{ maxHeight: '148px', overflowY: 'auto' }}>
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

const formatReportAmount = (value) => {
    if (value === null || value === undefined || String(value).trim() === '') return '-';
    const amount = Number(value);
    return Number.isFinite(amount) ? amount.toFixed(2) : String(value);
};

const CustomerReportsPage = () => {
    const [filters, setFilters] = useState(emptyFilters());
    const [reports, setReports] = useState([]);
    const [options, setOptions] = useState({ locations: [], months: [], years: [], people: [], leadClosures: [], deviceTypes: [], validities: [] });
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pageMeta, setPageMeta] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });

    const fetchReports = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            Object.entries(filters).forEach(([key, value]) => {
                if (value !== '') {
                    const paramKey = key === 'installationPerson' ? 'installation_person' : key === 'dateFrom' ? 'date_from' : key === 'dateTo' ? 'date_to' : key === 'deviceType' ? 'device_type' : key === 'leadClosure' ? 'lead_closure' : key;
                    params.set(paramKey, value);
                }
            });
            params.set('page', page);
            params.set('page_size', pageSize);
            const response = await api.get(`/customers/reports.php?${params.toString()}`, { skipGlobalError: true });
            if (!response.data?.success) {
                showGlobalError(response.data?.message || 'Failed to load customer reports.');
                return;
            }
            setReports(response.data.data?.reports || []);
            setOptions(response.data.data?.options || options);
            const nextPageMeta = response.data.data?.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
            setPageMeta(nextPageMeta);
            if (page > nextPageMeta.total_pages) setPage(nextPageMeta.total_pages);
        } catch (error) {
            showGlobalError(error.response?.data?.message || 'Failed to load customer reports.');
        } finally {
            setLoading(false);
        }
    }, [filters, page, pageSize]);

    useEffect(() => { fetchReports(); }, [fetchReports]);

    const updateFilter = (name, value) => {
        setFilters(current => ({ ...current, [name]: value }));
        setPage(1);
    };

    const toExportRows = (items) => items.map(row => ({
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

    const exportColumns = Object.keys(toExportRows(reports)[0] || {
        Username: '', 'Mobile No': '', Location: '', 'Vehicle No': '', 'Device Type': '',
        'IMEI No': '', 'SIM No 1': '', 'SIM No 2': '', Validity: '', 'Installation Person': '',
        'Installation Date': '', 'Lead Closure': '', Payment: '', 'Amount Paid': '', 'Amount Pending': ''
    }).map(header => ({ header, key: header }));

    const fetchAllReportRows = async () => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '') {
                const paramKey = key === 'installationPerson' ? 'installation_person' : key === 'dateFrom' ? 'date_from' : key === 'dateTo' ? 'date_to' : key === 'deviceType' ? 'device_type' : key === 'leadClosure' ? 'lead_closure' : key;
                params.set(paramKey, value);
            }
        });
        const response = await api.get(`/customers/reports.php?${params.toString()}`, { skipGlobalError: true });
        if (!response.data?.success) {
            throw new Error(response.data?.message || 'Failed to load customer report export data.');
        }
        return response.data.data?.reports || [];
    };

    const handleExportExcel = async () => {
        try {
            const exportRows = toExportRows(await fetchAllReportRows());
            exportToExcel(exportRows, 'Customer_Reports', 'Customer Reports', { columns: exportColumns });
        } catch (error) {
            showGlobalError(error.response?.data?.message || error.message || 'Failed to export customer reports.');
        }
    };

    const handleExportPDF = async () => {
        let exportRows;
        try {
            exportRows = toExportRows(await fetchAllReportRows());
        } catch (error) {
            showGlobalError(error.response?.data?.message || error.message || 'Failed to export customer reports.');
            return;
        }
        const pdfRows = exportRows.map((row) => ({
            ...row,
            'Amount Paid': formatReportAmount(row['Amount Paid']),
            'Amount Pending': formatReportAmount(row['Amount Pending'])
        }));
        const columnWidths = [21, 15, 22, 17, 18, 21, 17, 17, 13, 22, 19, 20, 14, 17, 18];
        const columnStyles = Object.fromEntries(columnWidths.map((cellWidth, index) => [index, { cellWidth }]));
        columnStyles[13].halign = 'right';
        columnStyles[14].halign = 'right';

        exportToPDF(pdfRows, 'Customer_Reports', 'CUSTOMER REPORTS', exportColumns, {
            orientation: 'landscape',
            format: 'a4',
            tableOptions: {
                margin: { top: 10, right: 13, bottom: 13, left: 13 },
                tableWidth: 271,
                showHead: 'everyPage',
                rowPageBreak: 'avoid',
                theme: 'grid',
                styles: {
                    fontSize: 6.2,
                    cellPadding: 1.3,
                    overflow: 'linebreak',
                    valign: 'middle',
                    lineColor: [203, 213, 225],
                    lineWidth: 0.1,
                    textColor: [30, 41, 59]
                },
                headStyles: {
                    fillColor: [55, 48, 163],
                    textColor: [255, 255, 255],
                    fontSize: 6.2,
                    fontStyle: 'bold',
                    halign: 'center',
                    valign: 'middle'
                },
                alternateRowStyles: { fillColor: [245, 247, 250] },
                columnStyles
            }
        });
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Customer Reports</h2>
                    <p className="page-subtitle">Review customer information and export detailed reports.</p>
                </div>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={handleExportExcel} disabled={loading || reports.length === 0}>
                        <Download size={16} /> Export Excel
                    </button>
                    <button className="btn btn-outline" onClick={handleExportPDF} disabled={loading || reports.length === 0}>
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            <div className="card">
                <div className="table-filter-card" style={{ border: 'none', boxShadow: 'none', padding: '20px 22px 20px 22px', borderBottom: '1px solid #e2e8f0', marginBottom: '10px' }}>
                    <div className="table-filter-grid">
                        <div className="filter-group">
                            <label className="filter-label">Search</label>
                            <div className="search-input-wrapper">
                                <Search size={16} className="search-icon" />
                                <input className="form-control filter-input has-icon table-filter-search" placeholder="Username, mobile, vehicle, IMEI or SIM" value={filters.search} onChange={e => updateFilter('search', e.target.value)} />
                            </div>
                        </div>
                        <SearchableDropdown label="Location" value={filters.location} options={[{ value: '', label: 'All' }, ...options.locations.map(location => ({ value: location, label: location }))]} onChange={value => updateFilter('location', value)} />
                        <div className="filter-group">
                            <label className="filter-label">From Date</label>
                            <input type="date" className="form-control filter-input" value={filters.dateFrom} onChange={e => {
                                if (filters.dateTo && e.target.value > filters.dateTo) {
                                    showGlobalError('From Date cannot be later than To Date.');
                                    return;
                                }
                                updateFilter('dateFrom', e.target.value);
                            }} />
                        </div>
                        <div className="filter-group">
                            <label className="filter-label">To Date</label>
                            <input type="date" className="form-control filter-input" value={filters.dateTo} onChange={e => {
                                if (filters.dateFrom && e.target.value && e.target.value < filters.dateFrom) {
                                    showGlobalError('From Date cannot be later than To Date.');
                                    return;
                                }
                                updateFilter('dateTo', e.target.value);
                            }} />
                        </div>
                        <SearchableDropdown label="Installation Person" value={filters.installationPerson} options={[{ value: '', label: 'All' }, ...options.people.map(person => ({ value: person.value, label: `${person.label} (${person.type})` }))]} onChange={value => updateFilter('installationPerson', value)} />
                        <SearchableDropdown label="Lead Closure" value={filters.leadClosure} options={[{ value: '', label: 'All' }, ...options.leadClosures.map(item => ({ value: item.id, label: item.name }))]} onChange={value => updateFilter('leadClosure', value)} />
                        <SearchableDropdown label="Payment" value={filters.payment} options={[{ value: '', label: 'All' }, ...PAYMENT_STATUS_FILTERS]} onChange={value => updateFilter('payment', value)} />
                        <SearchableDropdown label="Device Type" value={filters.deviceType} options={[{ value: '', label: 'All' }, ...options.deviceTypes.map(item => ({ value: item.id, label: item.name }))]} onChange={value => updateFilter('deviceType', value)} />
                        <SearchableDropdown label="Validity" value={filters.validity} options={[{ value: '', label: 'All' }, ...options.validities.map(value => ({ value, label: `${value} Months` }))]} onChange={value => updateFilter('validity', value)} />
                    </div>
                    
                    <div className="table-filter-actions">
                        <button className="btn btn-primary table-filter-btn" onClick={fetchReports}><Search size={16} /> Search</button>
                        <button className="btn btn-secondary table-filter-btn" onClick={() => { setFilters(emptyFilters()); setPage(1); }}><RotateCcw size={16} /> Reset</button>
                    </div>
                </div>

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Mobile No</th>
                                <th>Location</th>
                                <th>Vehicle No</th>
                                <th>Device / IMEI</th>
                                <th>SIM No</th>
                                <th>Installation Person</th>
                                <th>Installation Date</th>
                                <th>Lead Closure</th>
                                <th>Payment</th>
                                <th>Validity</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="11" className="text-center">Loading customer reports...</td></tr>
                            ) : reports.length === 0 ? (
                                <tr><td colSpan="11" className="text-center empty-state">No customer reports found.</td></tr>
                            ) : (
                                reports.map((row) => (
                                    <tr key={row.id}>
                                        <td>{row.username || '-'}</td>
                                        <td>{row.primary_mobile_no || '-'}</td>
                                        <td>{row.location || '-'}</td>
                                        <td>{row.vehicle_no || '-'}</td>
                                        <td>{[row.device_type, row.imei_no].filter(Boolean).join(' / ') || '-'}</td>
                                        <td>{[row.sim_no_1, row.sim_no_2].filter(Boolean).join(' / ') || '-'}</td>
                                        <td>{row.installation_person || '-'}</td>
                                        <td>{row.installation_date ? formatDate(row.installation_date) : '-'}</td>
                                        <td>{row.lead_closure || '-'}</td>
                                        <td>{row.payment_status || '-'}</td>
                                        <td>{row.validity_months ? `${row.validity_months} Months` : '-'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={pageMeta.total}
                    onPageChange={setPage}
                    onPageSizeChange={(size) => { setPageSize(size); setPage(1); }}
                    itemName="entries"
                />
            </div>
        </div>
    );
};

export default CustomerReportsPage;
