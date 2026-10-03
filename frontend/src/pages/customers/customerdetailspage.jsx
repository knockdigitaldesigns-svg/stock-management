import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
    Plus,
    Search,
    RotateCcw,
    Eye,
    Pencil,
    Trash2,
    Download,
    Upload
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import CustomerExcelUploadModal from './CustomerExcelUploadModal';
import { showGlobalError } from '../../context/ErrorContext';
import Pagination from '../../components/Pagination/Pagination';

const CustomerDetailsPage = () => {

    const navigate = useNavigate();
    const { hasPermission } = useAuth();

    const [customers, setCustomers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const [search, setSearch] = useState('');
    const [platformFilter, setPlatformFilter] = useState('');
    const [locationFilter, setLocationFilter] = useState('');
    const [paymentFilter, setPaymentFilter] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);

    const canAdd =
        hasPermission('customers.add');

    const canEdit =
        hasPermission('customers.edit');

    const canDelete =
        hasPermission('customers.delete');


    // ============================================================
    // LOAD CUSTOMERS
    // ============================================================

    const loadCustomers = async () => {

        try {

            setLoading(true);
            setError('');

            const response =
                await api.get('/customers/list.php');

            if (!response.data?.success) {

                throw new Error(
                    response.data?.message ||
                    'Failed to load customers.'
                );
            }

            const list =
                response.data?.data?.customers ||
                response.data?.customers ||
                response.data?.data ||
                [];

            setCustomers(
                Array.isArray(list)
                    ? list
                    : []
            );

        } catch (err) {

            console.error(
                'Customer list error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load customers.'
            );

        } finally {

            setLoading(false);

        }
    };

    const [masterPlatforms, setMasterPlatforms] = useState([]);

    const loadPlatforms = async () => {
        try {
            const res = await api.get('/platforms/list.php').catch(() => null);
            const raw = res?.data?.data?.platforms || [];
            const active = raw
                .filter(p => !p.status || String(p.status).toLowerCase() === 'active')
                .map(p => p.platform_name);
            setMasterPlatforms(active);
        } catch (e) {
            console.error('Failed to load platforms', e);
        }
    };

    useEffect(() => {
        loadCustomers();
        loadPlatforms();
    }, []);


    // ============================================================
    // FILTER OPTIONS
    // ============================================================

    const platforms = useMemo(() => {

        return [
            ...new Set([
                ...masterPlatforms,
                ...customers
                    .map(
                        item =>
                            item.platform_name ||
                            item.platform ||
                            ''
                    )
                    .filter(Boolean)
            ])
        ].sort();

    }, [masterPlatforms, customers]);


    const locations = useMemo(() => {

        return [
            ...new Set(
                customers
                    .map(
                        item =>
                            item.location ||
                            ''
                    )
                    .filter(Boolean)
            )
        ].sort();

    }, [customers]);


    // ============================================================
    // FILTER
    // ============================================================

    const filteredCustomers =
        useMemo(() => {

            const searchText =
                search
                    .trim()
                    .toLowerCase();

            return customers.filter(
                item => {

                    const username =
                        String(
                            item.username || ''
                        ).toLowerCase();

                    const mobile =
                        String(
                            item.primary_mobile_no ||
                            item.mobile_no ||
                            ''
                        ).toLowerCase();

                    const vehicleNo =
                        String(
                            item.vehicle_no ||
                            ''
                        ).toLowerCase();

                    const imei =
                        String(
                            item.imei_no ||
                            ''
                        ).toLowerCase();

                    const platform =
                        String(
                            item.platform_name ||
                            item.platform ||
                            ''
                        );

                    const location =
                        String(
                            item.location ||
                            ''
                        );

                    const paymentStatus =
                        String(
                            item.payment_status ||
                            'Pending'
                        );


                    const matchesSearch =
                        !searchText ||
                        username.includes(searchText) ||
                        mobile.includes(searchText) ||
                        vehicleNo.includes(searchText) ||
                        imei.includes(searchText);


                    const matchesPlatform =
                        !platformFilter ||
                        platform === platformFilter;


                    const matchesLocation =
                        !locationFilter ||
                        location === locationFilter;


                    let matchesPayment = true;

                    if (paymentFilter) {

                        if (
                            paymentFilter ===
                            'Pending'
                        ) {

                            matchesPayment =
                                paymentStatus === 'Pending' ||
                                paymentStatus === 'Not Paid' ||
                                paymentStatus === 'Partially Paid';

                        } else {

                            matchesPayment =
                                paymentStatus ===
                                paymentFilter;

                        }
                    }


                    const recordDate =
                        String(
                            item.installation_date ||
                            item.created_at ||
                            ''
                        ).slice(0, 10);


                    const matchesFrom =
                        !dateFrom ||
                        recordDate >= dateFrom;


                    const matchesTo =
                        !dateTo ||
                        recordDate <= dateTo;


                    return (
                        matchesSearch &&
                        matchesPlatform &&
                        matchesLocation &&
                        matchesPayment &&
                        matchesFrom &&
                        matchesTo
                    );
                }
            );

        }, [
            customers,
            search,
            platformFilter,
            locationFilter,
            paymentFilter,
            dateFrom,
            dateTo
        ]);


    // ============================================================
    // PAGINATION
    // ============================================================

    const totalPages =
        Math.max(
            1,
            Math.ceil(
                filteredCustomers.length /
                pageSize
            )
        );

    const currentPage =
        Math.min(
            page,
            totalPages
        );

    const startIndex =
        (currentPage - 1) *
        pageSize;

    const paginatedCustomers =
        filteredCustomers.slice(
            startIndex,
            startIndex + pageSize
        );


    useEffect(() => {

        setPage(1);

    }, [
        search,
        platformFilter,
        locationFilter,
        paymentFilter,
        dateFrom,
        dateTo,
        pageSize
    ]);


    // ============================================================
    // RESET
    // ============================================================

    const handleReset = () => {

        setSearch('');
        setPlatformFilter('');
        setLocationFilter('');
        setPaymentFilter('');
        setDateFrom('');
        setDateTo('');
        setPage(1);

    };


    // ============================================================
    // ADD
    // ============================================================

    const handleAddCustomer = () => {

        navigate(
            '/customer-management/details/add'
        );

    };


    // ============================================================
    // VIEW
    // ============================================================

    const handleView = (id) => {

        navigate(
            `/customer-management/details/${id}/view`
        );

    };


    // ============================================================
    // EDIT
    // ============================================================

    const handleEdit = (id) => {

        navigate(
            `/customer-management/details/${id}/edit`
        );

    };


    // ============================================================
    // DELETE
    // ============================================================

    const handleDelete = async (id) => {

        const confirmed =
            window.confirm(
                'Are you sure you want to delete this customer?'
            );

        if (!confirmed) {
            return;
        }


        try {

            const response =
                await api.delete(
                    '/customers/delete.php',
                    {
                        data: {
                            id: Number(id)
                        }
                    }
                );


            if (!response.data?.success) {

                throw new Error(
                    response.data?.message ||
                    'Failed to delete customer.'
                );

            }


            await loadCustomers();

        } catch (err) {

            console.error(
                'Customer delete error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to delete customer.'
            );

        }
    };


    // ============================================================
    // PAYMENT BADGE
    // ============================================================

    const paymentBadge = (status) => {

        const value =
            String(
                status ||
                'Pending'
            ).toLowerCase();


        let background = '#fef3c7';
        let color = '#b45309';


        if (value === 'paid') {

            background = '#dcfce7';
            color = '#15803d';

        } else if (
            value === 'partially paid'
        ) {

            background = '#dbeafe';
            color = '#1d4ed8';

        }


        return {
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '4px 9px',
            borderRadius: '999px',
            background,
            color,
            fontSize: '11px',
            fontWeight: 600,
            whiteSpace: 'nowrap'
        };
    };


    const formatCurrency = (amount) => {
        if (amount === null || amount === undefined || amount === '') {
            return '—';
        }
        const num = Number(amount);
        if (Number.isNaN(num)) {
            return amount;
        }
        return `₹${num.toLocaleString('en-IN')}`;
    };

    const getDisplayTotalAmount = (item) => {
        if (item.total_amount !== null && item.total_amount !== undefined && item.total_amount !== '') {
            const num = Number(item.total_amount);
            if (num > 0 || !item.total_sale_amount) {
                return formatCurrency(item.total_amount);
            }
        }
        if (item.total_sale_amount !== null && item.total_sale_amount !== undefined && item.total_sale_amount !== '') {
            return formatCurrency(item.total_sale_amount);
        }
        return '—';
    };

    const getDisplayPendingAmount = (item) => {
        if (item.amount_pending !== null && item.amount_pending !== undefined && item.amount_pending !== '') {
            return formatCurrency(item.amount_pending);
        }
        if (item.payment_status === 'Pending' && item.total_sale_amount) {
            return formatCurrency(item.total_sale_amount);
        }
        return '—';
    };


    // ============================================================
    // EXPORT
    // ============================================================

    const handleExport = () => {

        if (!filteredCustomers.length) {

            window.alert(
                'No customer records available to export.'
            );

            return;
        }


        const headers = [
            'Customer Username',
            'Platform',
            'Primary Mobile',
            'Secondary Mobile',
            'Email',
            'Location',
            'Pincode',
            'Customer Status',
            'Vehicle No',
            'Vehicle Type',
            'Device Model',
            'IMEI No',
            'SIM No 1',
            'SIM No 2',
            'SIM Validity',
            'Installation Person Type',
            'Installation Person',
            'Lead Closure By',
            'Installation Date',
            'Total Sale Amount',
            'Transaction ID',
            'Payment Mode',
            'Device Charge',
            'Software Charge',
            'Technician Charge',
            'SIM Charge',
            'Courier Charge',
            'Total Amount',
            'Amount Paid',
            'Amount Pending',
            'Payment Status'
        ];

        const rows = filteredCustomers.map((item) => [
            item.username || '',
            item.platform_name || item.platform || '',
            item.primary_mobile_no || item.mobile_no || '',
            item.secondary_mobile_no || '',
            item.email || '',
            item.location || '',
            item.pincode || '',
            item.status || 'Active',
            item.vehicle_no || '',
            item.vehicle_type || '',
            item.device_model || item.device_type || '',
            item.imei_no || '',
            item.sim_no_1 || item.sim_no || '',
            item.sim_no_2 || '',
            item.validity_months ? (String(item.validity_months).includes('Month') ? item.validity_months : `${item.validity_months} Months`) : '',
            item.installation_person_type || '',
            item.installation_person || '',
            item.lead_closure || '',
            item.installation_date ? String(item.installation_date).slice(0, 10) : '',
            item.total_sale_amount !== null && item.total_sale_amount !== undefined ? item.total_sale_amount : '',
            item.transaction_id || '',
            item.payment_mode || '',
            item.device_charge !== null && item.device_charge !== undefined ? item.device_charge : '',
            item.software_charge !== null && item.software_charge !== undefined ? item.software_charge : '',
            item.technician_charge !== null && item.technician_charge !== undefined ? item.technician_charge : '',
            item.sim_charge !== null && item.sim_charge !== undefined ? item.sim_charge : '',
            item.courier_charge !== null && item.courier_charge !== undefined ? item.courier_charge : '',
            item.total_amount !== null && item.total_amount !== undefined ? item.total_amount : '',
            item.amount_paid !== null && item.amount_paid !== undefined ? item.amount_paid : '',
            item.amount_pending !== null && item.amount_pending !== undefined ? item.amount_pending : '',
            item.payment_status || 'Pending'
        ]);


        const csv = [
            headers.join(','),
            ...rows.map(
                row =>
                    row
                        .map(
                            value =>
                                `"${String(
                                    value
                                ).replace(
                                    /"/g,
                                    '""'
                                )}"`
                        )
                        .join(',')
            )
        ].join('\n');


        const blob =
            new Blob(
                [csv],
                {
                    type:
                        'text/csv;charset=utf-8;'
                }
            );


        const url =
            URL.createObjectURL(blob);


        const link =
            document.createElement('a');

        link.href = url;
        link.download =
            'customer-details.csv';

        document.body.appendChild(link);

        link.click();

        document.body.removeChild(link);

        URL.revokeObjectURL(url);
    };


    // ============================================================
    // COMMON STYLES
    // ============================================================

    const inputStyle = {
        width: '100%',
        height: '40px',
        boxSizing: 'border-box',
        padding: '0 11px',
        border: '1px solid #dbe2ea',
        borderRadius: '7px',
        background: '#fff',
        color: '#1e293b',
        fontSize: '13px',
        outline: 'none'
    };


    const labelStyle = {
        display: 'block',
        marginBottom: '7px',
        fontSize: '13px',
        fontWeight: 600,
        color: '#334155'
    };


    return (

        <div
            className="page-container"
            style={{
                width: '100%',
                minWidth: 0
            }}
        >

            {/* =====================================================
                HEADER
            ===================================================== */}

            <div
                className="page-header"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: '20px',
                    marginBottom: '22px'
                }}
            >

                <div>

                    <h2
                        style={{
                            margin: 0,
                            fontSize: '25px',
                            fontWeight: 700,
                            color: '#111827'
                        }}
                    >
                        Customer Details
                    </h2>

                    <p
                        className="page-subtitle"
                        style={{
                            margin: '5px 0 0',
                            color: '#64748b',
                            fontSize: '14px'
                        }}
                    >
                        Manage all customer information,
                        vehicle details, installation
                        and payment details.
                    </p>

                </div>


                <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                    {canAdd && (
                        <button
                            type="button"
                            className="btn btn-outline"
                            onClick={() => setIsUploadModalOpen(true)}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '7px',
                                whiteSpace: 'nowrap'
                            }}
                        >
                            <Upload size={17} />
                            Upload Excel
                        </button>
                    )}

                    {canAdd && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleAddCustomer}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '7px',
                                whiteSpace: 'nowrap'
                            }}
                        >
                            <Plus size={17} />
                            Add Customer
                        </button>
                    )}
                </div>

            </div>


            {/* =====================================================
                ERROR
            ===================================================== */}

            {error && (

                <div
                    className="alert alert-danger"
                    style={{
                        marginBottom: '16px'
                    }}
                >
                    {error}
                </div>
            )}

            {/* =====================================================
                FILTERS + TABLE
            ===================================================== */}
            <div className="card">
                <div className="table-filter-card">
                    <div className="table-filter-grid">

                        {/* SEARCH */}
                        <div className="filter-group">
                            <label className="filter-label">Search</label>
                            <div style={{ position: 'relative' }}>
                                <Search size={16} style={{ position: 'absolute', left: '11px', top: '10px', color: '#64748b' }} />
                                <input
                                    type="text"
                                    className="form-control filter-input"
                                    value={search}
                                    onChange={e => setSearch(e.target.value)}
                                    placeholder="Username / Mobile / Vehicle / IMEI"
                                    style={{ paddingLeft: '34px' }}
                                />
                            </div>
                        </div>

                        {/* PLATFORM */}
                        <div className="filter-group">
                            <label className="filter-label">Platform</label>
                            <select
                                className="form-control filter-input"
                                value={platformFilter}
                                onChange={e => setPlatformFilter(e.target.value)}
                            >
                                <option value="">All Platforms</option>
                                {platforms.map(platform => (
                                    <option key={platform} value={platform}>{platform}</option>
                                ))}
                            </select>
                        </div>

                        {/* LOCATION */}
                        <div className="filter-group">
                            <label className="filter-label">Location</label>
                            <select
                                className="form-control filter-input"
                                value={locationFilter}
                                onChange={e => setLocationFilter(e.target.value)}
                            >
                                <option value="">All Locations</option>
                                {locations.map(location => (
                                    <option key={location} value={location}>{location}</option>
                                ))}
                            </select>
                        </div>

                        {/* PAYMENT */}
                        <div className="filter-group">
                            <label className="filter-label">Payment Status</label>
                            <select
                                className="form-control filter-input"
                                value={paymentFilter}
                                onChange={e => setPaymentFilter(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="Paid">Paid</option>
                                <option value="Pending">Pending</option>
                                <option value="Partially Paid">Partially Paid</option>
                                <option value="Not Paid">Not Paid</option>
                            </select>
                        </div>

                        {/* DATE FROM */}
                        <div className="filter-group">
                            <label className="filter-label">From Date</label>
                            <input
                                type="date"
                                className="form-control filter-input"
                                value={dateFrom}
                                onChange={e => {
                                    if (dateTo && e.target.value > dateTo) {
                                        showGlobalError('From Date cannot be later than To Date.');
                                        return;
                                    }
                                    setDateFrom(e.target.value)
                                }}
                                max={dateTo || new Date().toISOString().split('T')[0]}
                            />
                        </div>

                        {/* DATE TO */}
                        <div className="filter-group">
                            <label className="filter-label">To Date</label>
                            <input
                                type="date"
                                className="form-control filter-input"
                                value={dateTo}
                                onChange={e => {
                                    if (dateFrom && e.target.value && e.target.value < dateFrom) {
                                        showGlobalError('From Date cannot be later than To Date.');
                                        return;
                                    }
                                    setDateTo(e.target.value)
                                }}
                                min={dateFrom || undefined}
                                max={new Date().toISOString().split('T')[0]}
                            />
                        </div>

                    </div>

                    <div className="table-filter-actions">
                        <button type="button" className="btn btn-primary" onClick={() => setPage(1)}>
                            <Search size={16} /> Search
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={handleReset}>
                            <RotateCcw size={16} /> Reset
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={handleExport}>
                            <Download size={16} /> Export
                        </button>
                    </div>
                </div>

                {/* TABLE */}
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                {[
                                    // '#',
                                    'Username',
                                    'Platform',
                                    'Primary Mobile No',
                                    'Location',
                                    'Vehicle No',
                                    'IMEI No',
                                    'SIM No',
                                    'Total Amount',
                                    'Pending Amount',
                                    'Payment Status',
                                    'Actions'
                                ].map(heading => (
                                    <th key={heading}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="11" className="text-center">Loading customers...</td>
                                </tr>
                            ) : paginatedCustomers.length === 0 ? (
                                <tr>
                                    <td colSpan="11" className="text-center empty-state">No customer records found.</td>
                                </tr>
                            ) : (
                                paginatedCustomers.map((item, index) => {
                                    const id = item.id || item.customer_id;
                                    return (
                                        <tr key={id}>
                                            {/* <td>{startIndex + index + 1}</td> */}
                                            <td><strong>{item.username || '-'}</strong></td>
                                            <td>{item.platform_name || item.platform || '-'}</td>
                                            <td>{item.primary_mobile_no || item.mobile_no || '-'}</td>
                                            <td>{item.location || '-'}</td>
                                            <td>{item.vehicle_no || '-'}</td>
                                            <td>{item.imei_no || '-'}</td>
                                            <td>{item.sim_no || item.sim_no_1 || '-'}</td>
                                            <td>{getDisplayTotalAmount(item)}</td>
                                            <td>{getDisplayPendingAmount(item)}</td>
                                            <td>
                                                <span style={paymentBadge(item.payment_status)}>
                                                    {item.payment_status || 'Pending'}
                                                </span>
                                            </td>
                                            <td>
                                                <div className="action-buttons" style={{ flexWrap: 'nowrap' }}>
                                                    <button type="button" className="icon-btn view" title="View" onClick={() => handleView(id)}>
                                                        <Eye size={16} />
                                                    </button>
                                                    {canEdit && (
                                                        <button type="button" className="icon-btn edit" title="Edit" onClick={() => handleEdit(id)}>
                                                            <Pencil size={16} />
                                                        </button>
                                                    )}
                                                    {canDelete && (
                                                        <button type="button" className="icon-btn delete" title="Delete" onClick={() => handleDelete(id)}>
                                                            <Trash2 size={16} />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={filteredCustomers.length}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="entries"
                />
            </div>

            {isUploadModalOpen && (
                <CustomerExcelUploadModal
                    onClose={() => setIsUploadModalOpen(false)}
                    onSuccess={() => {
                        setIsUploadModalOpen(false);
                        loadCustomers();
                    }}
                />
            )}

        </div>
    );
};





export default CustomerDetailsPage;