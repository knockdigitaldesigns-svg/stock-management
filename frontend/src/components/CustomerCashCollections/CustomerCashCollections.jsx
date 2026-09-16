import { useEffect, useState } from 'react';
import api from '../../services/api';
import Modal from '../Modal/Modal';
import { formatDate } from '../../utils/date';

const money = (value) => `₹${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;

const CustomerCashCollections = ({ ownerId, recipientType, onSaved }) => {
    const [collections, setCollections] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState(null);
    const [amountRemitted, setAmountRemitted] = useState('');
    const [settlementDate, setSettlementDate] = useState(new Date().toISOString().slice(0, 10));
    const [paymentMode, setPaymentMode] = useState('Cash');
    const [transactionId, setTransactionId] = useState('');
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const loadCollections = async () => {
        setLoading(true);
        try {
            const response = await api.get(`/cash_collections/list.php?recipient_type=${recipientType}&recipient_id=${ownerId}`);
            setCollections(response.data?.data?.collections || []);
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Failed to load customer cash collections.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { loadCollections(); }, [ownerId, recipientType]);

    const openSettlement = (collection) => {
        setSelected(collection);
        setAmountRemitted(String(collection.amount_remitted || 0));
        setSettlementDate(collection.settlement_date || new Date().toISOString().slice(0, 10));
        setPaymentMode(collection.settlement_payment_mode || 'Cash');
        setTransactionId(collection.settlement_transaction_id || '');
        setNotes(collection.settlement_notes || '');
        setError('');
    };

    const saveSettlement = async (event) => {
        event.preventDefault();
        setError('');
        const amount = Number(amountRemitted);
        if (!Number.isFinite(amount) || amount < 0 || amount > Number(selected.amount_collected)) {
            setError('Amount Remitted cannot exceed Overall Amount Collected.');
            return;
        }
        setSaving(true);
        try {
            await api.post('/cash_collections/settle.php', {
                collection_id: selected.id,
                amount_remitted: amount,
                settlement_date: settlementDate,
                payment_mode: paymentMode,
                transaction_id: transactionId,
                notes
            });
            setSelected(null);
            await loadCollections();
            onSaved?.();
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Failed to save settlement.');
        } finally {
            setSaving(false);
        }
    };

    return <>
        <h4>{recipientType} Customer Cash Collections</h4>
        {loading && <p>Loading customer collections...</p>}
        <div className="table-container">
            <table>
                <thead><tr><th>Customer</th><th>Mobile</th><th>Installation Date</th><th>Software</th><th>Validity</th><th>Overall Amount Collected</th><th>Amount Remitted</th><th>Pending Amount</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    {!loading && collections.length === 0 ? <tr><td colSpan="10" className="text-center">No customer cash collections.</td></tr> : collections.map((collection) => <tr key={collection.id}>
                        <td>{collection.username || '-'}</td>
                        <td>{collection.primary_mobile_no || '-'}</td>
                        <td>{formatDate(collection.installation_date)}</td>
                        <td>{collection.platform_name || '-'}</td>
                        <td>{collection.validity_months ? `${collection.validity_months} Months` : '-'}</td>
                        <td>{money(collection.amount_collected)}</td>
                        <td>{money(collection.amount_remitted)}</td>
                        <td>{money(collection.pending_amount)}</td>
                        <td><span className={`badge ${collection.settlement_status === 'Paid' ? 'badge-success' : collection.settlement_status === 'Partially Paid' ? 'badge-warning' : 'badge-danger'}`}>{collection.settlement_status}</span></td>
                        <td><button type="button" className="btn btn-outline" onClick={() => openSettlement(collection)}>Settle</button></td>
                    </tr>)}
                </tbody>
            </table>
        </div>
        <Modal isOpen={Boolean(selected)} onClose={() => setSelected(null)} title="Record Company Settlement" maxWidth="520px" footer={<><button type="button" className="btn btn-outline" onClick={() => setSelected(null)} disabled={saving}>Cancel</button><button type="submit" form="cash-settlement-form" className="btn btn-primary" disabled={saving}>{saving ? 'Saving...' : 'Save Settlement'}</button></>}>
            {selected && <form id="cash-settlement-form" onSubmit={saveSettlement}>
                <p><strong>{selected.username}</strong> collected {money(selected.amount_collected)}.</p>
                {error && <div className="alert alert-danger">{error}</div>}
                <div className="form-group"><label className="form-label">Amount Remitted / Settled</label><input className="form-control" type="number" min="0" max={selected.amount_collected} step="0.01" value={amountRemitted} onChange={(event) => setAmountRemitted(event.target.value)} required /></div>
                <div className="form-group"><label className="form-label">Settlement Date</label><input className="form-control" type="date" value={settlementDate} onChange={(event) => setSettlementDate(event.target.value)} required /></div>
                <div className="form-group"><label className="form-label">Payment Mode</label><select className="form-control" value={paymentMode} onChange={(event) => setPaymentMode(event.target.value)}><option>Cash</option><option>UPI</option><option>Card</option><option>Bank Transfer</option><option>Other</option></select></div>
                <div className="form-group"><label className="form-label">Transaction ID</label><input className="form-control" value={transactionId} onChange={(event) => setTransactionId(event.target.value)} /></div>
                <div className="form-group"><label className="form-label">Notes</label><textarea className="form-control" value={notes} onChange={(event) => setNotes(event.target.value)} rows="3" /></div>
            </form>}
        </Modal>
    </>;
};

export default CustomerCashCollections;
