import { useEffect, useState } from 'react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import { formatDate } from '../../utils/date';
import { softwareDropdownOptions } from '../../constants/software';
import PaymentModal from '../../components/PaymentModal/PaymentModal';
import { showGlobalError } from '../../context/ErrorContext';

const UpdateStockModal = ({ onClose, onSuccess, initialOwner = '' }) => {
    const [ownerOptions, setOwnerOptions] = useState([]);
    const [selectedOwner, setSelectedOwner] = useState('');
    const [ownerSummary, setOwnerSummary] = useState(null);
    const [ownerDevices, setOwnerDevices] = useState([]);
    const [ownerSims, setOwnerSims] = useState([]);
    const [selectedOwnerDeviceIds, setSelectedOwnerDeviceIds] = useState([]);
    const [selectedOwnerSimIds, setSelectedOwnerSimIds] = useState([]);
    const [deviceNotes, setDeviceNotes] = useState({});
    const [simNotes, setSimNotes] = useState({});

    const [searchQuery, setSearchQuery] = useState('');
    const [searchResult, setSearchResult] = useState(null);
    const [searchSummary, setSearchSummary] = useState(null);
    const [searchLoading, setSearchLoading] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [selectedSearchItem, setSelectedSearchItem] = useState(false);

    const [usedFor, setUsedFor] = useState('');
    const [software, setSoftware] = useState('');
    const [totalAmount, setTotalAmount] = useState('');
    const [amountPaid, setAmountPaid] = useState('');
    const [paymentStatus, setPaymentStatus] = useState('Not Paid');
    const [paymentDealer, setPaymentDealer] = useState(null);
    const [updateLoading, setUpdateLoading] = useState(false);
    const [updateError, setUpdateError] = useState('');

    const triggerSearchError = (msg) => {
        setSearchError(msg);
        showGlobalError(msg);
    };

    const triggerUpdateError = (msg) => {
        setUpdateError(msg);
        showGlobalError(msg);
    };

    useEffect(() => {
        const fetchOwners = async () => {
            try {
                const [dealersRes, techniciansRes] = await Promise.all([
                    api.get('/dealers/list.php'),
                    api.get('/technicians/list.php')
                ]);

                const dealers = (dealersRes?.data?.data?.dealers || [])
                    .filter((dealer) => ['Onsite', 'Offsite'].includes(dealer.installation_status))
                    .map((dealer) => ({
                        value: `dealer:${dealer.id}`,
                        label: `Dealer — ${dealer.dealer_name}`
                    }));

                const technicians = (techniciansRes?.data?.data?.technicians || [])
                    .map((tech) => ({
                        value: `technician:${tech.id}`,
                        label: `Technician — ${tech.technician_name}`
                    }));

                setOwnerOptions([...dealers, ...technicians]);
            } catch (error) {
                console.error('Failed to load owner list', error);
            }
        };

        fetchOwners();
    }, []);

    useEffect(() => {
        if (initialOwner) handleOwnerChange(initialOwner);
    }, [initialOwner]);

    const clearSearchFlow = () => {
        setSearchQuery('');
        setSearchResult(null);
        setSearchSummary(null);
        setSelectedSearchItem(false);
        setSearchError('');
        setUsedFor('');
    };

    const fetchOwnerInventory = async (ownerType, ownerId) => {
        if (!ownerType || !ownerId) {
            setOwnerSummary(null);
            setOwnerDevices([]);
            setOwnerSims([]);
            setSelectedOwnerDeviceIds([]);
            setSelectedOwnerSimIds([]);
            return;
        }

        try {
            const response = await api.get(`/stock/owner_details.php?owner_type=${encodeURIComponent(ownerType)}&owner_id=${encodeURIComponent(ownerId)}`);
            if (response.data.success) {
                const details = response.data.data || {};
                const devices = (details.devices || []).map((device) => ({
                    ...device,
                    statusLabel: device.status_label || (device.usage_type ? `Used for ${device.usage_type === 'ET' ? 'ET' : device.usage_type.charAt(0) + device.usage_type.slice(1).toLowerCase()}` : 'Available')
                }));
                const sims = (details.sims || []).map((sim) => ({
                    ...sim,
                    statusLabel: sim.status_label || (sim.usage_type ? `Used for ${sim.usage_type === 'ET' ? 'ET' : sim.usage_type.charAt(0) + sim.usage_type.slice(1).toLowerCase()}` : 'Available')
                }));

                setOwnerSummary(details.summary || null);
                setOwnerDevices(devices);
                setOwnerSims(sims);
                setSelectedOwnerDeviceIds(devices.filter((device) => device.is_read_only).map((device) => device.id));
                setSelectedOwnerSimIds(sims.filter((sim) => sim.is_read_only).map((sim) => sim.id));
            }
        } catch (error) {
            console.error('Failed to fetch owner inventory', error);
        }
    };

    const fetchSearchOwnerSummary = async (ownerType, ownerId) => {
        if (!ownerType || !ownerId) {
            setSearchSummary(null);
            return;
        }

        try {
            const response = await api.get(`/stock/owner_details.php?owner_type=${encodeURIComponent(ownerType)}&owner_id=${encodeURIComponent(ownerId)}`);
            if (response.data.success) {
                setSearchSummary(response.data.data.summary || null);
            }
        } catch (error) {
            console.error('Failed to fetch search owner summary', error);
        }
    };

    const handleOwnerChange = (value) => {
        setSelectedOwner(value || '');
        setSelectedOwnerDeviceIds([]);
        setSelectedOwnerSimIds([]);
        setDeviceNotes({});
        setSimNotes({});
        setUpdateError('');

        if (!value) {
            setOwnerSummary(null);
            setOwnerDevices([]);
            setOwnerSims([]);
            return;
        }

        const [ownerType, ownerId] = String(value).split(':');
        fetchOwnerInventory(ownerType, ownerId);
    };

    const handleOwnerDeviceToggle = (deviceId) => {
        setSelectedOwnerDeviceIds((prev) =>
            prev.includes(deviceId) ? prev.filter((id) => id !== deviceId) : [...prev, deviceId]
        );
    };

    const handleOwnerSimToggle = (simId) => {
        setSelectedOwnerSimIds((prev) =>
            prev.includes(simId) ? prev.filter((id) => id !== simId) : [...prev, simId]
        );
    };

    const isReadOnlyInventoryItem = (item) => Boolean(item.is_used_for_et || item.is_used_for_customer || item.is_read_only);

    const handleSearch = async (event) => {
        event.preventDefault();
        const value = searchQuery.trim();

        if (!value) {
            triggerSearchError('Please enter an IMEI or SIM number.');
            return;
        }

        const isImei = /^\d{15}$/.test(value);
        const isSim = /^(\d{10}|\d{13})$/.test(value);

        if (!isImei && !isSim) {
            setSearchResult(null);
            setSearchSummary(null);
            setSelectedSearchItem(false);
            triggerSearchError('No device/SIM found for this number.');
            return;
        }

        setSearchLoading(true);
        setSearchError('');
        setUpdateError('');
        setSearchResult(null);
        setSearchSummary(null);
        setSelectedSearchItem(false);

        try {
            const selectedOwnerParts = selectedOwner ? String(selectedOwner).split(':') : [];
            const response = await api.post('/stock/search.php', {
                search: value,
                owner_type: selectedOwnerParts[0] || '',
                owner_id: selectedOwnerParts[1] ? Number(selectedOwnerParts[1]) : 0
            });
            const payload = response.data?.data || {};
            const result = payload?.data || payload?.item || null;

            if (response.data.success && result) {
                setSearchResult(result);
                if (!result.owner_matches_selected) {
                    triggerSearchError('This asset belongs to another owner and cannot be added here.');
                }
                setSelectedSearchItem(Boolean(result.is_used));
                if (result.owner_type && result.owner_id) {
                    fetchSearchOwnerSummary(result.owner_type, result.owner_id);
                }
            } else {
                triggerSearchError(response.data.message || 'No device/SIM found for this number.');
            }
        } catch (error) {
            triggerSearchError(error.response?.data?.message || 'No device/SIM found for this number.');
        } finally {
            setSearchLoading(false);
        }
    };

    const buildUpdatePayload = () => {
        if (selectedOwner) {
            const [ownerType, ownerId] = String(selectedOwner).split(':');
            const selectedDeviceIds = selectedOwnerDeviceIds.filter((id) => !isReadOnlyInventoryItem(ownerDevices.find((device) => device.id === id)));
            const selectedSimIds = selectedOwnerSimIds.filter((id) => !isReadOnlyInventoryItem(ownerSims.find((sim) => sim.id === id)));
            if (selectedSearchItem && searchResult?.owner_matches_selected && !searchResult.is_used) {
                if (searchResult.type === 'device') selectedDeviceIds.push(searchResult.id);
                if (searchResult.type === 'sim') selectedSimIds.push(searchResult.id);
            }
            return {
                owner_type: ownerType,
                owner_id: Number(ownerId),
                device_ids: [...new Set(selectedDeviceIds)],
                sim_ids: [...new Set(selectedSimIds)],
                used_for: usedFor
                , device_notes: deviceNotes, sim_notes: simNotes, software, total_amount: totalAmount, amount_paid: amountPaid, payment_status: paymentStatus
            };
        }

        if (searchResult && selectedSearchItem) {
            if (searchResult.is_used || !searchResult.owner_matches_selected) {
                return null;
            }
            return {
                owner_type: searchResult.owner_type,
                owner_id: Number(searchResult.owner_id),
                used_for: usedFor,
                device_ids: searchResult.type === 'device' ? [searchResult.id] : [],
                sim_ids: searchResult.type === 'sim' ? [searchResult.id] : [],
                device_notes: searchResult.type === 'device' ? { [searchResult.id]: searchResult.notes || '' } : {},
                sim_notes: searchResult.type === 'sim' ? { [searchResult.id]: searchResult.notes || '' } : {}, software, total_amount: totalAmount, amount_paid: amountPaid, payment_status: paymentStatus
            };
        }

        return null;
    };

    const handleUpdate = async () => {
        if (searchResult?.is_used) {
            triggerUpdateError('Used assets are read-only and cannot be updated.');
            return;
        }
        if (searchResult && searchResult.owner_matches_selected === false) {
            triggerUpdateError('This asset belongs to another owner and cannot be added here.');
            return;
        }
        if (!usedFor) {
            triggerUpdateError('Please select a usage type.');
            return;
        }

        const payload = buildUpdatePayload();
        if (!payload) {
            if (searchResult) {
                triggerUpdateError('Please select the device/SIM.');
            } else if (selectedOwner) {
                triggerUpdateError('Please select at least one device or SIM for this owner.');
            } else {
                triggerUpdateError('Please select a dealer/technician or search a device/SIM first.');
            }
            return;
        }
        const selectedCount = (payload.device_ids?.length || 0) + (payload.sim_ids?.length || 0);
        if (usedFor === 'DEALER' && selectedCount !== 1) {
            triggerUpdateError('Select one allocation at a time when recording dealer payment.');
            return;
        }
        if (Number(amountPaid || 0) > Number(totalAmount || 0)) {
            triggerUpdateError('Amount paid cannot be greater than total price.');
            return;
        }

        setUpdateLoading(true);
        setUpdateError('');

        try {
            const response = await api.post('/stock/update.php', payload);
            if (response.data.success) {
                if (typeof onSuccess === 'function') {
                    onSuccess();
                }
                if (typeof onClose === 'function') {
                    onClose();
                }
            } else {
                triggerUpdateError(response.data.message || 'Failed to update stock');
            }
        } catch (error) {
            triggerUpdateError(error.response?.data?.message || 'Network error occurred');
        } finally {
            setUpdateLoading(false);
        }
    };

    const renderSummary = (summary) => {
        if (!summary) return null;

        return (
            <div className="card" style={{ padding: '1rem', backgroundColor: '#f8fafc', border: '1px solid var(--border-color)' }}>
                <h4 style={{ marginTop: 0, marginBottom: '0.75rem' }}>Stock Summary</h4>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(140px, 1fr))', gap: '0.75rem' }}>
                    <div><strong>Total Device:</strong> {summary.total_device ?? 0}</div>
                    <div><strong>Total SIM:</strong> {summary.total_sim ?? 0}</div>
                    <div><strong>Used Device:</strong> {summary.used_device ?? 0}</div>
                    <div><strong>Used SIM:</strong> {summary.used_sim ?? 0}</div>
                    <div><strong>Available Device:</strong> {summary.available_device ?? 0}</div>
                    <div><strong>Available SIM:</strong> {summary.available_sim ?? 0}</div>
                </div>
            </div>
        );
    };

    return (
        <Modal isOpen={true} onClose={onClose} title="Update Stock" maxWidth="820px" footer={
            <>
                <button type="button" className="btn btn-outline" onClick={onClose} disabled={updateLoading}>Cancel</button>
                <button
                    type="button"
                    className="btn btn-primary"
                    onClick={handleUpdate}
                    disabled={updateLoading || (!usedFor) || (!selectedOwner && !searchResult) || (!selectedOwner && searchResult && !selectedSearchItem) || Boolean(searchResult?.is_used) || searchResult?.owner_matches_selected === false}
                >
                    {updateLoading ? 'Updating...' : 'Update Stock'}
                </button>
            </>
        }>
            <div style={{ display: 'grid', gap: '1.25rem' }}>
                <form onSubmit={handleSearch} style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: '0.75rem' }}>
                    <div className="form-group" style={{ margin: 0 }}>
                        <label className="form-label">Search IMEI No or SIM No</label>
                        <input
                            type="text"
                            className="form-control"
                            placeholder="Search IMEI No or SIM No"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                    </div>
                    <div style={{ alignSelf: 'end' }}>
                        <button type="submit" className="btn btn-primary" disabled={searchLoading} style={{ width: '110px' }}>
                            {searchLoading ? 'Searching...' : 'Search'}
                        </button>
                    </div>
                </form>

                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <button type="button" className="btn btn-outline" onClick={clearSearchFlow} style={{ fontSize: '0.8rem', padding: '0.45rem 0.75rem' }}>
                        Clear Search
                    </button>
                </div>

                {searchError && <div className="alert alert-danger">{searchError}</div>}
                {updateError && <div className="alert alert-danger">{updateError}</div>}

                {searchResult && (
                    <div className="card" style={{ padding: '1rem', backgroundColor: '#f8fafc', border: '1px solid var(--border-color)' }}>
                        <h4 style={{ marginTop: 0, marginBottom: '0.75rem' }}>Search Result</h4>

                        <label style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem' }}>
                            <input
                                type="checkbox"
                                checked={selectedSearchItem}
                                disabled={Boolean(searchResult.is_used || searchResult.is_read_only || searchResult.owner_matches_selected === false)}
                                onChange={(e) => setSelectedSearchItem(e.target.checked)}
                            />
                            <span>{searchResult.imei_no || searchResult.sim_no}</span>
                        </label>

                        <div style={{ display: 'grid', gap: '0.5rem' }}>
                            <div><strong>Current Owner:</strong> {searchResult.owner_name || 'Warehouse'}</div>
                            <div><strong>Owner Type:</strong> {searchResult.owner_type ? searchResult.owner_type.charAt(0).toUpperCase() + searchResult.owner_type.slice(1) : 'N/A'}</div>
                            <div><strong>Status:</strong> {searchResult.status_label || searchResult.status || 'N/A'}</div>
                            {searchResult.is_read_only && <div><strong>State:</strong> Read Only</div>}
                            {searchResult.customer_name && <div><strong>Customer:</strong> {searchResult.customer_name}</div>}
                            <div><strong>Purchase Date:</strong> {formatDate(searchResult.purchase_date)}</div>
                            {searchResult.type === 'device' && <div><strong>Device Model:</strong> {searchResult.device_model || 'N/A'}</div>}
                        </div>

                        {searchSummary && renderSummary(searchSummary)}
                    </div>
                )}

                <div className="form-group" style={{ margin: 0 }}>
                    <label className="form-label">Dealer / Technician</label>
                    <SearchableDropdown
                        options={ownerOptions}
                        value={selectedOwner}
                        onChange={handleOwnerChange}
                        placeholder="Select dealer or technician"
                    />
                </div>

                {selectedOwner && ownerSummary && renderSummary(ownerSummary)}

                {selectedOwner && ownerDevices.length > 0 && (
                    <div>
                        <h4 style={{ margin: '0 0 0.75rem' }}>Device / IMEI</h4>
                        <div style={{ display: 'grid', gap: '0.5rem' }}>
                            {ownerDevices.map((device) => {
                                const isLocked = isReadOnlyInventoryItem(device);
                                const isSelected = selectedOwnerDeviceIds.includes(device.id);
                                const statusLabel = device.statusLabel || (device.usage_type ? `Used for ${device.usage_type === 'ET' ? 'ET' : device.usage_type.charAt(0) + device.usage_type.slice(1).toLowerCase()}` : 'Available');

                                return (
                                    <div key={device.id} style={{ padding: '0.75rem', border: '1px solid var(--border-color)', borderRadius: '0.5rem', display: 'grid', gap: '0.5rem' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                disabled={isLocked}
                                                onChange={() => handleOwnerDeviceToggle(device.id)}
                                            />
                                            <span style={{ minWidth: '130px', fontWeight: 500 }}>{device.imei_no}</span>
                                            <span style={{ fontSize: '0.8rem', color: isLocked ? '#166534' : '#1f2937' }}>{statusLabel}</span>
                                            {device.is_used_for_customer && <span style={{ fontSize: '0.8rem', color: '#475569' }}>Customer: {device.customer_name || 'Assigned'}</span>}
                                            {isLocked && <span style={{ fontSize: '0.75rem', color: '#475569' }}>Read Only</span>}
                                        </div>

                                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap', fontSize: '0.85rem', color: '#475569' }}>
                                            <span>Software: {device.software || '-'}</span>
                                            <span>Total: ₹{Number(device.total_amount || 0).toFixed(2)}</span>
                                            <span>Paid: ₹{Number(device.amount_paid || 0).toFixed(2)}</span>
                                            <span>Pending: ₹{Number(device.pending_amount || 0).toFixed(2)}</span>
                                            <span>{device.payment_status || (Number(device.total_amount || 0) > 0 ? 'Not Paid' : 'No Payment Required')}</span>
                                            {selectedOwner.startsWith('dealer:') && <button type="button" className="btn btn-outline" onClick={() => setPaymentDealer({ id: Number(selectedOwner.split(':')[1]), dealer_name: ownerOptions.find((owner) => owner.value === selectedOwner)?.label?.replace('Dealer — ', '') || 'Dealer' })}>Payment</button>}
                                        </div>

                                        {!isLocked && isSelected && (
                                            <textarea
                                                className="form-control"
                                                rows="3"
                                                placeholder="Enter notes..."
                                                value={deviceNotes[device.id] || ''}
                                                onChange={(e) => setDeviceNotes((prev) => ({ ...prev, [device.id]: e.target.value }))}
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {selectedOwner && ownerSims.length > 0 && (
                    <div>
                        <h4 style={{ margin: '0 0 0.75rem' }}>SIM</h4>
                        <div style={{ display: 'grid', gap: '0.5rem' }}>
                            {ownerSims.map((sim) => {
                                const isLocked = isReadOnlyInventoryItem(sim);
                                const isSelected = selectedOwnerSimIds.includes(sim.id);
                                const statusLabel = sim.statusLabel || (sim.usage_type ? `Used for ${sim.usage_type === 'ET' ? 'ET' : sim.usage_type.charAt(0) + sim.usage_type.slice(1).toLowerCase()}` : 'Available');

                                return (
                                    <div key={sim.id} style={{ padding: '0.75rem', border: '1px solid var(--border-color)', borderRadius: '0.5rem', display: 'grid', gap: '0.5rem' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                disabled={isLocked}
                                                onChange={() => handleOwnerSimToggle(sim.id)}
                                            />
                                            <span style={{ minWidth: '130px', fontWeight: 500 }}>{sim.sim_no}</span>
                                            <span style={{ fontSize: '0.8rem', color: isLocked ? '#166534' : '#1f2937' }}>{statusLabel}</span>
                                            {sim.is_used_for_customer && <span style={{ fontSize: '0.8rem', color: '#475569' }}>Customer: {sim.customer_name || 'Assigned'}</span>}
                                            {isLocked && <span style={{ fontSize: '0.75rem', color: '#475569' }}>Read Only</span>}
                                        </div>

                                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap', fontSize: '0.85rem', color: '#475569' }}>
                                            <span>Software: {sim.software || '-'}</span>
                                            <span>Total: ₹{Number(sim.total_amount || 0).toFixed(2)}</span>
                                            <span>Paid: ₹{Number(sim.amount_paid || 0).toFixed(2)}</span>
                                            <span>Pending: ₹{Number(sim.pending_amount || 0).toFixed(2)}</span>
                                            <span>{sim.payment_status || (Number(sim.total_amount || 0) > 0 ? 'Not Paid' : 'No Payment Required')}</span>
                                            {selectedOwner.startsWith('dealer:') && <button type="button" className="btn btn-outline" onClick={() => setPaymentDealer({ id: Number(selectedOwner.split(':')[1]), dealer_name: ownerOptions.find((owner) => owner.value === selectedOwner)?.label?.replace('Dealer — ', '') || 'Dealer' })}>Payment</button>}
                                        </div>

                                        {!isLocked && isSelected && (
                                            <textarea
                                                className="form-control"
                                                rows="3"
                                                placeholder="Enter notes..."
                                                value={simNotes[sim.id] || ''}
                                                onChange={(e) => setSimNotes((prev) => ({ ...prev, [sim.id]: e.target.value }))}
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="form-group" style={{ margin: 0 }}>
                    <label className="form-label">Used For</label>
                    <select className="form-control" value={usedFor} onChange={(e) => setUsedFor(e.target.value)}>
                        <option value="">Select...</option>
                        <option value="ET">Used for ET</option>
                        <option value="TECHNICIAN">Used for Technician</option>
                        <option value="DEALER">Used for Dealer</option>
                    </select>
                </div>
                <div className="form-group" style={{ margin: 0 }}><label className="form-label">Software</label><SearchableDropdown options={softwareDropdownOptions} value={software} onChange={setSoftware} placeholder="Select software" /></div>
                <div className="card" style={{ padding: '1rem', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.75rem' }}>
                    <div className="form-group" style={{ margin: 0 }}><label className="form-label">Total Price</label><input type="number" min="0" step="0.01" className="form-control" value={totalAmount} onChange={(e) => { setTotalAmount(e.target.value); const paid = Number(amountPaid) || 0; setPaymentStatus(paid === 0 ? 'Not Paid' : paid >= Number(e.target.value) ? 'Paid' : 'Partially Paid'); }} /></div>
                    <div className="form-group" style={{ margin: 0 }}><label className="form-label">Amount Paid</label><input type="number" min="0" step="0.01" className="form-control" value={amountPaid} onChange={(e) => { setAmountPaid(e.target.value); const paid = Number(e.target.value) || 0; setPaymentStatus(paid === 0 ? 'Not Paid' : paid >= Number(totalAmount) ? 'Paid' : 'Partially Paid'); }} /></div>
                    <div className="form-group" style={{ margin: 0 }}><label className="form-label">Pending Payment</label><input className="form-control" value={`₹${Math.max(0, Number(totalAmount || 0) - Number(amountPaid || 0)).toFixed(2)}`} readOnly /></div>
                    <div className="form-group" style={{ margin: 0 }}><label className="form-label">Payment Status</label><select className="form-control" value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)}><option>Paid</option><option>Partially Paid</option><option>Not Paid</option></select></div>
                </div>
                {paymentDealer && <PaymentModal dealer={paymentDealer} onClose={() => setPaymentDealer(null)} onSuccess={() => { setPaymentDealer(null); const [type, id] = selectedOwner.split(':'); fetchOwnerInventory(type, id); }} />}
            </div>
        </Modal>
    );
};

export default UpdateStockModal;
