import { useEffect, useMemo, useRef, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import { PAYMENT_MODES } from '../../../constants/paymentModes';

const localToday = () => {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
};

const newRequestKey = () => (
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`
);

const currency = (value) => `₹${Number(value || 0).toFixed(2)}`;
const outstandingFor = (allocation) => Math.max(
    0,
    Number(allocation.total_pending_amount ?? allocation.pending_amount ?? 0)
);

const DealerSimActivationBulkPaymentModal = ({ selectedIds, onClose, onSuccess }) => {
    const [rows, setRows] = useState([]);
    const [amounts, setAmounts] = useState({});
    const [paymentMode, setPaymentMode] = useState('');
    const [transactionId, setTransactionId] = useState('');
    const [paymentDate, setPaymentDate] = useState(localToday);
    const [remarks, setRemarks] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [retryLocked, setRetryLocked] = useState(false);
    const [selectionStale, setSelectionStale] = useState(false);
    const [error, setError] = useState('');
    const requestKeyRef = useRef(newRequestKey());
    const savingRef = useRef(false);

    useEffect(() => {
        let active = true;
        Promise.all(selectedIds.map(async (allocationId) => {
            const response = await api.get(`/dealers/sim_activation_get.php?allocation_id=${encodeURIComponent(allocationId)}`);
            if (!response.data.success || !response.data.data?.allocation) {
                throw new Error(response.data.message || `Unable to revalidate SIM activation #${allocationId}.`);
            }
            return response.data.data.allocation;
        })).then((allocations) => {
            if (!active) return;
            const eligible = [];
            const staleSims = [];
            allocations.forEach((allocation) => {
                const outstanding = outstandingFor(allocation);
                if (outstanding <= 0) {
                    staleSims.push(allocation.sim_no || `#${allocation.allocation_id}`);
                    return;
                }
                eligible.push(allocation);
            });
            setRows(eligible);
            setAmounts(Object.fromEntries(eligible.map((allocation) => [
                allocation.allocation_id,
                outstandingFor(allocation).toFixed(2)
            ])));
            if (staleSims.length > 0) {
                setSelectionStale(true);
                setError(`Selection is out of date: ${staleSims.join(', ')} no longer have an outstanding balance. Close this modal and refresh the list.`);
            } else if (eligible.length === 0) {
                setError('The selected SIM activations no longer have an outstanding balance.');
            }
        }).catch((loadError) => {
            if (active) setError(loadError.response?.data?.message || loadError.message || 'Unable to revalidate the selected SIMs.');
        }).finally(() => {
            if (active) setLoading(false);
        });
        return () => {
            active = false;
        };
    }, [selectedIds]);

    const totalOutstanding = rows.reduce((sum, row) => sum + outstandingFor(row), 0);
    const totalToPay = useMemo(() => rows.reduce((sum, row) => {
        const amount = Number(amounts[row.allocation_id] || 0);
        return sum + (Number.isFinite(amount) ? amount : 0);
    }, 0), [amounts, rows]);
    const busy = saving || retryLocked;

    const savePayment = async () => {
        if (savingRef.current || saving) return;
        setError('');
        if (selectionStale) return setError('Close this modal and refresh the SIM list before saving.');
        if (!rows.length) return setError('Select at least one eligible SIM activation.');
        if (!paymentMode || !PAYMENT_MODES.includes(paymentMode)) return setError('Please select a valid Payment Mode.');
        if (!paymentDate) return setError('Please select a Payment Date.');
        if (paymentMode !== 'Cash' && !/^[0-9]{6}$/.test(transactionId)) {
            return setError('Enter a 6-digit Transaction ID.');
        }
        for (const row of rows) {
            const outstanding = outstandingFor(row);
            const amount = Number(amounts[row.allocation_id]);
            if (!Number.isFinite(amount) || amount <= 0 || amount > outstanding) {
                return setError(`${row.sim_no}: payment must be greater than zero and cannot exceed its ${currency(outstanding)} outstanding balance.`);
            }
        }

        savingRef.current = true;
        setSaving(true);
        try {
            const response = await api.post('/dealers/sim_activation_bulk_payment.php', {
                request_key: requestKeyRef.current,
                payment_mode: paymentMode,
                transaction_id: paymentMode === 'Cash' ? '' : transactionId,
                payment_date: paymentDate,
                remarks,
                total_amount_to_pay: Number(totalToPay.toFixed(2)),
                allocations: rows.map((row) => ({
                    allocation_id: Number(row.allocation_id),
                    amount_paid: Number(amounts[row.allocation_id])
                }))
            });
            if (!response.data.success) {
                requestKeyRef.current = newRequestKey();
                setError(response.data.message || 'Unable to save bulk SIM payments.');
                return;
            }
            onSuccess();
        } catch (saveError) {
            setError(saveError.response?.data?.message || saveError.message || 'Unable to save bulk SIM payments.');
            if (saveError.response) {
                requestKeyRef.current = newRequestKey();
            } else {
                setRetryLocked(true);
            }
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen
            onClose={busy ? undefined : onClose}
            title="Close SIM Payments"
            maxWidth="1100px"
            className="dealer-sim-bulk-payment-modal"
            footer={(
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={busy}>Cancel</button>
                    <button type="button" className="btn btn-primary" onClick={savePayment} disabled={saving || loading || selectionStale || !rows.length}>
                        {saving ? 'Saving Payments...' : retryLocked ? 'Retry Same Payment' : 'Save Payment'}
                    </button>
                </>
            )}
        >
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            <div className="dealer-sim-bulk-payment-summary">
                <span>Selected SIMs: <strong>{rows.length}</strong></span>
                <span>Total outstanding: <strong>{currency(totalOutstanding)}</strong></span>
                <span>Total amount to pay: <strong>{currency(totalToPay)}</strong></span>
            </div>
            <div className="table-container dealer-sim-bulk-payment-table">
                <table>
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Dealer Name</th>
                            <th>SIM Number</th>
                            <th>Original Amount</th>
                            <th>Previously Paid</th>
                            <th>Outstanding</th>
                            <th>Amount to Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="7" className="text-center">Revalidating selected SIMs...</td></tr>
                        ) : rows.map((row, index) => (
                            <tr key={row.allocation_id}>
                                <td>{index + 1}</td>
                                <td>{row.dealer_name || '-'}</td>
                                <td>{row.sim_no || '-'}</td>
                                <td>{currency(row.total_payment_amount ?? row.total_amount)}</td>
                                <td>{currency(row.total_paid_amount ?? row.amount_paid)}</td>
                                <td>{currency(outstandingFor(row))}</td>
                                <td>
                                    <input
                                        className="form-control"
                                        type="number"
                                        min="0.01"
                                        max={outstandingFor(row)}
                                        step="0.01"
                                        value={amounts[row.allocation_id] ?? ''}
                                        disabled={busy || selectionStale}
                                        onChange={(event) => setAmounts((current) => ({
                                            ...current,
                                            [row.allocation_id]: event.target.value
                                        }))}
                                        aria-label={`Amount to pay for SIM ${row.sim_no}`}
                                    />
                                </td>
                            </tr>
                        ))}
                        {!loading && rows.length === 0 && <tr><td colSpan="7" className="text-center">No eligible SIMs selected.</td></tr>}
                    </tbody>
                    {!loading && rows.length > 0 && (
                        <tfoot>
                            <tr>
                                <th colSpan="5">Total</th>
                                <th>{currency(totalOutstanding)}</th>
                                <th>{currency(totalToPay)}</th>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
            <div className="dealer-sim-bulk-payment-fields">
                <div className="form-group">
                    <label className="form-label">Payment Mode *</label>
                    <select className="form-control" value={paymentMode} disabled={busy} onChange={(event) => setPaymentMode(event.target.value)}>
                        <option value="">Select payment mode</option>
                        {PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}
                    </select>
                </div>
                <div className="form-group">
                    <label className="form-label">Transaction ID{paymentMode && paymentMode !== 'Cash' ? ' *' : ''}</label>
                    <input
                        className="form-control"
                        type="text"
                        inputMode="numeric"
                        maxLength={6}
                        value={transactionId}
                        disabled={busy || paymentMode === 'Cash'}
                        onChange={(event) => setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6))}
                        placeholder={paymentMode === 'Cash' ? 'Not required' : '6 digits'}
                    />
                </div>
                <div className="form-group">
                    <label className="form-label">Payment Date *</label>
                    <input className="form-control" type="date" value={paymentDate} disabled={busy} onChange={(event) => setPaymentDate(event.target.value)} />
                </div>
                <div className="form-group dealer-sim-bulk-payment-remarks">
                    <label className="form-label">Remarks</label>
                    <textarea className="form-control" rows="2" value={remarks} disabled={busy} onChange={(event) => setRemarks(event.target.value)} />
                </div>
            </div>
            {retryLocked && <p role="status">The result is not confirmed. Retry the same payment without changing its details to prevent duplicate records.</p>}
        </Modal>
    );
};

export default DealerSimActivationBulkPaymentModal;
