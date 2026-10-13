import { useEffect, useMemo, useRef, useState } from 'react';
import api from '../../services/api';
import Modal from '../Modal/Modal';
import { PAYMENT_MODES } from '../../constants/paymentModes';
import { formatDate } from '../../utils/date';

const money = (value) => `₹${Number(value || 0).toFixed(2)}`;
const localDateToday = () => {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const makeRequestKey = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
const getPaymentStatus = (total, paid) => {
    const totalAmount = Number(total || 0);
    const amountPaid = Number(paid || 0);
    if (totalAmount <= 0) return '';
    if (amountPaid >= totalAmount) return 'Paid';
    return amountPaid > 0 ? 'Partially Paid' : 'Not Paid';
};

const PaymentModal = ({ dealer, onClose, onSuccess }) => {
    const [allocations, setAllocations] = useState([]);
    const [selectedIds, setSelectedIds] = useState([]);
    const [amounts, setAmounts] = useState({});
    const [bulkMode, setBulkMode] = useState(false);
    const [individual, setIndividual] = useState(null);
    const [totalPrice, setTotalPrice] = useState('0');
    const [amountPaid, setAmountPaid] = useState('0');
    const [paymentMode, setPaymentMode] = useState('');
    const [paymentDate, setPaymentDate] = useState(localDateToday);
    const [transactionId, setTransactionId] = useState('');
    const [remarks, setRemarks] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [retryLocked, setRetryLocked] = useState(false);
    const selectAllRef = useRef(null);
    const savingRef = useRef(false);
    const requestKeyRef = useRef('');

    useEffect(() => {
        if (!dealer) return undefined;
        let active = true;
        api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer.id}`)
            .then((response) => {
                if (!active) return;
                const data = response.data?.data || {};
                setAllocations([
                    ...(data.devices || []).map((item) => ({
                        ...item,
                        asset_type: 'Device',
                        label: `Device: ${item.imei_no || item.model_name || item.id}`
                    })),
                    ...(data.sims || []).map((item) => ({
                        ...item,
                        asset_type: 'SIM',
                        label: `SIM: ${item.sim_no || item.id}`
                    }))
                ]);
            })
            .catch((loadError) => {
                if (active) setError(loadError.response?.data?.message || 'Unable to load dealer allocations.');
            });
        return () => { active = false; };
    }, [dealer]);

    const eligibleAllocations = useMemo(
        () => allocations.filter((item) => Number(item.total_amount || 0) > Number(item.amount_paid || 0)),
        [allocations]
    );
    const selectedAllocations = useMemo(
        () => selectedIds.map((id) => allocations.find((item) => String(item.allocation_id) === String(id))).filter(Boolean),
        [allocations, selectedIds]
    );
    const selectedPending = selectedAllocations.reduce((sum, item) => sum + Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0)), 0);
    const selectedPaymentTotal = selectedAllocations.reduce((sum, item) => {
        const amount = Number(amounts[item.allocation_id] || 0);
        return sum + (Number.isFinite(amount) ? amount : 0);
    }, 0);
    const allEligibleSelected = eligibleAllocations.length > 0 && eligibleAllocations.every((item) => selectedIds.includes(String(item.allocation_id)));

    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = selectedIds.length > 0 && !allEligibleSelected;
        }
    }, [selectedIds, allEligibleSelected]);

    const toggleAllocation = (item) => {
        const id = String(item.allocation_id);
        if (selectedIds.includes(id)) {
            setSelectedIds(selectedIds.filter((selectedId) => selectedId !== id));
        } else {
            setAmounts((currentAmounts) => ({
                ...currentAmounts,
                [item.allocation_id]: Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0)).toFixed(2)
            }));
            setSelectedIds([...selectedIds, id]);
        }
        setError('');
    };

    const toggleAll = () => {
        if (allEligibleSelected) {
            setSelectedIds([]);
            return;
        }
        setSelectedIds(eligibleAllocations.map((item) => String(item.allocation_id)));
        setAmounts((current) => {
            const next = { ...current };
            eligibleAllocations.forEach((item) => {
                next[item.allocation_id] = Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0)).toFixed(2);
            });
            return next;
        });
        setError('');
    };

    const startBulkPayment = () => {
        if (selectedAllocations.length === 0) return;
        requestKeyRef.current = makeRequestKey();
        setRetryLocked(false);
        setPaymentMode('');
        setPaymentDate(localDateToday());
        setTransactionId('');
        setRemarks('');
        setBulkMode(true);
        setError('');
    };

    const openIndividual = (item) => {
        setIndividual(item);
        setTotalPrice(String(item.total_amount ?? 0));
        const outstanding = Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0));
        setAmountPaid(item.asset_type === 'SIM' ? outstanding.toFixed(2) : String(item.amount_paid ?? 0));
        setPaymentMode(item.payment_mode || '');
        setPaymentDate(localDateToday());
        setTransactionId('');
        setRemarks('');
        setError('');
    };

    const saveIndividual = async () => {
        if (!individual) return;
        setError('');
        const paidValue = Number(amountPaid);
        if (!Number.isFinite(paidValue) || paidValue < 0) return setError('Please enter a valid Amount Paid.');
        if (individual.asset_type === 'SIM') {
            const outstanding = Math.max(0, Number(individual.total_amount || 0) - Number(individual.amount_paid || 0));
            if (paidValue <= 0 || paidValue > outstanding) return setError(`Payment must be greater than zero and cannot exceed ${money(outstanding)}.`);
            if (!paymentMode || !PAYMENT_MODES.includes(paymentMode)) return setError('Please select a valid Payment Mode.');
            if (paymentMode !== 'Cash' && !/^[0-9]{6}$/.test(transactionId)) return setError('Enter a unique 6-digit Transaction ID.');
            if (!paymentDate) return setError('Please select a Payment Date.');
        } else {
            if (!Number.isFinite(Number(totalPrice)) || Number(totalPrice) < 0) return setError('Please enter a valid Total Price.');
            if (paidValue > Number(totalPrice)) return setError('Amount Paid cannot exceed Total Amount.');
            if (paymentMode && !PAYMENT_MODES.includes(paymentMode)) return setError('Please select a valid Payment Mode.');
            if (paymentMode && paymentMode !== 'Cash' && !/^[0-9]{6}$/.test(transactionId)) return setError('Enter a unique 6-digit Transaction ID.');
        }
        if (savingRef.current) return;
        savingRef.current = true;
        setSaving(true);
        try {
            if (individual.asset_type === 'SIM') {
                const response = await api.post('/dealers/sim_activation_payment_create.php', {
                    allocation_id: individual.allocation_id,
                    idempotency_key: makeRequestKey(),
                    amount_paid: paidValue,
                    payment_mode: paymentMode,
                    transaction_id: paymentMode === 'Cash' ? '' : transactionId,
                    payment_date: paymentDate,
                    remarks
                });
                if (!response.data.success) throw new Error(response.data.message || 'Unable to record payment.');
            } else {
                const expected = getPaymentStatus(totalPrice, amountPaid);
                const response = await api.post('/allocations/payment_update.php', {
                    allocation_id: individual.allocation_id,
                    total_amount: Number(totalPrice),
                    amount_paid: paidValue,
                    payment_status: expected,
                    payment_mode: paymentMode || null,
                    transaction_id: paymentMode === 'Cash' ? null : transactionId || null
                });
                if (!response.data.success) throw new Error(response.data.message || 'Unable to update payment.');
            }
            onSuccess();
        } catch (saveError) {
            setError(saveError.response?.data?.message || saveError.message || 'Unable to save payment.');
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    };

    const saveBulk = async () => {
        if (savingRef.current) return;
        setError('');
        if (!selectedAllocations.length) return setError('Select at least one eligible allocation.');
        if (!paymentMode || !PAYMENT_MODES.includes(paymentMode)) return setError('Please select a valid Payment Mode.');
        if (!paymentDate) return setError('Please select a Payment Date.');
        if (paymentMode !== 'Cash' && !/^[0-9]{6}$/.test(transactionId)) {
            return setError('Enter a 6-digit Transaction ID.');
        }
        for (const item of selectedAllocations) {
            const outstanding = Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0));
            const amount = Number(amounts[item.allocation_id]);
            if (!Number.isFinite(amount) || amount <= 0 || amount > outstanding) {
                return setError(`${item.label}: payment must be greater than zero and cannot exceed its ${money(outstanding)} outstanding balance.`);
            }
        }
        if (savingRef.current) return;
        if (!requestKeyRef.current) requestKeyRef.current = makeRequestKey();
        savingRef.current = true;
        setSaving(true);
        try {
            const response = await api.post('/allocations/dealer_bulk_payment.php', {
                dealer_id: dealer.id,
                request_key: requestKeyRef.current,
                payment_mode: paymentMode,
                payment_date: paymentDate,
                transaction_id: paymentMode === 'Cash' ? '' : transactionId,
                remarks,
                allocations: selectedAllocations.map((item) => ({
                    allocation_id: item.allocation_id,
                    amount_to_pay: Number(amounts[item.allocation_id])
                }))
            });
            if (!response.data.success) {
                requestKeyRef.current = '';
                setError(response.data.message || 'Unable to save bulk payments.');
                return;
            }
            onSuccess();
        } catch (saveError) {
            setError(saveError.response?.data?.message || saveError.message || 'Unable to save bulk payments.');
            if (saveError.response) {
                requestKeyRef.current = '';
            } else {
                setRetryLocked(true);
            }
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    };

    const individualPending = individual
        ? Math.max(0, Number(totalPrice || 0) - (Number(amountPaid) || 0))
        : 0;
    const individualExpected = getPaymentStatus(totalPrice, amountPaid);
    const busy = saving || retryLocked;

    return (
        <Modal
            isOpen={Boolean(dealer)}
            onClose={busy ? undefined : onClose}
            title={bulkMode ? 'Close Allocation Payments' : individual ? 'Individual Payment' : 'Dealer Payments'}
            maxWidth="1100px"
            footer={bulkMode ? (
                <>
                    <button className="btn btn-outline" type="button" disabled={busy} onClick={() => { setBulkMode(false); setError(''); }}>Back to Allocations</button>
                    <button className="btn btn-primary" type="button" onClick={saveBulk} disabled={busy}>
                        {saving ? 'Saving Payments...' : retryLocked ? 'Retry Same Payment' : 'Save Payment'}
                    </button>
                </>
            ) : individual ? (
                <>
                    <button className="btn btn-outline" type="button" disabled={busy} onClick={() => { setIndividual(null); setError(''); }}>Back to Allocations</button>
                    <button className="btn btn-primary" type="button" disabled={busy} onClick={saveIndividual}>{saving ? 'Saving...' : 'Save Payment'}</button>
                </>
            ) : (
                <button className="btn btn-outline" type="button" onClick={onClose}>Close</button>
            )}
        >
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            <div className="form-group">
                <label className="form-label">Dealer</label>
                <input className="form-control" value={dealer?.dealer_name || ''} readOnly />
            </div>

            {bulkMode ? (
                <>
                    <h4>Selected Allocations ({selectedAllocations.length})</h4>
                    <p>Total outstanding: <strong>{money(selectedPending)}</strong> · Payment to apply: <strong>{money(selectedPaymentTotal)}</strong></p>
                    <div className="table-container">
                        <table>
                            <thead><tr><th>Allocation</th><th>Original Total</th><th>Previously Paid</th><th>Outstanding</th><th>Amount to Pay</th></tr></thead>
                            <tbody>
                                {selectedAllocations.map((item) => {
                                    const outstanding = Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0));
                                    return (
                                        <tr key={item.allocation_id}>
                                            <td>{item.label} <small>(#{item.allocation_id})</small></td>
                                            <td>{money(item.total_amount)}</td>
                                            <td>{money(item.amount_paid)}</td>
                                            <td>{money(outstanding)}</td>
                                            <td><input className="form-control" type="number" min="0.01" max={outstanding} step="0.01" value={amounts[item.allocation_id] ?? ''} disabled={busy} onChange={(event) => setAmounts((current) => ({ ...current, [item.allocation_id]: event.target.value }))} aria-label={`Amount to pay for allocation ${item.allocation_id}`} /></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginTop: 16 }}>
                        <div className="form-group"><label className="form-label">Payment Mode *</label><select className="form-control" value={paymentMode} disabled={busy} onChange={(event) => setPaymentMode(event.target.value)}><option value="">Select payment mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></div>
                        <div className="form-group"><label className="form-label">Payment Date *</label><input className="form-control" type="date" value={paymentDate} disabled={busy} onChange={(event) => setPaymentDate(event.target.value)} /></div>
                        <div className="form-group"><label className="form-label">Transaction ID{paymentMode && paymentMode !== 'Cash' ? ' *' : ''}</label><input className="form-control" type="text" inputMode="numeric" maxLength={6} value={transactionId} disabled={busy || paymentMode === 'Cash'} onChange={(event) => setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6))} placeholder={paymentMode === 'Cash' ? 'Not required' : '6 digits'} /></div>
                        <div className="form-group" style={{ gridColumn: '1 / -1' }}><label className="form-label">Remarks</label><textarea className="form-control" rows="2" value={remarks} disabled={busy} onChange={(event) => setRemarks(event.target.value)} /></div>
                    </div>
                    {retryLocked && <p role="status">The result is not confirmed. Retry this same payment without changing its details to avoid duplicate payment.</p>}
                </>
            ) : individual ? (
                individual.asset_type === 'SIM' ? (
                    <>
                        <p>{individual.label} · Allocation #{individual.allocation_id}</p>
                        <div className="form-group"><label className="form-label">Original Total</label><input className="form-control" value={money(individual.total_amount)} readOnly /></div>
                        <div className="form-group"><label className="form-label">Previously Paid</label><input className="form-control" value={money(individual.amount_paid)} readOnly /></div>
                        <div className="form-group"><label className="form-label">Outstanding</label><input className="form-control" value={money(Math.max(0, Number(individual.total_amount || 0) - Number(individual.amount_paid || 0)))} readOnly /></div>
                        <div className="form-group"><label className="form-label">Amount to Pay *</label><input className="form-control" type="number" min="0.01" max={Math.max(0, Number(individual.total_amount || 0) - Number(individual.amount_paid || 0))} step="0.01" value={amountPaid} disabled={busy} onChange={(event) => setAmountPaid(event.target.value)} /></div>
                        <div className="form-group"><label className="form-label">Payment Mode *</label><select className="form-control" value={paymentMode} disabled={busy} onChange={(event) => setPaymentMode(event.target.value)}><option value="">Select payment mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></div>
                        <div className="form-group"><label className="form-label">Transaction ID{paymentMode !== 'Cash' ? ' *' : ''}</label><input className="form-control" type="text" inputMode="numeric" maxLength={6} value={transactionId} disabled={busy || paymentMode === 'Cash'} onChange={(event) => setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6))} /></div>
                        <div className="form-group"><label className="form-label">Payment Date *</label><input className="form-control" type="date" value={paymentDate} disabled={busy} onChange={(event) => setPaymentDate(event.target.value)} /></div>
                        <div className="form-group"><label className="form-label">Remarks</label><textarea className="form-control" rows="2" value={remarks} disabled={busy} onChange={(event) => setRemarks(event.target.value)} /></div>
                    </>
                ) : (
                    <>
                        <p>{individual.label} · Allocation #{individual.allocation_id}</p>
                        <div className="form-group"><label className="form-label">Software</label><input className="form-control" value={individual.software || dealer?.software || '-'} readOnly /></div>
                        <div className="form-group"><label className="form-label">Total Price</label><input type="number" min="0" step="0.01" className="form-control" value={totalPrice} disabled={busy} onChange={(event) => setTotalPrice(event.target.value)} /></div>
                        <div className="form-group"><label className="form-label">Amount Paid</label><input type="number" min="0" max={totalPrice} step="0.01" className="form-control" value={amountPaid} disabled={busy} onChange={(event) => setAmountPaid(event.target.value)} /></div>
                        <div className="form-group"><label className="form-label">Pending Payment</label><input className="form-control" value={money(individualPending)} readOnly /></div>
                        <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={individualExpected || 'No Payment Required'} readOnly /></div>
                        <div className="form-group"><label className="form-label">Payment Mode</label><select className="form-control" value={paymentMode} disabled={busy} onChange={(event) => setPaymentMode(event.target.value)}><option value="">Select payment mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></div>
                        <div className="form-group"><label className="form-label">Transaction ID{paymentMode && paymentMode !== 'Cash' ? ' *' : ''}</label><input className="form-control" type="text" inputMode="numeric" maxLength={6} value={transactionId} disabled={busy || paymentMode === 'Cash'} onChange={(event) => setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6))} /></div>
                    </>
                )
            ) : (
                <>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, margin: '12px 0' }}>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input ref={selectAllRef} type="checkbox" checked={allEligibleSelected} onChange={toggleAll} disabled={!eligibleAllocations.length} />
                            Select All Eligible Allocations
                        </label>
                        <strong>Selected Allocations: {selectedIds.length}</strong>
                    </div>
                    <p>Select All applies to every eligible allocation shown below. This list is not paginated.</p>
                    <div className="table-container">
                        <table>
                            <thead><tr><th>Select</th><th>Allocation ID</th><th>Allocation Date</th><th>Asset Details</th><th>Total Amount</th><th>Amount Paid</th><th>Amount Pending</th><th>Payment Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                {allocations.map((item) => {
                                    const pending = Math.max(0, Number(item.total_amount || 0) - Number(item.amount_paid || 0));
                                    const eligible = pending > 0;
                                    return (
                                        <tr key={item.allocation_id}>
                                            <td><input type="checkbox" checked={selectedIds.includes(String(item.allocation_id))} disabled={!eligible} onChange={() => toggleAllocation(item)} aria-label={`Select allocation ${item.allocation_id}`} /></td>
                                            <td>#{item.allocation_id}</td>
                                            <td>{formatDate(item.allocation_date)}</td>
                                            <td>{item.label}</td>
                                            <td>{money(item.total_amount)}</td>
                                            <td>{money(item.amount_paid)}</td>
                                            <td>{money(pending)}</td>
                                            <td>{item.payment_status || (pending <= 0 ? 'Paid' : 'Not Paid')}</td>
                                            <td><button className="btn btn-outline" type="button" onClick={() => openIndividual(item)}>Individual Payment</button></td>
                                        </tr>
                                    );
                                })}
                                {!allocations.length && <tr><td colSpan="9" className="text-center">No allocations found for this dealer.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
                        <button className="btn btn-primary" type="button" disabled={!selectedIds.length} onClick={startBulkPayment}>Close Payment</button>
                    </div>
                </>
            )}
        </Modal>
    );
};

export default PaymentModal;
