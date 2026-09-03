import { useEffect, useMemo, useState } from 'react';
import api from '../../services/api';
import Modal from '../Modal/Modal';

const money = (value) => `₹${Number(value || 0).toFixed(2)}`;
const getPaymentStatus = (total, paid) => {
    const totalAmount = Number(total || 0);
    const amountPaid = Number(paid || 0);
    if (totalAmount <= 0) return '';
    if (amountPaid >= totalAmount) return 'Paid';
    return amountPaid > 0 ? 'Partially Paid' : 'Not Paid';
};
const PaymentModal = ({ dealer, onClose, onSuccess }) => {
    const [allocations, setAllocations] = useState([]); const [selected, setSelected] = useState('');
    const [amountPaid, setAmountPaid] = useState(''); const [status, setStatus] = useState('Not Paid'); const [error, setError] = useState(''); const [saving, setSaving] = useState(false);
    useEffect(() => { if (!dealer) return; api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer.id}`).then((res) => {
        const data = res.data.data || {}; setAllocations([...(data.devices || []).map((x) => ({ ...x, label: `Device: ${x.imei_no}` })), ...(data.sims || []).map((x) => ({ ...x, label: `SIM: ${x.sim_no}` }))]);
    }).catch(() => setError('Unable to load dealer allocations.')); }, [dealer]);
    const allocation = useMemo(() => allocations.find((item) => String(item.allocation_id) === String(selected)), [allocations, selected]);
    useEffect(() => { if (allocation) { setAmountPaid(String(allocation.amount_paid || 0)); setStatus(getPaymentStatus(allocation.total_amount, allocation.amount_paid)); } }, [allocation]);
    const pending = allocation ? Math.max(0, Number(allocation.total_amount || 0) - (Number(amountPaid) || 0)) : 0;
    const expected = getPaymentStatus(allocation?.total_amount, amountPaid);
    const save = async () => { setError(''); if (!allocation) return setError('Select an allocation.'); if (Number(amountPaid) > Number(allocation.total_amount)) return setError('Amount paid cannot be greater than total price.'); if (status !== expected) return setError(`Payment status must be ${expected}.`); setSaving(true); try { const res = await api.post('/allocations/payment_update.php', { allocation_id: allocation.allocation_id, amount_paid: Number(amountPaid) || 0, payment_status: status }); if (!res.data.success) throw new Error(res.data.message); onSuccess(); } catch (err) { setError(err.response?.data?.message || err.message || 'Unable to update payment.'); } finally { setSaving(false); } };
    return <Modal isOpen={Boolean(dealer)} onClose={onClose} title="Payment" maxWidth="520px" footer={<><button className="btn btn-outline" onClick={onClose}>Cancel</button><button className="btn btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Payment'}</button></>}>
        {error && <div className="alert alert-danger">{error}</div>}<div className="form-group"><label className="form-label">Dealer / Customer</label><input className="form-control" value={dealer?.dealer_name || ''} readOnly /></div>
        <div className="form-group"><label className="form-label">Allocation</label><select className="form-control" value={selected} onChange={(e) => setSelected(e.target.value)}><option value="">Select allocation</option>{allocations.map((item) => <option key={item.allocation_id} value={item.allocation_id}>{item.label} — {money(item.total_amount)}</option>)}</select></div>
        {allocation && <><div className="form-group"><label className="form-label">Software</label><input className="form-control" value={allocation.software || dealer?.software || '-'} readOnly /></div><div className="form-group"><label className="form-label">Total Price</label><input className="form-control" value={money(allocation.total_amount)} readOnly /></div><div className="form-group"><label className="form-label">Amount Paid</label><input type="number" min="0" max={allocation.total_amount} step="0.01" className="form-control" value={amountPaid} onChange={(e) => { setAmountPaid(e.target.value); setStatus(getPaymentStatus(allocation.total_amount, e.target.value)); }} /></div><div className="form-group"><label className="form-label">Pending Payment</label><input className="form-control" value={money(pending)} readOnly /></div><div className="form-group"><label className="form-label">Payment Status</label><select className="form-control" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">No Payment Due</option><option>Paid</option><option>Partially Paid</option><option>Not Paid</option></select></div><div className="form-group"><label className="form-label">Payment Mode</label><input className="form-control" value={allocation.payment_mode || '-'} readOnly /></div></>}
    </Modal>;
};
export default PaymentModal;
