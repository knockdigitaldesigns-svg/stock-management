import { useEffect, useMemo, useState } from 'react';
import { Search, RotateCcw, Download } from 'lucide-react';
import api from '../../services/api';
import { formatDate } from '../../utils/date';
import { exportToExcel, exportToPDF } from '../../utils/export';
import Pagination from '../../components/Pagination/Pagination';

const PendingPayments = () => {
    const [rows, setRows] = useState([]);
    const [allocationRows, setAllocationRows] = useState([]);
    const [customerRows, setCustomerRows] = useState([]);
    const [loading, setLoading] = useState(true);

    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('All');
    const [status, setStatus] = useState('All');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);

    const fetchPendingPayments = async () => {
        try {
            setLoading(true);

            const response = await api.get('/payments/pending.php');

            if (response.data.success) {
                setRows(response.data.data?.rows || []);
                setAllocationRows(response.data.data?.allocation_rows || []);
                setCustomerRows(response.data.data?.customer_rows || []);
            } else {
                setRows([]);
                setAllocationRows([]);
                setCustomerRows([]);
            }
        } catch (error) {
            console.error('Failed to fetch pending payments:', error);
            setRows([]);
            setAllocationRows([]);
            setCustomerRows([]);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchPendingPayments();
    }, []);

    const filteredRows = useMemo(() => {
        return rows.filter((row) => {
            const text = search.trim().toLowerCase();

            const matchesSearch =
                !text ||
                String(row.name || '').toLowerCase().includes(text) ||
                String(row.type || '').toLowerCase().includes(text);

            const matchesCategory =
                category === 'All' || row.category === category;

            const matchesStatus =
                status === 'All' || row.status_group === status;

            const rowDate = row.date || '';

            const matchesFrom =
                !dateFrom || rowDate >= dateFrom;

            const matchesTo =
                !dateTo || rowDate <= dateTo;

            return (
                matchesSearch &&
                matchesCategory &&
                matchesStatus &&
                matchesFrom &&
                matchesTo
            );
        });
    }, [rows, search, category, status, dateFrom, dateTo]);

    const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    const currentPage = Math.min(page, pageCount);
    const paginatedRows = filteredRows.slice((currentPage - 1) * pageSize, currentPage * pageSize);

    const summaryTotals = useMemo(() => {
        const text = search.trim().toLowerCase();
        const matchingRows = [...customerRows, ...allocationRows].filter((row) => {
            const matchesSearch =
                !text ||
                String(row.name || '').toLowerCase().includes(text) ||
                String(row.type || '').toLowerCase().includes(text);
            const matchesCategory = category === 'All' || row.category === category;
            const rowDate = row.date || '';
            const matchesFrom = !dateFrom || rowDate >= dateFrom;
            const matchesTo = !dateTo || rowDate <= dateTo;

            return matchesSearch && matchesCategory && matchesFrom && matchesTo;
        });

        return matchingRows.reduce(
            (acc, row) => {
                acc.total += Number(row.total_amount || 0);
                acc.paid += Number(row.amount_paid || 0);
                acc.pending += Number(row.pending_amount || 0);
                return acc;
            },
            { total: 0, paid: 0, pending: 0 }
        );
    }, [customerRows, allocationRows, search, category, dateFrom, dateTo]);

    const resetFilters = () => {
        setPage(1);
        setSearch('');
        setCategory('All');
        setStatus('All');
        setDateFrom('');
        setDateTo('');
    };

    const updateFilter = (setter, value) => {
        setPage(1);
        setter(value);
    };

    const getExportRows = () => filteredRows.map((row) => ({
            Date: row.date || '',
            Category: row.category || '—',
            Name: row.name || '—',
            Type: row.type || '—',
            'Total Amount (₹)': Number(row.total_amount || 0),
            'Amount Paid (₹)': Number(row.amount_paid || 0),
            'Pending Amount (₹)': Number(row.pending_amount || 0),
            'Payment Status': row.display_status || 'Pending'
        }));

    const getExportSummary = () => [
            { label: 'Total Amount', value: `INR ${summaryTotals.total.toLocaleString('en-IN')}` },
            { label: 'Amount Paid', value: `INR ${summaryTotals.paid.toLocaleString('en-IN')}` },
            { label: 'Pending Amount', value: `INR ${summaryTotals.pending.toLocaleString('en-IN')}` }
        ];

    const handleExportExcel = () => {
        const exportRows = getExportRows();
        const summary = getExportSummary();

        exportToExcel(exportRows, 'Pending_Payments', 'Payment Records', {
            summaryTitle: 'Payment Summary',
            summary,
            tableTitle: 'Pending Payment Records',
            combinedSheet: { title: 'PENDING PAYMENTS' }
        });
    };

    const handleExportPDF = () => {
        const exportRows = getExportRows();
        const summary = getExportSummary();
        exportToPDF(
            exportRows,
            'Pending_Payments',
            'PENDING PAYMENTS',
            [
                { header: 'Date', key: 'Date' },
                { header: 'Category', key: 'Category' },
                { header: 'Name', key: 'Name' },
                { header: 'Type', key: 'Type' },
                { header: 'Total Amount', key: 'Total Amount (₹)' },
                { header: 'Amount Paid', key: 'Amount Paid (₹)' },
                { header: 'Pending Amount', key: 'Pending Amount (₹)' },
                { header: 'Payment Status', key: 'Payment Status' }
            ],
            {
                orientation: 'landscape',
                summaryTitle: 'Payment Summary',
                summary,
                tableTitle: 'Pending Payment Records',
                styles: { cellPadding: 2, overflow: 'linebreak' },
                columnStyles: {
                    0: { cellWidth: 22 },
                    1: { cellWidth: 22 },
                    2: { cellWidth: 35 },
                    3: { cellWidth: 24 },
                    4: { cellWidth: 28 },
                    5: { cellWidth: 28 },
                    6: { cellWidth: 28 },
                    7: { cellWidth: 32 }
                }
            }
        );
    };

    const getStatusClass = (statusValue) => {
        const value = String(statusValue || '').toLowerCase();

        if (value === 'partially paid') {
            return 'badge badge-info';
        }

        if (value === 'paid') {
            return 'badge badge-success';
        }

        return 'badge badge-warning';
    };

    return (
        <div className="page-container">

            <div
                className="page-header"
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    gap: '20px',
                    marginBottom: '20px'
                }}
            >
                <div>
                    <h2>Pending Payments</h2>
                    <p className="page-subtitle" style={{ marginTop: '5px' }}>
                        Track customer, dealer and technician payments, including linked SIM renewals.
                    </p>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={handleExportExcel}
                        disabled={loading || filteredRows.length === 0}
                    >
                        <Download size={16} /> Export Excel
                    </button>
                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={handleExportPDF}
                        disabled={loading || filteredRows.length === 0}
                    >
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            {/* Summary */}
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
                    gap: '16px',
                    marginBottom: '20px'
                }}
            >
                <div className="card">
                    <div style={{ color: 'var(--text-secondary)', marginBottom: '7px' }}>
                        Total Amount
                    </div>

                    <div style={{ fontSize: '25px', fontWeight: 700 }}>
                        ₹{summaryTotals.total.toLocaleString('en-IN')}
                    </div>
                    <div style={{ color: 'var(--text-secondary)', fontSize: '12px', marginTop: '6px' }}>
                        Includes completed payments and SIM renewals
                    </div>
                </div>

                <div className="card">
                    <div style={{ color: 'var(--text-secondary)', marginBottom: '7px' }}>
                        Amount Paid
                    </div>

                    <div style={{ fontSize: '25px', fontWeight: 700 }}>
                        ₹{summaryTotals.paid.toLocaleString('en-IN')}
                    </div>
                </div>

                <div className="card">
                    <div style={{ color: 'var(--text-secondary)', marginBottom: '7px' }}>
                        Pending Amount
                    </div>

                    <div style={{ fontSize: '25px', fontWeight: 700 }}>
                        ₹{summaryTotals.pending.toLocaleString('en-IN')}
                    </div>
                </div>
            </div>

            {/* Filters */}
            <div className="table-filter-card card" style={{ padding: '20px 22px', marginBottom: '20px' }}>
                <div className="table-filter-grid">
                    
                    <div className="filter-group">
                        <label className="filter-label">Search</label>
                        <div className="search-input-wrapper">
                            <Search size={16} className="search-icon" />
                            <input
                                type="text"
                                className="form-control filter-input has-icon table-filter-search"
                                placeholder="Search name / type"
                                value={search}
                                onChange={(e) => updateFilter(setSearch, e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="filter-group">
                        <label className="filter-label">Category</label>
                        <select
                            className="form-control filter-input"
                            value={category}
                            onChange={(e) => updateFilter(setCategory, e.target.value)}
                        >
                            <option value="All">All</option>
                            <option value="Customer">Customer</option>
                            <option value="Dealer">Dealer</option>
                            <option value="Technician">Technician</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label className="filter-label">Payment Status</label>
                        <select
                            className="form-control filter-input"
                            value={status}
                            onChange={(e) => updateFilter(setStatus, e.target.value)}
                        >
                            <option value="All">All</option>
                            <option value="Pending">Pending</option>
                            <option value="Partially Paid">Partially Paid</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label className="filter-label">Date From</label>
                        <input
                            type="date"
                            className="form-control filter-input"
                            value={dateFrom}
                            onChange={(e) => {
                                if (dateTo && e.target.value > dateTo) {
                                    alert('From Date cannot be later than To Date.');
                                    return;
                                }
                                updateFilter(setDateFrom, e.target.value);
                            }}
                        />
                    </div>

                    <div className="filter-group">
                        <label className="filter-label">Date To</label>
                        <input
                            type="date"
                            className="form-control filter-input"
                            value={dateTo}
                            onChange={(e) => {
                                if (dateFrom && e.target.value && e.target.value < dateFrom) {
                                    alert('From Date cannot be later than To Date.');
                                    return;
                                }
                                updateFilter(setDateTo, e.target.value);
                            }}
                        />
                    </div>
                </div>

                <div className="table-filter-actions">
                    <button type="button" className="btn btn-primary table-filter-btn" onClick={() => {}}>
                        <Search size={16} /> Search
                    </button>
                    <button
                        type="button"
                        className="btn btn-secondary table-filter-btn"
                        onClick={resetFilters}
                    >
                        <RotateCcw size={16} /> Reset
                    </button>
                </div>
            </div>

            {/* Table */}
            <div className="card">

                <div
                    style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        marginBottom: '14px'
                    }}
                >
                    <h3 style={{ margin: 0 }}>
                        Pending Payment Records
                    </h3>

                    <div style={{ color: 'var(--text-secondary)' }}>
                        {filteredRows.length} records
                    </div>
                </div>

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Total Amount</th>
                                <th>Amount Paid</th>
                                <th>Pending Amount</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="9" className="text-center">
                                        Loading pending payments...
                                    </td>
                                </tr>
                            ) : filteredRows.length === 0 ? (
                                <tr>
                                    <td colSpan="9" className="text-center">
                                        No pending payment records found.
                                    </td>
                                </tr>
                            ) : (
                                paginatedRows.map((row, index) => (
                                    <tr key={`${row.category}-${row.id}-${index}`}>
                                        <td>{(currentPage - 1) * pageSize + index + 1}</td>

                                        <td>
                                            {row.date
                                                ? formatDate(row.date)
                                                : '—'}
                                        </td>

                                        <td>{row.category || '—'}</td>

                                        <td>
                                            <strong>
                                                {row.name || '—'}
                                            </strong>
                                        </td>

                                        <td>{row.type || '—'}</td>

                                        <td>
                                            ₹{Number(row.total_amount || 0).toLocaleString('en-IN')}
                                        </td>

                                        <td>
                                            ₹{Number(row.amount_paid || 0).toLocaleString('en-IN')}
                                        </td>

                                        <td>
                                            <strong>
                                                ₹{Number(row.pending_amount || 0).toLocaleString('en-IN')}
                                            </strong>
                                        </td>

                                        <td>
                                            <span className={getStatusClass(row.display_status)}>
                                                {row.display_status || 'Pending'}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={currentPage}
                    totalItems={filteredRows.length}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={(size) => {
                        setPageSize(size);
                        setPage(1);
                    }}
                    itemName="payment records"
                />
            </div>
        </div>
    );
};

export default PendingPayments;