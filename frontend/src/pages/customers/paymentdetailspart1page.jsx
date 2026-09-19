import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
    ArrowLeft,
    ArrowRight,
    RefreshCw,
    Wallet
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const initialForm = {
    totalSaleAmount: '',
    transactionId: '',
    paymentMode: ''
};

const extractList = (response, keys = []) => {
    const responseData = response?.data;

    if (Array.isArray(responseData)) {
        return responseData;
    }

    const dataObject = responseData?.data;

    if (Array.isArray(dataObject)) {
        return dataObject;
    }

    for (const key of keys) {
        if (Array.isArray(dataObject?.[key])) {
            return dataObject[key];
        }

        if (Array.isArray(responseData?.[key])) {
            return responseData[key];
        }
    }

    return [];
};

const paymentModeOptions = [
    'Cash',
    'UPI',
    'Card',
    'Bank Transfer'
];

const PaymentDetailsPart1Page = () => {
    const navigate = useNavigate();
    const { customerId } = useParams();
    const { hasPermission } = useAuth();

    const [form, setForm] = useState(initialForm);
    const [saleAmounts, setSaleAmounts] = useState([]);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };
    const [success, setSuccess] = useState('');
    const [showValidation, setShowValidation] = useState(false);
    const [showStep5BlockedModal, setShowStep5BlockedModal] = useState(false);
    const [showPendingSaveModal, setShowPendingSaveModal] = useState(false);

    const [newVehicleFlow, setNewVehicleFlow] = useState(false);
    const isEditMode = Boolean(customerId) && !newVehicleFlow;

    const canAddCustomer = hasPermission('customers.add');
    const canEditCustomer = hasPermission('customers.edit');

    const hasEditPermission = isEditMode
        ? canEditCustomer
        : canAddCustomer;

    const storageKey = customerId
        ? `customer_creation_${customerId}`
        : 'customer_creation_new';

    const hasPaymentMode = Boolean(form.paymentMode);

    const isCashPayment =
        form.paymentMode === 'Cash';

    const shouldGoToPart2 =
        Boolean(form.paymentMode);

    const paymentErrors = {
        totalSaleAmount: showValidation && !form.totalSaleAmount ? 'Total Sale Amount is required.' : '',
        transactionId: form.transactionId && !/^\d{6}$/.test(form.transactionId)
            ? 'Transaction ID must contain exactly 6 digits.'
            : form.transactionId && !form.paymentMode
                ? 'Payment Mode is required when Transaction ID is entered.'
                : '',
        paymentMode: form.paymentMode && form.paymentMode !== 'Cash' && !form.transactionId
            ? 'Transaction ID is required for this payment mode.'
            : ''
    };

    const loadSaleAmounts = async () => {
        try {
            setLoading(true);
            setError('');

            const response = await api.get(
                '/sale_amounts/list.php'
            );

            const saleAmountsData = extractList(
                response,
                ['sale_amounts']
            );

            const activeSaleAmounts = saleAmountsData
                .filter((item) => {
                    if (!item) {
                        return false;
                    }

                    if (
                        item.status === undefined ||
                        item.status === null ||
                        item.status === ''
                    ) {
                        return true;
                    }

                    return (
                        String(item.status).toLowerCase() ===
                        'active'
                    );
                })
                .map((item) => ({
                    ...item,
                    sale_amount: Number(item.sale_amount)
                }));

            setSaleAmounts(activeSaleAmounts);
        } catch (err) {
            console.error(
                'Sale amount loading error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load sale amounts.'
            );
        } finally {
            setLoading(false);
        }
    };

    const loadSavedPaymentPart1 = async () => {
        if (!customerId) {
            return;
        }

        try {
            const stored = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            if (stored.new_vehicle_flow || stored.append_vehicle) {
                setNewVehicleFlow(true);
                setForm(stored.step4 || initialForm);
                return;
            }

            setNewVehicleFlow(false);

            const response = await api.get(
                `/customers/details.php?customer_id=${encodeURIComponent(
                    customerId
                )}`
            );

            if (!response.data?.success) {
                if (stored.step4) {
                    setForm({
                        totalSaleAmount:
                            stored.step4.totalSaleAmount ?? '',
                        transactionId:
                            stored.step4.transactionId ?? '',
                        paymentMode:
                            stored.step4.paymentMode ?? ''
                    });
                }

                return;
            }

            const paymentPart1 =
                response.data?.data?.payment_part1 || {};

            const hasSavedPaymentRecord =
                Number(
                    paymentPart1.total_sale_amount ?? 0
                ) > 0 ||
                Boolean(paymentPart1.transaction_id) ||
                Boolean(paymentPart1.payment_mode);

            const nextForm = hasSavedPaymentRecord
                ? {
                    totalSaleAmount:
                        paymentPart1.total_sale_amount ?? '',
                    transactionId:
                        paymentPart1.transaction_id ?? '',
                    paymentMode:
                        paymentPart1.payment_mode ?? ''
                }
                : {
                    totalSaleAmount:
                        stored.step4?.totalSaleAmount ?? '',
                    transactionId:
                        stored.step4?.transactionId ?? '',
                    paymentMode:
                        stored.step4?.paymentMode ?? ''
                };

            setForm(nextForm);

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    ...stored,
                    customer_id: Number(customerId),
                    step4: nextForm
                })
            );
        } catch (err) {
            console.error(
                'Saved payment part 1 loading error:',
                err
            );

            try {
                const stored = JSON.parse(
                    sessionStorage.getItem(storageKey) || '{}'
                );

                if (stored.step4) {
                    setForm({
                        totalSaleAmount:
                            stored.step4.totalSaleAmount ?? '',
                        transactionId:
                            stored.step4.transactionId ?? '',
                        paymentMode:
                            stored.step4.paymentMode ?? ''
                    });
                }
            } catch (fallbackErr) {
                console.error(
                    'Payment part 1 fallback error:',
                    fallbackErr
                );
            }
        }
    };

    useEffect(() => {
        loadSaleAmounts();
        loadSavedPaymentPart1();
    }, [customerId]);

    useEffect(() => {
        if (!form.totalSaleAmount) {
            return;
        }

        const savedSaleAmount = Number(
            form.totalSaleAmount
        );

        setSaleAmounts((previous) => {
            const exists = previous.some(
                (item) =>
                    Number(item.sale_amount) ===
                    savedSaleAmount
            );

            if (exists) {
                return previous;
            }

            return [
                ...previous,
                {
                    id: `saved-${savedSaleAmount}`,
                    sale_amount: savedSaleAmount,
                    status: 'Inactive'
                }
            ].sort(
                (a, b) =>
                    Number(a.sale_amount) -
                    Number(b.sale_amount)
            );
        });
    }, [form.totalSaleAmount]);

    const handleChange = (event) => {
        const { name, value } = event.target;

        let updatedValue = value;

        if (name === 'transactionId') {
            updatedValue = value
                .replace(/\D/g, '')
                .slice(0, 6);
        }

        setForm((previous) => ({
            ...previous,
            [name]: updatedValue
        }));

        setError('');
        setSuccess('');
    };

    const validateForm = () => {
        if (paymentErrors.totalSaleAmount) return paymentErrors.totalSaleAmount;

        const amount = Number(form.totalSaleAmount);

        if (!Number.isFinite(amount) || amount <= 0) {
            return 'Invalid Total Sale Amount.';
        }

        if (paymentErrors.transactionId) return paymentErrors.transactionId;

        /*
         * Transaction ID without Payment Mode
         * is always invalid.
         */

        /*
         * Cash does NOT require Transaction ID.
         */
        if (paymentErrors.paymentMode) return paymentErrors.paymentMode;

        return '';
    };

    const saveStep4ToSession = () => {
        try {
            const existing = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    ...existing,
                    customer_id: Number(customerId),
                    step4: {
                        totalSaleAmount:
                            form.totalSaleAmount,
                        transactionId:
                            form.transactionId,
                        paymentMode:
                            form.paymentMode
                    }
                })
            );
        } catch (err) {
            console.error(
                'Session storage error:',
                err
            );
        }
    };

    const performSavePaymentPart1 = async (goToPart2) => {
        try {
            setSaving(true);

            const stored = JSON.parse(sessionStorage.getItem(storageKey) || '{}');

            const paymentPayload = {
                customer_id: Number(customerId),
                vehicle_id: stored.new_vehicle_flow
                    ? Number(stored.vehicle_record_id || 0)
                    : 0,

                /*
                 * Explicitly identify this as Part 1.
                 */
                payment_step: 1,

                total_sale_amount:
                    Number(form.totalSaleAmount),

                transaction_id:
                    form.transactionId || null,

                payment_mode:
                    form.paymentMode || null,

                /*
                 * Part 1 starts with no charge breakdown.
                 */
                device_charge: 0,
                software_charge: 0,
                technician_charge: 0,
                sim_charge: 0,
                courier_charge: 0,

                total_amount: 0,
                amount_paid: 0,
                amount_pending: 0,

                /*
                 * Sale amount only = Pending.
                 * Cash / other mode will go to Part 2.
                 */
                payment_status: 'Pending'
            };

            await api.post(
                '/customers/payments/create.php',
                paymentPayload
            );

            saveStep4ToSession();

            /*
             * No Payment Mode:
             *
             * Sale Amount only
             * -> Pending
             * -> Finish customer flow
             */
            if (!goToPart2) {
                sessionStorage.removeItem(storageKey);

                setSuccess(
                    'Payment saved as Pending.'
                );

                setTimeout(() => {
                    navigate(
                        '/customer-management/details'
                    );
                }, 300);

                return;
            }

            /*
             * Cash:
             * Transaction ID is optional.
             *
             * UPI / Card / Bank Transfer:
             * Transaction ID already validated.
             *
             * All of them go to Part 2.
             */
            setSuccess(
                isCashPayment
                    ? 'Cash payment selected. Continue to Payment Details.'
                    : 'Payment details saved. Continue to Payment Details.'
            );

            setTimeout(() => {
                navigate(
                    `/customer-management/details/payment-details/${customerId}`
                );
            }, 300);
        } catch (err) {
            console.error(
                'Payment part 1 save error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save payment details.'
            );
        } finally {
            setSaving(false);
        }
    };

    const handleSaveAndNext = async (event) => {
        event.preventDefault();
        setShowValidation(true);

        setError('');
        setSuccess('');

        if (!customerId) {
            triggerError('Customer ID is missing.');
            return;
        }

        if (!hasEditPermission) {
            triggerError(
                isEditMode
                    ? 'You do not have permission to edit customers.'
                    : 'You do not have permission to add customers.'
            );
            return;
        }

        const validationError = validateForm();

        if (validationError) {
            triggerError(validationError);
            return;
        }

        if (!shouldGoToPart2) {
            setShowPendingSaveModal(true);
            return;
        }

        await performSavePaymentPart1(true);
    };

    const handlePendingSaveOk = async () => {
        setShowPendingSaveModal(false);
        await performSavePaymentPart1(false);
    };

    const handleReset = () => {
        const confirmed = window.confirm(
            'Are you sure you want to reset the payment details?'
        );

        if (!confirmed) {
            return;
        }

        setForm(initialForm);
        setError('');
        setSuccess('');

        try {
            const existing = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            delete existing.step4;

            sessionStorage.setItem(
                storageKey,
                JSON.stringify(existing)
            );
        } catch (err) {
            console.error(
                'Reset session error:',
                err
            );
        }
    };

    const handleBack = () => {
        saveStep4ToSession();

        navigate(
            `/customer-management/details/installation/${customerId}`
        );
    };

    return (
        <div className="page-container">

            <div
                className="page-header"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: '20px',
                    marginBottom: '20px'
                }}
            >
                <div>
                    <h2>Payment Details</h2>

                    <p
                        className="page-subtitle"
                        style={{ marginTop: '4px' }}
                    >
                        Add initial payment information
                    </p>
                </div>

                <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={handleBack}
                    disabled={saving}
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '7px',
                        flexShrink: 0
                    }}
                >
                    <ArrowLeft size={16} />
                    Back
                </button>
            </div>

            <div
                className="card customer-step-card"
                style={{ marginBottom: '20px' }}
            >
                <div className="customer-stepper">

                    <div
                        className="customer-step completed"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">✓</div>
                        <span>Customer Details</span>
                    </div>

                    <div className="step-line active-line" />

                    <div
                        className="customer-step completed"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/vehicle/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">✓</div>
                        <span>Vehicle Details</span>
                    </div>

                    <div className="step-line active-line" />

                    <div
                        className="customer-step completed"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/installation/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">✓</div>
                        <span>Installation</span>
                    </div>

                    <div className="step-line active-line" />

                    <div
                        className="customer-step active"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/payment/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">4</div>
                        <span>Payment</span>
                    </div>

                    <div className="step-line" />

                    <div
                        className="customer-step disabled"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                /*
                                 * Gate: Payment Mode must be selected.
                                 * For non-Cash modes, Transaction ID is also required.
                                 * Uses the same rules as paymentErrors.
                                 */
                                const hasMode = Boolean(form.paymentMode);
                                const needsTxn = hasMode && form.paymentMode !== 'Cash' && !form.transactionId;
                                const txnInvalid = form.transactionId && !/^\d{6}$/.test(form.transactionId);

                                if (!hasMode || needsTxn || txnInvalid) {
                                    setShowStep5BlockedModal(true);
                                    return;
                                }

                                navigate(`/customer-management/details/payment-details/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">5</div>
                        <span>Payment Details</span>
                    </div>

                </div>
            </div>

            {error && (
                <div
                    className="alert alert-danger"
                    style={{ marginBottom: '16px' }}
                >
                    {error}
                </div>
            )}

            {success && (
                <div
                    className="alert alert-success"
                    style={{ marginBottom: '16px' }}
                >
                    {success}
                </div>
            )}

            <div className="card">

                <div className="customer-section-title">
                    <Wallet size={20} />
                    <h3>Payment Details - Part 1</h3>
                </div>

                <form onSubmit={handleSaveAndNext}>

                    <div className="customer-vehicle-grid">

                        <div className="form-group">
                            <label className="form-label">
                                Total Sale Amount
                                <span className="required">*</span>
                            </label>

                            <select
                                name="totalSaleAmount"
                                value={form.totalSaleAmount}
                                onChange={handleChange}
                                className="form-control"
                                disabled={loading || saving}
                            >
                                <option value="">
                                    {loading
                                        ? 'Loading sale amounts...'
                                        : 'Select Sale Amount'}
                                </option>

                                {saleAmounts.map((item) => (
                                    <option
                                        key={item.id}
                                        value={item.sale_amount}
                                    >
                                        ₹
                                        {Number(
                                            item.sale_amount
                                        ).toLocaleString('en-IN')}
                                    </option>
                                ))}
                            </select>
                            {paymentErrors.totalSaleAmount && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {paymentErrors.totalSaleAmount}</small>}
                        </div>

                        <div className="form-group">
                            <label className="form-label">
                                Transaction ID
                            </label>

                            <input
                                type="text"
                                name="transactionId"
                                value={form.transactionId}
                                onChange={handleChange}
                                className="form-control"
                                placeholder="Enter 6 digit transaction ID"
                                maxLength={6}
                                inputMode="numeric"
                                disabled={saving}
                            />
                            {paymentErrors.transactionId && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {paymentErrors.transactionId}</small>}
                        </div>

                        <div className="form-group">
                            <label className="form-label">
                                Payment Mode
                            </label>

                            <select
                                name="paymentMode"
                                value={form.paymentMode}
                                onChange={handleChange}
                                className="form-control"
                                disabled={saving}
                            >
                                <option value="">
                                    Select Payment Mode
                                </option>

                                {paymentModeOptions.map(
                                    (item) => (
                                        <option
                                            key={item}
                                            value={item}
                                        >
                                            {item}
                                        </option>
                                    )
                                )}
                            </select>
                            {paymentErrors.paymentMode && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {paymentErrors.paymentMode}</small>}
                        </div>

                    </div>

                    <div
                        className="customer-form-actions"
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'flex-end',
                            gap: '10px',
                            marginTop: '30px',
                            paddingTop: '20px',
                            borderTop:
                                '1px solid #e5e7eb'
                        }}
                    >

                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={handleBack}
                            disabled={saving}
                        >
                            <ArrowLeft size={16} />
                            Back
                        </button>

                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={handleReset}
                            disabled={saving}
                        >
                            <RefreshCw size={16} />
                            Reset
                        </button>

                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={
                                saving ||
                                loading ||
                                !hasEditPermission ||
                                Object.values(paymentErrors).some(Boolean)
                            }
                        >
                            {saving
                                ? 'Saving...'
                                : shouldGoToPart2
                                    ? (
                                        isEditMode
                                            ? 'Update & Next'
                                            : 'Save & Next'
                                    )
                                    : (
                                        isEditMode
                                            ? 'Update'
                                            : 'Save'
                                    )}

                            {!saving && shouldGoToPart2 && (
                                <ArrowRight size={16} />
                            )}
                        </button>

                    </div>

                </form>
            </div>

            {/* Step 5 navigation blocked modal */}
            {showStep5BlockedModal && (
                <div
                    style={{
                        position: 'fixed',
                        inset: 0,
                        backgroundColor: 'rgba(0, 0, 0, 0.5)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        zIndex: 9999
                    }}
                >
                    <div
                        style={{
                            backgroundColor: '#fff',
                            borderRadius: '10px',
                            padding: '32px 28px',
                            maxWidth: '420px',
                            width: '90%',
                            boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
                            textAlign: 'center'
                        }}
                    >
                        <p
                            style={{
                                color: '#1e293b',
                                marginBottom: '24px',
                                fontSize: '15px',
                                lineHeight: '1.5',
                                fontWeight: '500'
                            }}
                        >
                            Transaction ID or Payment Mode must be selected to navigate to Payment Details.
                        </p>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => setShowStep5BlockedModal(false)}
                        >
                            OK
                        </button>
                    </div>
                </div>
            )}

            {/* Pending Payment Confirmation Modal */}
            {showPendingSaveModal && (
                <div
                    style={{
                        position: 'fixed',
                        inset: 0,
                        backgroundColor: 'rgba(0, 0, 0, 0.5)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        zIndex: 9999
                    }}
                >
                    <div
                        style={{
                            backgroundColor: '#fff',
                            borderRadius: '10px',
                            padding: '32px 28px',
                            maxWidth: '420px',
                            width: '90%',
                            boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
                            textAlign: 'center'
                        }}
                    >
                        <div
                            style={{
                                fontSize: '20px',
                                fontWeight: '600',
                                color: '#1e293b',
                                marginBottom: '12px'
                            }}
                        >
                            Payment Pending
                        </div>
                        <p
                            style={{
                                color: '#64748b',
                                marginBottom: '28px',
                                fontSize: '15px',
                                lineHeight: '1.5'
                            }}
                        >
                            This user is saved as payment pending.
                        </p>
                        <div
                            style={{
                                display: 'flex',
                                gap: '12px',
                                justifyContent: 'center'
                            }}
                        >
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => setShowPendingSaveModal(false)}
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={handlePendingSaveOk}
                                disabled={saving}
                            >
                                {saving ? 'Saving...' : 'OK'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

        </div>
    );
};

export default PaymentDetailsPart1Page;