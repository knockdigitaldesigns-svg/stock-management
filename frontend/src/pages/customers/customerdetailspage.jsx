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
                FILTER CARD
            ===================================================== */}

            <div
                className="card"
                style={{
                    padding: '20px 22px',
                    marginBottom: '20px',
                    borderRadius: '12px'
                }}
            >

                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns:
                            'minmax(220px, 1.5fr) repeat(5, minmax(130px, 1fr))',
                        gap: '15px',
                        alignItems: 'end'
                    }}
                >

                    {/* SEARCH */}

                    <div>

                        <label style={labelStyle}>
                            Search
                        </label>

                        <div
                            style={{
                                position: 'relative'
                            }}
                        >

                            <Search
                                size={16}
                                style={{
                                    position: 'absolute',
                                    left: '11px',
                                    top: '12px',
                                    color: '#64748b'
                                }}
                            />

                            <input
                                type="text"
                                value={search}
                                onChange={e =>
                                    setSearch(
                                        e.target.value
                                    )
                                }
                                placeholder="Username / Mobile / Vehicle / IMEI"
                                style={{
                                    ...inputStyle,
                                    paddingLeft: '34px'
                                }}
                            />

                        </div>

                    </div>


                    {/* PLATFORM */}

                    <div>

                        <label style={labelStyle}>
                            Platform
                        </label>

                        <select
                            value={platformFilter}
                            onChange={e =>
                                setPlatformFilter(
                                    e.target.value
                                )
                            }
                            style={inputStyle}
                        >

                            <option value="">
                                All Platforms
                            </option>

                            {platforms.map(
                                platform => (
                                    <option
                                        key={platform}
                                        value={platform}
                                    >
                                        {platform}
                                    </option>
                                )
                            )}

                        </select>

                    </div>


                    {/* LOCATION */}

                    <div>

                        <label style={labelStyle}>
                            Location
                        </label>

                        <select
                            value={locationFilter}
                            onChange={e =>
                                setLocationFilter(
                                    e.target.value
                                )
                            }
                            style={inputStyle}
                        >

                            <option value="">
                                All Locations
                            </option>

                            {locations.map(
                                location => (
                                    <option
                                        key={location}
                                        value={location}
                                    >
                                        {location}
                                    </option>
                                )
                            )}

                        </select>

                    </div>


                    {/* PAYMENT */}

                    <div>

                        <label style={labelStyle}>
                            Payment Status
                        </label>

                        <select
                            value={paymentFilter}
                            onChange={e =>
                                setPaymentFilter(
                                    e.target.value
                                )
                            }
                            style={inputStyle}
                        >

                            <option value="">
                                All
                            </option>

                            <option value="Paid">
                                Paid
                            </option>

                            <option value="Pending">
                                Pending
                            </option>

                            <option value="Partially Paid">
                                Partially Paid
                            </option>

                            <option value="Not Paid">
                                Not Paid
                            </option>

                        </select>

                    </div>


                    {/* DATE FROM */}

                    <div>

                        <label style={labelStyle}>
                            Date From
                        </label>

                        <input
                            type="date"
                            value={dateFrom}
                            onChange={e => {
                                if (dateTo && e.target.value > dateTo) {
                                    showGlobalError('From Date cannot be later than To Date.');
                                    return;
                                }
                                setDateFrom(
                                    e.target.value
                                )
                            }}
                            max={
                                dateTo ||
                                new Date()
                                    .toISOString()
                                    .split('T')[0]
                            }
                            style={inputStyle}
                        />

                    </div>


                    {/* DATE TO */}

                    <div>

                        <label style={labelStyle}>
                            Date To
                        </label>

                        <input
                            type="date"
                            value={dateTo}
                            onChange={e => {
                                if (dateFrom && e.target.value && e.target.value < dateFrom) {
                                    showGlobalError('From Date cannot be later than To Date.');
                                    return;
                                }
                                setDateTo(
                                    e.target.value
                                )
                            }}
                            min={dateFrom || undefined}
                            max={
                                new Date()
                                    .toISOString()
                                    .split('T')[0]
                            }
                            style={inputStyle}
                        />

                    </div>

                </div>


                {/* FILTER ACTIONS */}

                <div
                    style={{
                        display: 'flex',
                        justifyContent: 'flex-end',
                        gap: '9px',
                        marginTop: '17px'
                    }}
                >

                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() =>
                            setPage(1)
                        }
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '7px'
                        }}
                    >

                        <Search size={16} />

                        Search

                    </button>


                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={
                            handleReset
                        }
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '7px'
                        }}
                    >

                        <RotateCcw size={16} />

                        Reset

                    </button>

                </div>

            </div>


            {/* =====================================================
                TABLE CARD
            ===================================================== */}

            <div
                className="card"
                style={{
                    padding: 0,
                    overflow: 'hidden',
                    borderRadius: '12px'
                }}
            >

                {/* TOOLBAR */}

                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '15px 20px',
                        borderBottom:
                            '1px solid #e5e7eb'
                    }}
                >

                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '7px',
                            fontSize: '13px',
                            color: '#475569'
                        }}
                    >

                        <span>
                            Show
                        </span>

                        <select
                            value={pageSize}
                            onChange={e =>
                                setPageSize(
                                    Number(
                                        e.target.value
                                    )
                                )
                            }
                            style={{
                                width: '62px',
                                height: '34px',
                                border:
                                    '1px solid #dbe2ea',
                                borderRadius: '6px',
                                padding: '0 7px'
                            }}
                        >

                            <option value={10}>
                                10
                            </option>

                            <option value={25}>
                                25
                            </option>

                            <option value={50}>
                                50
                            </option>

                            <option value={100}>
                                100
                            </option>

                        </select>

                        <span>
                            entries
                        </span>

                    </div>


                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={
                            handleExport
                        }
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '7px'
                        }}
                    >

                        <Download size={16} />

                        Export

                    </button>

                </div>


                {/* TABLE */}

                <div
                    style={{
                        width: '100%',
                        overflowX: 'auto'
                    }}
                >

                    <table
                        style={{
                            width: '100%',
                            minWidth: '1150px',
                            borderCollapse:
                                'collapse',
                            fontSize: '13px'
                        }}
                    >

                        <thead>

                            <tr>

                                {[
                                    '#',
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
                                ].map(
                                    heading => (

                                        <th
                                            key={
                                                heading
                                            }
                                            style={{
                                                height:
                                                    '44px',
                                                padding:
                                                    '0 12px',
                                                background:
                                                    '#f8fafc',
                                                borderBottom:
                                                    '1px solid #e2e8f0',
                                                color:
                                                    '#475569',
                                                fontSize:
                                                    '12px',
                                                fontWeight:
                                                    700,
                                                textAlign:
                                                    'left',
                                                whiteSpace:
                                                    'nowrap'
                                            }}
                                        >
                                            {heading}
                                        </th>

                                    )
                                )}

                            </tr>

                        </thead>


                        <tbody>

                            {loading ? (

                                <tr>

                                    <td
                                        colSpan="12"
                                        style={{
                                            padding:
                                                '50px',
                                            textAlign:
                                                'center',
                                            color:
                                                '#64748b'
                                        }}
                                    >
                                        Loading customers...
                                    </td>

                                </tr>

                            ) : paginatedCustomers.length === 0 ? (

                                <tr>

                                    <td
                                        colSpan="12"
                                        style={{
                                            padding:
                                                '50px',
                                            textAlign:
                                                'center',
                                            color:
                                                '#64748b'
                                        }}
                                    >
                                        No customer records found.
                                    </td>

                                </tr>

                            ) : (

                                paginatedCustomers.map(
                                    (item, index) => {

                                        const id =
                                            item.id ||
                                            item.customer_id;

                                        return (

                                            <tr
                                                key={id}
                                            >

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        startIndex +
                                                        index +
                                                        1
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    <strong
                                                        style={{
                                                            color:
                                                                '#0f172a'
                                                        }}
                                                    >
                                                        {
                                                            item.username ||
                                                            '-'
                                                        }
                                                    </strong>
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.platform_name ||
                                                        item.platform ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.primary_mobile_no ||
                                                        item.mobile_no ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.location ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.vehicle_no ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.imei_no ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {
                                                        item.sim_no ||
                                                        item.sim_no_1 ||
                                                        '-'
                                                    }
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {getDisplayTotalAmount(item)}
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >
                                                    {getDisplayPendingAmount(item)}
                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >

                                                    <span
                                                        style={
                                                            paymentBadge(
                                                                item.payment_status
                                                            )
                                                        }
                                                    >
                                                        {
                                                            item.payment_status ||
                                                            'Pending'
                                                        }
                                                    </span>

                                                </td>

                                                <td
                                                    style={tdStyle}
                                                >

                                                    <div
                                                        style={{
                                                            display:
                                                                'flex',
                                                            alignItems:
                                                                'center',
                                                            gap:
                                                                '5px'
                                                        }}
                                                    >

                                                        <button
                                                            type="button"
                                                            title="View"
                                                            onClick={() =>
                                                                handleView(
                                                                    id
                                                                )
                                                            }
                                                            style={iconButton('#2563eb')}
                                                        >
                                                            <Eye
                                                                size={
                                                                    16
                                                                }
                                                            />
                                                        </button>


                                                        {canEdit && (

                                                            <button
                                                                type="button"
                                                                title="Edit"
                                                                onClick={() =>
                                                                    handleEdit(
                                                                        id
                                                                    )
                                                                }
                                                                style={iconButton('#2563eb')}
                                                            >
                                                                <Pencil
                                                                    size={
                                                                        16
                                                                    }
                                                                />
                                                            </button>

                                                        )}


                                                        {canDelete && (

                                                            <button
                                                                type="button"
                                                                title="Delete"
                                                                onClick={() =>
                                                                    handleDelete(
                                                                        id
                                                                    )
                                                                }
                                                                style={iconButton('#ef4444')}
                                                            >
                                                                <Trash2
                                                                    size={
                                                                        16
                                                                    }
                                                                />
                                                            </button>

                                                        )}

                                                    </div>

                                                </td>

                                            </tr>

                                        );

                                    }
                                )

                            )}

                        </tbody>

                    </table>

                </div>


                {/* PAGINATION */}

                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent:
                            'space-between',
                        gap: '15px',
                        padding:
                            '14px 20px',
                        borderTop:
                            '1px solid #e5e7eb'
                    }}
                >

                    <span
                        style={{
                            fontSize: '12px',
                            color: '#64748b'
                        }}
                    >

                        Showing{' '}

                        {filteredCustomers.length === 0
                            ? 0
                            : startIndex + 1}

                        {' '}to{' '}

                        {Math.min(
                            startIndex +
                            pageSize,
                            filteredCustomers.length
                        )}

                        {' '}of{' '}

                        {filteredCustomers.length}

                        {' '}entries

                    </span>


                    <div
                        style={{
                            display: 'flex',
                            gap: '5px'
                        }}
                    >

                        <button
                            type="button"
                            disabled={
                                currentPage === 1
                            }
                            onClick={() =>
                                setPage(
                                    currentPage - 1
                                )
                            }
                            style={
                                paginationButton(
                                    false,
                                    currentPage === 1
                                )
                            }
                        >
                            Previous
                        </button>


                        {Array.from(
                            {
                                length:
                                    totalPages
                            },
                            (_, index) =>
                                index + 1
                        )
                            .slice(
                                Math.max(
                                    0,
                                    currentPage - 3
                                ),
                                currentPage + 2
                            )
                            .map(
                                pageNumber => (

                                    <button
                                        key={
                                            pageNumber
                                        }
                                        type="button"
                                        onClick={() =>
                                            setPage(
                                                pageNumber
                                            )
                                        }
                                        style={
                                            paginationButton(
                                                currentPage ===
                                                pageNumber,
                                                false
                                            )
                                        }
                                    >
                                        {
                                            pageNumber
                                        }
                                    </button>

                                )
                            )}


                        <button
                            type="button"
                            disabled={
                                currentPage ===
                                totalPages
                            }
                            onClick={() =>
                                setPage(
                                    currentPage + 1
                                )
                            }
                            style={
                                paginationButton(
                                    false,
                                    currentPage ===
                                    totalPages
                                )
                            }
                        >
                            Next
                        </button>

                    </div>

                </div>

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


// ============================================================
// TABLE STYLE HELPERS
// ============================================================

const tdStyle = {
    height: '50px',
    padding: '8px 12px',
    borderBottom:
        '1px solid #eef2f7',
    color: '#334155',
    whiteSpace: 'nowrap'
};


const iconButton = (color) => ({
    width: '30px',
    height: '30px',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 0,
    border: 'none',
    background: 'transparent',
    color,
    borderRadius: '6px',
    cursor: 'pointer'
});


const paginationButton = (
    active,
    disabled
) => ({
    minWidth: '34px',
    height: '34px',
    padding: '0 9px',
    border:
        active
            ? '1px solid #2563eb'
            : '1px solid #dbe2ea',
    borderRadius: '6px',
    background:
        active
            ? '#2563eb'
            : '#ffffff',
    color:
        active
            ? '#ffffff'
            : disabled
                ? '#cbd5e1'
                : '#475569',
    cursor:
        disabled
            ? 'not-allowed'
            : 'pointer',
    fontSize: '12px'
});


export default CustomerDetailsPage;