import { useEffect, useState } from 'react';
import api from '../../services/api';
import Modal from '../Modal/Modal';
import { formatDate } from '../../utils/date';
import { showGlobalError } from '../../context/ErrorContext';

const money = (value) => `₹${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const dateTime = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const CustomerCashCollections = ({ ownerId, recipientType, onSaved }) => {
    const [collections, setCollections] = useState([]);
    const [summary, setSummary] = useState({ total_collected: 0, total_settled: 0, total_pending: 0 });
    const [settlementHistory, setSettlementHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedSingle, setSelectedSingle] = useState(null);

    // Overall FIFO Settlement State
    const [settlementAmountInput, setSettlementAmountInput] = useState('');
    const [settlementDate, setSettlementDate] = useState(new Date().toISOString().slice(0, 10));
    const [paymentMode, setPaymentMode] = useState('Cash');
    const [transactionId, setTransactionId] = useState('');
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [successMsg, setSuccessMsg] = useState('');

    // Single Row Settlement State
    const [singleRemitted, setSingleRemitted] = useState('');
    const [singleDate, setSingleDate] = useState(new Date().toISOString().slice(0, 10));
    const [singleMode, setSingleMode] = useState('Cash');
    const [singleTxId, setSingleTxId] = useState('');
    const [singleNotes, setSingleNotes] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const loadCollections = async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get(`/cash_collections/list.php?recipient_type=${recipientType}&recipient_id=${ownerId}`);
            const data = response.data?.data || {};
            setCollections(data.collections || []);
            setSummary(data.summary || { total_collected: 0, total_settled: 0, total_pending: 0 });
            setSettlementHistory(data.settlement_history || []);
        } catch (requestError) {
            triggerError(requestError.response?.data?.message || 'Failed to load customer cash collections.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadCollections();
    }, [ownerId, recipientType]);

    // Calculate totals
    const totalCollected = Number(summary.total_collected || 0);
    const totalRemitted = Number(summary.total_settled || 0);
    const totalPending = Number(summary.total_pending || 0);

    const enteredSettlement = Number(settlementAmountInput || 0);
    const remainingAfterSettlement = Math.max(0, totalPending - enteredSettlement);

    const handleOverallFifoSettle = async (event) => {
        event.preventDefault();
        setError('');
        setSuccessMsg('');

        if (totalPending <= 0) {
            return triggerError('There is no outstanding pending amount to settle.');
        }

        if (!settlementAmountInput || isNaN(enteredSettlement) || enteredSettlement <= 0) {
            return triggerError('Settlement Amount must be greater than 0.');
        }

        if (enteredSettlement > totalPending) {
            return triggerError(`Settlement Amount (${money(enteredSettlement)}) cannot exceed Total Outstanding Amount (${money(totalPending)}).`);
        }

        if (paymentMode !== 'Cash' && !transactionId.trim()) {
            return triggerError('Transaction ID is required for non-cash payment modes.');
        }

        setSaving(true);
        try {
            const response = await api.post('/cash_collections/settle.php', {
                recipient_type: recipientType,
                recipient_id: ownerId,
                settlement_amount: enteredSettlement,
                settlement_date: settlementDate,
                payment_mode: paymentMode,
                transaction_id: transactionId.trim(),
                notes: notes.trim()
            });

            if (response.data.success) {
                setSuccessMsg(response.data.message || 'Settlement processed successfully.');
                setSettlementAmountInput('');
                setTransactionId('');
                setNotes('');
                await loadCollections();
                onSaved?.();
            } else {
                triggerError(response.data.message || 'Failed to process settlement.');
            }
        } catch (requestError) {
            triggerError(requestError.response?.data?.message || 'Failed to process settlement.');
        } finally {
            setSaving(false);
        }
    };

    const openSingleSettlement = (collection) => {
        setSelectedSingle(collection);
        setSingleRemitted('');
        setSingleDate(collection.settlement_date || new Date().toISOString().slice(0, 10));
        setSingleMode(collection.settlement_payment_mode || 'Cash');
        setSingleTxId(collection.settlement_transaction_id || '');
        setSingleNotes(collection.settlement_notes || '');
        setError('');
    };

    const saveSingleSettlement = async (event) => {
        event.preventDefault();
        setError('');
        const amount = Number(singleRemitted);
        if (isNaN(amount) || amount <= 0 || amount > Number(selectedSingle.pending_amount)) {
            return triggerError('Settlement Amount must be greater than 0 and cannot exceed the current pending amount.');
        }

        if (singleMode !== 'Cash' && !singleTxId.trim()) {
            return triggerError('Transaction ID is required for non-cash payment mode.');
        }

        setSaving(true);
        try {
            await api.post('/cash_collections/settle.php', {
                collection_id: selectedSingle.id,
                settlement_amount: amount,
                settlement_date: singleDate,
                payment_mode: singleMode,
                transaction_id: singleTxId.trim(),
                notes: singleNotes.trim()
            });
            setSelectedSingle(null);
            await loadCollections();
            onSaved?.();
        } catch (requestError) {
            triggerError(requestError.response?.data?.message || 'Failed to save settlement.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="cash-collections-container">
            <h4 style={{ marginBottom: '1rem' }}>{recipientType} Customer Cash Collections</h4>

            {error && <div className="alert alert-danger" style={{ marginBottom: '1rem' }}>{error}</div>}
            {successMsg && <div className="alert badge-success" style={{ marginBottom: '1rem', padding: '0.75rem 1rem' }}>{successMsg}</div>}

            {/* OVERALL SETTLEMENT SECTION */}
            <div className="card" style={{ padding: '1.25rem', marginBottom: '1.5rem', backgroundColor: '#f8fafc', border: '1px solid var(--border-color, #cbd5e1)' }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '1rem', marginBottom: '1.25rem', borderBottom: '1fr solid #e2e8f0', paddingBottom: '1rem' }}>
                    <div>
                        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary, #64748b)', display: 'block', fontWeight: '500' }}>Total Collected</span>
                        <span style={{ fontSize: '1.1rem', fontWeight: '600', color: 'var(--text-primary)' }}>{money(totalCollected)}</span>
                    </div>
                    <div>
                        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary, #64748b)', display: 'block', fontWeight: '500' }}>Total Settled</span>
                        <span style={{ fontSize: '1.1rem', fontWeight: '600', color: '#16a34a' }}>{money(totalRemitted)}</span>
                    </div>
                    <div>
                            <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary, #64748b)', display: 'block', fontWeight: '500' }}>Current Pending</span>
                        <span style={{ fontSize: '1.2rem', fontWeight: '700', color: totalPending > 0 ? '#dc2626' : '#16a34a' }}>{money(totalPending)}</span>
                    </div>
                    {enteredSettlement > 0 && (
                        <div>
                            <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary, #64748b)', display: 'block', fontWeight: '500' }}>Remaining After Settlement</span>
                            <span style={{ fontSize: '1.1rem', fontWeight: '600', color: '#0284c7' }}>{money(remainingAfterSettlement)}</span>
                        </div>
                    )}
                </div>

                <form onSubmit={handleOverallFifoSettle}>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '1rem', alignItems: 'end' }}>
                        <div className="form-group" style={{ marginBottom: 0 }}>
                            <label className="form-label" style={{ fontWeight: '600' }}>Enter Settlement Amount *</label>
                            <input
                                type="number"
                                min="0.01"
                                max={totalPending || 0}
                                step="any"
                                className="form-control"
                                placeholder="e.g. 3000"
                                value={settlementAmountInput}
                                onChange={(e) => setSettlementAmountInput(e.target.value)}
                                disabled={saving || totalPending <= 0}
                                required
                            />
                        </div>

                        <div className="form-group" style={{ marginBottom: 0 }}>
                            <label className="form-label">Settlement Date *</label>
                            <input
                                type="date"
                                className="form-control"
                                value={settlementDate}
                                onChange={(e) => setSettlementDate(e.target.value)}
                                disabled={saving || totalPending <= 0}
                                required
                            />
                        </div>

                        <div className="form-group" style={{ marginBottom: 0 }}>
                            <label className="form-label">Payment Mode *</label>
                            <select
                                className="form-control"
                                value={paymentMode}
                                onChange={(e) => setPaymentMode(e.target.value)}
                                disabled={saving || totalPending <= 0}
                            >
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Card">Card</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div className="form-group" style={{ marginBottom: 0 }}>
                            <label className="form-label">Transaction ID {paymentMode !== 'Cash' ? '*' : ''}</label>
                            <input
                                type="text"
                                className="form-control"
                                placeholder={paymentMode !== 'Cash' ? 'Enter TXN ID' : 'Optional for Cash'}
                                value={transactionId}
                                onChange={(e) => setTransactionId(e.target.value)}
                                disabled={saving || totalPending <= 0}
                            />
                        </div>

                        <div className="form-group" style={{ marginBottom: 0 }}>
                            <button
                                type="submit"
                                className="btn btn-primary"
                                style={{ width: '100%', height: '38px', whiteSpace: 'nowrap' }}
                                disabled={saving || totalPending <= 0 || !settlementAmountInput || enteredSettlement <= 0 || enteredSettlement > totalPending}
                            >
                                {saving ? 'Settling...' : 'Settle Amount'}
                            </button>
                        </div>
                    </div>

                    <div className="form-group" style={{ marginTop: '0.75rem', marginBottom: 0 }}>
                        <input
                            type="text"
                            className="form-control"
                            placeholder="Settlement notes (optional)..."
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            disabled={saving || totalPending <= 0}
                        />
                    </div>
                </form>
            </div>

            <div className="card" style={{ padding: '1.25rem', marginBottom: '1.5rem' }}>
                <h4>Settlement History</h4>
                <div className="table-container">
                    <table>
                        <thead><tr><th>Date &amp; Time</th><th>Settlement Amount</th><th>Payment Mode</th><th>Transaction ID</th><th>Settled By</th><th>Before</th><th>After</th></tr></thead>
                        <tbody>
                            {settlementHistory.length === 0 ? <tr><td colSpan="7" className="text-center">No settlements recorded.</td></tr> : settlementHistory.map((settlement) => (
                                <tr key={settlement.id}>
                                    <td>{dateTime(`${settlement.settlement_date} ${settlement.created_at?.split(' ')[1] || ''}`)}</td>
                                    <td>{money(settlement.settlement_amount)}</td>
                                    <td>{settlement.payment_mode || '-'}</td>
                                    <td>{settlement.transaction_id || '-'}</td>
                                    <td>{settlement.settled_by_name || '-'}</td>
                                    <td>{money(settlement.outstanding_before)}</td>
                                    <td>{money(settlement.outstanding_after)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {settlementHistory.length > 0 && <div className="card" style={{ padding: '1.25rem', marginBottom: '1.5rem' }}>
                <h4>Settlement Allocations</h4>
                {settlementHistory.map((settlement, index) => <div key={settlement.id} style={{ marginBottom: '1rem' }}>
                    <strong>Settlement #{index + 1}: {money(settlement.settlement_amount)}</strong>
                    <div className="table-container">
                        <table>
                            <thead><tr><th>Customer</th><th>Allocated</th><th>Outstanding Before</th><th>Outstanding After</th></tr></thead>
                            <tbody>{settlement.allocations.map((allocation) => <tr key={allocation.collection_id}><td>{allocation.username || '-'}</td><td>{money(allocation.amount_allocated)}</td><td>{money(allocation.outstanding_before)}</td><td>{money(allocation.outstanding_after)}</td></tr>)}</tbody>
                        </table>
                    </div>
                </div>)}
            </div>}

            {/* CUSTOMER COLLECTIONS TABLE */}
            {loading ? (
                <p>Loading customer collections...</p>
            ) : (
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Mobile</th>
                                <th>Installation Date</th>
                                <th>Software</th>
                                <th>Validity</th>
                                <th>Original Collected</th>
                                <th>Amount Settled</th>
                                <th>Pending Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {collections.length === 0 ? (
                                <tr>
                                    <td colSpan="10" className="text-center">No customer cash collections records found.</td>
                                </tr>
                            ) : (
                                collections.map((collection) => {
                                    const isFullySettled = Number(collection.pending_amount || 0) <= 0;
                                    const isPartiallySettled = Number(collection.amount_remitted || 0) > 0 && !isFullySettled;
                                    const statusLabel = isFullySettled ? 'Settled' : isPartiallySettled ? 'Partially Settled' : 'Pending';

                                    return (
                                        <tr key={collection.id}>
                                            <td className="truncate-cell" title={collection.username}>{collection.username || '-'}</td>
                                            <td>{collection.primary_mobile_no || '-'}</td>
                                            <td>{formatDate(collection.installation_date)}</td>
                                            <td>{collection.platform_name || '-'}</td>
                                            <td>{collection.validity_months ? `${collection.validity_months} Months` : '-'}</td>
                                            <td>{money(collection.amount_collected)}</td>
                                            <td style={{ color: '#16a34a', fontWeight: '500' }}>{money(collection.amount_remitted)}</td>
                                            <td style={{ color: Number(collection.pending_amount) > 0 ? '#dc2626' : 'inherit', fontWeight: '600' }}>{money(collection.pending_amount)}</td>
                                            <td>
                                                <span className={`badge ${isFullySettled ? 'badge-success' : isPartiallySettled ? 'badge-warning' : 'badge-danger'}`}>
                                                    {statusLabel}
                                                </span>
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="btn btn-outline"
                                                    style={{ padding: '0.25rem 0.5rem', fontSize: '0.8rem' }}
                                                    onClick={() => openSingleSettlement(collection)}
                                                >
                                                    Settle
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {/* SINGLE ROW SETTLEMENT MODAL */}
            <Modal
                isOpen={Boolean(selectedSingle)}
                onClose={() => setSelectedSingle(null)}
                title="Record Customer Settlement"
                maxWidth="520px"
                footer={
                    <>
                        <button type="button" className="btn btn-outline" onClick={() => setSelectedSingle(null)} disabled={saving}>
                            Cancel
                        </button>
                        <button type="submit" form="single-cash-settlement-form" className="btn btn-primary" disabled={saving}>
                            {saving ? 'Saving...' : 'Save Settlement'}
                        </button>
                    </>
                }
            >
                {selectedSingle && (
                    <form id="single-cash-settlement-form" onSubmit={saveSingleSettlement}>
                        <p>
                            <strong>{selectedSingle.username}</strong> - Collected {money(selectedSingle.amount_collected)}.
                        </p>
                        <div className="form-group">
                            <label className="form-label">Settlement Amount</label>
                            <input
                                className="form-control"
                                type="number"
                                min="0"
                                max={selectedSingle.pending_amount}
                                step="0.01"
                                value={singleRemitted}
                                onChange={(event) => setSingleRemitted(event.target.value)}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Settlement Date</label>
                            <input
                                className="form-control"
                                type="date"
                                value={singleDate}
                                onChange={(event) => setSingleDate(event.target.value)}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Mode</label>
                            <select
                                className="form-control"
                                value={singleMode}
                                onChange={(event) => setSingleMode(event.target.value)}
                            >
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Card">Card</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Transaction ID {singleMode !== 'Cash' ? '*' : ''}</label>
                            <input
                                className="form-control"
                                value={singleTxId}
                                onChange={(event) => setSingleTxId(event.target.value)}
                                placeholder={singleMode !== 'Cash' ? 'Enter TXN ID' : 'Optional for Cash'}
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Notes</label>
                            <textarea
                                className="form-control"
                                value={singleNotes}
                                onChange={(event) => setSingleNotes(event.target.value)}
                                rows="3"
                            />
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
};

export default CustomerCashCollections;
