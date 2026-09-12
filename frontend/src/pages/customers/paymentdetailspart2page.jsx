import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
    ArrowLeft,
    RefreshCw,
    ReceiptText
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const initialForm = {
    deviceCharge: '',
    softwareCharge: '',
    technicianCharge: '',
    simCharge: '',
    courierCharge: '',
    amountPaid: '',
    paymentMode: '',
    transactionId: ''
};

const normalizeCurrency = (value) => {
    if (
        value === '' ||
        value === null ||
        value === undefined
    ) {
        return 0;
    }

    const parsed = Number(
        String(value).replace(/[^0-9.]/g, '')
    );

    return Number.isFinite(parsed)
        ? parsed
        : 0;
};

const PaymentDetailsPart2Page = () => {
    const navigate = useNavigate();
    const { customerId } = useParams();
    const { hasPermission } = useAuth();

    const [form, setForm] = useState(initialForm);

    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);

    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };
    const [success, setSuccess] = useState('');

    const [totalSaleAmount, setTotalSaleAmount] =
        useState(0);

    const [isEditMode, setIsEditMode] =
        useState(false);

    const [showPendingModal, setShowPendingModal] =
        useState(false);

    const storageKey = customerId
        ? `customer_creation_${customerId}`
        : 'customer_creation_new';

    const canAddCustomer =
        hasPermission('customers.add');

    const canEditCustomer =
        hasPermission('customers.edit');

    /*
     * For an existing customer:
     * customers.edit permission required.
     */
    const hasSavePermission = isEditMode
        ? canEditCustomer
        : canAddCustomer;

    const getStoredStep4 = () => {
        try {
            const stored = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            return stored.step4 || {};
        } catch (err) {
            console.error(
                'Stored step 4 loading error:',
                err
            );

            return {};
        }
    };

    const loadSavedPaymentPart2 = async () => {
        if (!customerId) {
            return;
        }

        try {
            setLoading(true);
            setError('');

            const response = await api.get(
                `/customers/details.php?customer_id=${encodeURIComponent(
                    customerId
                )}`
            );

            if (!response.data?.success) {
                throw new Error(
                    'Failed to load customer payment details.'
                );
            }

            const customer =
                response.data?.data?.customer || {};

            const paymentPart1 =
                response.data?.data?.payment_part1 || {};

            const paymentPart2 =
                response.data?.data?.payment_part2 || {};

            const stored = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            const hasStoredStep4 =
                stored.step4 &&
                stored.step4.totalSaleAmount;

            const saleAmount = hasStoredStep4
                ? normalizeCurrency(
                    stored.step4.totalSaleAmount
                )
                : normalizeCurrency(
                    paymentPart1.total_sale_amount
                );

            /*
             * Customer ID means this is edit/open existing flow.
             */
            setIsEditMode(
                Boolean(customer.id || customerId)
            );

            setTotalSaleAmount(saleAmount);

            const formatValue = (value) => {
                return (
                    value !== null &&
                    value !== undefined
                )
                    ? String(value)
                    : '';
            };

            setForm({
                paymentMode:
                    hasStoredStep4
                        ? formatValue(
                            stored.step4.paymentMode
                        )
                        : formatValue(
                            paymentPart1.payment_mode
                        ),

                transactionId:
                    hasStoredStep4
                        ? formatValue(
                            stored.step4.transactionId
                        )
                        : formatValue(
                            paymentPart1.transaction_id
                        ),

                amountPaid:
                    formatValue(
                        paymentPart2.amount_paid
                    ),

                deviceCharge:
                    formatValue(
                        paymentPart2.device_charge
                    ),

                softwareCharge:
                    formatValue(
                        paymentPart2.software_charge
                    ),

                technicianCharge:
                    formatValue(
                        paymentPart2.technician_charge
                    ),

                simCharge:
                    formatValue(
                        paymentPart2.sim_charge
                    ),

                courierCharge:
                    formatValue(
                        paymentPart2.courier_charge
                    )
            });

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    ...stored,
                    customer_id:
                        Number(customerId),

                    step4: {
                        totalSaleAmount:
                            hasStoredStep4
                                ? formatValue(
                                    stored.step4
                                        .totalSaleAmount
                                )
                                : formatValue(
                                    paymentPart1
                                        .total_sale_amount
                                ),

                        transactionId:
                            hasStoredStep4
                                ? formatValue(
                                    stored.step4
                                        .transactionId
                                )
                                : formatValue(
                                    paymentPart1
                                        .transaction_id
                                ),

                        paymentMode:
                            hasStoredStep4
                                ? formatValue(
                                    stored.step4
                                        .paymentMode
                                )
                                : formatValue(
                                    paymentPart1
                                        .payment_mode
                                )
                    }
                })
            );
        } catch (err) {
            console.error(
                'Saved payment part 2 loading error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load saved payment details.'
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadSavedPaymentPart2();
    }, [customerId]);

    const handleChange = (event) => {
        const { name, value } = event.target;

        let updatedValue = value;

        if (name === 'amountPaid') {
            updatedValue = value
                .replace(/[^0-9.]/g, '')
                .slice(0, 12);
        } else if (
            name === 'deviceCharge' ||
            name === 'softwareCharge' ||
            name === 'technicianCharge' ||
            name === 'simCharge' ||
            name === 'courierCharge'
        ) {
            updatedValue = value
                .replace(/[^0-9.]/g, '')
                .slice(0, 12);
        }

        setForm((previous) => ({
            ...previous,
            [name]: updatedValue
        }));

        setError('');
        setSuccess('');
    };

    const calculateChargeSummary = () => {
        const totalAmount =
            normalizeCurrency(form.deviceCharge) +
            normalizeCurrency(form.softwareCharge) +
            normalizeCurrency(form.technicianCharge) +
            normalizeCurrency(form.simCharge) +
            normalizeCurrency(form.courierCharge);

        const amountPaid =
            normalizeCurrency(form.amountPaid);

        const amountPending = Math.max(
            totalAmount - amountPaid,
            0
        );

        let paymentStatus = 'Not Paid';

        if (
            amountPaid > 0 &&
            amountPaid < totalAmount
        ) {
            paymentStatus = 'Partially Paid';
        } else if (
            amountPaid > 0 &&
            amountPaid >= totalAmount
        ) {
            paymentStatus = 'Paid';
        }

        return {
            totalAmount,
            amountPaid,
            amountPending,
            paymentStatus
        };
    };

    const summary = calculateChargeSummary();

    const saleLabel = `₹${Number(totalSaleAmount || 0).toLocaleString('en-IN')}`;
    const chargeFieldLabels = {
        deviceCharge: 'Device Charge',
        softwareCharge: 'Software Charge',
        technicianCharge: 'Technician Charge',
        simCharge: 'SIM Charge',
        courierCharge: 'Courier Charge'
    };
    const chargeErrors = Object.fromEntries(Object.entries(chargeFieldLabels).map(([field, label]) => [
        field,
        totalSaleAmount > 0 && normalizeCurrency(form[field]) > totalSaleAmount
            ? `${label} cannot exceed Total Sale Amount of ${saleLabel}.`
            : ''
    ]));
    const amountPaidError = summary.amountPaid > summary.totalAmount
        ? `Amount Paid cannot exceed Total Amount of ₹${summary.totalAmount.toLocaleString('en-IN')}.`
        : '';
    const totalMismatchError = totalSaleAmount > 0
        ? summary.totalAmount > totalSaleAmount
            ? `Total charges cannot exceed Total Sale Amount of ${saleLabel}.`
            : Math.abs(summary.totalAmount - totalSaleAmount) > 0.005
                ? `Total charges must equal Total Sale Amount of ${saleLabel}.`
                : ''
        : '';

    const totalSaleAmountMismatch =
        totalSaleAmount > 0 &&
        Math.abs(
            summary.totalAmount -
            totalSaleAmount
        ) > 0.005;

    const isAmountPaidDisabled =
        saving ||
        totalSaleAmountMismatch ||
        totalSaleAmount <= 0;

    const validateForm = () => {
        if (!customerId) {
            return 'Customer ID is missing.';
        }

        if (!totalSaleAmount) {
            return (
                'Please complete Payment Details Part 1 first.'
            );
        }

        /*
         * Total Amount MUST equal Sale Amount.
         */
        if (totalMismatchError) return totalMismatchError;

        /*
         * Amount Paid can be 0.
         */
        if (summary.amountPaid < 0) {
            return 'Amount Paid cannot be negative.';
        }

        if (amountPaidError) return amountPaidError;

        return '';
    };

    // Detect pending-only mode: only total sale amount entered, all other fields blank/zero
    const isPendingOnlyMode =
        isEditMode &&
        Number(totalSaleAmount) > 0 &&
        !normalizeCurrency(form.deviceCharge) &&
        !normalizeCurrency(form.softwareCharge) &&
        !normalizeCurrency(form.technicianCharge) &&
        !normalizeCurrency(form.simCharge) &&
        !normalizeCurrency(form.courierCharge) &&
        (!form.amountPaid || normalizeCurrency(form.amountPaid) === 0) &&
        (!form.paymentMode || form.paymentMode === '' || form.paymentMode === '—') &&
        (!form.transactionId || form.transactionId === '' || form.transactionId === '—');

    const handleSaveCustomer = async (event) => {
        event.preventDefault();

        setError('');
        setSuccess('');

        if (!hasSavePermission) {
            triggerError(
                isEditMode
                    ? 'You do not have permission to edit customers.'
                    : 'You do not have permission to add customers.'
            );
            return;
        }

        // If pending-only mode, show the confirmation modal instead
        if (isPendingOnlyMode) {
            if (!customerId) {
                triggerError('Customer ID is missing.');
                return;
            }
            if (!totalSaleAmount) {
                triggerError('Please complete Payment Details Part 1 first.');
                return;
            }
            setShowPendingModal(true);
            return;
        }

        const validationError =
            validateForm();

        if (validationError) {
            triggerError(validationError);
            return;
        }

        try {
            setSaving(true);

            const stored = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            const step3 = stored.step3 || {};
            const step4 = stored.step4 || {};

            /*
             * Installation details must exist.
             * In EDIT mode the data already lives in the database — skip
             * the sessionStorage gate and let the backend validate.
             * In CREATE mode (sequential wizard), sessionStorage must have
             * the step3 fields before we can proceed.
             */
            if (
                !isEditMode && (
                    !step3.installation_person_id ||
                    !step3.lead_closure_id ||
                    !step3.installation_date
                )
            ) {
                throw new Error(
                    'Please complete the Installation Details step first.'
                );
            }

            const paymentPayload = {
                customer_id:
                    Number(customerId),

                /*
                 * Explicitly identify Part 2.
                 */
                payment_step: 2,

                total_sale_amount:
                    totalSaleAmount,

                transaction_id:
                    form.transactionId ||
                    step4.transactionId ||
                    null,

                payment_mode:
                    form.paymentMode ||
                    step4.paymentMode ||
                    null,

                device_charge:
                    normalizeCurrency(
                        form.deviceCharge
                    ),

                software_charge:
                    normalizeCurrency(
                        form.softwareCharge
                    ),

                technician_charge:
                    normalizeCurrency(
                        form.technicianCharge
                    ),

                sim_charge:
                    normalizeCurrency(
                        form.simCharge
                    ),

                courier_charge:
                    normalizeCurrency(
                        form.courierCharge
                    ),

                total_amount:
                    summary.totalAmount,

                amount_paid:
                    summary.amountPaid,

                amount_pending:
                    summary.amountPending,

                payment_status:
                    summary.paymentStatus
            };

            /*
             * Save/UPDATE payment.
             */
            await api.post(
                '/customers/payments/create.php',
                paymentPayload
            );

            sessionStorage.removeItem(storageKey);

            setSuccess(
                isEditMode
                    ? 'Customer payment details updated successfully.'
                    : 'Customer saved successfully.'
            );

            setTimeout(() => {
                navigate(
                    '/customer-management/details'
                );
            }, 400);
        } catch (err) {
            console.error(
                'Customer final save error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save customer payment details.'
            );
        } finally {
            setSaving(false);
        }
    };

    const handlePendingOk = async () => {
        setShowPendingModal(false);

        try {
            setSaving(true);
            setError('');

            const pendingPayload = {
                customer_id: Number(customerId),
                payment_step: 2,
                total_sale_amount: totalSaleAmount,
                transaction_id: null,
                payment_mode: null,
                device_charge: 0,
                software_charge: 0,
                technician_charge: 0,
                sim_charge: 0,
                courier_charge: 0,
                total_amount: totalSaleAmount,
                amount_paid: 0,
                amount_pending: totalSaleAmount,
                payment_status: 'Pending'
            };

            await api.post(
                '/customers/payments/create.php',
                pendingPayload
            );

            sessionStorage.removeItem(storageKey);

            navigate('/customer-management/details');
        } catch (err) {
            console.error('Pending payment save error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save pending payment.'
            );
        } finally {
            setSaving(false);
        }
    };

    const handleReset = () => {
        const confirmed = window.confirm(
            'Are you sure you want to reset the payment details?'
        );

        if (!confirmed) {
            return;
        }

        setForm((previous) => ({
            ...previous,
            deviceCharge: '',
            softwareCharge: '',
            technicianCharge: '',
            simCharge: '',
            courierCharge: '',
            amountPaid: ''
        }));

        setError('');
        setSuccess('');
    };

    const handleBack = () => {
        navigate(
            `/customer-management/details/payment/${customerId}`
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
                        Complete payment breakdown
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
                        gap: '7px'
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
                        className="customer-step completed"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/payment/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">✓</div>
                        <span>Payment</span>
                    </div>

                    <div className="step-line active-line" />

                    <div
                        className="customer-step active"
                        style={{ cursor: 'default' }}
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

            {totalSaleAmountMismatch && (
                <div
                    className="alert alert-warning"
                    style={{ marginBottom: '16px' }}
                >
                    Total Amount must equal Total Sale Amount
                    {' '}
                    (
                    ₹
                    {totalSaleAmount.toLocaleString(
                        'en-IN'
                    )}
                    ).
                </div>
            )}

            <div className="card">

                <div className="customer-section-title">
                    <ReceiptText size={20} />
                    <h3>
                        Payment Details - Part 2
                    </h3>
                </div>

                {loading ? (
                    <div
                        style={{
                            padding: '20px',
                            textAlign: 'center'
                        }}
                    >
                        Loading payment data...
                    </div>
                ) : (
                    <form
                        onSubmit={
                            handleSaveCustomer
                        }
                    >

                        <div className="customer-vehicle-grid">

                            {/* ROW 1: Device Charge & Software Charge */}
                            <div className="form-group">
                                <label className="form-label">
                                    Device Charge
                                </label>

                                <input
                                    type="text"
                                    name="deviceCharge"
                                    value={
                                        form.deviceCharge
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter device charge"
                                    disabled={saving}
                                />
                                {chargeErrors.deviceCharge && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {chargeErrors.deviceCharge}</small>}
                            </div>

                            <div className="form-group">
                                <label className="form-label">
                                    Software Charge
                                </label>

                                <input
                                    type="text"
                                    name="softwareCharge"
                                    value={
                                        form.softwareCharge
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter software charge"
                                    disabled={saving}
                                />
                                {chargeErrors.softwareCharge && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {chargeErrors.softwareCharge}</small>}
                            </div>

                            {/* ROW 2: Technician Charge & SIM Charge */}
                            <div className="form-group">
                                <label className="form-label">
                                    Technician Charge
                                </label>

                                <input
                                    type="text"
                                    name="technicianCharge"
                                    value={
                                        form.technicianCharge
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter technician charge"
                                    disabled={saving}
                                />
                                {chargeErrors.technicianCharge && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {chargeErrors.technicianCharge}</small>}
                            </div>

                            <div className="form-group">
                                <label className="form-label">
                                    SIM Charge
                                </label>

                                <input
                                    type="text"
                                    name="simCharge"
                                    value={
                                        form.simCharge
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter SIM charge"
                                    disabled={saving}
                                />
                                {chargeErrors.simCharge && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {chargeErrors.simCharge}</small>}
                            </div>

                            {/* ROW 3: Courier Charge & Total Amount */}
                            <div className="form-group">
                                <label className="form-label">
                                    Courier Charge
                                </label>

                                <input
                                    type="text"
                                    name="courierCharge"
                                    value={
                                        form.courierCharge
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter courier charge"
                                    disabled={saving}
                                />
                                {chargeErrors.courierCharge && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {chargeErrors.courierCharge}</small>}
                            </div>

                            <div className="form-group">
                                <label className="form-label">
                                    Total Amount
                                </label>

                                <input
                                    type="text"
                                    value={
                                        summary.totalAmount
                                    }
                                    className="form-control"
                                    readOnly
                                />
                                {totalMismatchError && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {totalMismatchError}</small>}
                            </div>

                            {/* ROW 4: Amount Paid & Amount Pending */}
                            <div className="form-group">
                                <label className="form-label">
                                    Amount Paid
                                </label>

                                <input
                                    type="text"
                                    name="amountPaid"
                                    value={
                                        form.amountPaid
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter amount paid"
                                    disabled={
                                        isAmountPaidDisabled
                                    }
                                />
                                {amountPaidError && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {amountPaidError}</small>}
                            </div>

                            <div className="form-group">
                                <label className="form-label">
                                    Amount Pending
                                </label>

                                <input
                                    type="text"
                                    value={
                                        summary.amountPending
                                    }
                                    className="form-control"
                                    readOnly
                                />
                            </div>

                            {/* ROW 5: Payment Status & Payment Mode */}
                            <div className="form-group">
                                <label className="form-label">
                                    Payment Status
                                </label>

                                <input
                                    type="text"
                                    value={
                                        summary.paymentStatus
                                    }
                                    className="form-control"
                                    readOnly
                                />
                            </div>

                            <div className="form-group">
                                <label className="form-label">
                                    Payment Mode
                                </label>

                                <input
                                    type="text"
                                    value={
                                        form.paymentMode ||
                                        '—'
                                    }
                                    className="form-control"
                                    readOnly
                                />
                            </div>

                            {/* ROW 6: Transaction ID */}
                            <div className="form-group">
                                <label className="form-label">
                                    Transaction ID
                                </label>

                                <input
                                    type="text"
                                    value={
                                        form.transactionId ||
                                        '—'
                                    }
                                    className="form-control"
                                    readOnly
                                />
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
                                    !hasSavePermission ||
                                    (!isPendingOnlyMode && Boolean(totalMismatchError || amountPaidError || Object.values(chargeErrors).some(Boolean)))
                                }
                            >
                                {saving
                                    ? isEditMode
                                        ? 'Updating...'
                                        : 'Saving...'
                                    : isEditMode
                                        ? 'Update Customer'
                                        : 'Save Customer'}
                            </button>

                        </div>

                    </form>
                )}

            </div>

            {/* Pending Payment Confirmation Modal */}
            {showPendingModal && (
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
                            Save as Pending Payment?
                        </div>
                        <p
                            style={{
                                color: '#64748b',
                                marginBottom: '28px',
                                fontSize: '15px',
                                lineHeight: '1.5'
                            }}
                        >
                            This user is saved as pending payment.
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
                                onClick={() => setShowPendingModal(false)}
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={handlePendingOk}
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

export default PaymentDetailsPart2Page;