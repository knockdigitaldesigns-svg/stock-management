const fs = require('fs');
const path = require('path');

const filePath = path.join('c:', 'Users', 'ASUS', 'Downloads', 'Stock management', 'frontend', 'src', 'components', 'TableFilterBar', 'TableFilterBar.jsx');

let content = fs.readFileSync(filePath, 'utf8');

// Ensure lucide-react is imported
if (!content.includes("import { Search, RotateCcw }")) {
    content = content.replace("import './TableFilterBar.css';", "import './TableFilterBar.css';\nimport { Search, RotateCcw } from 'lucide-react';");
}

const renderStart = content.indexOf('return (', content.lastIndexOf('set = (key)'));
const renderEnd = content.indexOf('};', renderStart);

const newRender = `return (
        <div className="table-filter-card card">
            <div className="table-filter-grid">
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
                        <select className="form-control filter-input" value={filters.dealer_id || ''} onChange={set('dealer_id')}>
                            <option value="">All Dealers</option>
                            {(dealerOptions || []).map((dealer) => (
                                <option key={dealer.value} value={dealer.value}>{dealer.label}</option>
                            ))}
                            {!dealerOptions && unique(items.map(i => i.dealer_name)).map((dealer) => (
                                <option key={dealer} value={dealer}>{dealer}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 3. Platform */}
                {(showPlatform || platformOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Platform</label>
                        <select className="form-control filter-input" value={filters.platform || ''} onChange={set('platform')}>
                            <option value="">All Platforms</option>
                            {resolvedPlatforms.map((platform) => (
                                <option key={platform} value={platform}>{platform}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 4. Device Model */}
                {(showDeviceModel || deviceModelOptions || deviceOptions || deviceKey) && (
                    <div className="filter-group">
                        <label className="filter-label">Device Model</label>
                        <select className="form-control filter-input" value={filters.deviceModel || filters.deviceType || ''} onChange={(e) => {
                            onChange({ ...filters, deviceModel: e.target.value, deviceType: e.target.value });
                        }}>
                            <option value="">All Device Models</option>
                            {resolvedDeviceModels.map((model) => (
                                <option key={model} value={model}>{model}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 5. Device Alert / Alert Status */}
                {(showDeviceAlert || deviceAlertOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Device Alert</label>
                        <select className="form-control filter-input" value={filters.deviceAlert || ''} onChange={set('deviceAlert')}>
                            <option value="">All Alert Status</option>
                            {resolvedDeviceAlerts.map((alert) => (
                                <option key={alert} value={alert}>{alert}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 6. SIM Type */}
                {(showSimType || simTypeOptions || simOptions || simKey) && (
                    <div className="filter-group">
                        <label className="filter-label">SIM Type</label>
                        <select className="form-control filter-input" value={filters.simType || ''} onChange={set('simType')}>
                            <option value="">All SIM Types</option>
                            {resolvedSimTypes.map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 7. SIM Validity */}
                {(showSimValidity || simValidityOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">SIM Validity</label>
                        <select className="form-control filter-input" value={filters.simValidity || ''} onChange={set('simValidity')}>
                            <option value="">All SIM Validity</option>
                            {resolvedSimValidities.map((validity) => (
                                <option key={validity} value={validity}>{validity.includes('Month') ? validity : \`\${validity} Months\`}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 8. Asset */}
                {showAsset && (
                    <div className="filter-group">
                        <label className="filter-label">Asset</label>
                        <select className="form-control filter-input" value={filters.asset || ''} onChange={set('asset')}>
                            <option value="">All Assets</option>
                            <option value="Device">Device</option>
                            <option value="SIM">SIM</option>
                        </select>
                    </div>
                )}

                {/* 9. Software */}
                {(showSoftware || softwareOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Software</label>
                        <select className="form-control filter-input" value={filters.software || ''} onChange={set('software')}>
                            <option value="">All Software</option>
                            {resolvedSoftware.map((software) => (
                                <option key={software} value={software}>{software}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 10. Installation Status */}
                {(showInstallationStatus || installationStatusOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Installation Status</label>
                        <select className="form-control filter-input" value={filters.installationStatus || ''} onChange={set('installationStatus')}>
                            <option value="">All Installation Status</option>
                            {resolvedInstallationStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 11. Status (Available / Allocated / Used) */}
                {(showStatus || statusOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Status</label>
                        <select className="form-control filter-input" value={filters.status || ''} onChange={set('status')}>
                            <option value="">All Status</option>
                            {resolvedStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 12. Payment Status */}
                {(showPaymentStatus || paymentStatusOptions) && (
                    <div className="filter-group">
                        <label className="filter-label">Payment Status</label>
                        <select className="form-control filter-input" value={filters.paymentStatus || ''} onChange={set('paymentStatus')}>
                            <option value="">All Payment Status</option>
                            {resolvedPaymentStatuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* 13. Owner Type (Dealer / Technician) */}
                {(showOwnerType || ownerKey) && (
                    <div className="filter-group">
                        <label className="filter-label">Owner Type</label>
                        <select className="form-control filter-input" value={filters.ownerType || ''} onChange={set('ownerType')}>
                            <option value="">All Owners</option>
                            {resolvedOwnerTypes.map((type) => (
                                <option key={type.toLowerCase()} value={type.toLowerCase()}>{type}</option>
                            ))}
                        </select>
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

                {/* 15. Extra Date Filters */}
                {customDateFilters.length > 0 && customDateFilters.map((dateFilter) => (
                    <div key={dateFilter.key} className="filter-group">
                        <label className="filter-label">{dateFilter.label}</label>
                        <div style={{ display: 'flex', gap: '0.25rem' }}>
                            {filters[\`\${dateFilter.key}_operator\`] === 'year' ? (
                                <select
                                    className="form-control filter-input"
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
                                    className="form-control filter-input"
                                    type={filters[\`\${dateFilter.key}_operator\`] === 'month' ? 'month' : 'date'}
                                    value={filters[dateFilter.key] || ''}
                                    onChange={(event) => onChange({ ...filters, [dateFilter.key]: event.target.value })}
                                    aria-label={dateFilter.label}
                                    style={{ flex: 1 }}
                                />
                            )}
                            <select
                                className="form-control filter-input"
                                value={filters[\`\${dateFilter.key}_operator\`] || 'exact'}
                                onChange={(event) => onChange({ ...filters, [dateFilter.key]: '', [\`\${dateFilter.key}_operator\`]: event.target.value })}
                                aria-label={\`\${dateFilter.label} operator\`}
                                style={{ width: 'auto', minWidth: '80px', padding: '0 5px' }}
                            >
                                <option value="exact">Exact</option>
                                <option value="month">Month</option>
                                <option value="year">Year</option>
                            </select>
                        </div>
                    </div>
                ))}
            </div>

            {/* 16. Filter Actions */}
            <div className="table-filter-actions">
                <button
                    type="button"
                    className="btn btn-primary table-filter-btn"
                    onClick={() => {}} /* Just for UI */
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
`;

content = content.substring(0, renderStart) + newRender + content.substring(renderEnd);
fs.writeFileSync(filePath, content, 'utf8');
console.log('Done replacing JSX');
