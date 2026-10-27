import { useState } from 'react';
import { AlertTriangle, LoaderCircle, RotateCcw, Search, Signal, Smartphone } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import './InventoryLookup.css';

const Field = ({ label, value }) => {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    return (
        <div className="inventory-lookup-field">
            <span>{label}</span>
            <strong>{value}</strong>
        </div>
    );
};

const StatusBadge = ({ status, label }) => {
    const normalized = String(status || '').toLowerCase();
    const statusClass = normalized.includes('available')
        ? 'available'
        : normalized.includes('installed') || normalized.includes('used')
            ? 'used'
            : normalized.includes('conflict') || normalized.includes('archived')
                ? 'warning'
                : 'allocated';
    return <span className={`inventory-status-badge ${statusClass}`}>{label}: {status || 'Status not recorded'}</span>;
};

const InventoryLookup = () => {
    const [searchType, setSearchType] = useState('imei');
    const [identifier, setIdentifier] = useState('');
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const runSearch = async (event) => {
        event?.preventDefault();
        const normalizedIdentifier = identifier.trim().replace(/\s+/g, '');
        if (!normalizedIdentifier) {
            setError(`Enter an ${searchType === 'imei' ? 'IMEI' : 'SIM'} number to search.`);
            setResult(null);
            return;
        }

        setLoading(true);
        setError('');
        setResult(null);
        try {
            const response = await api.get('/stock/lookup.php', {
                params: { type: searchType, identifier: normalizedIdentifier },
                skipGlobalError: true
            });
            setResult(response.data.data || null);
            if (!response.data.success) {
                setError(response.data.message || 'Unable to retrieve tracking information.');
            }
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to retrieve tracking information. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const resetSearch = () => {
        setIdentifier('');
        setResult(null);
        setError('');
    };

    const assets = searchType === 'imei'
        ? (result?.devices || []).filter((device) => device.imei_no === result?.identifier)
        : (result?.sims || []).filter((sim) => sim.sim_no === result?.identifier);

    return (
        <section className="inventory-lookup card" aria-labelledby="inventory-lookup-title">
            <div className="inventory-lookup-heading">
                <div className="inventory-lookup-icon"><Search size={20} /></div>
                <div>
                    <h3 id="inventory-lookup-title">Inventory Lookup / Track IMEI &amp; SIM</h3>
                    <p>Trace stock ownership, customer links, SIM lifecycle and recorded history.</p>
                </div>
            </div>

            <form className="inventory-lookup-form" onSubmit={runSearch}>
                <label className="inventory-lookup-control">
                    <span>Search by</span>
                    <select value={searchType} onChange={(event) => setSearchType(event.target.value)}>
                        <option value="imei">IMEI Number</option>
                        <option value="sim">SIM Number</option>
                    </select>
                </label>
                <label className="inventory-lookup-control inventory-lookup-query">
                    <span>{searchType === 'imei' ? 'IMEI number' : 'SIM number'}</span>
                    <input
                        type="text"
                        inputMode="numeric"
                        value={identifier}
                        onChange={(event) => setIdentifier(event.target.value)}
                        placeholder={searchType === 'imei' ? 'Enter 15-digit IMEI number' : 'Enter 10- or 13-digit SIM number'}
                        aria-label={searchType === 'imei' ? 'IMEI number' : 'SIM number'}
                    />
                </label>
                <div className="inventory-lookup-actions">
                    <button className="btn btn-primary" type="submit" disabled={loading}>
                        {loading ? <LoaderCircle className="inventory-lookup-spinner" size={16} /> : <Search size={16} />}
                        {loading ? 'Searching...' : 'Search'}
                    </button>
                    <button className="btn btn-outline" type="button" onClick={resetSearch} disabled={loading}>
                        <RotateCcw size={16} /> Clear
                    </button>
                </div>
            </form>

            {error && <div className="inventory-lookup-message error" role="alert">{error}</div>}
            {loading && <div className="inventory-lookup-loading" role="status">Searching inventory records...</div>}

            {result && !result.found && !error && (
                <div className="inventory-lookup-message empty" role="status">
                    No device, SIM, or customer vehicle record was found for <strong>{result.identifier}</strong>.
                </div>
            )}

            {result?.found && (
                <div className="inventory-lookup-results">
                    {result.warnings?.length > 0 && (
                        <div className="inventory-lookup-warnings" role="alert">
                            <AlertTriangle size={18} />
                            <div>
                                <strong>Data needs verification</strong>
                                <ul>{result.warnings.map((warning) => <li key={warning}>{warning}</li>)}</ul>
                            </div>
                        </div>
                    )}

                    {assets.length > 0 && (
                        <div className="inventory-lookup-section">
                            <h4>{searchType === 'imei' ? 'Device information' : 'SIM information'}</h4>
                            <div className="inventory-asset-grid">
                                {searchType === 'imei' ? assets.map((device) => (
                                    <article className="inventory-asset-card" key={`device-${device.id}`}>
                                        <div className="inventory-asset-card-heading">
                                            <span className="inventory-asset-type"><Smartphone size={17} /> Device</span>
                                            <StatusBadge label="Device status" status={device.tracking?.status_label} />
                                        </div>
                                        <div className="inventory-lookup-fields">
                                            <Field label="IMEI number" value={device.imei_no} />
                                            <Field label="Device model" value={device.model_name} />
                                            <Field label="Inward date" value={device.purchase_date ? formatDate(device.purchase_date) : null} />
                                            <Field label="Current holder" value={device.tracking?.current_holder_name} />
                                            <Field label="Holder type" value={device.tracking?.current_holder_type} />
                                            <Field label="Current location" value={device.tracking?.current_location || 'Not Available'} />
                                        </div>
                                    </article>
                                )) : assets.map((sim) => {
                                    const allocation = sim.tracking?.current_allocation;
                                    return (
                                        <article className="inventory-asset-card" key={`sim-${sim.id}`}>
                                            <div className="inventory-asset-card-heading">
                                                <span className="inventory-asset-type"><Signal size={17} /> SIM</span>
                                                <StatusBadge label="SIM status" status={sim.tracking?.status_label} />
                                            </div>
                                            <div className="inventory-lookup-fields">
                                                <Field label="SIM number" value={sim.sim_no} />
                                                <Field label="SIM type" value={sim.sim_type} />
                                                <Field label="Inward date" value={sim.purchase_date ? formatDate(sim.purchase_date) : null} />
                                                <Field label="Current holder" value={sim.tracking?.current_holder_name} />
                                                <Field label="Holder type" value={sim.tracking?.current_holder_type} />
                                                <Field label="Current location" value={sim.tracking?.current_location || 'Not Available'} />
                                                <Field label="SIM lifecycle status" value={allocation?.sim_status} />
                                                <Field label="SIM given date" value={allocation?.sim_given_date ? formatDate(allocation.sim_given_date) : null} />
                                                <Field label="Activation date" value={allocation?.sim_activation_date ? formatDate(allocation.sim_activation_date) : null} />
                                                <Field label="Expiry date" value={allocation?.sim_expiry_date ? formatDate(allocation.sim_expiry_date) : null} />
                                                <Field label="Validity" value={allocation?.sim_validity_months ? `${allocation.sim_validity_months} months` : null} />
                                                <Field label="Deactivation date" value={allocation?.sim_deactivation_date ? formatDate(allocation.sim_deactivation_date) : null} />
                                                <Field label="Reactivation date" value={allocation?.sim_reactivation_date ? formatDate(allocation.sim_reactivation_date) : null} />
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {result.vehicles?.length > 0 && (
                        <div className="inventory-lookup-section">
                            <h4>Customer and vehicle associations</h4>
                            <div className="inventory-vehicle-grid">
                                {result.vehicles.map((vehicle) => (
                                    <article className="inventory-vehicle-card" key={vehicle.id}>
                                        <div className="inventory-vehicle-title">
                                            <strong>{vehicle.vehicle_no || '—'}</strong>
                                            <span>{vehicle.vehicle_type || '—'}</span>
                                        </div>
                                        <div className="inventory-lookup-fields">
                                            <Field label="Customer username" value={vehicle.customer_name} />
                                            <Field label="Customer mobile number" value={vehicle.primary_mobile_no} />
                                            <Field label="Platform" value={vehicle.platform_name} />
                                            <Field label="Linked IMEI" value={vehicle.imei_no} />
                                            {searchType === 'sim' && (
                                                <>
                                                    <Field label="Linked SIM 1" value={vehicle.sim_no_1} />
                                                    <Field label="Linked SIM 2" value={vehicle.sim_no_2} />
                                                </>
                                            )}
                                            <Field label="Installation date" value={vehicle.installation_date ? formatDate(vehicle.installation_date) : null} />
                                            <Field label="Installed by" value={vehicle.installation_person} />
                                            <Field label="Renewal status" value={vehicle.renewal_sim_status} />
                                            <Field label="Last renewed" value={vehicle.last_renewed_date ? formatDate(vehicle.last_renewed_date) : null} />
                                            <Field label="Next renewal" value={vehicle.next_renewal_date ? formatDate(vehicle.next_renewal_date) : null} />
                                            <Field label="Renewal validity" value={vehicle.renewal_validity_months ? `${vehicle.renewal_validity_months} months` : null} />
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
};

export default InventoryLookup;
