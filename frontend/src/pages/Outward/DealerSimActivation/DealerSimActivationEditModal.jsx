import { useEffect, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import { PAYMENT_MODES } from '../../../constants/paymentModes';
import { formatDate } from '../../../utils/date';

const localToday = () => {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
};

const newPaymentRequestKey = () => (
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`
);

const currency = (value) => `₹${Number(value || 0).toFixed(2)}`;

const DealerSimActivationEditModal = ({
    allocationId,
    mode = 'edit',
    lifecycleAction = '',
    canEditDetails = false,
    onClose,
    onSuccess,
    onPaymentSuccess
}) => {
    const [allocation, setAllocation] = useState(null);
    const [payments, setPayments] = useState([]);
    const [lifecyclePayments, setLifecyclePayments] = useState([]);
    const [simValidities, setSimValidities] = useState([]);
    const [form, setForm] = useState(null);
    const [paymentDetails, setPaymentDetails] = useState(null);
    const [adminSimDetails, setAdminSimDetails] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [closePayment, setClosePayment] = useState(false);
    const [paymentAmount, setPaymentAmount] = useState('');
    const [paymentMode, setPaymentMode] = useState('');
    const [transactionId, setTransactionId] = useState('');
    const [paymentDate, setPaymentDate] = useState(localToday);
    const [paymentRemarks, setPaymentRemarks] = useState('');
    const [paymentRequestKey, setPaymentRequestKey] = useState(newPaymentRequestKey);
    const [closeLifecyclePayment, setCloseLifecyclePayment] = useState(false);
    const [lifecyclePayment, setLifecyclePayment] = useState({
        totalAmount: '',
        amountPaid: '',
        paymentMode: '',
        paymentDate: '',
        transactionId: ''
    });
    const [previousPendingPayment, setPreviousPendingPayment] = useState({
        amountPaid: '',
        paymentMode: '',
        paymentDate: '',
        transactionId: ''
    });

    const loadDetails = async () => {
        const response = await api.get(`/dealers/sim_activation_get.php?allocation_id=${encodeURIComponent(allocationId)}`);
        const details = response.data.data?.allocation;
        if (!response.data.success || !details) {
            throw new Error(response.data.message || 'Dealer SIM activation record not found.');
        }
        setAllocation(details);
        setPayments(response.data.data?.payments || []);
        setLifecyclePayments(response.data.data?.lifecycle_payments || []);
        return details;
    };

    useEffect(() => {
        let isCurrent = true;
        Promise.all([
            api.get(`/dealers/sim_activation_get.php?allocation_id=${encodeURIComponent(allocationId)}`),
            api.get('/sim_validities/list.php')
        ]).then(([allocationResponse, validitiesResponse]) => {
            const details = allocationResponse.data.data?.allocation;
            if (!allocationResponse.data.success || !details) {
                throw new Error(allocationResponse.data.message || 'Dealer SIM activation record not found.');
            }
            if (!validitiesResponse.data.success) {
                throw new Error(validitiesResponse.data.message || 'Failed to load SIM validities.');
            }
            if (!isCurrent) return;
            setAllocation(details);
            setPayments(allocationResponse.data.data?.payments || []);
            setLifecyclePayments(allocationResponse.data.data?.lifecycle_payments || []);
            setSimValidities(validitiesResponse.data.data?.validities || []);
            setForm({
                activation_date: details.activation_date || '',
                sim_validity_id: String(details.sim_validity_id || ''),
                renewal_validity_id: '',
                reactivation_validity_id: String(details.sim_validity_id || ''),
                deactivation_date: '',
                reactivation_date: ''
            });
            setPaymentDetails({
                total_amount: String(details.total_amount || 0),
                amount_paid: String(details.amount_paid || 0),
                payment_mode: details.payment_mode || '',
                payment_date: details.payment_date || '',
                transaction_id: details.transaction_id || ''
            });
            setAdminSimDetails({
                given_date: details.given_date || '',
                deactivation_date: details.deactivation_date || '',
                reactivation_date: details.reactivation_date || ''
            });
        }).catch((loadError) => {
            if (isCurrent) setError(loadError.response?.data?.message || loadError.message || 'Unable to load SIM activation details.');
        }).finally(() => {
            if (isCurrent) setLoading(false);
        });
        return () => {
            isCurrent = false;
        };
    }, [allocationId]);

    const update = (key, value) => setForm((previous) => ({ ...previous, [key]: value }));
    const isLifecycle = mode === 'lifecycle';
    const isView = mode === 'view';
    const isAdminEdit = canEditDetails && !isView && !isLifecycle;
    const isAvailable = String(allocation?.sim_status || '').toLowerCase() === 'available';
    const showLifecyclePayment = isLifecycle && ['renew', 'reactivate'].includes(lifecycleAction);
    const totalAmount = Number(isAdminEdit ? paymentDetails?.total_amount : allocation?.total_amount || 0);
    const amountPaid = Number(isAdminEdit ? paymentDetails?.amount_paid : allocation?.amount_paid || 0);
    const amountPending = Math.max(0, totalAmount - amountPaid);
    const transactionRequired = Boolean(paymentMode && paymentMode !== 'Cash');
    const lifecycleTotalAmount = Number(lifecyclePayment.totalAmount || 0);
    const lifecycleAmountPaid = Number(lifecyclePayment.amountPaid || 0);
    const lifecycleAmountPending = Math.max(0, lifecycleTotalAmount - lifecycleAmountPaid);
    const lifecycleOutstandingPending = lifecyclePayments.reduce(
        (total, payment) => total + (
            ['Renew SIM', 'Reactivate SIM', 'Safe Custody'].includes(payment.action_type)
                ? Number(payment.amount_pending || 0)
                : 0
        ),
        0
    );
    const previousPendingPaid = Number(previousPendingPayment.amountPaid || 0);
    const previousPendingRemaining = Math.max(0, lifecycleOutstandingPending - previousPendingPaid);
    const lifecyclePaymentStatus = lifecycleTotalAmount <= 0 || lifecycleAmountPaid <= 0
        ? 'Not Paid'
        : lifecycleAmountPending <= 0
            ? 'Paid'
            : 'Partially Paid';
    const lifecycleTransactionRequired = lifecycleAmountPaid > 0
        && lifecyclePayment.paymentMode !== 'Cash';
    const hasLifecyclePayment = showLifecyclePayment;
    const actionLabels = {
        renew: 'Renew SIM',
        deactivate: 'Deactivate SIM',
        safe_custody: 'Move to Safe Custody',
        reactivate: 'Reactivate SIM'
    };

    const calculateExpiry = (startDate, validityId) => {
        if (!startDate || !validityId) return '-';
        const validity = simValidities.find((item) => String(item.id) === String(validityId));
        if (!validity) return '-';
        const [year, month, day] = startDate.split('-').map(Number);
        const targetMonth = month - 1 + Number(validity.months);
        const targetYear = year + Math.floor(targetMonth / 12);
        const normalizedMonth = (targetMonth % 12) + 1;
        const lastDay = new Date(Date.UTC(targetYear, normalizedMonth, 0)).getUTCDate();
        const normalizedDay = Math.min(day, lastDay);
        return formatDate(`${targetYear}-${String(normalizedMonth).padStart(2, '0')}-${String(normalizedDay).padStart(2, '0')}`);
    };

    const handleSave = async () => {
        if (!allocation || !form) return;
        setError('');
        if (isLifecycle) {
            if (lifecycleAction === 'renew' && !allocation.expiry_date) {
                setError('The saved Expiry Date is missing. SIM renewal cannot calculate the next renewal cycle.');
                return;
            }
            if (lifecycleAction === 'renew' && !form.renewal_validity_id) {
                setError('Please select the Renewal Validity.');
                return;
            }
            if (lifecycleAction === 'deactivate' && !form.deactivation_date) {
                setError('Please select the Deactivation Date.');
                return;
            }
            if (lifecycleAction === 'reactivate' && !form.reactivation_date) {
                setError('Please select the Actual Reactivation Date.');
                return;
            }
            if (lifecycleAction === 'reactivate' && !allocation.expiry_date) {
                setError('The saved Expiry Date is missing. SIM reactivation cannot calculate the next renewal cycle.');
                return;
            }
            if (lifecycleAction === 'reactivate' && !form.reactivation_validity_id) {
                setError('Please select the Reactivation Validity.');
                return;
            }
            if (hasLifecyclePayment) {
                if (!Number.isFinite(lifecycleTotalAmount) || lifecycleTotalAmount < 0
                    || !Number.isFinite(lifecycleAmountPaid) || lifecycleAmountPaid < 0) {
                    setError('Payment amounts cannot be negative.');
                    return;
                }
                if (['renew', 'reactivate'].includes(lifecycleAction) && lifecycleTotalAmount <= 0) {
                    setError(`Enter a ${lifecycleAction === 'renew' ? 'Renewal' : 'Reactivation'} Total Amount greater than zero.`);
                    return;
                }
                if (lifecycleAmountPaid > lifecycleTotalAmount) {
                    setError('Amount Paid cannot exceed Total Amount.');
                    return;
                }
                if (lifecycleAmountPaid > 0 && !lifecyclePayment.paymentMode) {
                    setError('Please select the Payment Mode.');
                    return;
                }
                if (lifecycleAmountPaid > 0 && !lifecyclePayment.paymentDate) {
                    setError('Please select the Payment Date.');
                    return;
                }
                if (lifecycleTransactionRequired && !/^\d{6}$/.test(lifecyclePayment.transactionId)) {
                    setError('Transaction ID must contain exactly 6 digits.');
                    return;
                }
            }
            if (['renew', 'reactivate'].includes(lifecycleAction) && closeLifecyclePayment) {
                if (!Number.isFinite(previousPendingPaid) || previousPendingPaid <= 0) {
                    setError('Enter an old pending payment greater than zero.');
                    return;
                }
                if (previousPendingPaid > lifecycleOutstandingPending) {
                    setError(`Payment cannot exceed the old pending balance of ${currency(lifecycleOutstandingPending)}.`);
                    return;
                }
                if (!previousPendingPayment.paymentMode) {
                    setError('Please select the Payment Mode for the old pending payment.');
                    return;
                }
                if (!previousPendingPayment.paymentDate) {
                    setError('Please select the Payment Date for the old pending payment.');
                    return;
                }
                if (previousPendingPayment.paymentMode !== 'Cash'
                    && !/^\d{6}$/.test(previousPendingPayment.transactionId)) {
                    setError('The old pending Transaction ID must contain exactly 6 digits.');
                    return;
                }
            }
        } else if (isAdminEdit) {
            if ((form.activation_date && !form.sim_validity_id)
                || (!form.activation_date && form.sim_validity_id)) {
                setError('Activation Date and Validity must both be provided.');
                return;
            }
            if (allocation.sim_status !== 'Available' && (!form.activation_date || !form.sim_validity_id)) {
                setError('Activation Date and Validity cannot be cleared after the SIM has been activated.');
                return;
            }
            if (form.activation_date && !/^\d{4}-\d{2}-\d{2}$/.test(form.activation_date)) {
                setError('Please enter a valid Activation Date.');
                return;
            }
            for (const [fieldLabel, value] of [
                ['Given Date', adminSimDetails?.given_date],
                ['Deactivation Date', adminSimDetails?.deactivation_date],
                ['Reactivation Date', adminSimDetails?.reactivation_date]
            ]) {
                if (value && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    setError(`Please enter a valid ${fieldLabel}.`);
                    return;
                }
            }
            const editedTotal = Number(paymentDetails?.total_amount);
            const editedPaid = Number(paymentDetails?.amount_paid);
            if (!Number.isFinite(editedTotal) || editedTotal < 0
                || !Number.isFinite(editedPaid) || editedPaid < 0) {
                setError('Payment amounts must be valid non-negative numbers.');
                return;
            }
            if (editedPaid > editedTotal) {
                setError('Amount Paid cannot exceed Total Amount.');
                return;
            }
            if (editedPaid > 0 && !paymentDetails.payment_mode) {
                setError('Please select the Payment Mode.');
                return;
            }
            if (editedPaid > 0 && !paymentDetails.payment_date) {
                setError('Please select the Payment Date.');
                return;
            }
            if (editedPaid > 0 && paymentDetails.payment_mode !== 'Cash'
                && !/^\d{6}$/.test(paymentDetails.transaction_id)) {
                setError('Transaction ID must contain exactly 6 digits.');
                return;
            }
        }

        const payload = isLifecycle
            ? {
                allocation_id: allocation.allocation_id,
                action: lifecycleAction,
                renewal_validity_id: form.renewal_validity_id,
                reactivation_validity_id: form.reactivation_validity_id,
                deactivation_date: form.deactivation_date,
                reactivation_date: form.reactivation_date,
                lifecycle_payment: hasLifecyclePayment ? {
                    total_amount: lifecycleTotalAmount,
                    amount_paid: lifecycleAmountPaid,
                    payment_mode: lifecyclePayment.paymentMode,
                    payment_date: lifecyclePayment.paymentDate,
                    transaction_id: lifecyclePayment.transactionId
                } : undefined,
                previous_pending_payment: ['renew', 'reactivate'].includes(lifecycleAction) && closeLifecyclePayment ? {
                    amount_paid: previousPendingPaid,
                    payment_mode: previousPendingPayment.paymentMode,
                    payment_date: previousPendingPayment.paymentDate,
                    transaction_id: previousPendingPayment.transactionId
                } : undefined
            }
            : {
                allocation_id: allocation.allocation_id,
                action: 'update',
                activation_date: form.activation_date,
                sim_validity_id: form.sim_validity_id,
                ...(adminSimDetails.given_date !== (allocation.given_date || '') ? { given_date: adminSimDetails.given_date } : {}),
                deactivation_date: adminSimDetails.deactivation_date,
                reactivation_date: adminSimDetails.reactivation_date,
                payment_details: {
                    total_amount: Number(paymentDetails.total_amount),
                    amount_paid: Number(paymentDetails.amount_paid),
                    payment_mode: paymentDetails.payment_mode,
                    payment_date: paymentDetails.payment_date,
                    transaction_id: paymentDetails.transaction_id
                }
            };

        setSaving(true);
        try {
            const response = await api.post('/dealers/sim_activation_update.php', payload);
            if (!response.data.success) {
                throw new Error(response.data.message || `Failed to ${isLifecycle ? 'apply lifecycle action' : 'save SIM activation details'}.`);
            }
            await onSuccess();
        } catch (saveError) {
            setError(saveError.response?.data?.message || saveError.message || 'Unable to save SIM activation details.');
        } finally {
            setSaving(false);
        }
    };

    const handlePaymentToggle = (checked) => {
        setClosePayment(checked);
        setPaymentAmount('');
        setPaymentMode('');
        setTransactionId('');
        setPaymentDate(localToday());
        setPaymentRemarks('');
        setPaymentRequestKey(newPaymentRequestKey());
    };

    const handleRecordPayment = async () => {
        setError('');
        const amount = Number(paymentAmount);
        if (!Number.isFinite(amount) || amount <= 0) {
            setError('Payment Amount must be greater than zero.');
            return;
        }
        if (amount > amountPending) {
            setError(`Payment Amount cannot exceed the current outstanding balance of ${currency(amountPending)}.`);
            return;
        }
        if (!paymentMode) {
            setError('Please select the Payment Mode.');
            return;
        }
        if (!paymentDate) {
            setError('Please select the Payment Date.');
            return;
        }
        if (transactionRequired && !/^\d{6}$/.test(transactionId)) {
            setError('Transaction ID must contain exactly 6 digits.');
            return;
        }

        setSaving(true);
        try {
            const response = await api.post('/dealers/sim_activation_payment_create.php', {
                allocation_id: allocation.allocation_id,
                idempotency_key: paymentRequestKey,
                amount_paid: amount,
                payment_mode: paymentMode,
                transaction_id: transactionRequired ? transactionId : '',
                payment_date: paymentDate,
                remarks: paymentRemarks
            });
            if (!response.data.success) {
                throw new Error(response.data.message || 'Unable to record SIM payment.');
            }
            await loadDetails();
            handlePaymentToggle(false);
            await onPaymentSuccess?.();
        } catch (paymentError) {
            setError(paymentError.response?.data?.message || paymentError.message || 'Unable to record SIM payment.');
        } finally {
            setSaving(false);
        }
    };

    const handleRecordPreviousRenewalPayment = async () => {
        setError('');
        if (!Number.isFinite(previousPendingPaid) || previousPendingPaid <= 0) {
            setError('Enter an old renewal pending payment greater than zero.');
            return;
        }
        if (previousPendingPaid > lifecycleOutstandingPending) {
            setError(`Payment cannot exceed the old renewal outstanding balance of ${currency(lifecycleOutstandingPending)}.`);
            return;
        }
        if (!previousPendingPayment.paymentMode) {
            setError('Please select the Payment Mode for the old renewal payment.');
            return;
        }
        if (!previousPendingPayment.paymentDate) {
            setError('Please select the Payment Date for the old renewal payment.');
            return;
        }
        if (previousPendingPayment.paymentMode !== 'Cash'
            && !/^\d{6}$/.test(previousPendingPayment.transactionId)) {
            setError('The old renewal Transaction ID must contain exactly 6 digits.');
            return;
        }

        setSaving(true);
        try {
            const response = await api.post('/dealers/sim_activation_update.php', {
                allocation_id: allocation.allocation_id,
                action: 'renewal_payment',
                previous_pending_payment: {
                    amount_paid: previousPendingPaid,
                    payment_mode: previousPendingPayment.paymentMode,
                    payment_date: previousPendingPayment.paymentDate,
                    transaction_id: previousPendingPayment.transactionId
                }
            });
            if (!response.data.success) {
                throw new Error(response.data.message || 'Unable to record previous renewal payment.');
            }
            await loadDetails();
            setCloseLifecyclePayment(false);
            setPreviousPendingPayment({
                amountPaid: '',
                paymentMode: '',
                paymentDate: '',
                transactionId: ''
            });
            await onPaymentSuccess?.();
        } catch (paymentError) {
            setError(paymentError.response?.data?.message || paymentError.message || 'Unable to record previous renewal payment.');
        } finally {
            setSaving(false);
        }
    };

    const actionTitle = actionLabels[lifecycleAction] || 'Lifecycle Action';
    const title = isView ? 'View Dealer SIM Activation' : isLifecycle ? actionTitle : 'Edit Dealer SIM Activation';
    const renderLifecyclePaymentHistory = () => (
        <>
            <h4 className="sim-activation-modal-section-title sim-activation-modal-full-width">RENEWAL PAYMENT HISTORY</h4>
            {lifecyclePayments.length === 0 ? (
                <p className="sim-activation-payment-empty">No renewal or lifecycle payment history is available for this SIM.</p>
            ) : (
                <div className="sim-activation-payment-history">
                    <table>
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Action Date</th>
                                <th>Total Amount</th>
                                <th>Amount Paid</th>
                                <th>Amount Pending</th>
                                <th>Payment Mode</th>
                                <th>Payment Date</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lifecyclePayments.map((payment) => {
                                const amount = Number(payment.payment_amount || 0);
                                const paid = Number(payment.amount_paid || 0);
                                const pending = Number(payment.amount_pending || 0);
                                const status = payment.action_type === 'Renewal Payment'
                                    ? 'Payment Recorded'
                                    : amount <= 0 || paid <= 0
                                        ? 'Not Paid'
                                        : pending <= 0
                                            ? 'Paid'
                                            : 'Partially Paid';
                                return (
                                    <tr key={payment.id}>
                                        <td>{payment.action_type}</td>
                                        <td>{formatDate(payment.action_date)}</td>
                                        <td>{payment.action_type === 'Renewal Payment' ? '-' : currency(amount)}</td>
                                        <td>{currency(paid)}</td>
                                        <td>{currency(pending)}</td>
                                        <td>{payment.payment_mode || '-'}</td>
                                        <td>{formatDate(payment.payment_date)}</td>
                                        <td>{payment.transaction_id || '-'}</td>
                                        <td>{status}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
    const renderPaymentDetails = (allowPaymentEntry) => (
        <>
            <h3 className="sim-activation-modal-section-title sim-activation-modal-full-width">
                {lifecycleAction === 'renew' ? 'NEW RENEWAL PAYMENT' : lifecycleAction === 'reactivate' ? 'NEW REACTIVATION PAYMENT' : 'PAYMENT DETAILS'}
            </h3>
            <div className="sim-activation-modal-grid">
                <div className="form-group">
                    <label className="form-label">Total Amount (₹)</label>
                    {isAdminEdit
                        ? <input type="number" min="0" step="0.01" className="form-control" value={paymentDetails?.total_amount ?? ''} onChange={(event) => setPaymentDetails({ ...paymentDetails, total_amount: event.target.value })} />
                        : <input className="form-control" value={currency(totalAmount)} readOnly />}
                </div>
                <div className="form-group">
                    <label className="form-label">Amount Paid (₹)</label>
                    {isAdminEdit
                        ? <input type="number" min="0" step="0.01" className="form-control" max={paymentDetails?.total_amount || undefined} value={paymentDetails?.amount_paid ?? ''} onChange={(event) => setPaymentDetails({ ...paymentDetails, amount_paid: event.target.value })} />
                        : <input className="form-control" value={currency(amountPaid)} readOnly />}
                </div>
                <div className="form-group"><label className="form-label">Amount Pending (₹)</label><input className="form-control" value={currency(amountPending)} readOnly /></div>
                <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={totalAmount <= 0 ? 'Not Paid' : amountPending <= 0 ? 'Paid' : amountPaid > 0 ? 'Partially Paid' : 'Not Paid'} readOnly /></div>
                <div className="form-group">
                    <label className="form-label">Payment Mode</label>
                    {isAdminEdit
                        ? <select className="form-control" value={paymentDetails?.payment_mode || ''} onChange={(event) => setPaymentDetails({ ...paymentDetails, payment_mode: event.target.value, transaction_id: event.target.value === 'Cash' ? '' : paymentDetails.transaction_id })}>
                            <option value="">Select payment mode</option>
                            {PAYMENT_MODES.map((modeOption) => <option key={modeOption} value={modeOption}>{modeOption}</option>)}
                        </select>
                        : <input className="form-control" value={allocation.payment_mode || '-'} readOnly />}
                </div>
                <div className="form-group">
                    <label className="form-label">Payment Date</label>
                    {isAdminEdit
                        ? <input type="date" className="form-control" value={paymentDetails?.payment_date || ''} onChange={(event) => setPaymentDetails({ ...paymentDetails, payment_date: event.target.value })} />
                        : <input className="form-control" value={formatDate(allocation.payment_date)} readOnly />}
                </div>
                <div className="form-group">
                    <label className="form-label">Transaction ID{isAdminEdit && amountPaid > 0 && paymentDetails?.payment_mode !== 'Cash' ? ' *' : ''}</label>
                    {isAdminEdit
                        ? <input className="form-control" value={paymentDetails?.transaction_id || ''} onChange={(event) => setPaymentDetails({ ...paymentDetails, transaction_id: event.target.value.replace(/\D/g, '').slice(0, 6) })} inputMode="numeric" maxLength={6} placeholder="6 digits, if applicable" />
                        : <input className="form-control" value={allocation.transaction_id || '-'} readOnly />}
                </div>
            </div>
            {isAdminEdit && <p className="sim-activation-payment-empty">Payment summary edits are audited; existing payment history entries remain unchanged.</p>}

            <h4 className="sim-activation-modal-section-title sim-activation-modal-full-width">PAYMENT HISTORY</h4>
            {payments.length === 0 ? (
                <p className="sim-activation-payment-empty">No payment history is available for this SIM.</p>
            ) : (
                <div className="sim-activation-payment-history">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount Paid</th>
                                <th>Amount Pending</th>
                                <th>Payment Mode</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td>{formatDate(payment.payment_date)}</td>
                                    <td>{currency(payment.amount_paid)}</td>
                                    <td>{currency(payment.amount_pending)}</td>
                                    <td>{payment.payment_mode || '-'}</td>
                                    <td>{payment.transaction_id || '-'}</td>
                                    <td>{payment.payment_status || '-'}</td>
                                    <td>{payment.remarks || '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {allowPaymentEntry && amountPending > 0 && (
                <>
                    <label className="sim-activation-close-payment">
                        <input type="checkbox" checked={closePayment} onChange={(event) => handlePaymentToggle(event.target.checked)} />
                        <span>Close Payment</span>
                    </label>
                    {closePayment && (
                        <div className="sim-activation-modal-grid sim-activation-new-payment">
                            <div className="form-group">
                                <label className="form-label">Payment Amount (₹) *</label>
                                <input type="number" min="0.01" max={amountPending} step="0.01" className="form-control" value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} />
                                <small>Outstanding: {currency(amountPending)}</small>
                            </div>
                            <div className="form-group">
                                <label className="form-label">Payment Mode *</label>
                                <select className="form-control" value={paymentMode} onChange={(event) => setPaymentMode(event.target.value)}>
                                    <option value="">Select payment mode</option>
                                    {PAYMENT_MODES.map((modeOption) => <option key={modeOption} value={modeOption}>{modeOption}</option>)}
                                </select>
                            </div>
                            <div className="form-group">
                                <label className="form-label">Payment Date *</label>
                                <input type="date" className="form-control" value={paymentDate} onChange={(event) => setPaymentDate(event.target.value)} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Transaction ID{transactionRequired ? ' *' : ''}</label>
                                <input className="form-control" value={transactionId} onChange={(event) => setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" maxLength={6} pattern="[0-9]{6}" placeholder={transactionRequired ? 'Enter 6 digits' : 'Not required for Cash'} />
                            </div>
                            <div className="form-group sim-activation-modal-full-width">
                                <label className="form-label">Remarks (optional)</label>
                                <textarea className="form-control" maxLength={1000} value={paymentRemarks} onChange={(event) => setPaymentRemarks(event.target.value)} rows={2} />
                            </div>
                        </div>
                    )}
                </>
            )}
        </>
    );
    const renderLifecyclePaymentDetails = () => (
        <>
            <h3 className="sim-activation-modal-section-title sim-activation-modal-full-width">PAYMENT DETAILS</h3>
            {['renew', 'reactivate'].includes(lifecycleAction) && (
                <>
                    <div className="sim-activation-modal-grid sim-activation-new-payment">
                        <div className="form-group">
                            <label className="form-label">{lifecycleAction === 'renew' ? 'Renewal' : 'Reactivation'} Total Amount (₹) *</label>
                            <input type="number" min="0.01" step="0.01" className="form-control" value={lifecyclePayment.totalAmount} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, totalAmount: event.target.value })} />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Amount Paid (₹)</label>
                            <input type="number" min="0" step="0.01" className="form-control" value={lifecyclePayment.amountPaid} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, amountPaid: event.target.value })} />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Amount Pending (₹)</label>
                            <input className="form-control" value={currency(lifecycleAmountPending)} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Status</label>
                            <input className="form-control" value={lifecyclePaymentStatus} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Mode</label>
                            <select className="form-control" value={lifecyclePayment.paymentMode} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, paymentMode: event.target.value })}>
                                <option value="">Select payment mode</option>
                                {PAYMENT_MODES.map((modeOption) => <option key={modeOption} value={modeOption}>{modeOption}</option>)}
                            </select>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Date</label>
                            <input type="date" className="form-control" value={lifecyclePayment.paymentDate} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, paymentDate: event.target.value })} />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Transaction ID{lifecycleTransactionRequired ? ' *' : ''}</label>
                            <input className="form-control" value={lifecyclePayment.transactionId} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, transactionId: event.target.value.replace(/\D/g, '').slice(0, 6) })} inputMode="numeric" maxLength={6} pattern="[0-9]{6}" placeholder={lifecycleTransactionRequired ? 'Enter 6 digits' : '6 digits, if applicable'} />
                        </div>
                    </div>
                <label className="sim-activation-close-payment">
                    <input
                        type="checkbox"
                        checked={closeLifecyclePayment}
                        disabled={lifecycleOutstandingPending <= 0}
                        onChange={(event) => {
                            setCloseLifecyclePayment(event.target.checked);
                            setPreviousPendingPayment({
                                amountPaid: '',
                                paymentMode: '',
                                paymentDate: '',
                                transactionId: ''
                            });
                        }}
                    />
                    <span>Close Old Pending Payment</span>
                </label>
                {closeLifecyclePayment && (
                    <div className="sim-activation-modal-grid sim-activation-new-payment">
                        <div className="form-group">
                            <label className="form-label">Old Pending Outstanding (₹)</label>
                            <input className="form-control" value={currency(lifecycleOutstandingPending)} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Amount Paid (₹) *</label>
                            <input type="number" min="0.01" max={lifecycleOutstandingPending} step="0.01" className="form-control" value={previousPendingPayment.amountPaid} onChange={(event) => setPreviousPendingPayment({ ...previousPendingPayment, amountPaid: event.target.value })} />
                            <small>Outstanding after payment: {currency(previousPendingRemaining)}</small>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Mode *</label>
                            <select className="form-control" value={previousPendingPayment.paymentMode} onChange={(event) => setPreviousPendingPayment({ ...previousPendingPayment, paymentMode: event.target.value })}>
                                <option value="">Select payment mode</option>
                                {PAYMENT_MODES.map((modeOption) => <option key={modeOption} value={modeOption}>{modeOption}</option>)}
                            </select>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Payment Date *</label>
                            <input type="date" className="form-control" value={previousPendingPayment.paymentDate} onChange={(event) => setPreviousPendingPayment({ ...previousPendingPayment, paymentDate: event.target.value })} />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Transaction ID{previousPendingPayment.paymentMode !== 'Cash' ? ' *' : ''}</label>
                            <input className="form-control" value={previousPendingPayment.transactionId} onChange={(event) => setPreviousPendingPayment({ ...previousPendingPayment, transactionId: event.target.value.replace(/\D/g, '').slice(0, 6) })} inputMode="numeric" maxLength={6} pattern="[0-9]{6}" placeholder={previousPendingPayment.paymentMode !== 'Cash' ? 'Enter 6 digits' : '6 digits, if applicable'} />
                        </div>
                        <div className="sim-activation-modal-full-width">
                            <button type="button" className="btn btn-primary" onClick={handleRecordPreviousRenewalPayment} disabled={saving}>
                                {saving ? 'Recording...' : 'Record Old Payment'}
                            </button>
                        </div>
                    </div>
                )}
                </>
            )}
            {!['renew', 'reactivate'].includes(lifecycleAction) && hasLifecyclePayment && <div className="sim-activation-modal-grid sim-activation-new-payment">
                <div className="form-group">
                    <label className="form-label">Total Amount (₹)</label>
                    <input type="number" min="0" step="0.01" className="form-control" value={lifecyclePayment.totalAmount} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, totalAmount: event.target.value })} />
                    <small>Outstanding Pending: {currency(lifecycleOutstandingPending)}</small>
                </div>
                <div className="form-group">
                    <label className="form-label">Amount Paid (₹)</label>
                    <input type="number" min="0" step="0.01" className="form-control" value={lifecyclePayment.amountPaid} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, amountPaid: event.target.value })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Amount Pending (₹)</label>
                    <input className="form-control" value={currency(lifecycleAmountPending)} readOnly />
                </div>
                <div className="form-group">
                    <label className="form-label">Payment Status</label>
                    <input className="form-control" value={lifecyclePaymentStatus} readOnly />
                </div>
                <div className="form-group">
                    <label className="form-label">Payment Mode</label>
                    <select className="form-control" value={lifecyclePayment.paymentMode} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, paymentMode: event.target.value })}>
                        <option value="">Select payment mode</option>
                        {PAYMENT_MODES.map((modeOption) => <option key={modeOption} value={modeOption}>{modeOption}</option>)}
                    </select>
                </div>
                <div className="form-group">
                    <label className="form-label">Payment Date</label>
                    <input type="date" className="form-control" value={lifecyclePayment.paymentDate} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, paymentDate: event.target.value })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Transaction ID{lifecycleTransactionRequired ? ' *' : ''}</label>
                    <input className="form-control" value={lifecyclePayment.transactionId} onChange={(event) => setLifecyclePayment({ ...lifecyclePayment, transactionId: event.target.value.replace(/\D/g, '').slice(0, 6) })} inputMode="numeric" maxLength={6} pattern="[0-9]{6}" placeholder={lifecycleTransactionRequired ? 'Enter 6 digits' : '6 digits, if applicable'} />
                </div>
            </div>}
            {renderLifecyclePaymentHistory()}
        </>
    );

    return (
        <Modal
            isOpen
            title={title}
            onClose={onClose}
            maxWidth="900px"
            footer={(
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={saving}>Cancel</button>
                    {!isView && !loading && allocation && form && (
                        <>
                            <button type="button" className="btn btn-primary" onClick={handleSave} disabled={saving}>
                                {saving ? 'Saving...' : isLifecycle ? 'Save Action' : 'Save Changes'}
                            </button>
                            {!isLifecycle && closePayment && (
                                <button type="button" className="btn btn-primary" onClick={handleRecordPayment} disabled={saving}>
                                    {saving ? 'Recording...' : 'Record Payment'}
                                </button>
                            )}
                        </>
                    )}
                </>
            )}
        >
            {loading && <p>Loading SIM activation details...</p>}
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            {!loading && !allocation && !error && <div className="alert alert-danger">SIM activation details are unavailable.</div>}
            {allocation && form && (isLifecycle ? (
                <>
                    <div className="sim-activation-modal-grid">
                        <div className="form-group"><label className="form-label">Dealer Name</label><input className="form-control" value={allocation.dealer_name || '-'} readOnly /></div>
                        <div className="form-group"><label className="form-label">SIM Number</label><input className="form-control" value={allocation.sim_no || '-'} readOnly /></div>
                        {lifecycleAction === 'renew' && (
                            <>
                                <div className="form-group">
                                    <label className="form-label">Previous Expiry / Renewal Date</label>
                                    <input className="form-control" value={formatDate(allocation.expiry_date)} readOnly />
                                </div>
                                <div className="form-group">
                                    <label className="form-label">Renewal Validity *</label>
                                    <select className="form-control" value={form.renewal_validity_id} onChange={(event) => update('renewal_validity_id', event.target.value)}>
                                        <option value="">Select Validity</option>
                                        {simValidities.map((validity) => <option key={validity.id} value={validity.id}>{validity.months} Months</option>)}
                                    </select>
                                </div>
                                <div className="form-group"><label className="form-label">New Expiry Date</label><input className="form-control" value={calculateExpiry(allocation.expiry_date, form.renewal_validity_id)} readOnly /></div>
                            </>
                        )}
                        {lifecycleAction === 'deactivate' && (
                            <div className="form-group"><label className="form-label">Deactivation Date *</label><input type="date" className="form-control" value={form.deactivation_date} onChange={(event) => update('deactivation_date', event.target.value)} /></div>
                        )}
                        {lifecycleAction === 'reactivate' && (
                            <>
                                <div className="form-group"><label className="form-label">Actual Reactivation Date *</label><input type="date" className="form-control" value={form.reactivation_date} onChange={(event) => update('reactivation_date', event.target.value)} /></div>
                                <div className="form-group"><label className="form-label">Previous Expiry Date</label><input className="form-control" value={formatDate(allocation.expiry_date)} readOnly /></div>
                                <div className="form-group">
                                    <label className="form-label">SIM Validity *</label>
                                    <select className="form-control" value={form.reactivation_validity_id} onChange={(event) => update('reactivation_validity_id', event.target.value)}>
                                        <option value="">Select Validity</option>
                                        {simValidities.filter((validity) => !validity.status || String(validity.status).toLowerCase() === 'active').map((validity) => <option key={validity.id} value={validity.id}>{validity.months} Months</option>)}
                                    </select>
                                </div>
                                <div className="form-group"><label className="form-label">Next Expiry / Renewal Date</label><input className="form-control" value={calculateExpiry(allocation.expiry_date, form.reactivation_validity_id)} readOnly /></div>
                            </>
                        )}
                        {lifecycleAction === 'safe_custody' && (
                            <p className="text-warning">SIM will be moved to Safe Custody. Existing dates will be preserved.</p>
                        )}
                    </div>
                    {showLifecyclePayment && renderLifecyclePaymentDetails()}
                </>
            ) : (
                <>
                    <h3 className="sim-activation-modal-section-title">SIM ACTIVATION DETAILS</h3>
                    <div className="sim-activation-modal-grid">
                        <div className="form-group"><label className="form-label">Dealer Name</label><input className="form-control" value={allocation.dealer_name || '-'} readOnly /></div>
                        <div className="form-group"><label className="form-label">SIM Number</label><input className="form-control" value={allocation.sim_no || '-'} readOnly /></div>
                        <div className="form-group">
                            <label className="form-label">Given Date</label>
                            {isAdminEdit
                                ? <input type="date" className="form-control" value={adminSimDetails?.given_date || ''} onChange={(event) => setAdminSimDetails({ ...adminSimDetails, given_date: event.target.value })} />
                                : <input className="form-control" value={formatDate(allocation.given_date)} readOnly />}
                        </div>
                        <div className="form-group"><label className="form-label">SIM Type</label><input className="form-control" value={allocation.sim_type || '-'} readOnly /></div>
                        <div className="form-group">
                            <label className="form-label">Activation Date</label>
                            {isAdminEdit
                                ? <input type="date" className="form-control" value={form.activation_date} onChange={(event) => update('activation_date', event.target.value)} />
                                : <input className="form-control" value={formatDate(allocation.activation_date)} readOnly />}
                        </div>
                        <div className="form-group">
                            <label className="form-label">Validity</label>
                            {isAdminEdit ? (
                                <select className="form-control" value={form.sim_validity_id} onChange={(event) => update('sim_validity_id', event.target.value)}>
                                    <option value="">Select Validity</option>
                                    {simValidities.map((validity) => <option key={validity.id} value={validity.id}>{validity.months} Months</option>)}
                                </select>
                            ) : <input className="form-control" value={allocation.validity_months ? `${allocation.validity_months} Months` : '-'} readOnly />}
                        </div>
                        <div className="form-group"><label className="form-label">Next Renewal Date</label><input className="form-control" value={isAdminEdit || isAvailable ? calculateExpiry(form.activation_date, form.sim_validity_id) : formatDate(allocation.expiry_date)} readOnly /></div>
                        <div className="form-group"><label className="form-label">Expiry Date</label><input className="form-control" value={formatDate(allocation.expiry_date)} readOnly /></div>
                        <div className="form-group">
                            <label className="form-label">Deactivation Date</label>
                            {isAdminEdit
                                ? <input type="date" className="form-control" value={adminSimDetails?.deactivation_date || ''} onChange={(event) => setAdminSimDetails({ ...adminSimDetails, deactivation_date: event.target.value })} />
                                : <input className="form-control" value={formatDate(allocation.deactivation_date)} readOnly />}
                        </div>
                        <div className="form-group">
                            <label className="form-label">Reactivation Date</label>
                            {isAdminEdit
                                ? <input type="date" className="form-control" value={adminSimDetails?.reactivation_date || ''} onChange={(event) => setAdminSimDetails({ ...adminSimDetails, reactivation_date: event.target.value })} />
                                : <input className="form-control" value={formatDate(allocation.reactivation_date)} readOnly />}
                        </div>
                        <div className="form-group"><label className="form-label">SIM Status</label><input className="form-control" value={allocation.sim_status || '-'} readOnly /></div>
                    </div>
                    {renderPaymentDetails(!isView)}
                </>
            ))}
        </Modal>
    );
};

export default DealerSimActivationEditModal;
