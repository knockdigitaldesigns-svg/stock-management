import { useMemo } from 'react';
import { useError } from '../../context/ErrorContext';
import './TableFilterBar.css';

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

export const unique = (values) => [
    ...new Set((values || []).filter(Boolean).map((v) => String(v).trim()))
].filter((v) => v !== '').sort((a, b) => a.localeCompare(b));

export const getAvailableYears = (items, dateKeys = []) => {
    const years = new Set();
    items.forEach((item) => dateKeys.forEach((key) => {
        const value = item[key];
        const match = typeof value === 'string' && value.match(/^(\d{4})-/);
        if (match) years.add(match[1]);
    }));
    return [...years].sort((a, b) => Number(b) - Number(a));
};

export const filterTableRows = (items, filters, {
    dateKeys = [],
    searchKeys = [],
    searchNestedKeys = [],
    platformKey,
    deviceModelKey,
    deviceAlertKey,
    simTypeKey,
    simValidityKey,
    assetKey,
    paymentStatusKey = 'payment_status',
    deviceKey,
    simKey,
    ownerKey,
    softwareKey,
    statusKey = 'status',
    installationStatusKey = 'installation_status',
    customDateFilters = []
} = {}) => {
    const query = (filters.search || '').trim().toLowerCase();

    return items.filter((item) => {
        // 1. Global Date Range Filtering
        if (filters.date_from || filters.date_to) {
            const fromDate = filters.date_from ? new Date(`${filters.date_from}T00:00:00`).getTime() : -Infinity;
            const toDate = filters.date_to ? new Date(`${filters.date_to}T23:59:59.999`).getTime() : Infinity;
            
            // if dateKeys is empty, how do we know the date? We'll assume customDateFilters if dateKeys empty or check all dateKeys.
            // But wait, the instruction says: "using the correct configured date key."
            // We use dateKeys as the source of truth for global date range filter on local tables.
            if (dateKeys.length > 0) {
                const matchesRange = dateKeys.some((key) => {
                    const value = item[key];
                    if (!value) return false;
                    const d = new Date(String(value).includes(' ') ? String(value).replace(' ', 'T') : `${String(value).slice(0, 10)}T00:00:00`);
                    return d.getTime() >= fromDate && d.getTime() <= toDate;
                });
                if (!matchesRange) return false;
            }
        }

        // 2. Search filter
        if (query && searchKeys.length > 0) {
            const matchesSearch = searchKeys.some((key) =>
                String(item[key] ?? '').toLowerCase().includes(query)
            ) || searchNestedKeys.some(({ key, fields = [] }) =>
                Array.isArray(item[key]) && item[key].some((nestedItem) =>
                    fields.some((field) => String(nestedItem[field] ?? '').toLowerCase().includes(query))
                )
            );
            if (!matchesSearch) return false;
        }

        // 2. Year & Month date filters (Removed, now using global date range)

        // 3. Platform filter
        if (filters.platform) {
            const p = filters.platform.toLowerCase();
            const rawPlatforms = item.platforms || (platformKey ? item[platformKey] : null) || item.software || item.platform_name || item.platform;
            const list = Array.isArray(rawPlatforms) ? rawPlatforms : [rawPlatforms].filter(Boolean);
            const matchesPlatform = list.some((val) => String(val).toLowerCase() === p);
            if (!matchesPlatform) return false;
        }

        // 4. Device Model filter
        if (filters.deviceModel || (deviceKey && filters.deviceType)) {
            const dm = (filters.deviceModel || filters.deviceType).toLowerCase();
            const rawModels = item.device_models || (deviceModelKey ? item[deviceModelKey] : null) || (deviceKey ? item[deviceKey] : null) || item.device_type || item.model_name;
            const list = Array.isArray(rawModels) ? rawModels : [rawModels].filter(Boolean);
            const matchesModel = list.some((val) => String(val).toLowerCase() === dm);
            if (!matchesModel) return false;
        }

        // 5. Device Alert filter
        if (filters.deviceAlert) {
            const alertFilter = filters.deviceAlert.toUpperCase();
            const itemAlerts = [
                item.device_alert_status,
                deviceAlertKey ? item[deviceAlertKey] : null,
                item.device_status,
                item.sim_status,
                item.status
            ].filter(Boolean).map(v => String(v).toUpperCase());
            
            const matchesAlert = itemAlerts.length > 0
                ? itemAlerts.includes(alertFilter)
                : alertFilter === 'SAFE';
            if (!matchesAlert) return false;
        }

        // 6. SIM Type filter
        if (filters.simType || (simKey && filters.legacySimType)) {
            const st = (filters.simType || filters.legacySimType).toLowerCase();
            const rawSimTypes = item.sim_types || (simTypeKey ? item[simTypeKey] : null) || (simKey ? item[simKey] : null) || item.sim_type;
            const list = Array.isArray(rawSimTypes) ? rawSimTypes : [rawSimTypes].filter(Boolean);
            const matchesSimType = list.some((val) => String(val).toLowerCase() === st);
            if (!matchesSimType) return false;
        }

        // 7. SIM Validity filter
        if (filters.simValidity) {
            const svNormalized = String(filters.simValidity).replace(/[^0-9]/g, '');
            const rawValidities = item.sim_validities || (simValidityKey ? item[simValidityKey] : null) || item.sim_validity_months || item.validity_months;
            const list = Array.isArray(rawValidities) ? rawValidities : [rawValidities].filter(Boolean);
            const matchesValidity = list.some((val) => {
                const num = String(val).replace(/[^0-9]/g, '');
                return num === svNormalized || String(val).toLowerCase() === String(filters.simValidity).toLowerCase();
            });
            if (!matchesValidity) return false;
        }

        // 7b. Asset filter
        if (filters.asset) {
            const asset = String(filters.asset).toLowerCase();
            const itemAsset = String((assetKey ? item[assetKey] : null) || item.item_type || (item.device_id !== null || item.imei_no ? 'Device' : 'SIM')).toLowerCase();
            if (itemAsset !== asset) return false;
        }

        // 8. Software filter
        if (filters.software) {
            const sf = filters.software.toLowerCase();
            const rawSoftware = (softwareKey ? item[softwareKey] : null) || item.software;
            if (String(rawSoftware || '').toLowerCase() !== sf) return false;
        }

        // 9. Installation Status filter
        if (filters.installationStatus) {
            const is = filters.installationStatus.toLowerCase();
            const rawStatus = (installationStatusKey ? item[installationStatusKey] : null) || item.installation_status;
            if (String(rawStatus || '').toLowerCase() !== is) return false;
        }

        // 10. Status filter (e.g. Available / Allocated / Used)
        if (filters.status) {
            const s = filters.status.toLowerCase();
            const rawStatus = (statusKey ? item[statusKey] : null) || item.status;
            if (String(rawStatus || '').toLowerCase() !== s) return false;
        }

        // 11. Payment Status filter
        if (filters.paymentStatus) {
            const ps = filters.paymentStatus.toLowerCase();
            const rawPayment = String(item[paymentStatusKey] || item.payment_status || '').toLowerCase();
            if (rawPayment !== ps) return false;
        }

        // 12. Custom Date Filters (Exact / Before / After)
        for (const dateFilter of customDateFilters) {
            const key = dateFilter.key;
            const value = filters[key];
            const operator = (filters[`${key}_operator`] || 'exact').toLowerCase();
            if (!value) continue;
            if (dateFilter.nestedKey) {
                const nestedItems = Array.isArray(item[dateFilter.nestedKey]) ? item[dateFilter.nestedKey] : [];
                const nestedDateKey = dateFilter.nestedDateKey || key;
                const targetDate = new Date(`${String(value).slice(0, 10)}T00:00:00`);
                const matchesNested = nestedItems.some((nestedItem) => {
                    const nestedValue = nestedItem[nestedDateKey];
                    if (!nestedValue) return false;
                    const currentDate = new Date(`${String(nestedValue).slice(0, 10)}T00:00:00`);
                    if (operator === 'year') return currentDate.getFullYear() === Number(value);
                    if (operator === 'month') return `${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, '0')}` === String(value).slice(0, 7);
                    return currentDate.getTime() === targetDate.getTime();
                });
                if (!matchesNested) return false;
                continue;
            }
            const currentValue = item[key] ?? null;
            if (!currentValue) return false;
            const currentDate = new Date(`${String(currentValue).slice(0, 10)}T00:00:00`);
            const targetDate = new Date(`${String(value).slice(0, 10)}T00:00:00`);
            const matches = operator === 'year'
                ? currentDate.getFullYear() === Number(value)
                : operator === 'month'
                    ? `${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, '0')}` === String(value).slice(0, 7)
                    : currentDate.getTime() === targetDate.getTime();
            if (!matches) return false;
        }

        // 13. Owner Type filter
        if (filters.ownerType) {
            const ot = filters.ownerType.toLowerCase();
            const rawOwner = String((ownerKey ? item[ownerKey] : null) || item.owner_type || '').toLowerCase();
            if (rawOwner !== ot) return false;
        }

        return true;
    });
};

const TableFilterBar = ({
    filters,
    onChange,
    onReset,
    items = [],
    dateKeys = [],
    searchPlaceholder = 'Search...',
    platformOptions,
    deviceModelOptions,
    deviceAlertOptions,
    simTypeOptions,
    simValidityOptions,
    softwareOptions,
    installationStatusOptions,
    paymentStatusOptions,
    statusOptions,
    ownerTypeOptions,
    showSearch = true,
    showPlatform = false,
    showDeviceModel = false,
    showDeviceAlert = false,
    showSimType = false,
    showSimValidity = false,
    showAsset = false,
    showSoftware = false,
    showInstallationStatus = false,
    showPaymentStatus = false,
    showStatus = false,
    showOwnerType = false,
    deviceKey,
    simKey,
    ownerKey,
    softwareKey,
    deviceOptions,
    simOptions,
    customDateFilters = [],
    showDateRange = false,
    dealerOptions,
    showDealer = false
}) => {
    const { showError } = useError();
    const years = useMemo(() => getAvailableYears(items, dateKeys), [items, dateKeys]);

    const resolvedPlatforms = useMemo(() => {
        if (platformOptions) return unique(platformOptions);
        return unique(items.flatMap((i) => i.platforms || [i.software, i.platform_name, i.platform]));
    }, [platformOptions, items]);

    const resolvedDeviceModels = useMemo(() => {
        if (deviceModelOptions) return unique(deviceModelOptions);
        if (deviceOptions) return unique(deviceOptions);
        return unique(items.flatMap((i) => i.device_models || [i.device_type, i.model_name]));
    }, [deviceModelOptions, deviceOptions, items]);

    const resolvedDeviceAlerts = useMemo(() => {
        if (deviceAlertOptions) return unique(deviceAlertOptions);
        return ['ALERT', 'WARNING', 'SAFE'];
    }, [deviceAlertOptions]);

    const resolvedSimTypes = useMemo(() => {
        if (simTypeOptions) return unique(simTypeOptions);
        if (simOptions) return unique(simOptions);
        return unique(items.flatMap((i) => i.sim_types || [i.sim_type]));
    }, [simTypeOptions, simOptions, items]);

    const resolvedSimValidities = useMemo(() => {
        if (simValidityOptions) return unique(simValidityOptions);
        return unique(items.flatMap((i) => i.sim_validities || [i.sim_validity_months, i.validity_months])).map((v) =>
            String(v).includes('Month') ? v : `${v} Months`
        );
    }, [simValidityOptions, items]);

    const resolvedSoftware = useMemo(() => {
        if (softwareOptions) return unique(softwareOptions);
        return unique(items.map((i) => (softwareKey ? i[softwareKey] : i.software)));
    }, [softwareOptions, items, softwareKey]);

    const resolvedInstallationStatuses = useMemo(() => {
        if (installationStatusOptions) return unique(installationStatusOptions);
        const statuses = unique(items.map((i) => i.installation_status));
        return statuses.length > 0 ? statuses : ['Willing', 'Not Willing'];
    }, [installationStatusOptions, items]);

    const resolvedPaymentStatuses = useMemo(() => {
        if (paymentStatusOptions) return unique(paymentStatusOptions);
        const statuses = unique(items.map((i) => i.payment_status));
        const defaultList = ['Paid', 'Partially Paid', 'Not Paid', 'No Payment Required'];
        return unique([...defaultList, ...statuses]);
    }, [paymentStatusOptions, items]);

    const resolvedStatuses = useMemo(() => {
        if (statusOptions) return unique(statusOptions);
        const statuses = unique(items.map((i) => i.status)).map(s => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase());
        const defaultList = ['Available', 'Allocated', 'Used'];
        return unique([...defaultList, ...statuses]);
    }, [statusOptions, items]);

    const resolvedOwnerTypes = useMemo(() => {
        if (ownerTypeOptions) return unique(ownerTypeOptions);
        return ['Dealer', 'Technician'];
    }, [ownerTypeOptions]);

    const getCustomDateYears = (dateFilter) => {
        const values = items.flatMap((item) => {
            const nestedItems = dateFilter.nestedKey && Array.isArray(item[dateFilter.nestedKey])
                ? item[dateFilter.nestedKey]
                : [item];
            return nestedItems.map((nestedItem) => nestedItem[dateFilter.nestedDateKey || dateFilter.key]);
        });
        return unique(values
            .map((value) => String(value || '').slice(0, 4))
            .filter((year) => /^\d{4}$/.test(year)))
            .sort((first, second) => Number(second) - Number(first));
    };

    const set = (key) => (event) => onChange({ ...filters, [key]: event.target.value });

    return (
        <div className="table-filter-bar">
            {/* 1. Search */}
            {showSearch && (
                <input
                    className="form-control table-filter-search"
                    value={filters.search || ''}
                    onChange={set('search')}
                    placeholder={searchPlaceholder}
                />
            )}

            {/* 2. Dealer */}
            {(showDealer || dealerOptions) && (
                <select className="form-control" value={filters.dealer_id || ''} onChange={set('dealer_id')}>
                    <option value="">All Dealers</option>
                    {(dealerOptions || []).map((dealer) => (
                        <option key={dealer.value} value={dealer.value}>{dealer.label}</option>
                    ))}
                    {!dealerOptions && unique(items.map(i => i.dealer_name)).map((dealer) => (
                        <option key={dealer} value={dealer}>{dealer}</option>
                    ))}
                </select>
            )}

            {/* 3. Platform */}
            {(showPlatform || platformOptions) && (
                <select className="form-control" value={filters.platform || ''} onChange={set('platform')}>
                    <option value="">All Platforms</option>
                    {resolvedPlatforms.map((platform) => (
                        <option key={platform} value={platform}>{platform}</option>
                    ))}
                </select>
            )}

            {/* 3. Device Model */}
            {(showDeviceModel || deviceModelOptions || deviceOptions || deviceKey) && (
                <select className="form-control" value={filters.deviceModel || filters.deviceType || ''} onChange={(e) => {
                    onChange({ ...filters, deviceModel: e.target.value, deviceType: e.target.value });
                }}>
                    <option value="">All Device Models</option>
                    {resolvedDeviceModels.map((model) => (
                        <option key={model} value={model}>{model}</option>
                    ))}
                </select>
            )}

            {/* 4. Device Alert / Alert Status */}
            {(showDeviceAlert || deviceAlertOptions) && (
                <select className="form-control" value={filters.deviceAlert || ''} onChange={set('deviceAlert')}>
                    <option value="">All Alert Status</option>
                    {resolvedDeviceAlerts.map((alert) => (
                        <option key={alert} value={alert}>{alert}</option>
                    ))}
                </select>
            )}

            {/* 5. SIM Type */}
            {(showSimType || simTypeOptions || simOptions || simKey) && (
                <select className="form-control" value={filters.simType || ''} onChange={set('simType')}>
                    <option value="">All SIM Types</option>
                    {resolvedSimTypes.map((type) => (
                        <option key={type} value={type}>{type}</option>
                    ))}
                </select>
            )}

            {/* 6. SIM Validity */}
            {(showSimValidity || simValidityOptions) && (
                <select className="form-control" value={filters.simValidity || ''} onChange={set('simValidity')}>
                    <option value="">All SIM Validity</option>
                    {resolvedSimValidities.map((validity) => (
                        <option key={validity} value={validity}>{validity.includes('Month') ? validity : `${validity} Months`}</option>
                    ))}
                </select>
            )}

            {/* 7. Software */}
            {showAsset && (
                <select className="form-control" value={filters.asset || ''} onChange={set('asset')}>
                    <option value="">All Assets</option>
                    <option value="Device">Device</option>
                    <option value="SIM">SIM</option>
                </select>
            )}

            {/* 8. Software */}
            {(showSoftware || softwareOptions) && (
                <select className="form-control" value={filters.software || ''} onChange={set('software')}>
                    <option value="">All Software</option>
                    {resolvedSoftware.map((software) => (
                        <option key={software} value={software}>{software}</option>
                    ))}
                </select>
            )}

            {/* 8. Installation Status */}
            {(showInstallationStatus || installationStatusOptions) && (
                <select className="form-control" value={filters.installationStatus || ''} onChange={set('installationStatus')}>
                    <option value="">All Installation Status</option>
                    {resolvedInstallationStatuses.map((status) => (
                        <option key={status} value={status}>{status}</option>
                    ))}
                </select>
            )}

            {/* 9. Status (Available / Allocated / Used) */}
            {(showStatus || statusOptions) && (
                <select className="form-control" value={filters.status || ''} onChange={set('status')}>
                    <option value="">All Status</option>
                    {resolvedStatuses.map((status) => (
                        <option key={status} value={status}>{status}</option>
                    ))}
                </select>
            )}

            {/* 10. Payment Status */}
            {(showPaymentStatus || paymentStatusOptions) && (
                <select className="form-control" value={filters.paymentStatus || ''} onChange={set('paymentStatus')}>
                    <option value="">All Payment Status</option>
                    {resolvedPaymentStatuses.map((status) => (
                        <option key={status} value={status}>{status}</option>
                    ))}
                </select>
            )}

            {/* 11. Owner Type (Dealer / Technician) */}
            {(showOwnerType || ownerKey) && (
                <select className="form-control" value={filters.ownerType || ''} onChange={set('ownerType')}>
                    <option value="">All Owners</option>
                    {resolvedOwnerTypes.map((type) => (
                        <option key={type.toLowerCase()} value={type.toLowerCase()}>{type}</option>
                    ))}
                </select>
            )}

            {/* 12. Global Date Range (Replaces Year/Month) */}
            {dateKeys.length > 0 && (
                <>
                    <div className="date-range-group">
                        <label className="date-range-label">From Date</label>
                        <input
                            className="form-control"
                            type="date"
                            value={filters.date_from || ''}
                            onChange={(e) => {
                                if (filters.date_to && e.target.value > filters.date_to) {
                                    showError('From Date cannot be later than To Date.');
                                    return;
                                }
                                onChange({ ...filters, date_from: e.target.value });
                            }}
                            aria-label="From Date"
                        />
                    </div>
                    <div className="date-range-group">
                        <label className="date-range-label">To Date</label>
                        <input
                            className="form-control"
                            type="date"
                            value={filters.date_to || ''}
                            onChange={(e) => {
                                if (filters.date_from && e.target.value && e.target.value < filters.date_from) {
                                    showError('From Date cannot be later than To Date.');
                                    return;
                                }
                                onChange({ ...filters, date_to: e.target.value });
                            }}
                            aria-label="To Date"
                        />
                    </div>
                </>
            )}

            {/* 13. Extra Date Filters */}
            {customDateFilters.length > 0 && customDateFilters.map((dateFilter) => (
                <div key={dateFilter.key} className="date-range-group">
                    <label className="date-range-label">{dateFilter.label}</label>
                    <div style={{ display: 'flex', gap: '0.25rem' }}>
                        {filters[`${dateFilter.key}_operator`] === 'year' ? (
                            <select
                                className="form-control"
                                value={filters[dateFilter.key] || ''}
                                onChange={(event) => onChange({ ...filters, [dateFilter.key]: event.target.value })}
                                aria-label={dateFilter.label}
                                style={{ flex: 1 }}
                            >
                                <option value="">Select year</option>
                                {getCustomDateYears(dateFilter).map((year) => <option key={year} value={year}>{year}</option>)}
                            </select>
                        ) : (
                            <input
                                className="form-control"
                                type={filters[`${dateFilter.key}_operator`] === 'month' ? 'month' : 'date'}
                                value={filters[dateFilter.key] || ''}
                                onChange={(event) => onChange({ ...filters, [dateFilter.key]: event.target.value })}
                                aria-label={dateFilter.label}
                                style={{ flex: 1 }}
                            />
                        )}
                        <select
                            className="form-control"
                            value={filters[`${dateFilter.key}_operator`] || 'exact'}
                            onChange={(event) => onChange({ ...filters, [dateFilter.key]: '', [`${dateFilter.key}_operator`]: event.target.value })}
                            aria-label={`${dateFilter.label} operator`}
                            style={{ width: 'auto', minWidth: '80px' }}
                        >
                            <option value="exact">Exact Date</option>
                            <option value="month">Month</option>
                            <option value="year">Year</option>
                        </select>
                    </div>
                </div>
            ))}

            {/* 14. Reset Filters Button */}
            <button type="button" className="btn btn-outline table-filter-reset" onClick={onReset}>
                Reset Filters
            </button>
        </div>
    );
};

export const emptyTableFilters = () => ({
    search: '',
    year: '',
    month: '',
    platform: '',
    deviceModel: '',
    deviceAlert: '',
    simType: '',
    simValidity: '',
    software: '',
    installationStatus: '',
    status: '',
    paymentStatus: '',
    deviceType: '',
    ownerType: '',
    given_date: '',
    given_date_operator: 'exact',
    activation_date: '',
    activation_date_operator: 'exact',
    date_from: '',
    date_to: ''
});

export default TableFilterBar;

