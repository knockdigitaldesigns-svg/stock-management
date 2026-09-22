import { useEffect, useMemo, useState } from 'react';
import api from '../../services/api';
import Modal from '../Modal/Modal';
import { PAYMENT_MODES } from '../../constants/paymentModes';

const money = (value) => `₹${Number(value || 0).toFixed(2)}`;
const getPaymentStatus = (total, paid) => {
    const totalAmount = Number(total || 0);
    const amountPaid = Number(paid || 0);
    if (totalAmount <= 0) return '';
    if (amountPaid >= totalAmount) return 'Paid';
    return amountPaid > 0 ? 'Partially Paid' : 'Not Paid';
};
const paymentModeOptions = PAYMENT_MODES;
const PaymentModal = ({ dealer, onClose, onSuccess }) => {
    const [allocations, setAllocations] = useState([]); const [selected, setSelected] = useState('');
    const [totalPrice, setTotalPrice] = useState('0'); const [amountPaid, setAmountPaid] = useState('0'); const [paymentMode, setPaymentMode] = useState(''); const [transactionId, setTransactionId] = useState(''); const [status, setStatus] = useState('Not Paid'); const [error, setError] = useState(''); const [saving, setSaving] = useState(false);
    useEffect(() => { if (!dealer) return; api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer.id}`).then((res) => {
        const data = res.data.data || {}; setAllocations([...(data.devices || []).map((x) => ({ ...x, label: `Device: ${x.imei_no}` })), ...(data.sims || []).map((x) => ({ ...x, label: `SIM: ${x.sim_no}` }))]);
    }).catch(() => setError('Unable to load dealer allocations.')); }, [dealer]);
    const allocation = useMemo(() => allocations.find((item) => String(item.allocation_id) === String(selected)), [allocations, selected]);
    useEffect(() => { if (allocation) { setTotalPrice(String(allocation.total_amount ?? 0)); setAmountPaid(String(allocation.amount_paid ?? 0)); setPaymentMode(allocation.payment_mode || ''); setTransactionId(allocation.transaction_id || ''); setStatus(getPaymentStatus(allocation.total_amount, allocation.amount_paid)); } }, [allocation]);
    const pending = allocation ? Math.max(0, Number(totalPrice || 0) - (Number(amountPaid) || 0)) : 0;
    const expected = getPaymentStatus(totalPrice, amountPaid);
    const save = async () => { setError(''); if (!allocation) return setError('Select an allocation.'); if (totalPrice === '' || totalPrice === null || totalPrice === undefined || !Number.isFinite(Number(totalPrice))) return setError('Please enter Total Price.'); if (Number(totalPrice) < 0) return setError('Total Price cannot be negative.'); if (amountPaid === '' || amountPaid === null || amountPaid === undefined) return setError('Please enter Amount Paid.'); if (Number(amountPaid) < 0) return setError('Amount Paid cannot be negative.'); if (Number(amountPaid) > Number(totalPrice)) return setError('Amount Paid cannot exceed Total Amount.'); if (paymentMode && !paymentModeOptions.includes(paymentMode)) return setError('Please select a valid Payment Mode.'); if (paymentMode && paymentMode !== 'Cash' && !transactionId.trim()) return setError('Please enter Transaction ID.'); setSaving(true); try { const res = await api.post('/allocations/payment_update.php', { allocation_id: allocation.allocation_id, total_amount: Number(totalPrice) || 0, amount_paid: Number(amountPaid) || 0, payment_status: expected, payment_mode: paymentMode || null, transaction_id: paymentMode === 'Cash' ? null : transactionId.trim() || null }); if (!res.data.success) throw new Error(res.data.message); onSuccess(); } catch (err) { setError(err.response?.data?.message || err.message || 'Unable to update payment.'); } finally { setSaving(false); } };
    return <Modal isOpen={Boolean(dealer)} onClose={onClose} title="Payment" maxWidth="520px" footer={<><button className="btn btn-outline" onClick={onClose}>Cancel</button><button className="btn btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving...' : 'Save Payment'}</button></>}>
        {error && <div className="alert alert-danger">{error}</div>}<div className="form-group"><label className="form-label">Dealer / Customer</label><input className="form-control" value={dealer?.dealer_name || ''} readOnly /></div>
        <div className="form-group"><label className="form-label">Allocation</label><select className="form-control" value={selected} onChange={(e) => setSelected(e.target.value)}><option value="">Select allocation</option>{allocations.map((item) => <option key={item.allocation_id} value={item.allocation_id}>{item.label} — {money(item.total_amount)}</option>)}</select></div>
        {allocation && <><div className="form-group"><label className="form-label">Software</label><input className="form-control" value={allocation.software || dealer?.software || '-'} readOnly /></div><div className="form-group"><label className="form-label">Total Price</label><input type="number" min="0" step="0.01" className="form-control" value={totalPrice} onChange={(e) => { setTotalPrice(e.target.value); setStatus(getPaymentStatus(e.target.value, amountPaid)); }} /></div><div className="form-group"><label className="form-label">Amount Paid</label><input type="number" min="0" max={totalPrice} step="0.01" className="form-control" value={amountPaid} onChange={(e) => { setAmountPaid(e.target.value); setStatus(getPaymentStatus(totalPrice, e.target.value)); }} /></div><div className="form-group"><label className="form-label">Pending Payment</label><input className="form-control" value={money(pending)} readOnly /></div><div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={expected || 'No Payment Required'} readOnly /></div><div className="form-group"><label className="form-label">Payment Mode</label><select className="form-control" value={paymentMode} onChange={(e) => setPaymentMode(e.target.value)}><option value="">Select payment mode</option>{paymentModeOptions.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></div><div className="form-group"><label className="form-label">Transaction ID{paymentMode && paymentMode !== 'Cash' ? ' *' : ''}</label><input className="form-control" value={transactionId} onChange={(e) => setTransactionId(e.target.value)} placeholder={paymentMode === 'Cash' ? 'Not required for Cash' : 'Enter transaction ID'} /></div></>}
    </Modal>;
};
export default PaymentModal;
