import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
    ArrowLeft,
    ArrowRight,
    RefreshCw,
    ClipboardList
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const initialForm = {
    installation_person_type: '',
    installation_person_id: '',
    lead_closure_id: '',
    installation_date: ''
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

const InstallationDetailsPage = () => {
    const navigate = useNavigate();
    const { customerId } = useParams();
    const { hasPermission } = useAuth();

    const [form, setForm] = useState(initialForm);

    const [technicians, setTechnicians] = useState([]);
    const [onsiteDealers, setOnsiteDealers] = useState([]);
    const [offsiteDealers, setOffsiteDealers] = useState([]);
    const [leadClosures, setLeadClosures] = useState([]);

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

    const isEditMode = Boolean(customerId);

    const canAddCustomer =
        hasPermission('customers.add');

    const canEditCustomer =
        hasPermission('customers.edit');

    const hasPermissionForAction = isEditMode
        ? canEditCustomer
        : canAddCustomer;

    const storageKey = customerId
        ? `customer_creation_${customerId}`
        : 'customer_creation_new';

    /*
     * ---------------------------------------------------------
     * Load Technician, Dealer and Lead Closure masters
     * ---------------------------------------------------------
     */
    const loadMasters = async () => {
        try {
            setLoading(true);
            setError('');

            const [
                techniciansResponse,
                dealersResponse,
                leadClosuresResponse
            ] = await Promise.all([
                api.get('/technicians/list.php'),
                api.get('/dealers/list.php'),
                api.get('/lead_closures/list.php')
            ]);

            const techniciansData = extractList(
                techniciansResponse,
                ['technicians']
            );

            const dealersData = extractList(
                dealersResponse,
                ['dealers']
            );

            const leadClosuresData = extractList(
                leadClosuresResponse,
                ['lead_closures']
            );

            /*
             * Technicians table does NOT have a status column.
             * Therefore do NOT filter by status.
             */
            const normalizedTechnicians =
                techniciansData
                    .filter((item) => item?.id)
                    .map((item) => ({
                        ...item,
                        id: Number(item.id),
                        type: 'Technician',
                        displayName:
                            item.technician_name ||
                            `Technician #${item.id}`
                    }));

            /*
             * Dealers:
             *
             * Onsite Dealer
             *     installation_status = Onsite
             *
             * Offsite Dealer
             *     installation_status = Offsite
             *
             * Not Willing
             *     never show
             */
            const validDealers = dealersData
                .filter((item) => item?.id);

            const normalizedOnsiteDealers =
                validDealers
                    .filter(
                        (item) =>
                            String(
                                item.installation_status || ''
                            )
                                .trim()
                                .toLowerCase() ===
                            'onsite'
                    )
                    .map((item) => ({
                        ...item,
                        id: Number(item.id),
                        type: 'Onsite Dealer',
                        displayName:
                            item.dealer_name ||
                            `Dealer #${item.id}`
                    }));

            const normalizedOffsiteDealers =
                validDealers
                    .filter(
                        (item) =>
                            String(
                                item.installation_status || ''
                            )
                                .trim()
                                .toLowerCase() ===
                            'offsite'
                    )
                    .map((item) => ({
                        ...item,
                        id: Number(item.id),
                        type: 'Offsite Dealer',
                        displayName:
                            item.dealer_name ||
                            `Dealer #${item.id}`
                    }));

            /*
             * Lead Closure master.
             * Existing endpoint may return status.
             * If status is missing, keep the record.
             */
            const normalizedLeadClosures =
                leadClosuresData.filter((item) => {
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
                        String(item.status)
                            .trim()
                            .toLowerCase() ===
                        'active'
                    );
                });

            setTechnicians(
                normalizedTechnicians
            );

            setOnsiteDealers(
                normalizedOnsiteDealers
            );

            setOffsiteDealers(
                normalizedOffsiteDealers
            );

            setLeadClosures(
                normalizedLeadClosures
            );
        } catch (err) {
            console.error(
                'Installation master loading error:',
                err
            );

            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load installation details data.'
            );
        } finally {
            setLoading(false);
        }
    };

    /*
     * ---------------------------------------------------------
     * Load existing installation details
     * ---------------------------------------------------------
     */
    const loadSavedInstallationData = async () => {
        if (!customerId) {
            setForm(initialForm);
            return;
        }

        try {
            const stored = JSON.parse(
                sessionStorage.getItem(
                    storageKey
                ) || '{}'
            );

            /*
             * Restore from session first.
             */
            /*
             * Otherwise fetch from backend.
             */
            const response = await api.get(
                `/customers/details.php?customer_id=${encodeURIComponent(
                    customerId
                )}`
            );

            if (!response.data?.success) {
                return;
            }

            const installation =
                response.data?.data?.installation ||
                {};
            const vehicle = response.data?.data?.vehicle || {};
            const selectedOwnerType = vehicle.device_owner_type || vehicle.sim_owner_type;
            const selectedOwnerId = vehicle.device_owner_id || vehicle.sim_owner_id;
            const ownerInstallationStatus = vehicle.device_owner_installation_status || vehicle.sim_owner_installation_status;
            const ownerType = selectedOwnerType === 'technician'
                ? 'Technician'
                : selectedOwnerType === 'dealer'
                    ? ownerInstallationStatus === 'Offsite' ? 'Offsite Dealer' : 'Onsite Dealer'
                    : '';

            const nextForm = {
                installation_person_type:
                    stored.step3?.installation_person_type ||
                    installation.installation_person_type ||
                    ownerType ||
                    '',
                installation_person_id:
                    stored.step3?.installation_person_id ||
                    installation.installation_person_id ||
                    (ownerType && selectedOwnerId ? String(selectedOwnerId) : '') ||
                    '',
                lead_closure_id:
                    stored.step3?.lead_closure_id ||
                    installation.lead_closure_id ||
                    '',
                installation_date:
                    stored.step3?.installation_date ||
                    installation.installation_date ||
                    ''
            };

            setForm(nextForm);

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    ...stored,
                    customer_id:
                        Number(customerId),
                    step3: nextForm
                })
            );
        } catch (err) {
            console.error(
                'Saved installation data loading error:',
                err
            );
        }
    };

    useEffect(() => {
        loadMasters();
        loadSavedInstallationData();
    }, [customerId]);

    /*
     * ---------------------------------------------------------
     * Change handlers
     * ---------------------------------------------------------
     */
    const handleInstallationTypeChange = (event) => {
        const value = event.target.value;
        setForm((previous) => ({
            ...previous,
            installation_person_type: value,
            installation_person_id: ''
        }));
        setError('');
        setSuccess('');
    };

    const handleInstallationPersonChange = (event) => {
        const value = event.target.value;
        setForm((previous) => ({
            ...previous,
            installation_person_id: value
        }));
        setError('');
        setSuccess('');
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((previous) => ({
            ...previous,
            [name]: value
        }));
        setError('');
        setSuccess('');
    };

    /*
     * ---------------------------------------------------------
     * Person label & placeholder helpers
     * ---------------------------------------------------------
     */
    const getPersonLabel = () => {
        if (form.installation_person_type === 'Technician') return 'Technician';
        if (form.installation_person_type === 'Onsite Dealer') return 'Onsite Dealer';
        if (form.installation_person_type === 'Offsite Dealer') return 'Offsite Dealer';
        return 'Installation Person';
    };

    const getPersonPlaceholder = () => {
        if (loading) return 'Loading installation persons...';
        if (form.installation_person_type === 'Technician') return 'Select Technician';
        if (form.installation_person_type === 'Onsite Dealer') return 'Select Onsite Dealer';
        if (form.installation_person_type === 'Offsite Dealer') return 'Select Offsite Dealer';
        return 'Select Installation Person';
    };

    const selectedPersonList =
        form.installation_person_type === 'Technician'
            ? technicians
            : form.installation_person_type === 'Onsite Dealer'
            ? onsiteDealers
            : form.installation_person_type === 'Offsite Dealer'
            ? offsiteDealers
            : [];

    /*
     * ---------------------------------------------------------
     * Form validation
     * ---------------------------------------------------------
     */
    const validateForm = () => {
        if (!form.installation_person_type) {
            return 'Installation Type is required.';
        }

        if (!form.installation_person_id) {
            return `${getPersonLabel()} is required.`;
        }

        if (!form.installation_date) {
            return 'Installation Date is required.';
        }

        if (!form.lead_closure_id) {
            return 'Lead Closure By is required.';
        }

        const [year, month, day] = form.installation_date
            .split('-')
            .map(Number);

        const selectedDate = new Date(year, month - 1, day);
        const today = new Date();

        selectedDate.setHours(0, 0, 0, 0);
        today.setHours(0, 0, 0, 0);

        if (
            selectedDate.getFullYear() !== year ||
            selectedDate.getMonth() !== month - 1 ||
            selectedDate.getDate() !== day
        ) {
            return 'Invalid Installation Date.';
        }

        if (selectedDate > today) {
            return 'Future dates are not allowed.';
        }

        return '';
    };

    const installationErrors = {
        installation_person_type:
            showValidation && !form.installation_person_type
                ? 'Installation Type is required.'
                : '',
        installation_person_id:
            showValidation && !form.installation_person_id
                ? `${getPersonLabel()} is required.`
                : '',
        installation_date:
            showValidation && !form.installation_date
                ? 'Installation Date is required.'
                : '',
        lead_closure_id:
            showValidation && !form.lead_closure_id
                ? 'Lead Closure By is required.'
                : ''
    };

    /*
     * ---------------------------------------------------------
     * Save Step 3 to session
     * ---------------------------------------------------------
     */
    const saveStep3ToSession = () => {
        try {
            const existing = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    ...existing,
                    customer_id: Number(customerId),
                    step3: {
                        installation_person_type: form.installation_person_type,
                        installation_person_id: Number(form.installation_person_id),
                        lead_closure_id: Number(form.lead_closure_id),
                        installation_date: form.installation_date
                    }
                })
            );
        } catch (err) {
            console.error('Session storage error:', err);
        }
    };

    /*
     * ---------------------------------------------------------
     * Save / Update Installation
     * ---------------------------------------------------------
     */
    const handleSaveAndNext = async (event) => {
        event.preventDefault();
        setShowValidation(true);

        setError('');
        setSuccess('');

        if (!customerId) {
            triggerError('Customer ID is missing.');
            return;
        }

        if (!hasPermissionForAction) {
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

        try {
            setSaving(true);

            const payload = {
                customer_id: Number(customerId),
                installation_person_type: form.installation_person_type,
                installation_person_id: Number(form.installation_person_id),
                lead_closure_id: Number(form.lead_closure_id),
                installation_date: form.installation_date
            };

            const response = await api.post(
                '/customers/installations/create.php',
                payload
            );

            if (!response.data?.success) {
                throw new Error(
                    response.data?.message ||
                    'Failed to save installation details.'
                );
            }

            saveStep3ToSession();

            setSuccess(
                isEditMode
                    ? 'Installation details updated successfully.'
                    : 'Installation details saved successfully.'
            );

            setTimeout(() => {
                navigate(
                    `/customer-management/details/payment/${customerId}`
                );
            }, 300);
        } catch (err) {
            console.error('Installation save error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save installation details.'
            );
        } finally {
            setSaving(false);
        }
    };

    /*
     * ---------------------------------------------------------
     * Reset
     * ---------------------------------------------------------
     */
    const handleReset = () => {
        const confirmed = window.confirm(
            'Are you sure you want to reset the installation details?'
        );

        if (!confirmed) {
            return;
        }

        setForm(initialForm);
        setShowValidation(false);
        setError('');
        setSuccess('');

        try {
            const existing = JSON.parse(
                sessionStorage.getItem(storageKey) || '{}'
            );

            delete existing.step3;

            sessionStorage.setItem(
                storageKey,
                JSON.stringify(existing)
            );
        } catch (err) {
            console.error('Reset session error:', err);
        }
    };

    /*
     * ---------------------------------------------------------
     * Back
     * ---------------------------------------------------------
     */
    const handleBack = () => {
        saveStep3ToSession();
        navigate(
            `/customer-management/details/vehicle/${customerId}`
        );
    };

    return (
        <div className="page-container">

            {/* Header */}
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
                    <h2>Installation Details</h2>
                    <p
                        className="page-subtitle"
                        style={{ marginTop: '4px' }}
                    >
                        {isEditMode
                            ? 'Update installation information'
                            : 'Add installation information'}
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

            {/* Stepper */}
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
                        className="customer-step active"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/installation/${customerId}`);
                            }
                        }}
                    >
                        <div className="step-circle">3</div>
                        <span>Installation</span>
                    </div>

                    <div className="step-line" />

                    <div
                        className="customer-step disabled"
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
                        onClick={async () => {
                            if (customerId) {
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
                        <div className="step-circle">5</div>
                        <span>Payment Details</span>
                    </div>
                </div>
            </div>

            {/* Error */}
            {error && (
                <div
                    className="alert alert-danger"
                    style={{ marginBottom: '16px' }}
                >
                    {error}
                </div>
            )}

            {/* Success */}
            {success && (
                <div
                    className="alert alert-success"
                    style={{ marginBottom: '16px' }}
                >
                    {success}
                </div>
            )}

            {/* Main Card */}
            <div className="card">
                <div className="customer-section-title">
                    <ClipboardList size={20} />
                    <h3>Installation Information</h3>
                </div>

                <form onSubmit={handleSaveAndNext}>
                    <div className="customer-vehicle-grid">

                        {/* ROW 1 LEFT: Installation Type */}
                        <div className="form-group">
                            <label className="form-label">
                                Installation Type
                                <span className="required">*</span>
                            </label>
                            <select
                                name="installation_person_type"
                                value={form.installation_person_type}
                                onChange={handleInstallationTypeChange}
                                className="form-control"
                                disabled={loading || saving}
                            >
                                <option value="">Select Installation Type</option>
                                <option value="Technician">Technician</option>
                                <option value="Onsite Dealer">Onsite Dealer</option>
                                <option value="Offsite Dealer">Offsite Dealer</option>
                            </select>
                            {installationErrors.installation_person_type && (
                                <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>
                                    ✕ {installationErrors.installation_person_type}
                                </small>
                            )}
                        </div>

                        {/* ROW 1 RIGHT: Installation Person */}
                        <div className="form-group">
                            <label className="form-label">
                                {getPersonLabel()}
                                <span className="required">*</span>
                            </label>
                            <select
                                name="installation_person_id"
                                value={form.installation_person_id}
                                onChange={handleInstallationPersonChange}
                                className="form-control"
                                disabled={loading || saving}
                            >
                                <option value="">
                                    {getPersonPlaceholder()}
                                </option>
                                {selectedPersonList.map((item) => (
                                    <option
                                        key={`${form.installation_person_type}-${item.id}`}
                                        value={item.id}
                                    >
                                        {item.displayName}
                                    </option>
                                ))}
                                {form.installation_person_id &&
                                    !selectedPersonList.some(
                                        (item) => String(item.id) === String(form.installation_person_id)
                                    ) && (
                                        <option value={form.installation_person_id}>
                                            {getPersonLabel()} #{form.installation_person_id}
                                        </option>
                                    )}
                            </select>
                            {installationErrors.installation_person_id && (
                                <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>
                                    ✕ {installationErrors.installation_person_id}
                                </small>
                            )}
                        </div>

                        {/* ROW 2 LEFT: Installation Date */}
                        <div className="form-group">
                            <label className="form-label">
                                Installation Date
                                <span className="required">*</span>
                            </label>
                            <input
                                type="date"
                                name="installation_date"
                                value={form.installation_date}
                                onChange={handleChange}
                                className="form-control"
                                disabled={saving}
                                max={
                                    new Date()
                                        .toISOString()
                                        .split('T')[0]
                                }
                            />
                            {installationErrors.installation_date && (
                                <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>
                                    ✕ {installationErrors.installation_date}
                                </small>
                            )}
                        </div>

                        {/* ROW 2 RIGHT: Lead Closure By */}
                        <div className="form-group">
                            <label className="form-label">
                                Lead Closure By
                                <span className="required">*</span>
                            </label>
                            <select
                                name="lead_closure_id"
                                value={form.lead_closure_id}
                                onChange={handleChange}
                                className="form-control"
                                disabled={loading || saving}
                            >
                                <option value="">
                                    {loading
                                        ? 'Loading lead closures...'
                                        : 'Select Lead Closure By'}
                                </option>
                                {leadClosures.map((item) => (
                                    <option
                                        key={item.id}
                                        value={item.id}
                                    >
                                        {item.lead_closure_name}
                                    </option>
                                ))}
                            </select>
                            {installationErrors.lead_closure_id && (
                                <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>
                                    ✕ {installationErrors.lead_closure_id}
                                </small>
                            )}
                        </div>

                    </div>

                    {/* Actions */}
                    <div
                        className="customer-form-actions"
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'flex-end',
                            gap: '10px',
                            marginTop: '30px',
                            paddingTop: '20px',
                            borderTop: '1px solid #e5e7eb'
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
                                !hasPermissionForAction
                            }
                        >
                            {saving
                                ? isEditMode
                                    ? 'Updating...'
                                    : 'Saving...'
                                : isEditMode
                                ? 'Update & Next'
                                : 'Save & Next'}

                            {!saving && <ArrowRight size={16} />}
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

        </div>
    );
};

export default InstallationDetailsPage;