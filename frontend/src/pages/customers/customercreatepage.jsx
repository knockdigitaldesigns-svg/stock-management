import { useEffect, useState } from 'react';
import {
    useNavigate,
    useParams
} from 'react-router-dom';

import {
    ArrowLeft,
    ArrowRight,
    RefreshCw,
    UserRound
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const initialForm = {
    platform_id: '',
    username: '',
    primary_mobile_no: '',
    secondary_mobile_no: '',
    email: '',
    location: '',
    pincode: ''
};

const CustomerCreatePage = () => {
    const navigate = useNavigate();
    const { customerId } = useParams();

    const { hasPermission } = useAuth();

    const isEditMode = Boolean(customerId);

    const [form, setForm] = useState(initialForm);

    const [platforms, setPlatforms] = useState([]);

    const [loadingPlatforms, setLoadingPlatforms] = useState(false);
    const [loadingCustomer, setLoadingCustomer] = useState(false);
    const [saving, setSaving] = useState(false);

    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [showValidation, setShowValidation] = useState(false);
    const [showStep5BlockedModal, setShowStep5BlockedModal] = useState(false);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const canAddCustomer = hasPermission('customers.add');
    const canEditCustomer = hasPermission('customers.edit');

    const fieldErrors = {
        platform_id: showValidation && !form.platform_id ? 'Platform is required.' : '',
        username: !form.username.trim()
            ? (showValidation ? 'Username is required.' : '')
            : !/^[a-zA-Z0-9._-]+$/.test(form.username.trim())
            ? 'Username can contain only letters, numbers, dot, underscore and hyphen.'
            : '',
        primary_mobile_no: !form.primary_mobile_no
            ? (showValidation ? 'Primary mobile number is required.' : '')
            : !/^\d{10}$/.test(form.primary_mobile_no)
                ? 'Primary mobile number must contain exactly 10 digits.'
                : '',
        secondary_mobile_no: form.secondary_mobile_no && !/^\d{10}$/.test(form.secondary_mobile_no)
            ? 'Secondary mobile number must contain exactly 10 digits.'
            : form.secondary_mobile_no === form.primary_mobile_no
                ? 'Secondary mobile number cannot be the same as primary mobile number.'
                : '',
        email: form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)
            ? 'Please enter a valid email address.'
            : '',
        location: showValidation && !form.location.trim() ? 'Location is required.' : '',
        pincode: !form.pincode
            ? (showValidation ? 'Pincode is required.' : '')
            : !/^\d{6}$/.test(form.pincode)
                ? 'Pincode must contain exactly 6 digits.'
                : ''
    };

    const storageKey = customerId
        ? `customer_creation_${customerId}`
        : 'customer_creation_new';

    /*
    |--------------------------------------------------------------------------
    | LOAD PLATFORMS
    |--------------------------------------------------------------------------
    */

    const fetchPlatforms = async () => {
        try {
            setLoadingPlatforms(true);
            setError('');

            const response = await api.get('/platforms/list.php');

            if (!response.data?.success) {
                throw new Error(
                    response.data?.message ||
                    'Failed to load platforms.'
                );
            }

            const platformList =
                response.data?.data?.platforms ||
                response.data?.platforms ||
                response.data?.data ||
                [];

            const activePlatforms = Array.isArray(platformList)
                ? platformList.filter((item) => {
                    if (
                        item?.status === undefined ||
                        item?.status === null ||
                        item?.status === ''
                    ) {
                        return true;
                    }

                    return String(item.status).toLowerCase() === 'active';
                })
                : [];

            setPlatforms(activePlatforms);
        } catch (err) {
            console.error('Platform loading error:', err);

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load platforms.'
            );
        } finally {
            setLoadingPlatforms(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | RESTORE SESSION DATA
    |--------------------------------------------------------------------------
    */

    const restoreSessionData = () => {
        try {
            const stored = sessionStorage.getItem(storageKey);

            if (!stored) {
                return false;
            }

            const parsed = JSON.parse(stored);

            if (!parsed?.step1) {
                return false;
            }

            setForm({
                ...initialForm,
                ...parsed.step1
            });

            return true;
        } catch (err) {
            console.error(
                'Session restore error:',
                err
            );

            return false;
        }
    };

    /*
    |--------------------------------------------------------------------------
    | LOAD CUSTOMER FOR EDIT
    |--------------------------------------------------------------------------
    */

const loadCustomer = async () => {
    if (!customerId) {
        setForm(initialForm);
        return;
    }
    // Fetch fresh data from the backend API.
    try {
        setLoadingCustomer(true);
        setError('');
        const response = await api.get(`/customers/details.php?customer_id=${encodeURIComponent(customerId)}`);
        if (!response.data?.success) {
            throw new Error(response.data?.message || 'Failed to load customer.');
        }
        const customer =
            response.data?.data?.customer ||
            response.data?.customer ||
            null;
        if (!customer) {
            throw new Error('Customer details not found.');
        }
        const restoredForm = {
            platform_id: String(customer.platform_id ?? customer.platformId ?? ''),
            username: customer.username ?? '',
            primary_mobile_no: customer.primary_mobile_no ?? customer.mobile_no ?? '',
            secondary_mobile_no: customer.secondary_mobile_no ?? '',
            email: customer.email ?? '',
            location: customer.location ?? '',
            pincode: customer.pincode ?? ''
        };
        setForm(restoredForm);
        // Merge with any existing session data and persist for later steps.
        const existing = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
        sessionStorage.setItem(
            storageKey,
            JSON.stringify({
                ...existing,
                customer_id: Number(customerId),
                step1: restoredForm
            })
        );
    } catch (err) {
        console.error('Error loading customer details:', err);
        triggerError(
            err.response?.data?.message ||
            err.message ||
            'Failed to load customer details.'
        );
    } finally {
        setLoadingCustomer(false);
    }
};

    /*
    |--------------------------------------------------------------------------
    | INITIAL LOAD
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        fetchPlatforms();

        if (isEditMode) {
            loadCustomer();
        } else {
            const restored = restoreSessionData();

            if (!restored) {
                setForm(initialForm);
            }
        }
    }, [customerId]);

    /*
    |--------------------------------------------------------------------------
    | CHANGE
    |--------------------------------------------------------------------------
    */

    const handleChange = (event) => {
        const {
            name,
            value
        } = event.target;

        let updatedValue = value;

        if (
            name === 'primary_mobile_no' ||
            name === 'secondary_mobile_no'
        ) {
            updatedValue = value
                .replace(/\D/g, '')
                .slice(0, 10);
        }

        if (name === 'pincode') {
            updatedValue = value
                .replace(/\D/g, '')
                .slice(0, 6);
        }

        if (name === 'username') {
            updatedValue = value
                .replace(/\s/g, '')
                .slice(0, 100);
        }

        setForm((previous) => ({
            ...previous,
            [name]: updatedValue
        }));

        setError('');
        setSuccess('');
    };

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    const validateForm = () => {
        if (!form.platform_id) return 'Please select a platform.';
        if (!form.username.trim()) return 'Username is required.';
        if (!form.primary_mobile_no) return 'Primary mobile number is required.';
        if (!form.location.trim()) return 'Location is required.';
        if (!form.pincode) return 'Pincode is required.';
        if (fieldErrors.platform_id) return fieldErrors.platform_id;

        if (fieldErrors.username) return fieldErrors.username;

        if (
            !/^[a-zA-Z0-9._-]+$/.test(
                form.username.trim()
            )
        ) {
            return (
                'Username can contain only letters, ' +
                'numbers, dot, underscore and hyphen.'
            );
        }

        if (fieldErrors.primary_mobile_no) return fieldErrors.primary_mobile_no;

        if (
            !/^\d{10}$/.test(
                form.primary_mobile_no
            )
        ) {
            return (
                'Primary mobile number must contain exactly 10 digits.'
            );
        }

        if (fieldErrors.secondary_mobile_no) return fieldErrors.secondary_mobile_no;

        if (fieldErrors.email) return fieldErrors.email;

        if (fieldErrors.location) return fieldErrors.location;

        if (fieldErrors.pincode) return fieldErrors.pincode;

        return '';
    };

    /*
    |--------------------------------------------------------------------------
    | SAVE STEP 1 TO SESSION
    |--------------------------------------------------------------------------
    */

    const saveStep1Session = (id) => {
        try {
            const existing = JSON.parse(
                sessionStorage.getItem(
                    `customer_creation_${id}`
                ) || '{}'
            );

            sessionStorage.setItem(
                `customer_creation_${id}`,
                JSON.stringify({
                    ...existing,
                    customer_id: Number(id),
                    step1: {
                        platform_id: Number(
                            form.platform_id
                        ),
                        username:
                            form.username.trim(),
                        primary_mobile_no:
                            form.primary_mobile_no.trim(),
                        secondary_mobile_no:
                            form.secondary_mobile_no.trim(),
                        email:
                            form.email.trim(),
                        location:
                            form.location.trim(),
                        pincode:
                            form.pincode.trim()
                    }
                })
            );
        } catch (err) {
            console.error(
                'Session save error:',
                err
            );
        }
    };

    /*
    |--------------------------------------------------------------------------
    | SAVE & NEXT / UPDATE & NEXT
    |--------------------------------------------------------------------------
    */

    const handleSaveAndNext = async (event) => {
        event.preventDefault();
        setShowValidation(true);

        setError('');
        setSuccess('');

        const validationError =
            validateForm();

        if (validationError) {
            triggerError(validationError);
            return;
        }

        if (
            !isEditMode &&
            !canAddCustomer
        ) {
            triggerError(
                'You do not have permission to add customers.'
            );

            return;
        }

        if (
            isEditMode &&
            !canEditCustomer
        ) {
            triggerError(
                'You do not have permission to edit customers.'
            );

            return;
        }

        try {
            setSaving(true);

            const payload = {
                platform_id: Number(
                    form.platform_id
                ),

                username:
                    form.username.trim(),

                primary_mobile_no:
                    form.primary_mobile_no.trim(),

                secondary_mobile_no:
                    form.secondary_mobile_no.trim(),

                email:
                    form.email.trim(),

                location:
                    form.location.trim(),

                pincode:
                    form.pincode.trim()
            };

            let response;

            /*
            |--------------------------------------------------------------------------
            | EDIT
            |--------------------------------------------------------------------------
            */

            if (isEditMode) {
                response = await api.put(
                    '/customers/update.php',
                    {
                        id: Number(customerId),
                        ...payload
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE
            |--------------------------------------------------------------------------
            */

            else {
                response = await api.post(
                    '/customers/create.php',
                    payload
                );
            }

            if (!response.data?.success) {
                throw new Error(
                    response.data?.message ||
                    'Failed to save customer details.'
                );
            }

            const savedId = Number(
                customerId ||
                response.data?.data?.customer_id ||
                response.data?.data?.id
            );

            if (!savedId) {
                throw new Error(
                    'Customer ID was not returned by the server.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | PRESERVE STEP 1
            |--------------------------------------------------------------------------
            */

            saveStep1Session(savedId);

            setSuccess(
                isEditMode
                    ? 'Customer details updated successfully.'
                    : 'Customer details saved successfully.'
            );

            /*
            |--------------------------------------------------------------------------
            | NEXT STEP
            |--------------------------------------------------------------------------
            */

            setTimeout(() => {
                navigate(
                    `/customer-management/details/vehicle/${savedId}`
                );
            }, 250);
        } catch (err) {
            console.error(
                'Customer save error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save customer details.'
            );
        } finally {
            setSaving(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | RESET
    |--------------------------------------------------------------------------
    */

    const handleReset = () => {
        const confirmed = window.confirm(
            'Are you sure you want to reset the customer details?'
        );

        if (!confirmed) {
            return;
        }

        setForm(initialForm);

        setError('');
        setSuccess('');

        try {
            sessionStorage.removeItem(
                storageKey
            );
        } catch (err) {
            console.error(
                'Session cleanup error:',
                err
            );
        }
    };

    /*
    |--------------------------------------------------------------------------
    | BACK
    |--------------------------------------------------------------------------
    */

    const handleBack = () => {
        navigate(
            '/customer-management/details'
        );
    };

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */

    return (
        <div className="page-container">

            {/* HEADER */}

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
                    <h2>
                        Customer Details
                    </h2>

                    <p
                        className="page-subtitle"
                        style={{
                            marginTop: '4px'
                        }}
                    >
                        {isEditMode
                            ? 'Edit customer information'
                            : 'Create a new customer'}
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

            {/* STEPPER */}

            <div
                className="card customer-step-card"
                style={{
                    marginBottom: '20px'
                }}
            >
                <div className="customer-stepper">

                    {/* STEP 1 */}

                    <div
                        className="customer-step active"
                        style={{ cursor: isEditMode ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (isEditMode && customerId) {
                                navigate(`/customer-management/details/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">
                            1
                        </div>

                        <span>
                            Customer Details
                        </span>
                    </div>

                    <div className="step-line" />

                    {/* STEP 2 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: isEditMode ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (isEditMode && customerId) {
                                navigate(`/customer-management/details/vehicle/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">
                            2
                        </div>

                        <span>
                            Vehicle Details
                        </span>
                    </div>

                    <div className="step-line" />

                    {/* STEP 3 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: isEditMode ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (isEditMode && customerId) {
                                navigate(`/customer-management/details/installation/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">
                            3
                        </div>

                        <span>
                            Installation
                        </span>
                    </div>

                    <div className="step-line" />

                    {/* STEP 4 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: isEditMode ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (isEditMode && customerId) {
                                navigate(`/customer-management/details/payment/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">
                            4
                        </div>

                        <span>
                            Payment
                        </span>
                    </div>

                    <div className="step-line" />

                    {/* STEP 5 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: isEditMode ? 'pointer' : 'default' }}
                        onClick={async () => {
                            if (isEditMode && customerId) {
                                try {
                                    const res = await api.get(
                                        `/customers/details.php?customer_id=${encodeURIComponent(customerId)}`
                                    );
                                    const pp1 = res.data?.data?.payment_part1 || {};
                                    const mode = pp1.payment_mode || '';
                                    const txn = pp1.transaction_id || '';
                                    const hasMode = Boolean(mode);
                                    const needsTxn = hasMode && mode !== 'Cash' && !txn;
                                    const txnInvalid = txn && !/^\d{6}$/.test(txn);

                                    if (!hasMode || needsTxn || txnInvalid) {
                                        setShowStep5BlockedModal(true);
                                        return;
                                    }
                                } catch {
                                    setShowStep5BlockedModal(true);
                                    return;
                                }

                                navigate(`/customer-management/details/payment-details/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">
                            5
                        </div>

                        <span>
                            Payment Details
                        </span>
                    </div>

                </div>
            </div>

            {/* ERROR */}

            {error && (
                <div
                    className="alert alert-danger"
                    style={{
                        marginBottom: '16px'
                    }}
                >
                    {error}
                </div>
            )}

            {/* SUCCESS */}

            {success && (
                <div
                    className="alert alert-success"
                    style={{
                        marginBottom: '16px'
                    }}
                >
                    {success}
                </div>
            )}

            {/* FORM CARD */}

            <div className="card">

                <div className="customer-section-title">
                    <UserRound size={20} />

                    <h3>
                        Basic Customer Information
                    </h3>
                </div>

                {loadingCustomer ? (
                    <div
                        style={{
                            padding: '45px',
                            textAlign: 'center',
                            color: '#64748b'
                        }}
                    >
                        Loading customer details...
                    </div>
                ) : (
                    <form
                        onSubmit={
                            handleSaveAndNext
                        }
                    >
                        <div className="customer-vehicle-grid">

                            {/* PLATFORM */}

                            <div className="form-group">

                                <label className="form-label">
                                    Platform
                                    <span className="required">
                                        *
                                    </span>
                                </label>

                                <select
                                    name="platform_id"
                                    value={
                                        form.platform_id
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    disabled={
                                        loadingPlatforms ||
                                        saving
                                    }
                                >
                                    <option value="">
                                        {loadingPlatforms
                                            ? 'Loading platforms...'
                                            : 'Select Platform'}
                                    </option>

                                    {platforms.map(
                                        (platform) => (
                                            <option
                                                key={
                                                    platform.id
                                                }
                                                value={
                                                    platform.id
                                                }
                                            >
                                                {
                                                    platform.platform_name
                                                }
                                            </option>
                                        )
                                    )}
                                </select>
                                {fieldErrors.platform_id && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.platform_id}</small>}

                            </div>

                            {/* USERNAME */}

                            <div className="form-group">

                                <label className="form-label">
                                    Username
                                    <span className="required">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    name="username"
                                    value={
                                        form.username
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter username"
                                    maxLength={100}
                                    disabled={saving}
                                />
                                {fieldErrors.username && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.username}</small>}

                            </div>

                            {/* PRIMARY MOBILE */}

                            <div className="form-group">

                                <label className="form-label">
                                    Primary Mobile No
                                    <span className="required">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="primary_mobile_no"
                                    value={
                                        form.primary_mobile_no
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter 10 digit mobile number"
                                    maxLength={10}
                                    disabled={saving}
                                />
                                {fieldErrors.primary_mobile_no && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.primary_mobile_no}</small>}

                            </div>

                            {/* SECONDARY MOBILE */}

                            <div className="form-group">

                                <label className="form-label">
                                    Secondary Mobile No
                                </label>

                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="secondary_mobile_no"
                                    value={
                                        form.secondary_mobile_no
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter secondary mobile number"
                                    maxLength={10}
                                    disabled={saving}
                                />
                                {fieldErrors.secondary_mobile_no && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.secondary_mobile_no}</small>}

                            </div>

                            {/* EMAIL */}

                            <div className="form-group">

                                <label className="form-label">
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    value={
                                        form.email
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter email address"
                                    maxLength={150}
                                    disabled={saving}
                                />
                                {fieldErrors.email && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.email}</small>}

                            </div>

                            {/* LOCATION */}

                            <div className="form-group">

                                <label className="form-label">
                                    Location
                                    <span className="required">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    name="location"
                                    value={
                                        form.location
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter location"
                                    maxLength={150}
                                    disabled={saving}
                                />
                                {fieldErrors.location && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.location}</small>}

                            </div>

                            {/* PINCODE */}

                            <div className="form-group">

                                <label className="form-label">
                                    Pincode
                                    <span className="required">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="pincode"
                                    value={
                                        form.pincode
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter 6 digit pincode"
                                    maxLength={6}
                                    disabled={saving}
                                />
                                {fieldErrors.pincode && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {fieldErrors.pincode}</small>}

                            </div>

                        </div>

                        {/* FOOTER */}

                        <div
                            className="customer-form-actions"
                        >

                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={
                                    handleReset
                                }
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
                                    loadingPlatforms ||
                                    loadingCustomer ||
                                    Object.values(fieldErrors).some(Boolean) ||
                                    (
                                        isEditMode
                                            ? !canEditCustomer
                                            : !canAddCustomer
                                    )
                                }
                            >
                                {saving
                                    ? 'Saving...'
                                    : isEditMode
                                        ? 'Update & Next'
                                        : 'Save & Next'}

                                {!saving && (
                                    <ArrowRight
                                        size={16}
                                    />
                                )}
                            </button>

                        </div>
                    </form>
                )}

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

        </div>
    );
};

export default CustomerCreatePage;