import { useState, useEffect } from 'react';
import { Trash2 } from 'lucide-react';
import api from '../../services/api';
import SearchableDropdown from '../SearchableDropdown/SearchableDropdown';
import Modal from '../Modal/Modal';
import DateInput from '../DateInput';
import { softwareDropdownOptions } from '../../constants/software';
import { showGlobalError } from '../../context/ErrorContext';

const getDealerAssignedSoftware = (dealer) => {
    if (!dealer) return [];
    if (Array.isArray(dealer.software_list) && dealer.software_list.length > 0) {
        return dealer.software_list.map(s => String(s).trim()).filter(Boolean);
    }
    if (typeof dealer.software === 'string' && dealer.software.trim() !== '') {
        return dealer.software.split(',').map(s => s.trim()).filter(Boolean);
    }
    return [];
};

const AddStockAllocationModal = ({ onClose, onSuccess, ownerType, ownersList }) => {
    const safeOwnersList = Array.isArray(ownersList) ? ownersList : [];
    const ownerOptions = safeOwnersList.map((owner) => ({
        value: owner.id,
        label: `${owner.dealer_name || owner.technician_name || 'Unknown'}${owner.installation_status ? ` - ${owner.installation_status}` : ''}`
    }));
    const allocationTypeOptions = [
        { value: 'device', label: 'Devices' },
        { value: 'sim', label: 'SIMs' },
        { value: 'both', label: 'Both Devices & SIMs' }
    ];
    const paymentModeOptions = [
        { value: 'Cash', label: 'Cash' },
        { value: 'UPI', label: 'UPI' },
        { value: 'Bank Transfer', label: 'Bank Transfer' },
        { value: 'Card', label: 'Card' },
        { value: 'Other', label: 'Other' }
    ];

    const [selectedOwner, setSelectedOwner] = useState('');
    const [allocationType, setAllocationType] = useState('device');
    const [deviceCount, setDeviceCount] = useState(1);
    const [simCount, setSimCount] = useState(1);
    const [devices, setDevices] = useState([{ id: Date.now(), date: '', item_id: '', amount: '', notes: '' }]);
    const [sims, setSims] = useState([{ id: Date.now() + 1, date: '', item_id: '', amount: '', notes: '' }]);
    const [totalAmount, setTotalAmount] = useState('');
    const [amountPaid, setAmountPaid] = useState('');
    const [paymentMode, setPaymentMode] = useState('');
    const [transactionId, setTransactionId] = useState('');
    const [software, setSoftware] = useState('');
    const [availableDevices, setAvailableDevices] = useState([]);
    const [availableSims, setAvailableSims] = useState([]);
    const [stockLoading, setStockLoading] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const selectedDealer = ownerType === 'dealer' ? safeOwnersList.find(d => Number(d.id) === Number(selectedOwner)) : null;
    const assignedSoftware = getDealerAssignedSoftware(selectedDealer);
    const availableSoftwareOptions = (ownerType === 'dealer' && assignedSoftware.length > 0)
        ? assignedSoftware.map(s => ({ value: s, label: s }))
        : softwareDropdownOptions;

    const dealerRequiresPayment = ownerType === 'dealer' && selectedDealer?.installation_status === 'Not Willing';
    const amountPaidEntered = String(amountPaid ?? '').trim() !== '';
    const pendingAmount = Math.max(0, Number(totalAmount || 0) - Number(amountPaid || 0));
    const paymentStatus = Number(totalAmount || 0) <= 0 ? '' : pendingAmount <= 0 ? 'Paid' : Number(amountPaid || 0) > 0 ? 'Partially Paid' : 'Not Paid';
    const paymentFieldsComplete = (!dealerRequiresPayment || String(totalAmount ?? '').trim() !== '') && (!amountPaidEntered || String(paymentMode ?? '').trim() !== '');
    const transactionRequired = amountPaidEntered && paymentMode && paymentMode !== 'Cash';
    const paymentValuesAreValid = ownerType !== 'dealer' || (
        Number.isFinite(Number(totalAmount)) &&
        Number.isFinite(Number(pendingAmount)) &&
        Number(totalAmount) >= 0 &&
        Number(amountPaid) >= 0 &&
        Number(amountPaid) <= Number(totalAmount)
    );
    const activeDevices = allocationType === 'sim' ? [] : devices;
    const activeSims = allocationType === 'device' ? [] : sims;
    const canSubmitAllocation = !loading && (
        selectedOwner &&
        (!dealerRequiresPayment || (String(totalAmount).trim() !== '' && Number.isFinite(Number(totalAmount)) && Number(totalAmount) > 0)) &&
        activeDevices.every((row) => row.date && row.item_id) &&
        activeSims.every((row) => row.date && row.item_id) &&
        (activeDevices.length > 0 || activeSims.length > 0) &&
        (ownerType !== 'dealer' || (paymentFieldsComplete && paymentValuesAreValid))
    );

    useEffect(() => {
        if (ownerType === 'dealer' && selectedOwner) {
            const dealer = safeOwnersList.find(d => Number(d.id) === Number(selectedOwner));
            const assigned = getDealerAssignedSoftware(dealer);
            if (assigned.length > 0) {
                if (software && !assigned.includes(software)) {
                    setSoftware('');
                }
            }
        }
    }, [selectedOwner, ownerType, safeOwnersList]);

    useEffect(() => {
        const fetchStock = async () => {
            setStockLoading(true);
            try {
                const requests = allocationType === 'device'
                    ? [api.get('/devices/list.php')]
                    : allocationType === 'sim'
                        ? [api.get('/sims/list.php')]
                        : [api.get('/devices/list.php'), api.get('/sims/list.php')];
                const responses = await Promise.all(requests);
                const format = (dataArray, type) => dataArray
                    .filter(item => item.status === 'available')
                    .map(item => ({
                            value: item.id,
                            label: type === 'device' ? `${item.imei_no} (${item.model_name})` : item.sim_no
                    }));
                if (allocationType !== 'sim') setAvailableDevices(format(responses[0].data.data.devices || [], 'device'));
                if (allocationType !== 'device') setAvailableSims(format(responses[allocationType === 'both' ? 1 : 0].data.data.sims || [], 'sim'));
            } catch (err) {
                console.error('Failed to fetch stock', err);
            } finally {
                setStockLoading(false);
            }
        };

        fetchStock();
    }, [allocationType]);

    useEffect(() => {
        const count = parseInt(deviceCount) || 1;
        if (count > devices.length) {
            const newItems = [...devices];
            for (let i = devices.length; i < count; i++) {
                const firstRow = devices[0] || {};
                newItems.push({
                    id: Date.now() + i,
                    date: firstRow.date || '',
                    item_id: '',
                    amount: '',
                    notes: ''
                });
            }
            setDevices(newItems);
        } else if (count < devices.length && count > 0) {
            setDevices(devices.slice(0, count));
        }
    }, [deviceCount]);

    useEffect(() => {
        const count = parseInt(simCount) || 1;
        if (count > sims.length) {
            const newItems = [...sims];
            for (let i = sims.length; i < count; i++) {
                newItems.push({ id: Date.now() + i, date: sims[0]?.date || '', item_id: '', amount: '', notes: '' });
            }
            setSims(newItems);
        } else if (count < sims.length && count > 0) {
            setSims(sims.slice(0, count));
        }
    }, [simCount]);

    const handleItemChange = (type, id, field, value) => {
        const collection = type === 'device' ? devices : sims;
        const updated = collection.map(item => item.id === id ? { ...item, [field]: value } : item);
        type === 'device' ? setDevices(updated) : setSims(updated);

        if (field === 'amount') {
            const allItems = type === 'device' ? [...updated, ...sims] : [...devices, ...updated];
            const newTotal = allItems.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
            setTotalAmount(newTotal.toFixed(2));
            if (amountPaid !== '' && Number(amountPaid) <= newTotal) {
                setError((currentError) => currentError === 'Amount Paid cannot be greater than Total Amount.' ? '' : currentError);
            }
        }
    };

    const removeRow = (type, id) => {
        const collection = type === 'device' ? devices : sims;
        if (collection.length > 1) {
            const updated = collection.filter(item => item.id !== id);
            type === 'device' ? setDevices(updated) : setSims(updated);
            type === 'device' ? setDeviceCount(prev => Math.max(1, Number(prev) - 1)) : setSimCount(prev => Math.max(1, Number(prev) - 1));

            const allItems = type === 'device' ? [...updated, ...sims] : [...devices, ...updated];
            const newTotal = allItems.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
            setTotalAmount(newTotal.toFixed(2));
            if (amountPaid !== '' && Number(amountPaid) <= newTotal) {
                setError((currentError) => currentError === 'Amount Paid cannot be greater than Total Amount.' ? '' : currentError);
            }
        }
    };

    const submitAllocation = async (event) => {
        event.preventDefault();
        setError('');

        if (!selectedOwner) return triggerError(`Please select a ${ownerType}`);

        const validateRows = (rows, type) => {
            const seenItems = new Set();
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                if (!row.date) return `Row ${i + 1}: Date is required`;
                if (!row.item_id) return `Row ${i + 1}: Please select a ${type}`;
                if (seenItems.has(row.item_id)) return `Row ${i + 1}: Duplicate ${type} selection in form`;
                seenItems.add(row.item_id);
            }
            return null;
        };
        const deviceError = validateRows(activeDevices, 'device');
        const simError = validateRows(activeSims, 'SIM');
        if (deviceError || simError) return triggerError(deviceError || simError);
        const currentTotalAmount = activeDevices.concat(activeSims).reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
        if (dealerRequiresPayment && (!String(totalAmount).trim() || !Number.isFinite(currentTotalAmount) || currentTotalAmount <= 0)) {
            return triggerError('Total Amount is required and must be greater than 0.');
        }
        const currentAmountPaid = amountPaid === '' ? 0 : Number(amountPaid);
        const currentPendingAmount = Math.max(0, currentTotalAmount - currentAmountPaid);
        const currentPaymentStatus = currentTotalAmount <= 0 ? 'Not Paid' : currentPendingAmount <= 0 ? 'Paid' : currentAmountPaid > 0 ? 'Partially Paid' : 'Not Paid';
        if (currentAmountPaid < 0) {
            return triggerError('Amount Paid cannot be negative.');
        }
        if (currentAmountPaid > currentTotalAmount) {
            return triggerError('Amount Paid cannot exceed Total Amount.');
        }
        if (ownerType === 'dealer') {
            const dealer = safeOwnersList.find(d => Number(d.id) === Number(selectedOwner));
            if (amountPaidEntered && !paymentMode) {
                return triggerError('Payment Mode is required when Amount Paid is entered.');
            }

            if (dealer?.threshold_amount !== null && dealer?.threshold_amount !== '' && dealer?.threshold_amount !== undefined && activeDevices.length > 0) {
                const threshold = Number(dealer.threshold_amount);
                const currentPending = Number(dealer.pending_amount || 0);
                if (Number.isFinite(threshold) && currentPending >= threshold) {
                    return triggerError('Your pending amount exceeds the threshold amount. Clear the pending to buy GPS device.');
                }
            }

            if (dealer?.installation_status === 'Not Willing') {
                if (totalAmount === '' || (amountPaidEntered && !paymentMode)) {
                    return triggerError('Payment details are mandatory for Not Willing dealers.');
                }

                const totalValue = Number(totalAmount);
                const paidValue = Number(amountPaid);
                if (!Number.isFinite(totalValue) || (amountPaidEntered && (!Number.isFinite(paidValue) || paidValue < 0 || paidValue > totalValue))) {
                    return triggerError('Payment details are invalid for Not Willing dealers.');
                }
            }

            if (software) {
                const assigned = getDealerAssignedSoftware(dealer);
                if (assigned.length > 0 && !assigned.includes(software)) {
                    return triggerError(`Selected software '${software}' is not assigned to this dealer.`);
                }
            }
        }
        if (transactionRequired && !transactionId.trim()) {
            return triggerError('Transaction ID is required for the selected Payment Mode.');
        }

        setLoading(true);
        try {
            const payload = {
                owner_type: ownerType,
                owner_id: selectedOwner,
                allocation_date: (activeDevices[0] || activeSims[0]).date,
                total_amount: currentTotalAmount,
                amount_paid: amountPaidEntered ? Number(amountPaid) : null,
                pending_amount: currentPendingAmount,
                payment_status: currentPaymentStatus,
                payment_mode: paymentMode,
                transaction_id: transactionRequired ? transactionId.trim() : null,
                software,
                devices: activeDevices.map(i => ({ id: i.item_id, allocation_date: i.date, amount: parseFloat(i.amount) || 0, notes: i.notes })),
                sims: activeSims.map(i => ({ id: i.item_id, allocation_date: i.date, amount: parseFloat(i.amount) || 0, notes: i.notes }))
            };

            const response = await api.post('/allocations/create.php', payload);
            if (response.data.success) {
                onSuccess();
            } else {
                triggerError(response.data.message || 'Failed to allocate stock');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Network error occurred');
        } finally {
            setLoading(false);
        }
    };

    const footer = (
        <>
            <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>
                Cancel
            </button>
            <button
                type="button"
                className="btn btn-primary"
                onClick={() => document.getElementById('allocate-stock-form')?.requestSubmit?.()}
                disabled={!canSubmitAllocation}
            >
                {loading ? 'Allocating...' : 'Confirm Allocation'}
            </button>
        </>
    );

    return (
        <Modal isOpen={true} onClose={onClose} title="Allocate Stock" maxWidth="820px" footer={footer}>
            <form id="allocate-stock-form" onSubmit={submitAllocation}>
                {error && <div className="alert alert-danger">{error}</div>}

                {ownerType === 'dealer' && dealerRequiresPayment && (
                    <div className="card" style={{ marginBottom: '1rem', padding: '1rem', border: '1px solid #f0b429', backgroundColor: '#fff8e1' }}>
                        <strong style={{ display: 'block', marginBottom: '0.35rem', color: '#8a5a00' }}>Payment Required</strong>
                        <span>Installation status is Not Willing. Payment details are mandatory for this allocation.</span>
                    </div>
                )}

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.5rem', marginBottom: '1.5rem' }}>
                    <div className="form-group">
                        <label className="form-label">Select {ownerType === 'dealer' ? 'Dealer' : 'Technician'} *</label>
                        <SearchableDropdown
                            options={ownerOptions}
                            value={selectedOwner}
                            onChange={setSelectedOwner}
                            placeholder={safeOwnersList.length ? 'Search dealer/technician...' : 'No owners available'}
                        />
                    </div>

                    <div className="form-group">
                        <label className="form-label">What are you allocating?</label>
                        <SearchableDropdown
                            options={allocationTypeOptions}
                            value={allocationType}
                            onChange={(value) => {
                                setAllocationType(value);
                                setDevices([{ id: Date.now(), date: '', item_id: '', amount: '', notes: '' }]);
                                setSims([{ id: Date.now() + 1, date: '', item_id: '', amount: '', notes: '' }]);
                                setDeviceCount(1);
                                setSimCount(1);
                            }}
                            placeholder="Select allocation type"
                        />
                    </div>
                </div>

                <hr style={{ margin: '1.5rem 0', borderColor: 'var(--border-color)', borderStyle: 'dashed' }} />

                {allocationType !== 'sim' && <>
                    <h4>Device Allocation</h4>
                    <div className="form-group" style={{ maxWidth: '200px' }}>
                        <label className="form-label">Device Count *</label>
                        <input type="number" min="1" max="50" className="form-control" value={deviceCount} onChange={(e) => setDeviceCount(e.target.value)} />
                    </div>
                    <div className="items-list" style={{ marginBottom: '1.5rem' }}>
                        {devices.map((item, index) => (
                            <div key={item.id} className="device-row" style={{ gridTemplateColumns: '1fr 2fr 1fr auto' }}>
                                <div className="form-group"><label className="form-label">Date *</label><DateInput className="form-control" value={item.date} onChange={(value) => handleItemChange('device', item.id, 'date', value)} required /></div>
                                <div className="form-group"><label className="form-label">Device Model *</label><SearchableDropdown options={availableDevices} value={item.item_id} onChange={(value) => handleItemChange('device', item.id, 'item_id', value)} placeholder={stockLoading ? 'Loading...' : 'Search IMEI / model...'} /></div>
                                <div className="form-group"><label className="form-label">Device Amount</label><input type="number" step="0.01" className="form-control" value={item.amount} onChange={(e) => handleItemChange('device', item.id, 'amount', e.target.value)} placeholder="0.00" /></div>
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}><label className="form-label">Notes</label><textarea className="form-control" rows="3" placeholder="Enter notes..." value={item.notes} onChange={(e) => handleItemChange('device', item.id, 'notes', e.target.value)} /></div>
                                {devices.length > 1 && <button type="button" className="icon-btn delete" onClick={() => removeRow('device', item.id)} aria-label={`Remove device ${index + 1}`}><Trash2 size={18} /></button>}
                            </div>
                        ))}
                    </div>
                </>}

                {allocationType !== 'device' && <>
                    <h4>SIM Allocation</h4>
                    <div className="form-group" style={{ maxWidth: '200px' }}>
                        <label className="form-label">SIM Count *</label>
                        <input type="number" min="1" max="50" className="form-control" value={simCount} onChange={(e) => setSimCount(e.target.value)} />
                    </div>
                    <div className="items-list" style={{ marginBottom: '1.5rem' }}>
                        {sims.map((item, index) => (
                            <div key={item.id} className="device-row" style={{ gridTemplateColumns: '1fr 2fr 1fr auto' }}>
                                <div className="form-group"><label className="form-label">Date *</label><DateInput className="form-control" value={item.date} onChange={(value) => handleItemChange('sim', item.id, 'date', value)} required /></div>
                                <div className="form-group"><label className="form-label">SIM No *</label><SearchableDropdown options={availableSims} value={item.item_id} onChange={(value) => handleItemChange('sim', item.id, 'item_id', value)} placeholder={stockLoading ? 'Loading...' : 'Search SIM...'} /></div>
                                <div className="form-group"><label className="form-label">SIM Amount</label><input type="number" step="0.01" className="form-control" value={item.amount} onChange={(e) => handleItemChange('sim', item.id, 'amount', e.target.value)} placeholder="0.00" /></div>
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}><label className="form-label">Notes</label><textarea className="form-control" rows="3" placeholder="Enter notes..." value={item.notes} onChange={(e) => handleItemChange('sim', item.id, 'notes', e.target.value)} /></div>
                                {sims.length > 1 && <button type="button" className="icon-btn delete" onClick={() => removeRow('sim', item.id)} aria-label={`Remove SIM ${index + 1}`}><Trash2 size={18} /></button>}
                            </div>
                        ))}
                    </div>
                </>}
                <div className="form-group"><label className="form-label">Software</label><SearchableDropdown options={availableSoftwareOptions} value={software} onChange={setSoftware} placeholder="Select software" /></div>

                {ownerType === 'dealer' && (
                    <>
                        <hr style={{ margin: '1.5rem 0', borderColor: 'var(--border-color)' }} />
                        <h4>Payment Details</h4>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: '1rem', marginTop: '1rem' }}>
                            <div className="form-group">
                                <label className="form-label">Total Amount{dealerRequiresPayment ? ' *' : ''}</label>
                                <input
                                    type="number"
                                    className="form-control"
                                    value={totalAmount}
                                    readOnly
                                />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Amount Paid</label>
                                <input type="number" min="0" step="0.01" className="form-control" value={amountPaid} onChange={e => { const value = e.target.value; setAmountPaid(value); const paid = Number(value); if (value === '' || (Number.isFinite(paid) && paid <= Number(totalAmount || 0))) setError(currentError => currentError === 'Amount Paid cannot be greater than Total Amount.' ? '' : currentError); }} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Pending Amount</label>
                                <input type="number" className="form-control" value={pendingAmount.toFixed(2)} readOnly />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Payment Status</label>
                                <input className="form-control" value={paymentStatus || 'No Payment Required'} readOnly />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Payment Mode{amountPaidEntered ? ' *' : ''}</label>
                                <SearchableDropdown
                                    options={paymentModeOptions}
                                    value={paymentMode}
                                    onChange={setPaymentMode}
                                    placeholder="Select payment mode"
                                />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Transaction ID{transactionRequired ? ' *' : ''}</label>
                                <input className="form-control" value={transactionId} onChange={e => setTransactionId(e.target.value)} placeholder={transactionRequired ? 'Enter transaction ID' : 'Not required for Cash'} />
                            </div>
                        </div>
                    </>
                )}
            </form>
        </Modal>
    );
};

export default AddStockAllocationModal;
