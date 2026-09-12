import { useEffect, useState } from 'react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import { softwareDropdownOptions } from '../../constants/software';
import { formatDate } from '../../utils/date';
import { showGlobalError } from '../../context/ErrorContext';

const StockAllocationEditModal = ({ allocationId, onClose, onSuccess }) => {
    const [allocation, setAllocation] = useState(null);
    const [ownerOptions, setOwnerOptions] = useState([]);
    const [form, setForm] = useState({ owner_type: '', owner_id: '', allocation_type: '', allocation_date: '', software: '', total_amount: '', amount_paid: '', payment_mode: '', notes: '' });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    useEffect(() => {
        Promise.all([
            api.get(`/stock/allocation.php?id=${allocationId}`),
            api.get('/dealers/list.php'),
            api.get('/technicians/list.php')
        ]).then(([allocationResponse, dealersResponse, techniciansResponse]) => {
            const item = allocationResponse.data.data?.allocation;
            if (!item) throw new Error('Allocation not found');
            setAllocation(item);
            setForm({ owner_type: item.owner_type, owner_id: String(item.owner_id), allocation_type: item.allocation_type || 'ET', allocation_date: item.allocation_date || '', software: item.software || '', total_amount: String(item.total_amount ?? 0), amount_paid: String(item.amount_paid ?? 0), payment_mode: item.payment_mode || '', notes: item.notes || '' });
            const dealers = (dealersResponse.data.data?.dealers || []).map((dealer) => ({ value: `dealer:${dealer.id}`, label: `Dealer — ${dealer.dealer_name}` }));
            const technicians = (techniciansResponse.data.data?.technicians || []).map((technician) => ({ value: `technician:${technician.id}`, label: `Technician — ${technician.technician_name}` }));
            setOwnerOptions([...dealers, ...technicians]);
        }).catch((loadError) => triggerError(loadError.response?.data?.message || loadError.message || 'Unable to load allocation.')).finally(() => setLoading(false));
    }, [allocationId]);

    const update = (key, value) => setForm((previous) => ({ ...previous, [key]: value }));
    const pending = Math.max(0, Number(form.total_amount || 0) - Number(form.amount_paid || 0));
    const status = Number(form.total_amount || 0) <= 0 ? 'No Payment Required' : Number(form.amount_paid || 0) <= 0 ? 'Not Paid' : pending <= 0 ? 'Paid' : 'Partially Paid';

    const save = async () => {
        if (Number(form.amount_paid || 0) > Number(form.total_amount || 0)) return triggerError('Amount paid cannot exceed total amount.');
        setSaving(true); setError('');
        try {
            const [ownerType, ownerId] = String(form.owner_id).includes(':') ? String(form.owner_id).split(':') : [form.owner_type, form.owner_id];
            const response = await api.post('/stock/allocation_update.php', { allocation_id: allocationId, owner_type: form.owner_type || ownerType, owner_id: Number(ownerId), allocation_type: form.allocation_type, allocation_date: form.allocation_date, software: form.software, total_amount: Number(form.total_amount || 0), amount_paid: Number(form.amount_paid || 0), payment_mode: form.payment_mode, notes: form.notes });
            if (!response.data.success) throw new Error(response.data.message);
            onSuccess();
        } catch (saveError) { triggerError(saveError.response?.data?.message || saveError.message || 'Unable to update allocation.'); } finally { setSaving(false); }
    };

    const ownerValue = form.owner_type && form.owner_id ? `${form.owner_type}:${form.owner_id}` : '';
    return <Modal isOpen title="Edit Stock Allocation" onClose={onClose} maxWidth="760px" footer={<><button type="button" className="btn btn-outline" onClick={onClose} disabled={saving}>Cancel</button><button type="button" className="btn btn-primary" onClick={save} disabled={loading || saving || !allocation}>{saving ? 'Saving...' : 'Save Changes'}</button></>}>
        {loading && <p>Loading allocation...</p>}
        {error && <div className="alert alert-danger">{error}</div>}
        {allocation && <div style={{ display: 'grid', gap: '1rem' }}>
            <div className="form-group"><label className="form-label">Dealer / Technician</label><SearchableDropdown options={ownerOptions} value={ownerValue} onChange={(value) => { const [ownerType, ownerId] = String(value).split(':'); update('owner_type', ownerType); update('owner_id', ownerId); }} placeholder="Select owner" /></div>
            <div className="form-group"><label className="form-label">Allocation Type</label><select className="form-control" value={form.allocation_type} onChange={(event) => update('allocation_type', event.target.value)}><option value="ET">ET</option><option value="dealer">Dealer</option><option value="technician">Technician</option></select></div>
            <div className="form-group"><label className="form-label">Allocation Date</label><input type="date" className="form-control" value={form.allocation_date} onChange={(event) => update('allocation_date', event.target.value)} /></div>
            <div><strong>{allocation.device_id ? 'Device' : 'SIM'}:</strong> {allocation.imei_no || allocation.sim_no || '-'} {allocation.device_model ? `(${allocation.device_model})` : allocation.sim_type ? `(${allocation.sim_type})` : ''}</div>
            <div className="form-group"><label className="form-label">Software</label><SearchableDropdown options={softwareDropdownOptions} value={form.software} onChange={(value) => update('software', value)} placeholder="Select software" /></div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: '0.75rem' }}><div className="form-group"><label className="form-label">Total Amount</label><input type="number" min="0" step="0.01" className="form-control" value={form.total_amount} onChange={(event) => update('total_amount', event.target.value)} /></div><div className="form-group"><label className="form-label">Amount Paid</label><input type="number" min="0" step="0.01" className="form-control" value={form.amount_paid} onChange={(event) => update('amount_paid', event.target.value)} /></div><div className="form-group"><label className="form-label">Pending Amount</label><input className="form-control" value={pending.toFixed(2)} readOnly /></div><div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={status} readOnly /></div></div>
            <div className="form-group"><label className="form-label">Payment Mode</label><select className="form-control" value={form.payment_mode} onChange={(event) => update('payment_mode', event.target.value)}><option value="">Select payment mode</option><option>Cash</option><option>UPI</option><option>Bank Transfer</option><option>Card</option><option>Other</option></select></div>
            <div className="form-group"><label className="form-label">Notes</label><textarea className="form-control" rows="3" value={form.notes} onChange={(event) => update('notes', event.target.value)} /></div>
            <small>Original allocation date: {formatDate(allocation.allocation_date)}</small>
        </div>}
    </Modal>;
};

export default StockAllocationEditModal;
