import { useMemo } from 'react';
import { useError } from '../../context/ErrorContext';
import './TableFilterBar.css';
import { Search, RotateCcw } from 'lucide-react';
import FilterSelect from '../FilterSelect/FilterSelect';
import SearchableDropdown from '../SearchableDropdown/SearchableDropdown';
import DateInput from '../DateInput';
import { PAYMENT_STATUS_OPTIONS } from '../../constants/paymentStatuses';

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
    onSearch,
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
    installationStatusNextRow = true,
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
    dateRangeFilters = [],
    showDateRange = false,
    dealerOptions,
    dealerDropdownResetKey = 0,
    showDealer = false,
    gridCols = 4,
    statusPlaceholder = 'All Status',
    className = '',
    technicianOptions,
    showTechnician = false,
    technicianDropdownResetKey = 0
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
        return PAYMENT_STATUS_OPTIONS;
    }, [paymentStatusOptions]);

    const resolvedStatuses = useMemo(() => {
        if (statusOptions) return [...new Set(statusOptions.filter(Boolean).map(String))];
        const statuses = unique(items.map((i) => i.status)).map(s => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase());
        const defaultList = ['Available', 'Allocated', 'Used'];
        return unique([...defaultList, ...statuses]);
    }, [statusOptions, items]);

    const resolvedOwnerTypes = useMemo(() => {
        if (ownerTypeOptions) return unique(ownerTypeOptions);
        return ['Dealer', 'Technician'];
    }, [ownerTypeOptions]);

    const getCustomDateYears = (dateFilter) => {
        if (Array.isArray(dateFilter.years)) {
            return unique(dateFilter.years).sort((first, second) => Number(second) - Number(first));
        }

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
        <div className={`table-filter-card card ${className}`.trim()}>
            <div className={`table-filter-grid${gridCols === 5 ? ' table-filter-grid--5col' : ''}`}>
                {/* 1. Search */}
                {showSearch && (
                    <div className="filter-group">
                        <label className="filter-label">Search</label>
                        <div className="search-input-wrapper">
                            <Search size={16} className="search-icon" />
                            <input
                                className="form-control filter-input has-icon table-filter-search"
                                value={filters.search || ''}
                                onChange={set('search')}
                                placeholder={searchPlaceholder}
                            />
                        </div>
                    </div>
                )}

                {/* 2. Dealer */}
                {(showDealer || dealerOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Dealer</label>
                        <SearchableDropdown
                            key={dealerDropdownResetKey}
                            value={filters.dealer_id || ''}
                            onChange={(value) => onChange({ ...filters, dealer_id: value })}
                            placeholder="All Dealers"
                            options={[
                                { value: '', label: 'All Dealers' },
                                ...(dealerOptions || unique(items.map((item) => item.dealer_name)).map((dealer) => ({ value: dealer, label: dealer })))
                            ]}
                        />
                    </div>
                )}

                {showTechnician && (
                    <div className="filter-group">
                        <label className="filter-label">Technician</label>
                        <SearchableDropdown
                            key={technicianDropdownResetKey}
                            value={filters.technician_id || ''}
                            onChange={(value) => onChange({ ...filters, technician_id: value })}
                            placeholder="All Technicians"
                            options={[
                                { value: '', label: 'All Technicians' },
                                ...(technicianOptions || [])
                            ]}
                        />
                    </div>
                )}

                {/* 3. Platform */}
                {(showPlatform || platformOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Platform</label>
                        <FilterSelect value={filters.platform || ''} onChange={set('platform')}>
                            <option value="">All Platforms</option>
                            {resolvedPlatforms.map((platform) => (
                                <option key={platform} value={platform}>{platform}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 4. Device Model */}
                {(showDeviceModel || deviceModelOptions || deviceOptions || deviceKey) && (
                    <div className="filter-group">
                        <label className="filter-label">Device Model</label>
                        <FilterSelect value={filters.deviceModel || filters.deviceType || ''} onChange={(e) => {
                            onChange({ ...filters, deviceModel: e.target.value, deviceType: e.target.value });
                        }}>
                            <option value="">All Device Models</option>
                            {resolvedDeviceModels.map((model) => (
                                <option key={model} value={model}>{model}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 5. Device Alert / Alert Status */}
                {(showDeviceAlert || deviceAlertOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Device Alert</label>
                        <FilterSelect value={filters.deviceAlert || ''} onChange={set('deviceAlert')}>
                            <option value="">All Alert Status</option>
                            {resolvedDeviceAlerts.map((alert) => (
                                <option key={alert} value={alert}>{alert}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 6. SIM Type */}
                {(showSimType || simTypeOptions || simOptions || simKey) && (
                    <div className="filter-group">
                        <label className="filter-label">SIM Type</label>
                        <FilterSelect value={filters.simType || ''} onChange={set('simType')}>
                            <option value="">All SIM Types</option>
                            {resolvedSimTypes.map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 7. SIM Validity */}
                {(showSimValidity || simValidityOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">SIM Validity</label>
                        <FilterSelect value={filters.simValidity || ''} onChange={set('simValidity')}>
                            <option value="">All SIM Validity</option>
                            {resolvedSimValidities.map((validity) => (
                                <option key={validity} value={validity}>{validity.includes('Month') ? validity : `${validity} Months`}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 8. Asset */}
                {showAsset && (
                    <div className="filter-group">
                        <label className="filter-label">Asset</label>
                        <FilterSelect value={filters.asset || ''} onChange={set('asset')}>
                            <option value="">All Assets</option>
                            <option value="Device">Device</option>
                            <option value="SIM">SIM</option>
                        </FilterSelect>
                    </div>
                )}

                {/* 9. Software */}
                {(showSoftware || softwareOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Software</label>
                        <FilterSelect value={filters.software || ''} onChange={set('software')}>
                            <option value="">All Software</option>
                            {resolvedSoftware.map((software) => (
                                <option key={software} value={software}>{software}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 10. Status (Available / Allocated / Used) */}
                {(showStatus || statusOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Status</label>
                        <FilterSelect value={filters.status || ''} onChange={set('status')}>
                            <option value="">{statusPlaceholder}</option>
                            {resolvedStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 11. Owner Type (Dealer / Technician) */}
                {(showOwnerType || ownerKey) && (
                    <div className="filter-group">
                        <label className="filter-label">Owner Type</label>
                        <FilterSelect value={filters.ownerType || ''} onChange={set('ownerType')}>
                            <option value="">All Owners</option>
                            {resolvedOwnerTypes.map((type) => (
                                <option key={type.toLowerCase()} value={type.toLowerCase()}>{type}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 12. Installation Status (starts on next row) */}
                {(showInstallationStatus || installationStatusOptions) && (
                    <div className={`filter-group${installationStatusNextRow ? ' filter-group--next-row' : ''}`}>
                        <label className="filter-label">Installation Status</label>
                        <FilterSelect value={filters.installationStatus || ''} onChange={set('installationStatus')}>
                            <option value="">All Installation Status</option>
                            {resolvedInstallationStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 13. Payment Status */}
                {(showPaymentStatus || paymentStatusOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Payment Status</label>
                        <FilterSelect value={filters.paymentStatus || ''} onChange={set('paymentStatus')}>
                            <option value="">All Payment Status</option>
                            {resolvedPaymentStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </FilterSelect>
                    </div>
                )}

                {/* 14. Global Date Range */}
                {dateKeys.length > 0 && (
                    <>
                        <div className="filter-group">
                            <label className="filter-label">From Date</label>
                            <input
                                className="form-control filter-input"
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
                        <div className="filter-group">
                            <label className="filter-label">To Date</label>
                            <input
                                className="form-control filter-input"
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

                {dateRangeFilters.map((dateFilter) => {
                    const fromKey = `${dateFilter.key}_from`;
                    const toKey = `${dateFilter.key}_to`;
                    const fromDate = filters[fromKey] || '';
                    const toDate = filters[toKey] || '';
                    const hasInvalidRange = fromDate && toDate && toDate < fromDate;

                    return (
                        <div className="filter-date-range-group" key={dateFilter.key}>
                            <div className="filter-group">
                                <label className="filter-label">{dateFilter.label} From</label>
                                <DateInput
                                    className="form-control filter-input"
                                    value={fromDate}
                                    allowFuture
                                    aria-label={`${dateFilter.label} From`}
                                    onChange={(value) => onChange({ ...filters, [fromKey]: value })}
                                />
                            </div>
                            <div className="filter-group">
                                <label className="filter-label">{dateFilter.label} To</label>
                                <DateInput
                                    className="form-control filter-input"
                                    value={toDate}
                                    allowFuture
                                    aria-label={`${dateFilter.label} To`}
                                    onChange={(value) => onChange({ ...filters, [toKey]: value })}
                                />
                            </div>
                            {hasInvalidRange && (
                                <div className="text-danger" role="alert" style={{ gridColumn: '1 / -1' }}>
                                    To Date must be on or after From Date.
                                </div>
                            )}
                        </div>
                    );
                })}

                {/* 15. Extra Date Filters */}
                {customDateFilters.length > 0 && customDateFilters.map((dateFilter) => (
                    <div key={dateFilter.key} className="filter-group">
                        <label className="filter-label">{dateFilter.label}</label>
                        <div style={{ display: 'flex', gap: '0.25rem' }}>
                            {filters[`${dateFilter.key}_operator`] === 'year' ? (
                                <FilterSelect
                                    value={filters[dateFilter.key] || ''}
                                    onChange={(event) => onChange({ ...filters, [dateFilter.key]: event.target.value })}
                                    aria-label={dateFilter.label}
                                    style={{ flex: 1 }}
                                >
                                    <option value="">Select year</option>
                                    {getCustomDateYears(dateFilter).map((year) => <option key={year} value={year}>{year}</option>)}
                                </FilterSelect>
                            ) : (
                                <input
                                    className="form-control filter-input"
                                    type={filters[`${dateFilter.key}_operator`] === 'month' ? 'month' : 'date'}
                                    value={filters[dateFilter.key] || ''}
                                    onChange={(event) => onChange({ ...filters, [dateFilter.key]: event.target.value })}
                                    aria-label={dateFilter.label}
                                    style={{ flex: 1 }}
                                />
                            )}
                            <FilterSelect
                                value={filters[`${dateFilter.key}_operator`] || 'exact'}
                                onChange={(event) => onChange({ ...filters, [dateFilter.key]: '', [`${dateFilter.key}_operator`]: event.target.value })}
                                aria-label={`${dateFilter.label} operator`}
                                style={{ width: 'auto', minWidth: '80px' }}
                            >
                                <option value="exact">Exact</option>
                                <option value="month">Month</option>
                                <option value="year">Year</option>
                            </FilterSelect>
                        </div>
                    </div>
                ))}
            </div>

            {/* 16. Filter Actions */}
            <div className="table-filter-actions">
                <button
                    type="button"
                    className="btn btn-primary table-filter-btn"
                    onClick={onSearch}
                    disabled={Boolean(dateRangeFilters.some(({ key }) =>
                        filters[`${key}_from`] && filters[`${key}_to`] &&
                        filters[`${key}_to`] < filters[`${key}_from`]
                    ))}
                >
                    <Search size={16} />
                    Search
                </button>
                <button
                    type="button"
                    className="btn btn-secondary table-filter-btn"
                    onClick={onReset}
                >
                    <RotateCcw size={16} />
                    Reset
                </button>
            </div>
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
    dealer_id: '',
    technician_id: '',
    deviceType: '',
    ownerType: '',
    given_date: '',
    given_date_operator: 'exact',
    activation_date: '',
    activation_date_operator: 'exact',
    activation_date_from: '',
    activation_date_to: '',
    date_from: '',
    date_to: ''
});

export default TableFilterBar;
