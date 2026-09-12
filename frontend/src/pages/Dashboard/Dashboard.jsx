import { useState, useEffect } from 'react';
import {
    PieChart,
    Pie,
    Cell,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer
} from 'recharts';

import {
    Package,
    Smartphone,
    CheckCircle,
    Clock,
    Truck,
    Calendar,
    RotateCw,
    ChevronLeft,
    ChevronRight,
    X
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import { useNavigate } from 'react-router-dom';

import './Dashboard.css';


// =========================================================
// Helper date utilities
// =========================================================

const pad = (n) => String(n).padStart(2, '0');

const toIsoDate = (d) => {
    if (!d || Number.isNaN(d.getTime())) return '';

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const toDisplayDate = (isoStr) => {
    if (!isoStr) return '';

    const parts = isoStr.split('-');

    if (parts.length !== 3) return isoStr;

    return `${parts[2]}-${parts[1]}-${parts[0]}`;
};

const parseIsoDate = (isoStr) => {
    if (!isoStr) return null;

    const parts = isoStr.split('-');

    if (parts.length !== 3) return null;

    return new Date(
        Number(parts[0]),
        Number(parts[1]) - 1,
        Number(parts[2])
    );
};

const getCurrentFyString = () => {
    const today = new Date();

    const month = today.getMonth() + 1;
    const year = today.getFullYear();

    const startYear = month >= 4 ? year : year - 1;

    return `F.Y. ${startYear}-${startYear + 1}`;
};

const getDefaultDateRange = () => {
    const today = new Date();

    const firstDay = new Date(
        today.getFullYear(),
        today.getMonth(),
        1
    );

    return {
        startDate: toIsoDate(firstDay),
        endDate: toIsoDate(today)
    };
};


// =========================================================
// Dashboard
// =========================================================

const Dashboard = () => {
    const { user } = useAuth();
    const navigate = useNavigate();

    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);

    // =====================================================
    // Global Filters
    // =====================================================

    const [financialYear, setFinancialYear] = useState(
        getCurrentFyString()
    );

    const [availableFYs, setAvailableFYs] = useState([
        getCurrentFyString()
    ]);

    const [dateRange, setDateRange] = useState(
        getDefaultDateRange()
    );

    // =====================================================
    // Date Picker State
    // =====================================================

    const [isPickerOpen, setIsPickerOpen] = useState(false);

    const [tempStartDate, setTempStartDate] = useState(
        dateRange.startDate
    );

    const [tempEndDate, setTempEndDate] = useState(
        dateRange.endDate
    );

    const [pickerError, setPickerError] = useState('');

    const [calendarBaseDate, setCalendarBaseDate] = useState(() => {
        const d =
            parseIsoDate(dateRange.startDate) ||
            new Date();

        return new Date(
            d.getFullYear(),
            d.getMonth(),
            1
        );
    });


    // =====================================================
    // Fetch Dashboard Stats
    // =====================================================

    const fetchStats = async (
        fy = financialYear,
        range = dateRange,
        isRefresh = false
    ) => {
        const requestStartedAt = Date.now();

        if (isRefresh) {
            setRefreshing(true);
        } else {
            setLoading(true);
        }

        try {
            const params = {
                financial_year: fy,
                start_date: range.startDate,
                end_date: range.endDate
            };

            if (isRefresh) {
                params.refresh = requestStartedAt;
            }

            const response = await api.get(
                '/dashboard/stats.php',
                { params }
            );

            if (response.data.success) {
                setStats(response.data.data);

                if (
                    response.data.data
                        ?.available_financial_years
                        ?.length > 0
                ) {
                    setAvailableFYs(
                        response.data.data.available_financial_years
                    );
                }
            }
        } catch (error) {
            console.error(
                'Failed to fetch dashboard stats',
                error
            );
        } finally {
            if (isRefresh) {
                const remainingFeedbackTime =
                    350 - (Date.now() - requestStartedAt);

                if (remainingFeedbackTime > 0) {
                    await new Promise((resolve) =>
                        setTimeout(
                            resolve,
                            remainingFeedbackTime
                        )
                    );
                }
            }

            setLoading(false);
            setRefreshing(false);
        }
    };


    // =====================================================
    // Initial Load
    // =====================================================

    useEffect(() => {
        fetchStats(financialYear, dateRange);
    }, []);


    // =====================================================
    // Financial Year Change
    // =====================================================

    const handleFyChange = (e) => {
        const newFy = e.target.value;

        setFinancialYear(newFy);

        fetchStats(newFy, dateRange);
    };


    // =====================================================
    // Refresh
    // =====================================================

    const handleRefresh = () => {
        fetchStats(
            financialYear,
            dateRange,
            true
        );
    };


    // =====================================================
    // Date Picker
    // =====================================================

    const openDatePicker = () => {
        setTempStartDate(dateRange.startDate);
        setTempEndDate(dateRange.endDate);
        setPickerError('');

        const d =
            parseIsoDate(dateRange.startDate) ||
            new Date();

        setCalendarBaseDate(
            new Date(
                d.getFullYear(),
                d.getMonth(),
                1
            )
        );

        setIsPickerOpen(true);
    };


    const closeDatePicker = () => {
        setIsPickerOpen(false);
        setPickerError('');
    };


    const applyDatePicker = () => {
        if (!tempStartDate || !tempEndDate) {
            setPickerError(
                'Please select both From Date and To Date.'
            );

            return;
        }

        if (tempEndDate < tempStartDate) {
            setPickerError(
                'To Date cannot be earlier than From Date.'
            );

            return;
        }

        const newRange = {
            startDate: tempStartDate,
            endDate: tempEndDate
        };

        setDateRange(newRange);
        setIsPickerOpen(false);
        setPickerError('');

        fetchStats(
            financialYear,
            newRange
        );
    };


    // =====================================================
    // Quick Date Selection
    // =====================================================

    const handleQuickSelect = (preset) => {
        const today = new Date();

        let start = new Date();
        let end = new Date();

        switch (preset) {
            case 'Today':
                start = today;
                end = today;
                break;

            case 'Yesterday':
                start = new Date(today);

                start.setDate(
                    today.getDate() - 1
                );

                end = new Date(start);
                break;

            case 'Last 7 Days':
                start = new Date(today);

                start.setDate(
                    today.getDate() - 6
                );

                end = today;
                break;

            case 'Last 30 Days':
                start = new Date(today);

                start.setDate(
                    today.getDate() - 29
                );

                end = today;
                break;

            case 'This Month':
                start = new Date(
                    today.getFullYear(),
                    today.getMonth(),
                    1
                );

                end = today;
                break;

            case 'Last Month':
                start = new Date(
                    today.getFullYear(),
                    today.getMonth() - 1,
                    1
                );

                end = new Date(
                    today.getFullYear(),
                    today.getMonth(),
                    0
                );

                break;

            case 'Current Financial Year': {
                const month = today.getMonth() + 1;
                const year = today.getFullYear();

                const startYear =
                    month >= 4
                        ? year
                        : year - 1;

                start = new Date(
                    startYear,
                    3,
                    1
                );

                end = new Date(
                    startYear + 1,
                    2,
                    31
                );

                break;
            }

            default:
                break;
        }

        const isoStart = toIsoDate(start);
        const isoEnd = toIsoDate(end);

        setTempStartDate(isoStart);
        setTempEndDate(isoEnd);
        setPickerError('');

        setCalendarBaseDate(
            new Date(
                start.getFullYear(),
                start.getMonth(),
                1
            )
        );
    };


    // =====================================================
    // Calendar Date Click
    // =====================================================

    const handleDateClick = (isoDateStr) => {
        setPickerError('');

        if (
            !tempStartDate ||
            (tempStartDate && tempEndDate)
        ) {
            setTempStartDate(isoDateStr);
            setTempEndDate('');
        } else if (
            tempStartDate &&
            !tempEndDate
        ) {
            if (isoDateStr >= tempStartDate) {
                setTempEndDate(isoDateStr);
            } else {
                setTempStartDate(isoDateStr);
                setTempEndDate('');
            }
        }
    };


    // =====================================================
    // Calendar Navigation
    // =====================================================

    const nextCalendarMonths = () => {
        setCalendarBaseDate(
            new Date(
                calendarBaseDate.getFullYear(),
                calendarBaseDate.getMonth() + 1,
                1
            )
        );
    };

    const prevCalendarMonths = () => {
        setCalendarBaseDate(
            new Date(
                calendarBaseDate.getFullYear(),
                calendarBaseDate.getMonth() - 1,
                1
            )
        );
    };


    // =====================================================
    // Render Calendar
    // =====================================================

    const renderSingleCalendar = (
        year,
        month
    ) => {
        const monthNames = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        const daysInMonth =
            new Date(
                year,
                month + 1,
                0
            ).getDate();

        const firstDayOfWeek =
            new Date(
                year,
                month,
                1
            ).getDay();

        const days = [];

        for (
            let i = 0;
            i < firstDayOfWeek;
            i++
        ) {
            days.push(
                <div
                    key={`empty-${i}`}
                    className="calendar-day empty"
                />
            );
        }

        for (
            let d = 1;
            d <= daysInMonth;
            d++
        ) {
            const dateStr =
                `${year}-${pad(month + 1)}-${pad(d)}`;

            let classNames = 'calendar-day';

            if (
                tempStartDate &&
                dateStr === tempStartDate
            ) {
                classNames += ' selected-start';
            }

            if (
                tempEndDate &&
                dateStr === tempEndDate
            ) {
                classNames += ' selected-end';
            }

            if (
                tempStartDate &&
                tempEndDate &&
                dateStr > tempStartDate &&
                dateStr < tempEndDate
            ) {
                classNames += ' in-range';
            }

            days.push(
                <button
                    key={d}
                    type="button"
                    className={classNames}
                    onClick={() =>
                        handleDateClick(dateStr)
                    }
                >
                    {d}
                </button>
            );
        }

        return (
            <div className="calendar-box">
                <div className="calendar-header-title">
                    {monthNames[month]} {year}
                </div>

                <div className="calendar-weekdays">
                    <span>Su</span>
                    <span>Mo</span>
                    <span>Tu</span>
                    <span>We</span>
                    <span>Th</span>
                    <span>Fr</span>
                    <span>Sa</span>
                </div>

                <div className="calendar-grid">
                    {days}
                </div>
            </div>
        );
    };


    const secondMonthDate =
        new Date(
            calendarBaseDate.getFullYear(),
            calendarBaseDate.getMonth() + 1,
            1
        );


    // =====================================================
    // Loading State
    // =====================================================

    if (loading && !stats) {
        return (
            <div
                className="page-container text-center"
                style={{ padding: '3rem' }}
            >
                Loading Dashboard...
            </div>
        );
    }


    // =====================================================
    // Error State
    // =====================================================

    if (!stats && !loading) {
        return (
            <div
                className="page-container text-center text-danger"
                style={{ padding: '3rem' }}
            >
                Failed to load dashboard data.
            </div>
        );
    }


    // =====================================================
    // Dashboard Data
    // =====================================================

    const {
        metrics = {},
        recent_allocations = [],
        stock_distribution = [],
        monthly_allocations = []
    } = stats || {};


    // =====================================================
    // UI
    // =====================================================

    return (
        <div className="page-container">

            {/* =================================================
                Dashboard Header
            ================================================= */}

            <div className="dashboard-header-container">

                <div className="page-header">

                    <h2>
                        Dashboard Overview
                    </h2>

                    <p
                        style={{
                            color: 'var(--text-secondary)',
                            margin: 0
                        }}
                    >
                        Welcome back,{' '}
                        {user?.employee_name ||
                            user?.username ||
                            'User'}
                    </p>

                </div>


                <div className="dashboard-global-filters">

                    {/* Financial Year */}

                    <div className="filter-item">

                        <select
                            className="form-control fy-dropdown"
                            value={financialYear}
                            onChange={handleFyChange}
                        >
                            {availableFYs.map(
                                (fy) => (
                                    <option
                                        key={fy}
                                        value={fy}
                                    >
                                        {fy}
                                    </option>
                                )
                            )}
                        </select>

                    </div>


                    {/* Date Range */}

                    <div className="filter-item">

                        <button
                            type="button"
                            className="btn btn-outline date-range-btn"
                            onClick={openDatePicker}
                        >
                            <Calendar size={18} />

                            <span>
                                {toDisplayDate(
                                    dateRange.startDate
                                )}{' '}
                                To{' '}
                                {toDisplayDate(
                                    dateRange.endDate
                                )}
                            </span>
                        </button>

                    </div>


                    {/* Refresh */}

                    <div className="filter-item">

                        <button
                            type="button"
                            className="btn btn-primary refresh-btn"
                            onClick={handleRefresh}
                            disabled={refreshing}
                        >
                            <RotateCw
                                size={18}
                                className={
                                    refreshing
                                        ? 'spin-icon'
                                        : ''
                                }
                            />

                            <span>
                                Refresh
                            </span>
                        </button>

                    </div>

                </div>

            </div>


            {/* =================================================
                Date Range Picker Modal
            ================================================= */}

            {isPickerOpen && (
                <div className="date-picker-overlay">

                    <div className="date-picker-modal">

                        <div className="date-picker-header">

                            <h3>
                                Select Date Range
                            </h3>

                            <button
                                type="button"
                                className="close-btn"
                                onClick={closeDatePicker}
                            >
                                <X size={20} />
                            </button>

                        </div>


                        <div className="date-picker-body">

                            {/* Quick Select */}

                            <div className="quick-select-sidebar">

                                <div className="quick-select-title">
                                    Quick Select
                                </div>

                                {[
                                    'Today',
                                    'Yesterday',
                                    'Last 7 Days',
                                    'Last 30 Days',
                                    'This Month',
                                    'Last Month',
                                    'Current Financial Year'
                                ].map(
                                    (preset) => (
                                        <button
                                            key={preset}
                                            type="button"
                                            className="quick-select-btn"
                                            onClick={() =>
                                                handleQuickSelect(
                                                    preset
                                                )
                                            }
                                        >
                                            {preset}
                                        </button>
                                    )
                                )}

                            </div>


                            {/* Calendars */}

                            <div className="calendars-container">

                                <div className="calendar-nav-bar">

                                    <button
                                        type="button"
                                        className="nav-arrow"
                                        onClick={
                                            prevCalendarMonths
                                        }
                                    >
                                        <ChevronLeft size={20} />
                                    </button>

                                    <span className="nav-month-range">
                                        Custom Date Selection
                                    </span>

                                    <button
                                        type="button"
                                        className="nav-arrow"
                                        onClick={
                                            nextCalendarMonths
                                        }
                                    >
                                        <ChevronRight size={20} />
                                    </button>

                                </div>


                                <div className="two-calendars-grid">

                                    {renderSingleCalendar(
                                        calendarBaseDate.getFullYear(),
                                        calendarBaseDate.getMonth()
                                    )}

                                    {renderSingleCalendar(
                                        secondMonthDate.getFullYear(),
                                        secondMonthDate.getMonth()
                                    )}

                                </div>


                                <div className="custom-inputs-row">

                                    <div className="input-group">

                                        <label>
                                            From Date:
                                        </label>

                                        <input
                                            type="date"
                                            className="form-control"
                                            value={tempStartDate}
                                            onChange={(e) => {
                                                setTempStartDate(
                                                    e.target.value
                                                );

                                                setPickerError('');
                                            }}
                                        />

                                    </div>


                                    <div className="input-group">

                                        <label>
                                            To Date:
                                        </label>

                                        <input
                                            type="date"
                                            className="form-control"
                                            value={tempEndDate}
                                            onChange={(e) => {
                                                setTempEndDate(
                                                    e.target.value
                                                );

                                                setPickerError('');
                                            }}
                                        />

                                    </div>

                                </div>


                                {pickerError && (
                                    <div className="picker-error-msg">
                                        {pickerError}
                                    </div>
                                )}

                            </div>

                        </div>


                        <div className="date-picker-footer">

                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={closeDatePicker}
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={applyDatePicker}
                            >
                                Apply
                            </button>

                        </div>

                    </div>

                </div>
            )}


            {/* =================================================
                Metrics Cards
            ================================================= */}

            <div className="metrics-grid">

                {/* Total Devices */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#e0e7ff',
                            color: '#3730a3'
                        }}
                    >
                        <Smartphone size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Total Devices
                        </p>

                        <h3 className="metric-value">
                            {metrics.total_devices}
                        </h3>

                    </div>

                </div>


                {/* Available Devices */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#dbeafe',
                            color: '#1e40af'
                        }}
                    >
                        <Package size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Available Devices
                        </p>

                        <h3 className="metric-value">
                            {metrics.available_devices}
                        </h3>

                    </div>

                </div>


                {/* Allocated Devices */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#fef3c7',
                            color: '#b45309'
                        }}
                    >
                        <Clock size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Allocated Devices
                        </p>

                        <h3 className="metric-value">
                            {metrics.allocated_devices}
                        </h3>

                    </div>

                </div>


                {/* Used Devices */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#e0f2fe',
                            color: '#0369a1'
                        }}
                    >
                        <Truck size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Used Devices
                        </p>

                        <h3 className="metric-value">
                            {metrics.used_devices}
                        </h3>

                    </div>

                </div>


                {/* Total SIMs */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#dcfce7',
                            color: '#166534'
                        }}
                    >
                        <CheckCircle size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Total SIMs
                        </p>

                        <h3 className="metric-value">
                            {metrics.total_sims}
                        </h3>

                    </div>

                </div>


                {/* Available SIMs */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#ccfbf1',
                            color: '#0f766e'
                        }}
                    >
                        <CheckCircle size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Available SIMs
                        </p>

                        <h3 className="metric-value">
                            {metrics.available_sims}
                        </h3>

                    </div>

                </div>


                {/* Allocated SIMs */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#ffe4e6',
                            color: '#be123c'
                        }}
                    >
                        <Clock size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Allocated SIMs
                        </p>

                        <h3 className="metric-value">
                            {metrics.allocated_sims}
                        </h3>

                    </div>

                </div>


                {/* Used SIMs */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#ede9fe',
                            color: '#6d28d9'
                        }}
                    >
                        <Truck size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Used SIMs
                        </p>

                        <h3 className="metric-value">
                            {metrics.used_sims}
                        </h3>

                    </div>

                </div>


                {/* Total Dealers */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#dbeafe',
                            color: '#1d4ed8'
                        }}
                    >
                        <Package size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Total Dealers
                        </p>

                        <h3 className="metric-value">
                            {metrics.total_dealers}
                        </h3>

                    </div>

                </div>


                {/* Total Technicians */}

                <div className="metric-card">

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#f3e8ff',
                            color: '#7c3aed'
                        }}
                    >
                        <Package size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Total Technicians
                        </p>

                        <h3 className="metric-value">
                            {metrics.total_technicians}
                        </h3>

                    </div>

                </div>


                {/* =================================================
                    Pending Payments
                    Click → /payments/pending
                ================================================= */}

                <div
                    className="metric-card"
                    onClick={() =>
                        navigate('/payments/pending')
                    }
                    style={{
                        cursor: 'pointer'
                    }}
                >

                    <div
                        className="metric-icon"
                        style={{
                            backgroundColor: '#fef2f2',
                            color: '#b91c1c'
                        }}
                    >
                        <Clock size={24} />
                    </div>

                    <div className="metric-content">

                        <p className="metric-title">
                            Pending Payments
                        </p>

                        <h3 className="metric-value">
                            {metrics.pending_payments ?? 0}
                        </h3>

                    </div>

                </div>

            </div>


            {/* =================================================
                Dashboard Charts
            ================================================= */}

            <div className="dashboard-grid">

                {/* Stock Distribution */}

                <div className="card dashboard-card">

                    <h3>
                        Stock Distribution
                    </h3>

                    <div className="chart-container">

                        {stock_distribution.length === 0 ? (

                            <div className="text-center">
                                No stock data available.
                            </div>

                        ) : (

                            <ResponsiveContainer
                                width="100%"
                                height={300}
                            >
                                <PieChart>

                                    <Pie
                                        data={
                                            stock_distribution
                                        }
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={100}
                                        paddingAngle={5}
                                        dataKey="value"
                                    >

                                        {stock_distribution.map(
                                            (
                                                entry,
                                                index
                                            ) => (
                                                <Cell
                                                    key={`cell-${index}`}
                                                    fill={
                                                        entry.fill
                                                    }
                                                />
                                            )
                                        )}

                                    </Pie>

                                    <Tooltip
                                        formatter={(
                                            value
                                        ) => [
                                            `${value} Items`,
                                            'Count'
                                        ]}
                                    />

                                    <Legend />

                                </PieChart>
                            </ResponsiveContainer>

                        )}

                    </div>

                </div>


                {/* Monthly Allocations */}

                <div className="card dashboard-card">

                    <h3>
                        Monthly Allocations
                    </h3>

                    <div className="chart-container">

                        {monthly_allocations.length === 0 ? (

                            <div className="text-center">
                                No allocation data available.
                            </div>

                        ) : (

                            <ResponsiveContainer
                                width="100%"
                                height={300}
                            >
                                <BarChart
                                    data={
                                        monthly_allocations
                                    }
                                >

                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        vertical={false}
                                    />

                                    <XAxis
                                        dataKey="name"
                                    />

                                    <YAxis />

                                    <Tooltip />

                                    <Legend />

                                    <Bar
                                        dataKey="devices"
                                        name="Devices"
                                        fill="#3730a3"
                                        radius={[
                                            4,
                                            4,
                                            0,
                                            0
                                        ]}
                                    />

                                    <Bar
                                        dataKey="sims"
                                        name="SIMs"
                                        fill="#3b82f6"
                                        radius={[
                                            4,
                                            4,
                                            0,
                                            0
                                        ]}
                                    />

                                </BarChart>
                            </ResponsiveContainer>

                        )}

                    </div>

                </div>


                {/* =================================================
                    Recent Allocations
                ================================================= */}

                <div className="card dashboard-card full-width">

                    <h3>
                        Recent Allocations
                    </h3>

                    <div className="table-container">

                        <table>

                            <thead>

                                <tr>

                                    <th>
                                        Date
                                    </th>

                                    <th>
                                        Owner
                                    </th>

                                    <th>
                                        Item Number
                                    </th>

                                    <th>
                                        Status / Owner Type
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                {recent_allocations.length === 0 ? (

                                    <tr>

                                        <td
                                            colSpan="4"
                                            className="text-center"
                                        >
                                            No recent allocations
                                        </td>

                                    </tr>

                                ) : (

                                    recent_allocations.map(
                                        (
                                            alloc,
                                            i
                                        ) => (

                                            <tr
                                                key={i}
                                            >

                                                <td>
                                                    {formatDate(
                                                        alloc.date
                                                    )}
                                                </td>

                                                <td>
                                                    {alloc.owner ||
                                                        'Unknown'}
                                                </td>

                                                <td>
                                                    {alloc.item ||
                                                        'N/A'}
                                                </td>

                                                <td>

                                                    <span
                                                        className={`badge ${
                                                            alloc.status ===
                                                            'dealer'
                                                                ? 'badge-primary'
                                                                : 'badge-info'
                                                        }`}
                                                    >
                                                        {alloc.status
                                                            ? alloc.status.toUpperCase()
                                                            : 'N/A'}
                                                    </span>

                                                </td>

                                            </tr>

                                        )
                                    )

                                )}

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </div>
    );
};

export default Dashboard;