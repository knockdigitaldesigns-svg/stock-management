import { useCallback, useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import {
    ArrowLeft,
    ChevronDown,
    Plus,
    UserRound,
    CarFront,
    Wrench,
    Wallet,
    Trash2,
    LockKeyhole
} from 'lucide-react';

import api from '../../services/api';
import { showGlobalError } from '../../context/ErrorContext';
import { useAuth } from '../../context/AuthContext';
import { PAYMENT_MODES } from '../../constants/paymentModes';
import {
    commitCustomerSubmission,
    saveCustomerDraft
} from '../../utils/customerSubmission';
import {
    getCustomerFieldErrors,
    sanitizeCustomerField,
    validateCustomerForm
} from '../../utils/customerValidation';
import {
    installationTypes,
    isInstallationDetailsComplete,
    isInstallationPersonRequired,
    mergeVehicleDraft
} from '../../utils/installationValidation';
import { normalizeCurrency } from '../../utils/paymentValidation';

const sectionCardStyle = {
    background: '#fff',
    border: '1px solid #e5e7eb',
    borderRadius: '18px',
    padding: '20px',
    marginBottom: '20px',
    boxShadow: '0 2px 8px rgba(15, 23, 42, 0.04)'
};

const fieldStyle = {
    display: 'flex',
    flexDirection: 'column',
    gap: '6px',
    padding: '12px 14px',
    border: '1px solid #e5e7eb',
    borderRadius: '12px',
    background: '#f8fafc'
};

const inputStyle = {
    width: '100%',
    border: '1px solid #d1d5db',
    borderRadius: '10px',
    padding: '8px 10px',
    fontSize: '14px',
    background: '#fff'
};

const changedVehicleFieldCategories = {
    vehicle: new Set([
        'vehicle_no',
        'vehicle_type_id',
        'device_model_id',
        'imei_no',
        'sim_no_1',
        'sim_no_2',
        'validity_id'
    ]),
    installation: new Set([
        'vehicle_installation_type',
        'vehicle_installation_person_id',
        'vehicle_lead_closure_id',
        'vehicle_installation_date'
    ]),
    paymentPart1: new Set([
        'vehicle_total_sale_amount',
        'vehicle_transaction_id',
        'vehicle_payment_mode'
    ]),
    paymentPart2: new Set([
        'vehicle_device_charge',
        'vehicle_software_charge',
        'vehicle_technician_charge',
        'vehicle_sim_charge',
        'vehicle_courier_charge',
        'vehicle_total_amount',
        'vehicle_amount_paid',
        'vehicle_cash_recipient_type'
    ])
};

const renderValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return value;
};

const renderValidity = (months) => {
    if (months === null || months === undefined || months === '') {
        return '—';
    }
    const value = String(months);
    return value.toLowerCase().includes('month') ? value : `${value} Months`;
};

const getPaymentPart2Summary = (vehicle) => {
    const chargeTotal = [
        vehicle.vehicle_device_charge,
        vehicle.vehicle_software_charge,
        vehicle.vehicle_technician_charge,
        vehicle.vehicle_sim_charge,
        vehicle.vehicle_courier_charge
    ].reduce((total, charge) => total + normalizeCurrency(charge), 0);
    const amountPaid = normalizeCurrency(vehicle.vehicle_amount_paid);

    return {
        chargeTotal,
        amountPaid,
        amountPending: Math.max(chargeTotal - amountPaid, 0),
        paymentStatus: amountPaid <= 0
            ? 'Not Paid'
            : amountPaid < chargeTotal
                ? 'Partially Paid'
                : 'Paid'
    };
};

const hasCompletedPaymentPart2 = (vehicle) => {
    const totalSaleAmount = Number(vehicle.vehicle_total_sale_amount) || 0;
    const { chargeTotal } = getPaymentPart2Summary(vehicle);
    const totalAmount = Number(vehicle.vehicle_total_amount) || 0;

    return totalSaleAmount > 0
        && Math.abs(chargeTotal - totalSaleAmount) <= 0.005
        && Math.abs(totalAmount - totalSaleAmount) <= 0.005;
};

const EditField = ({ label, value, onChange, type = 'text', digitsOnly = false, min, max, step, maxLength, inputMode, id, error, onBlur }) => (
    <div style={fieldStyle}>
        <strong>{label}</strong>
        <input
            id={id}
            type={type}
            value={value ?? ''}
            onChange={(event) => onChange(digitsOnly ? event.target.value.replace(/\D/g, '').slice(0, 6) : event.target.value)}
            onBlur={onBlur}
            inputMode={digitsOnly ? 'numeric' : inputMode}
            maxLength={maxLength ?? (digitsOnly ? 6 : undefined)}
            pattern={digitsOnly ? '[0-9]{6}' : undefined}
            min={min}
            max={max}
            step={step}
            style={inputStyle}
        />
        {error && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {error}</small>}
    </div>
);

const EditSelect = ({ label, value, onChange, options, getValue, getLabel, placeholder = 'Select', id, error, onBlur }) => (
    <div style={fieldStyle}>
        <strong>{label}</strong>
        <select
            id={id}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            onBlur={onBlur}
            style={inputStyle}
        >
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={getValue(option)} value={getValue(option)}>
                    {getLabel(option)}
                </option>
            ))}
        </select>
        {error && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {error}</small>}
    </div>
);

const CustomerViewPage = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { customerId } = useParams();
    const { hasPermission } = useAuth();

    const isEditMode = location.pathname.endsWith('/edit');
    const canAddVehicle = hasPermission('customers.add');
    const canDeleteVehicle = hasPermission('customers.delete');

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [validationAttempted, setValidationAttempted] = useState(false);
    const [touchedFields, setTouchedFields] = useState({});
    const [assetValidationErrors, setAssetValidationErrors] = useState({});
    const [customerChanged, setCustomerChanged] = useState(false);
    const [changedVehicles, setChangedVehicles] = useState({});
    const [verificationDraftId, setVerificationDraftId] = useState(0);
    const [activeEditStep, setActiveEditStep] = useState(() => {
        const requestedStep = Number(new URLSearchParams(location.search).get('step'));
        return requestedStep >= 1 && requestedStep <= 5 ? requestedStep : 1;
    });
    const [savedPaymentModes, setSavedPaymentModes] = useState({});
    const [masterData, setMasterData] = useState({
        platforms: [],
        vehicleTypes: [],
        deviceTypes: [],
        validities: [],
        saleAmounts: [],
        technicians: [],
        dealers: [],
        leadClosures: []
    });

    const triggerError = useCallback((msg) => {
        setError(msg);
        showGlobalError(msg);
    }, []);

    const [customerData, setCustomerData] = useState({
        customer: null,
        vehicles: [],
        installation: null,
        payment_part1: null,
        payment_part2: null
    });
    const [expandedVehicleId, setExpandedVehicleId] = useState(null);
    const [selectedEditVehicleId, setSelectedEditVehicleId] = useState(null);
    const vehicles = customerData.vehicles || [];
    const selectedEditVehicle = vehicles.find(
        (vehicle) => String(vehicle.id) === String(selectedEditVehicleId)
    );

    const loadCustomerDetails = useCallback(async () => {
        if (!customerId) {
            setLoading(false);
            triggerError('Customer ID is missing.');
            return false;
        }

        try {
            setLoading(true);
            setError('');

            const response = await api.get(
                `/customers/details.php?customer_id=${encodeURIComponent(customerId)}`
            );

            if (!response.data?.success) {
                throw new Error(
                    response.data?.message || 'Failed to load customer details.'
                );
            }

            const data = response.data?.data || {};
            const vehicles = Array.isArray(data.vehicles)
                ? data.vehicles
                : data.vehicle
                    ? [data.vehicle]
                    : [];
            const resumeKey = `customer_edit_verification_${customerId}`;
            const savedDraft = sessionStorage.getItem(resumeKey);
            let resumedSubmission = null;
            if (savedDraft) {
                try {
                    resumedSubmission = JSON.parse(savedDraft);
                } catch (draftError) {
                    console.error('Unable to read resumed customer verification draft:', draftError);
                    sessionStorage.removeItem(resumeKey);
                }
            }
            const resumedData = resumedSubmission?.submission_data;
            const resumedVehicles = Array.isArray(resumedData?.vehicles) ? resumedData.vehicles : [];
            const hydratedVehicles = vehicles.map((vehicle) => {
                const savedVehicle = resumedVehicles.find(
                    (item) => Number(item.vehicle_record_id || item.id) === Number(vehicle.id)
                );
                return mergeVehicleDraft(vehicle, savedVehicle);
            });
            setCustomerData({
                customer: data.customer
                    ? {
                        ...data.customer,
                        status: data.customer.status || 'Active',
                        ...(resumedData?.customer || {}),
                        vehicles: hydratedVehicles
                    }
                    : null,
                vehicles: hydratedVehicles,
                installation: data.installation || null,
                payment_part1: data.payment_part1 || null,
                payment_part2: data.payment_part2 || null
            });
            setSelectedEditVehicleId((previous) => (
                hydratedVehicles.some((vehicle) => String(vehicle.id) === String(previous))
                    ? previous
                    : hydratedVehicles[0]?.id ?? null
            ));
            setSavedPaymentModes(Object.fromEntries(
                hydratedVehicles.map((vehicle) => [
                    vehicle.id,
                    Boolean(String(vehicle.vehicle_payment_mode || '').trim())
                ])
            ));
            const resumedChanges = Object.fromEntries(resumedVehicles.map((vehicle) => [
                vehicle.vehicle_record_id || vehicle.id,
                vehicle.changed || {}
            ]));
            setCustomerChanged(Boolean(resumedData?.customer_changed));
            setChangedVehicles(resumedChanges);
            setVerificationDraftId(Number(resumedSubmission?.id || 0));
            if (resumedSubmission) {
                sessionStorage.removeItem(resumeKey);
            }
            return true;
        } catch (err) {
            console.error('Customer view loading error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load customer details.'
            );
            return false;
        } finally {
            setLoading(false);
        }
    }, [customerId, triggerError]);

    useEffect(() => {
        loadCustomerDetails();
    }, [loadCustomerDetails]);

    useEffect(() => {
        if (!isEditMode) {
            return;
        }

        const loadMasters = async () => {
            try {
                const [
                    platformsResponse,
                    vehicleTypesResponse,
                    deviceTypesResponse,
                    validitiesResponse,
                    techniciansResponse,
                    dealersResponse,
                    leadClosuresResponse
                ] = await Promise.all([
                    api.get('/platforms/list.php'),
                    api.get('/vehicle_types/list.php'),
                    api.get('/device_types/list.php'),
                    api.get('/sim_validities/list.php'),
                    api.get('/technicians/list.php'),
                    api.get('/dealers/list.php'),
                    api.get('/lead_closures/list.php')
                ]);

                const list = (response, key) => response.data?.data?.[key] || [];
                setMasterData({
                    platforms: list(platformsResponse, 'platforms').filter((item) =>
                        item?.status === undefined ||
                        item?.status === null ||
                        item?.status === '' ||
                        String(item.status).toLowerCase() === 'active'
                    ),
                    vehicleTypes: list(vehicleTypesResponse, 'vehicle_types'),
                    deviceTypes: list(deviceTypesResponse, 'device_types'),
                    validities: list(validitiesResponse, 'validities'),
                    technicians: list(techniciansResponse, 'technicians'),
                    dealers: list(dealersResponse, 'dealers'),
                    leadClosures: list(leadClosuresResponse, 'lead_closures')
                });
                try {
                    const saleAmountsResponse = await api.get('/sale_amounts/list.php');
                    setMasterData((previous) => ({
                        ...previous,
                        saleAmounts: list(saleAmountsResponse, 'sale_amounts')
                    }));
                } catch (saleAmountError) {
                    console.error('Customer edit sale amount loading error:', saleAmountError);
                    triggerError(
                        saleAmountError.response?.data?.message ||
                        saleAmountError.message ||
                        'Failed to load sale amount options.'
                    );
                }
            } catch (err) {
                console.error('Customer edit master data loading error:', err);
                triggerError(
                    err.response?.data?.message ||
                    err.message ||
                    'Failed to load customer edit options.'
                );
            }
        };

        loadMasters();
    }, [isEditMode, triggerError]);

    const updateCustomerField = (field, value) => {
        setCustomerChanged(true);
        setError('');
        setCustomerData((previous) => ({
            ...previous,
            customer: {
                ...(previous.customer || {}),
                [field]: sanitizeCustomerField(field, value)
            }
        }));
    };

    const updateVehicleField = (vehicleId, field, value) => {
        let updatedValue = value;
        if (field === 'vehicle_no') updatedValue = value.toUpperCase().replace(/^\s+/, '').slice(0, 20);
        if (field === 'imei_no') updatedValue = value.replace(/\D/g, '').slice(0, 15);
        if (field === 'sim_no_1' || field === 'sim_no_2') updatedValue = value.replace(/\D/g, '').slice(0, 13);
        setError('');
        if (['imei_no', 'sim_no_1', 'sim_no_2'].includes(field)) {
            setAssetValidationErrors((previous) => ({ ...previous, [field]: '' }));
        }
        const category = Object.entries(changedVehicleFieldCategories)
            .find(([, fields]) => fields.has(field))?.[0];
        if (category) {
            setChangedVehicles((previous) => ({
                ...previous,
                [vehicleId]: {
                    ...previous[vehicleId],
                    [category]: true
                }
            }));
        }

        setCustomerData((previous) => ({
            ...previous,
            vehicles: previous.vehicles.map((vehicle) =>
                Number(vehicle.id) === Number(vehicleId)
                    ? { ...vehicle, [field]: updatedValue }
                    : vehicle
            )
        }));
    };

    const markFieldTouched = (field) => {
        setTouchedFields((previous) => ({ ...previous, [field]: true }));
    };

    const fieldPresentation = (field, validationErrors) => ({
        id: `edit-field-${field}`,
        error: validationAttempted || touchedFields[field]
            ? assetValidationErrors[field] || validationErrors[field] || ''
            : '',
        onBlur: () => markFieldTouched(field)
    });

    const getActiveStepErrors = () => {
        const customer = customerData.customer || {};
        const vehicle = selectedEditVehicle || {};
        if (activeEditStep === 1) {
            return getCustomerFieldErrors(customer, true, true);
        }
        if (activeEditStep === 2) {
            return {
                vehicle_no: String(vehicle.vehicle_no || '').trim() ? '' : 'Vehicle No is required.',
                vehicle_type_id: vehicle.vehicle_type_id ? '' : 'Please select Vehicle Type.',
                imei_no: !vehicle.imei_no
                    ? 'IMEI No is required.'
                    : !/^\d{15}$/.test(String(vehicle.imei_no))
                        ? 'IMEI No must contain exactly 15 digits.'
                        : '',
                sim_no_1: !vehicle.sim_no_1
                    ? 'SIM No 1 is required.'
                    : !/^(?:\d{10}|\d{13})$/.test(String(vehicle.sim_no_1))
                        ? 'SIM No 1 must contain exactly 10 or 13 digits.'
                        : '',
                sim_no_2: vehicle.sim_no_2 && !/^(?:\d{10}|\d{13})$/.test(String(vehicle.sim_no_2))
                    ? 'SIM No 2 must contain exactly 10 or 13 digits.'
                    : vehicle.sim_no_2 && vehicle.sim_no_2 === vehicle.sim_no_1
                        ? 'SIM No 1 and SIM No 2 cannot be the same.'
                        : '',
                device_model_id: vehicle.device_model_id ? '' : 'Please select Device Model.',
                validity_id: vehicle.validity_id ? '' : 'Please select Validity.'
            };
        }
        if (activeEditStep === 3) {
            const type = vehicle.vehicle_installation_type || '';
            const requiresInstallationPerson = isInstallationPersonRequired(type);
            const deviceOwner = vehicle.device_owner_type && vehicle.device_owner_id
                ? { type: String(vehicle.device_owner_type).toLowerCase(), id: Number(vehicle.device_owner_id) }
                : null;
            const simOwner = vehicle.sim_owner_type && vehicle.sim_owner_id
                ? { type: String(vehicle.sim_owner_type).toLowerCase(), id: Number(vehicle.sim_owner_id) }
                : null;
            const ownerMismatch = deviceOwner && simOwner
                && (deviceOwner.type !== simOwner.type || deviceOwner.id !== simOwner.id);
            const date = String(vehicle.vehicle_installation_date || '');
            let dateError = date ? '' : 'Installation Date is required.';
            if (date) {
                const [year, month, day] = date.split('-').map(Number);
                const selectedDate = new Date(year, month - 1, day);
                const today = new Date();
                selectedDate.setHours(0, 0, 0, 0);
                today.setHours(0, 0, 0, 0);
                if (
                    selectedDate.getFullYear() !== year ||
                    selectedDate.getMonth() !== month - 1 ||
                    selectedDate.getDate() !== day
                ) dateError = 'Invalid Installation Date.';
                else if (selectedDate > today) dateError = 'Future dates are not allowed.';
            }
            return {
                vehicle_installation_type: ownerMismatch
                    ? 'Device and SIM are allocated to different persons. Please use Stock Transfer to move them to the same person before installation.'
                    : type ? '' : 'Installation Type is required.',
                vehicle_installation_person_id: type && requiresInstallationPerson && !vehicle.vehicle_installation_person_id
                    ? `${type === 'Technician' ? 'Technician' : type} is required.`
                    : '',
                vehicle_installation_date: dateError,
                vehicle_lead_closure_id: vehicle.vehicle_lead_closure_id ? '' : 'Lead Closure By is required.'
            };
        }
        if (activeEditStep === 4) {
            const amount = Number(vehicle.vehicle_total_sale_amount);
            const transactionId = String(vehicle.vehicle_transaction_id || '');
            const paymentMode = String(vehicle.vehicle_payment_mode || '');
            return {
                vehicle_total_sale_amount: !vehicle.vehicle_total_sale_amount
                    ? 'Total Sale Amount is required.'
                    : !Number.isFinite(amount) || amount <= 0
                        ? 'Invalid Total Sale Amount.'
                        : '',
                vehicle_transaction_id: transactionId && !/^\d{6}$/.test(transactionId)
                    ? 'Transaction ID must contain exactly 6 digits.'
                        : '',
                vehicle_payment_mode: transactionId && !paymentMode
                        ? 'Payment Mode is required when Transaction ID is entered.'
                        : !paymentMode
                            ? 'Please select Payment Mode before continuing to Payment Part 2.'
                        : !transactionId && paymentMode && paymentMode !== 'Cash'
                            ? 'Transaction ID is required for this payment mode.'
                            : ''
            };
        }
        if (activeEditStep === 5) {
            const saleAmount = Number(vehicle.vehicle_total_sale_amount) || 0;
            const chargeFields = [
                ['vehicle_device_charge', 'Device Charge'],
                ['vehicle_software_charge', 'Software Charge'],
                ['vehicle_technician_charge', 'Technician Charge'],
                ['vehicle_sim_charge', 'SIM Charge'],
                ['vehicle_courier_charge', 'Courier Charge']
            ];
            const chargeTotal = chargeFields.reduce((total, [field]) => total + normalizeCurrency(vehicle[field]), 0);
            const amountPaid = normalizeCurrency(vehicle.vehicle_amount_paid);
            const saleLabel = `₹${saleAmount.toLocaleString('en-IN')}`;
            const totalMismatch = saleAmount > 0
                ? chargeTotal > saleAmount
                    ? `Total charges cannot exceed Total Sale Amount of ${saleLabel}.`
                    : Math.abs(chargeTotal - saleAmount) > 0.005
                        ? `Total charges must equal Total Sale Amount of ${saleLabel}.`
                        : ''
                : '';
            const errors = {
                vehicle_total_amount: saleAmount <= 0
                    ? 'Please complete Payment Details Part 1 first.'
                    : totalMismatch
            };
            Object.assign(errors, Object.fromEntries(chargeFields.map(([field, label]) => [
                field,
                saleAmount > 0 && normalizeCurrency(vehicle[field]) > saleAmount
                    ? `${label} cannot exceed Total Sale Amount of ${saleLabel}.`
                    : ''
            ])));
            errors.vehicle_amount_paid = vehicle.vehicle_payment_mode
                && (vehicle.vehicle_amount_paid === '' || vehicle.vehicle_amount_paid === null || vehicle.vehicle_amount_paid === undefined)
                    ? 'Please enter Amount Paid.'
                    : amountPaid < 0
                        ? 'Amount Paid cannot be negative.'
                        : amountPaid > chargeTotal
                            ? `Amount Paid cannot exceed Total Amount of ₹${chargeTotal.toLocaleString('en-IN')}.`
                            : '';
            return errors;
        }
        return {};
    };

    const getFirstActiveStepError = () => {
        if (activeEditStep === 1) {
            const customer = customerData.customer || {};
            const validationError = validateCustomerForm(customer, true);
            if (validationError) {
                const fieldError = Object.entries(getCustomerFieldErrors(customer, true, true))
                    .find(([, message]) => message === validationError);
                return fieldError || ['platform_id', validationError];
            }
            return null;
        }
        const errors = getActiveStepErrors();
        return Object.entries(errors).find(([, message]) => message);
    };

    const focusInvalidField = (field) => {
        const element = document.getElementById(`edit-field-${field}`);
        element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        element?.focus();
    };

    const validateVehicleAvailability = async () => {
        const vehicle = selectedEditVehicle;
        if (!vehicle) return {};
        const errors = {};
        const vehicleId = encodeURIComponent(vehicle.id);
        if (vehicle.imei_no) {
            try {
                const response = await api.get(
                    `/devices/get_by_imei.php?imei=${encodeURIComponent(vehicle.imei_no)}&customer_id=${encodeURIComponent(customerId)}&vehicle_id=${vehicleId}`
                );
                const device = response.data?.data || response.data?.device || {};
                if (!response.data?.success || !device.id) {
                    errors.imei_no = response.data?.message || 'IMEI not found in device stock.';
                } else {
                    const status = String(device.status || '').toLowerCase();
                    const ownerType = String(device.owner_type || '').toLowerCase();
                    const ownerStatus = String(device.owner_installation_status || '').toLowerCase();
                    const eligibleOwner = Number(device.owner_id) > 0 && (
                        ownerType === 'technician' ||
                        (ownerType === 'dealer' && ['onsite', 'offsite'].includes(ownerStatus))
                    );
                    if (device.is_assigned_to_another_customer) {
                        errors.imei_no = 'IMEI is already assigned to another customer.';
                    } else if (status === 'used' && !device.is_assigned_to_current_customer) {
                        errors.imei_no = 'IMEI is already used and cannot be assigned again.';
                    } else if (
                        !device.is_assigned_to_current_customer &&
                        status !== 'available' &&
                        !(status === 'allocated' && eligibleOwner)
                    ) {
                        errors.imei_no = 'This IMEI is not available for allocation.';
                    } else if (device.device_model_id && String(vehicle.device_model_id) !== String(device.device_model_id)) {
                        updateVehicleField(vehicle.id, 'device_model_id', String(device.device_model_id));
                    }
                }
            } catch (requestError) {
                errors.imei_no = requestError.response?.data?.message || requestError.message || 'IMEI not found in device stock.';
            }
        }

        for (const [field, label] of [['sim_no_1', 'SIM No 1'], ['sim_no_2', 'SIM No 2']]) {
            const simNo = String(vehicle[field] || '').trim();
            if (!simNo) continue;
            try {
                const response = await api.get(
                    `/sims/list.php?search=${encodeURIComponent(simNo)}&customer_id=${encodeURIComponent(customerId)}&vehicle_id=${vehicleId}`
                );
                const sims = response.data?.data?.sims || [];
                const sim = sims.find((item) => String(item.sim_no) === simNo);
                const status = String(sim?.status || '').toLowerCase();
                if (!sim) {
                    errors[field] = `${label} does not exist in SIM Maintenance.`;
                } else if (sim.is_assigned_to_current_customer && !sim.is_assigned_to_another_customer) {
                    continue;
                } else if (status === 'used') {
                    errors[field] = 'SIM is already used and cannot be assigned again.';
                } else if (sim.is_assigned_to_another_customer) {
                    errors[field] = 'SIM is already assigned to another customer.';
                } else {
                    const ownerType = String(sim.owner_type || '').toLowerCase();
                    const ownerStatus = String(sim.owner_installation_status || '').toLowerCase();
                    const eligibleOwner = Number(sim.owner_id) > 0 && (
                        ownerType === 'technician' ||
                        (ownerType === 'dealer' && ['onsite', 'offsite'].includes(ownerStatus))
                    );
                    if (status !== 'available' && !(status === 'allocated' && eligibleOwner)) {
                        errors[field] = `${label} is not available for allocation.`;
                    }
                }
            } catch {
                errors[field] = `${label} does not exist in SIM Maintenance.`;
            }
        }
        return errors;
    };

    const updatePaymentPart2Field = (vehicleId, field, value) => {
        setChangedVehicles((previous) => ({
            ...previous,
            [vehicleId]: {
                ...previous[vehicleId],
                paymentPart2: true
            }
        }));
        setCustomerData((previous) => ({
            ...previous,
            vehicles: previous.vehicles.map((vehicle) => {
                if (Number(vehicle.id) !== Number(vehicleId)) {
                    return vehicle;
                }

                const updatedVehicle = { ...vehicle, [field]: value };
                const summary = getPaymentPart2Summary(updatedVehicle);
                return {
                    ...updatedVehicle,
                    vehicle_total_amount: summary.chargeTotal,
                    vehicle_amount_pending: summary.amountPending,
                    vehicle_payment_status: summary.paymentStatus
                };
            })
        }));
    };

    const handleBack = () => {
        navigate('/customer-management/details', {
            state: { customerDetailsPage: location.state?.customerDetailsPage }
        });
    };

    const handleCancelEdit = () => {
        navigate(`/customer-management/details/${customerId}/view`, {
            state: { customerDetailsPage: location.state?.customerDetailsPage }
        });
    };

    const handleAddVehicle = () => {
        navigate(`/customer-management/details/vehicle/${customerId}?mode=add`);
    };

    const handleDeleteVehicle = async (vehicleId) => {
        if (!window.confirm('Are you sure you want to delete this vehicle?')) {
            return;
        }

        try {
            await api.delete('/customers/vehicle_details/delete.php', {
                data: { id: Number(vehicleId) }
            });
            await loadCustomerDetails();
        } catch (err) {
            console.error('Customer vehicle delete error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to delete vehicle details.'
            );
        }
    };

    const handleSave = async ({ advance = false, navigateToStep = null } = {}) => {
        const customer = customerData.customer || {};
        const shouldValidateStep = advance ||
            (navigateToStep !== null && navigateToStep > activeEditStep) ||
            (navigateToStep === null && activeEditStep === 5);
        if (shouldValidateStep) {
            setValidationAttempted(true);
            const firstError = getFirstActiveStepError();
            if (firstError) {
                triggerError(firstError[1]);
                focusInvalidField(firstError[0]);
                return false;
            }
            setAssetValidationErrors({});
            if (activeEditStep === 2) {
                setSaving(true);
                const errors = await validateVehicleAvailability();
                setSaving(false);
                setAssetValidationErrors(errors);
                const assetError = Object.entries(errors).find(([, message]) => message);
                if (assetError) {
                    triggerError(assetError[1]);
                    focusInvalidField(assetError[0]);
                    return false;
                }
            }
        }

        if (
            ((activeEditStep === 4 && advance) || navigateToStep === 5) &&
            !String(selectedEditVehicle?.vehicle_payment_mode || '').trim()
        ) {
            triggerError('Please select Payment Mode before continuing to Payment Part 2.');
            return false;
        }

        try {
            setSaving(true);
            setError('');
            setSuccess('');

            const submissionVehicles = vehicles
                .filter((vehicle) => Object.values(changedVehicles[vehicle.id] || {}).some(Boolean))
                .map((vehicle) => {
                    const changed = changedVehicles[vehicle.id] || {};
                    const installationValues = [
                        vehicle.vehicle_installation_type,
                        vehicle.vehicle_lead_closure_id,
                        vehicle.vehicle_installation_date
                    ];
                    const hasInstallationData = installationValues.some((value) => value !== null && value !== undefined && value !== '');
                    const hasCompleteInstallation = isInstallationDetailsComplete({
                        installation_person_type: vehicle.vehicle_installation_type,
                        installation_person_id: vehicle.vehicle_installation_person_id,
                        lead_closure_id: vehicle.vehicle_lead_closure_id,
                        installation_date: vehicle.vehicle_installation_date
                    });
                    if (changed.installation && hasInstallationData && !hasCompleteInstallation) {
                        throw new Error(`Complete all installation fields for ${vehicle.vehicle_no || 'the vehicle'}.`);
                    }
                    const saleAmount = Number(vehicle.vehicle_total_sale_amount || 0);
                    if ((changed.paymentPart1 || changed.paymentPart2) && saleAmount <= 0) {
                        throw new Error(`Enter a total sale amount for ${vehicle.vehicle_no || 'the vehicle'}.`);
                    }
                    return {
                        ...vehicle,
                        vehicle_record_id: Number(vehicle.id),
                        changed,
                        installation: changed.installation && hasCompleteInstallation ? {
                            installation_person_type: vehicle.vehicle_installation_type,
                            installation_person_id: vehicle.vehicle_installation_person_id
                                ? Number(vehicle.vehicle_installation_person_id)
                                : null,
                            lead_closure_id: Number(vehicle.vehicle_lead_closure_id),
                            installation_date: vehicle.vehicle_installation_date
                        } : null,
                        payment_part1: changed.paymentPart1 ? {
                            total_sale_amount: saleAmount,
                            transaction_id: vehicle.vehicle_transaction_id || '',
                            payment_mode: vehicle.vehicle_payment_mode || ''
                        } : null,
                        payment_part2: changed.paymentPart2 ? {
                            total_sale_amount: saleAmount,
                            transaction_id: vehicle.vehicle_transaction_id || '',
                            payment_mode: vehicle.vehicle_payment_mode || '',
                            device_charge: normalizeCurrency(vehicle.vehicle_device_charge),
                            software_charge: normalizeCurrency(vehicle.vehicle_software_charge),
                            technician_charge: normalizeCurrency(vehicle.vehicle_technician_charge),
                            sim_charge: normalizeCurrency(vehicle.vehicle_sim_charge),
                            courier_charge: normalizeCurrency(vehicle.vehicle_courier_charge),
                            cash_to_technician: vehicle.vehicle_cash_recipient_type === 'Technician',
                            cash_to_dealer: vehicle.vehicle_cash_recipient_type === 'Dealer',
                            cash_to_et: vehicle.vehicle_cash_recipient_type === 'ET',
                            total_amount: getPaymentPart2Summary(vehicle).chargeTotal,
                            amount_paid: normalizeCurrency(vehicle.vehicle_amount_paid),
                            amount_pending: getPaymentPart2Summary(vehicle).amountPending,
                            payment_status: vehicle.vehicle_payment_status || 'Not Paid'
                        } : null
                    };
                });
            const submission = {
                existing_customer_id: Number(customerId),
                customer_changed: customerChanged,
                customer: {
                    platform_id: Number(customer.platform_id || 0),
                    platform_name: customer.platform_name || '',
                    username: String(customer.username || '').trim(),
                    primary_mobile_no: String(customer.primary_mobile_no || '').trim(),
                    secondary_mobile_no: String(customer.secondary_mobile_no || '').trim(),
                    email: String(customer.email || '').trim(),
                    location: String(customer.location || '').trim(),
                    pincode: String(customer.pincode || '').trim(),
                    status: customer.status || 'Active'
                },
                vehicles: submissionVehicles
            };
            const verificationPayment = submissionVehicles.find((vehicle) =>
                vehicle.payment_part2 &&
                ['Paid', 'Partially Paid'].includes(vehicle.payment_part2.payment_status)
            )?.payment_part2;
            if (verificationPayment) {
                submission.payment_part2 = verificationPayment;
            }

            let savedDraftId = verificationDraftId;
            if (customerChanged || submissionVehicles.length > 0) {
                savedDraftId = await saveCustomerDraft(
                    verificationDraftId,
                    Number(customerId),
                    submission
                );
                setVerificationDraftId(savedDraftId);
            }

            if (!advance && navigateToStep === null && verificationPayment) {
                const response = await api.post('/customers/verification/submit.php', {
                    submission_id: savedDraftId,
                    submission_data: submission
                });
                if (!response.data?.success) {
                    throw new Error(response.data?.message || 'Failed to submit for Admin verification.');
                }
                await loadCustomerDetails();
                setVerificationDraftId(0);
                setSuccess('Payment details submitted successfully for Admin verification.');
            } else if (!advance && navigateToStep === null && savedDraftId > 0) {
                const committed = await commitCustomerSubmission(submission, savedDraftId);
                await api.post('/customers/verification/finish.php', {
                    submission_id: savedDraftId,
                    customer_id: committed.customerId,
                    vehicle_id: committed.vehicleId,
                    payment_id: committed.paymentId
                });
                await loadCustomerDetails();
                setVerificationDraftId(0);
            } else if (!advance && navigateToStep === null && savedDraftId === 0) {
                setCustomerChanged(false);
                setChangedVehicles({});
            }

            if (advance && activeEditStep < 5) {
                setSuccess(savedDraftId > 0 ? 'Changes saved as a draft.' : 'No changes to save.');
                setActiveEditStep((step) => step + 1);
                setValidationAttempted(false);
                setTouchedFields({});
                setAssetValidationErrors({});
            } else if (navigateToStep !== null) {
                setSuccess(savedDraftId > 0 ? 'Changes saved as a draft.' : '');
                setActiveEditStep(navigateToStep);
                setValidationAttempted(false);
                setTouchedFields({});
                setAssetValidationErrors({});
            } else {
                if (!verificationPayment) {
                    setSuccess('Customer details updated successfully.');
                    setTimeout(() => {
                        navigate(`/customer-management/details/${customerId}/view`, {
                            state: { customerDetailsPage: location.state?.customerDetailsPage }
                        });
                    }, 250);
                }
            }
            return true;
        } catch (err) {
            console.error('Customer update error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to update customer details.'
            );
            return false;
        } finally {
            setSaving(false);
        }
    };

    const handlePreviousStep = async () => {
        if (activeEditStep <= 1) {
            return;
        }
        const previousStep = activeEditStep - 1;
        const hasUnsavedChanges = customerChanged || Object.values(changedVehicles)
            .some((changes) => Object.values(changes).some(Boolean));
        if (hasUnsavedChanges) {
            await handleSave({ navigateToStep: previousStep });
            return;
        }
        if (await loadCustomerDetails()) {
            setActiveEditStep(previousStep);
            setValidationAttempted(false);
            setTouchedFields({});
            setAssetValidationErrors({});
        }
    };

    const isPaymentPart2Unlocked = Boolean(
        savedPaymentModes[selectedEditVehicleId] &&
        String(selectedEditVehicle?.vehicle_payment_mode || '').trim()
    );

    const navigateEditStep = async (step) => {
        if (step === 5 && !isPaymentPart2Unlocked) {
            return;
        }

        const hasUnsavedChanges = customerChanged || Object.values(changedVehicles)
            .some((changes) => Object.values(changes).some(Boolean));
        if (hasUnsavedChanges) {
            await handleSave({ navigateToStep: step });
            return;
        }

        if (step > activeEditStep) {
            setValidationAttempted(true);
            const firstError = getFirstActiveStepError();
            if (firstError) {
                triggerError(firstError[1]);
                focusInvalidField(firstError[0]);
                return;
            }
            if (activeEditStep === 2) {
                setSaving(true);
                const errors = await validateVehicleAvailability();
                setSaving(false);
                setAssetValidationErrors(errors);
                const assetError = Object.entries(errors).find(([, message]) => message);
                if (assetError) {
                    triggerError(assetError[1]);
                    focusInvalidField(assetError[0]);
                    return;
                }
            }
        }

        setActiveEditStep(step);
        setValidationAttempted(false);
        setTouchedFields({});
        setAssetValidationErrors({});
        setError('');
        setSuccess('');
    };

    const customer = customerData.customer || {};
    const activeStepErrors = isEditMode ? getActiveStepErrors() : {};
    const existingOption = (options, id, labelKey, value = 'Unknown') => {
        if (!id || options.some((option) => String(option.id) === String(id))) {
            return options;
        }
        return [...options, { id, [labelKey]: value }];
    };
    const getSaleAmountOptions = (value) => {
        const options = masterData.saleAmounts.filter((item) =>
            !item.status || String(item.status).toLowerCase() === 'active'
        );
        if (value && !options.some((item) => Number(item.sale_amount) === Number(value))) {
            options.push({ id: `saved-${value}`, sale_amount: value, status: 'Inactive' });
        }
        return options;
    };

    if (!isEditMode) {
        const formatAmount = (amount) => {
            if (amount === null || amount === undefined || amount === '') {
                return '—';
            }
            const value = Number(amount);
            return Number.isFinite(value)
                ? `₹${value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                : amount;
        };
        const viewField = (label, value) => (
            <div key={label} style={fieldStyle}>
                <strong>{label}</strong>
                <span>{renderValue(value)}</span>
            </div>
        );
        const paymentField = (label, value) => viewField(
            label,
            value === null || value === undefined || value === '' ? value : formatAmount(value)
        );

        return (
            <div className="page-container">
                <header className="page-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', marginBottom: '20px' }}>
                        <div>
                            <h2 style={{ margin: 0 }}>Customer Details</h2>
                            <p className="page-subtitle" style={{ margin: '5px 0 0' }}>View customer and vehicle information</p>
                        </div>
                        <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
                            {hasPermission('customers.edit') && (
                                <button type="button" className="btn btn-primary" onClick={() => navigate(`/customer-management/details/${customerId}/edit`, {
                                    state: { customerDetailsPage: location.state?.customerDetailsPage }
                                })}>
                                    Edit Customer
                                </button>
                            )}
                            <button type="button" className="btn btn-secondary" onClick={handleBack}>
                                <ArrowLeft size={16} /> Back to Customer Details
                            </button>
                        </div>
                    </header>

                    {error && <div className="alert alert-danger" style={{ marginBottom: '16px' }}>{error}</div>}

                    {loading ? (
                        <div className="card" style={{ ...sectionCardStyle, maxWidth: 'none' }}>Loading customer details...</div>
                    ) : (
                        <>
                            <section className="card" style={{ ...sectionCardStyle, maxWidth: 'none', marginBottom: '16px' }}>
                                <div className="customer-section-title" style={{ marginBottom: '14px' }}>
                                    <UserRound size={19} />
                                    <h3>CUSTOMER DETAILS</h3>
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: '10px' }}>
                                    {viewField('Platform', customer.platform_name)}
                                    {viewField('Username', customer.username)}
                                    {viewField('Primary Mobile No', customer.primary_mobile_no)}
                                    {viewField('Secondary Mobile No', customer.secondary_mobile_no)}
                                    {viewField('Email', customer.email)}
                                    {viewField('Location', customer.location)}
                                    {viewField('Pincode', customer.pincode)}
                                    {viewField('Customer Status', customer.status)}
                                </div>
                            </section>

                            <section className="card" style={{ ...sectionCardStyle, maxWidth: 'none', marginBottom: 0 }}>
                                <div className="customer-section-title" style={{ marginBottom: '14px' }}>
                                    <CarFront size={19} />
                                    <h3>VEHICLES ({vehicles.length})</h3>
                                </div>
                                {vehicles.length === 0 ? (
                                    <p style={{ margin: 0, color: '#64748b' }}>No vehicle details have been added for this customer.</p>
                                ) : (
                                    <div style={{ display: 'grid', gap: '9px' }}>
                                        {vehicles.map((vehicle, index) => {
                                            const isExpanded = String(expandedVehicleId) === String(vehicle.id);
                                            return (
                                                <article key={vehicle.id} style={{ border: '1px solid #e2e8f0', borderRadius: '13px', background: '#fff', overflow: 'hidden' }}>
                                                    <button
                                                        type="button"
                                                        aria-expanded={isExpanded}
                                                        onClick={() => setExpandedVehicleId(isExpanded ? null : vehicle.id)}
                                                        style={{
                                                            width: '100%',
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'space-between',
                                                            gap: '12px',
                                                            border: 0,
                                                            background: isExpanded ? '#f1f5f9' : '#fff',
                                                            padding: '13px 15px',
                                                            textAlign: 'left',
                                                            cursor: 'pointer'
                                                        }}
                                                    >
                                                        <span style={{ minWidth: 0 }}>
                                                            <strong style={{ display: 'block', marginBottom: '7px' }}>Vehicle {index + 1}</strong>
                                                            <span style={{ display: 'flex', flexWrap: 'wrap', gap: '8px 18px', color: '#475569', fontSize: '13px' }}>
                                                                <span>Vehicle No: {renderValue(vehicle.vehicle_no)}</span>
                                                                <span>IMEI No: {renderValue(vehicle.imei_no)}</span>
                                                                <span>SIM No 1: {renderValue(vehicle.sim_no_1)}</span>
                                                            </span>
                                                        </span>
                                                        <ChevronDown
                                                            size={18}
                                                            style={{
                                                                flex: '0 0 auto',
                                                                transition: 'transform 160ms ease',
                                                                transform: isExpanded ? 'rotate(180deg)' : 'rotate(0)'
                                                            }}
                                                        />
                                                    </button>

                                                    {isExpanded && (
                                                        <div style={{ padding: '16px', borderTop: '1px solid #e2e8f0', display: 'grid', gap: '18px' }}>
                                                            <section>
                                                                <h4 style={{ margin: '0 0 10px' }}>VEHICLE DETAILS</h4>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '9px' }}>
                                                                    {viewField('Vehicle No', vehicle.vehicle_no)}
                                                                    {viewField('Vehicle Type', vehicle.vehicle_type)}
                                                                    {viewField('IMEI No', vehicle.imei_no)}
                                                                    {viewField('SIM No 1', vehicle.sim_no_1)}
                                                                    {viewField('SIM No 2', vehicle.sim_no_2)}
                                                                    {viewField('Device Model', vehicle.device_model)}
                                                                    {viewField('Validity', renderValidity(vehicle.validity_months))}
                                                                </div>
                                                            </section>

                                                            <section>
                                                                <h4 style={{ margin: '0 0 10px' }}>INSTALLATION</h4>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '9px' }}>
                                                                    {viewField('Installation Type', vehicle.vehicle_installation_type)}
                                                                    {viewField('Installation Person', vehicle.vehicle_installation_person)}
                                                                    {viewField('Installation Person Type', vehicle.vehicle_installation_person_type)}
                                                                    {viewField('Lead Closure By', vehicle.vehicle_lead_closure)}
                                                                    {viewField('Installation Date', vehicle.vehicle_installation_date)}
                                                                </div>
                                                            </section>

                                                            <section>
                                                                <h4 style={{ margin: '0 0 10px' }}>PAYMENT DETAILS - PART 1</h4>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '9px' }}>
                                                                    {paymentField('Total Sale Amount', vehicle.vehicle_total_sale_amount)}
                                                                    {viewField('Transaction ID', vehicle.vehicle_transaction_id)}
                                                                    {viewField('Payment Mode', vehicle.vehicle_payment_mode)}
                                                                </div>
                                                            </section>

                                                            <section>
                                                                <h4 style={{ margin: '0 0 10px' }}>PAYMENT DETAILS - PART 2</h4>
                                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '9px' }}>
                                                                    {paymentField('Device Charge', vehicle.vehicle_device_charge)}
                                                                    {paymentField('Software Charge', vehicle.vehicle_software_charge)}
                                                                    {paymentField('Technician Charge', vehicle.vehicle_technician_charge)}
                                                                    {paymentField('SIM Charge', vehicle.vehicle_sim_charge)}
                                                                    {paymentField('Courier Charge', vehicle.vehicle_courier_charge)}
                                                                    {paymentField('Total Amount', vehicle.vehicle_total_amount)}
                                                                    {viewField('Cash Collection Recipient', vehicle.vehicle_cash_recipient_name)}
                                                                    {paymentField('Amount Paid', vehicle.vehicle_amount_paid)}
                                                                    {paymentField('Amount Pending', vehicle.vehicle_amount_pending)}
                                                                    {viewField('Payment Status', vehicle.vehicle_payment_status)}
                                                                    {viewField('Payment Mode', vehicle.vehicle_payment_mode)}
                                                                    {viewField('Transaction ID', vehicle.vehicle_transaction_id)}
                                                                </div>
                                                            </section>
                                                        </div>
                                                    )}
                                                </article>
                                            );
                                        })}
                                    </div>
                                )}
                            </section>
                        </>
                    )}
            </div>
        );
    }

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
                    <h2>Customer Details</h2>
                    <p className="page-subtitle" style={{ marginTop: '4px' }}>
                        {isEditMode ? 'Edit customer information' : 'View customer information'}
                    </p>
                </div>

                <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                    {canAddVehicle && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleAddVehicle}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: '7px' }}
                        >
                            <Plus size={16} />
                            Add Vehicle
                        </button>
                    )}
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={isEditMode ? handleCancelEdit : handleBack}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '7px'
                        }}
                    >
                        <ArrowLeft size={16} />
                        {isEditMode ? 'Cancel' : 'Back'}
                    </button>

                </div>
            </div>

            {isEditMode && activeEditStep === 2 && vehicles.length > 1 && (
                <section className="card" style={sectionCardStyle}>
                    <div className="customer-section-title" style={{ marginBottom: '12px' }}>
                        <CarFront size={19} />
                        <h3>Select Vehicle to Edit</h3>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                        {vehicles.map((vehicle, index) => (
                            <button
                                key={vehicle.id}
                                type="button"
                                className={String(selectedEditVehicleId) === String(vehicle.id) ? 'btn btn-primary' : 'btn btn-secondary'}
                                onClick={() => {
                                    setSelectedEditVehicleId(vehicle.id);
                                    setValidationAttempted(false);
                                    setTouchedFields({});
                                    setAssetValidationErrors({});
                                }}
                            >
                                Vehicle {index + 1} - {vehicle.vehicle_no || 'No vehicle number'}
                            </button>
                        ))}
                    </div>
                </section>
            )}

            <div
                className="card customer-step-card"
                style={{
                    marginBottom: '20px'
                }}
            >
                <div className="customer-stepper">
                    {[
                        { step: 1, label: 'Customer Details' },
                        { step: 2, label: 'Vehicle Details' },
                        { step: 3, label: 'Installation Details' },
                        { step: 4, label: 'Payment Part 1' },
                        { step: 5, label: 'Payment Part 2' }
                    ].map(({ step, label }, index) => {
                        const locked = step === 5 && !isPaymentPart2Unlocked;
                        const active = activeEditStep === step;
                        const completed = activeEditStep > step;
                        return (
                            <div key={step} style={{ display: 'contents' }}>
                                <button
                                    type="button"
                                    className={`customer-step ${active ? 'active' : completed ? 'completed' : ''}`}
                                    onClick={() => navigateEditStep(step)}
                                    disabled={locked || saving || loading}
                                    aria-current={active ? 'step' : undefined}
                                    title={locked ? 'Save Payment Part 1 with a Payment Mode to unlock' : label}
                                    style={{
                                        border: 0,
                                        background: 'transparent',
                                        font: 'inherit',
                                        cursor: locked ? 'not-allowed' : 'pointer',
                                        opacity: locked ? 0.55 : 1
                                    }}
                                >
                                    <div className="step-circle">
                                        {locked ? <LockKeyhole size={14} /> : completed ? '✓' : step}
                                    </div>
                                    <span>{label}</span>
                                </button>
                                {index < 4 && (
                                    <div className={`step-line ${activeEditStep > step ? 'active-line' : ''}`} />
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {error && (
                <div className="alert alert-danger" style={{ marginBottom: '16px' }}>
                    {error}
                </div>
            )}

            {success && (
                <div className="alert alert-success" style={{ marginBottom: '16px' }}>
                    {success}
                </div>
            )}

            {loading ? (
                <div className="card" style={sectionCardStyle}>
                    <p>Loading customer details...</p>
                </div>
            ) : (
                <>
                    {(!isEditMode || activeEditStep === 1) && (
                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <UserRound size={20} />
                            <h3>Customer Details</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Username" value={customer.username} onChange={(value) => updateCustomerField('username', value)} maxLength={100} {...fieldPresentation('username', activeStepErrors)} />
                                    <EditSelect
                                        label="Platform"
                                        value={customer.platform_id}
                                        onChange={(value) => updateCustomerField('platform_id', value)}
                                        options={existingOption(masterData.platforms, customer.platform_id, 'platform_name', customer.platform_name)}
                                        getValue={(option) => option.id}
                                        getLabel={(option) => option.platform_name}
                                        {...fieldPresentation('platform_id', activeStepErrors)}
                                    />
                                    <EditField label="Primary Mobile" value={customer.primary_mobile_no} onChange={(value) => updateCustomerField('primary_mobile_no', value)} inputMode="numeric" maxLength={10} {...fieldPresentation('primary_mobile_no', activeStepErrors)} />
                                    <EditField label="Secondary Mobile" value={customer.secondary_mobile_no} onChange={(value) => updateCustomerField('secondary_mobile_no', value)} inputMode="numeric" maxLength={10} {...fieldPresentation('secondary_mobile_no', activeStepErrors)} />
                                    <EditField label="Email" value={customer.email} onChange={(value) => updateCustomerField('email', value)} type="email" maxLength={150} {...fieldPresentation('email', activeStepErrors)} />
                                    <EditField label="Location" value={customer.location} onChange={(value) => updateCustomerField('location', value)} maxLength={150} {...fieldPresentation('location', activeStepErrors)} />
                                    <EditField label="Pincode" value={customer.pincode} onChange={(value) => updateCustomerField('pincode', value)} inputMode="numeric" maxLength={6} {...fieldPresentation('pincode', activeStepErrors)} />
                                    <EditSelect
                                        label="Status"
                                        value={customer.status}
                                        onChange={(value) => updateCustomerField('status', value)}
                                        options={['Active', 'Inactive']}
                                        getValue={(option) => option}
                                        getLabel={(option) => option}
                                        {...fieldPresentation('status', activeStepErrors)}
                                    />
                                </>
                            ) : (
                                <>
                                    <div style={fieldStyle}><strong>Username</strong><span>{renderValue(customer.username)}</span></div>
                                    <div style={fieldStyle}><strong>Platform</strong><span>{renderValue(customer.platform_name)}</span></div>
                                    <div style={fieldStyle}><strong>Primary Mobile</strong><span>{renderValue(customer.primary_mobile_no)}</span></div>
                                    <div style={fieldStyle}><strong>Secondary Mobile</strong><span>{renderValue(customer.secondary_mobile_no)}</span></div>
                                    <div style={fieldStyle}><strong>Email</strong><span>{renderValue(customer.email)}</span></div>
                                    <div style={fieldStyle}><strong>Location</strong><span>{renderValue(customer.location)}</span></div>
                                    <div style={fieldStyle}><strong>Pincode</strong><span>{renderValue(customer.pincode)}</span></div>
                                    <div style={fieldStyle}><strong>Status</strong><span>{renderValue(customer.status)}</span></div>
                                </>
                            )}
                        </div>
                    </div>
                    )}

                    {vehicles.map((vehicle, index) => isEditMode && (activeEditStep === 1 || String(selectedEditVehicleId) !== String(vehicle.id)) ? null : (
                        <div className="card" style={sectionCardStyle} key={vehicle.id}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                                <div className="customer-section-title" style={{ marginBottom: 0 }}>
                                    <CarFront size={20} />
                                    <h3>Vehicle {index + 1}</h3>
                                </div>
                                {canDeleteVehicle && (
                                    <button
                                        type="button"
                                        className="btn btn-outline"
                                        onClick={() => handleDeleteVehicle(vehicle.id)}
                                        style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
                                    >
                                        <Trash2 size={15} />
                                        Delete Vehicle
                                    </button>
                                )}
                            </div>
                            {(!isEditMode || activeEditStep === 2) && (
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                                {isEditMode ? (
                                    <>
                                        <EditField label="Vehicle No" value={vehicle.vehicle_no} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_no', value)} maxLength={20} {...fieldPresentation('vehicle_no', activeStepErrors)} />
                                        <EditSelect
                                            label="Vehicle Type"
                                            value={vehicle.vehicle_type_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_type_id', value)}
                                            options={existingOption(masterData.vehicleTypes, vehicle.vehicle_type_id, 'vehicle_type', vehicle.vehicle_type)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => option.vehicle_type}
                                            {...fieldPresentation('vehicle_type_id', activeStepErrors)}
                                        />
                                        <EditSelect
                                            label="Device Model"
                                            value={vehicle.device_model_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'device_model_id', value)}
                                            options={existingOption(masterData.deviceTypes, vehicle.device_model_id, 'device_type', vehicle.device_model)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => option.device_type}
                                            {...fieldPresentation('device_model_id', activeStepErrors)}
                                        />
                                        <EditField label="IMEI No" value={vehicle.imei_no} onChange={(value) => updateVehicleField(vehicle.id, 'imei_no', value)} inputMode="numeric" maxLength={15} {...fieldPresentation('imei_no', activeStepErrors)} />
                                        <EditField label="SIM No 1" value={vehicle.sim_no_1} onChange={(value) => updateVehicleField(vehicle.id, 'sim_no_1', value)} inputMode="numeric" maxLength={13} {...fieldPresentation('sim_no_1', activeStepErrors)} />
                                        <EditField label="SIM No 2" value={vehicle.sim_no_2} onChange={(value) => updateVehicleField(vehicle.id, 'sim_no_2', value)} inputMode="numeric" maxLength={13} {...fieldPresentation('sim_no_2', activeStepErrors)} />
                                        <EditSelect
                                            label="Validity"
                                            value={vehicle.validity_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'validity_id', value)}
                                            options={existingOption(masterData.validities, vehicle.validity_id, 'months', vehicle.validity_months)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => `${option.months} Months`}
                                            {...fieldPresentation('validity_id', activeStepErrors)}
                                        />
                                    </>
                                ) : (
                                    <>
                                        <div style={fieldStyle}><strong>Vehicle No</strong><span>{renderValue(vehicle.vehicle_no)}</span></div>
                                        <div style={fieldStyle}><strong>Vehicle Type</strong><span>{renderValue(vehicle.vehicle_type)}</span></div>
                                        <div style={fieldStyle}><strong>Device Model</strong><span>{renderValue(vehicle.device_model)}</span></div>
                                        <div style={fieldStyle}><strong>IMEI No</strong><span>{renderValue(vehicle.imei_no)}</span></div>
                                        <div style={fieldStyle}><strong>SIM No 1</strong><span>{renderValue(vehicle.sim_no_1)}</span></div>
                                        <div style={fieldStyle}><strong>SIM No 2</strong><span>{renderValue(vehicle.sim_no_2)}</span></div>
                                        <div style={fieldStyle}><strong>Validity</strong><span>{renderValidity(vehicle.validity_months)}</span></div>
                                    </>
                                )}
                            </div>
                            )}
                            {(!isEditMode || activeEditStep === 3) && (
                            <div style={{ marginTop: '22px', borderTop: '1px solid #e5e7eb', paddingTop: '18px' }}>
                                <div className="customer-section-title" style={{ marginBottom: '14px' }}>
                                    <Wrench size={18} />
                                    <h3>Installation</h3>
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                                    {isEditMode ? (
                                        <>
                                            <EditSelect
                                                label="Installation Type"
                                                value={vehicle.vehicle_installation_type || ''}
                                                onChange={(value) => {
                                                    updateVehicleField(vehicle.id, 'vehicle_installation_type', value);
                                                    updateVehicleField(vehicle.id, 'vehicle_installation_person_id', '');
                                                }}
                                                options={installationTypes}
                                                getValue={(option) => option}
                                                getLabel={(option) => option}
                                                {...fieldPresentation('vehicle_installation_type', activeStepErrors)}
                                            />
                                            {vehicle.vehicle_installation_type === 'Direct' ? null : vehicle.vehicle_installation_type === 'Technician' ? (
                                                <EditSelect
                                                    label="Installation Person"
                                                    value={vehicle.vehicle_installation_person_id}
                                                    onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_installation_person_id', value)}
                                                    options={existingOption(masterData.technicians, vehicle.vehicle_installation_person_id, 'technician_name', vehicle.vehicle_installation_person)}
                                                    getValue={(option) => option.id}
                                                    getLabel={(option) => option.technician_name}
                                                    {...fieldPresentation('vehicle_installation_person_id', activeStepErrors)}
                                                />
                                            ) : (
                                                <EditSelect
                                                    label="Installation Person"
                                                    value={vehicle.vehicle_installation_person_id}
                                                    onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_installation_person_id', value)}
                                                    options={existingOption(
                                                        masterData.dealers.filter((dealer) => String(dealer.installation_status || '').toLowerCase() === (vehicle.vehicle_installation_type === 'Onsite Dealer' ? 'onsite' : 'offsite')),
                                                        vehicle.vehicle_installation_person_id,
                                                        'dealer_name',
                                                        vehicle.vehicle_installation_person
                                                    )}
                                                    getValue={(option) => option.id}
                                                    getLabel={(option) => option.dealer_name}
                                                    {...fieldPresentation('vehicle_installation_person_id', activeStepErrors)}
                                                />
                                            )}
                                            <EditSelect
                                                label="Lead Closure By"
                                                value={vehicle.vehicle_lead_closure_id}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_lead_closure_id', value)}
                                                options={existingOption(masterData.leadClosures, vehicle.vehicle_lead_closure_id, 'lead_closure_name', vehicle.vehicle_lead_closure)}
                                                getValue={(option) => option.id}
                                                getLabel={(option) => option.lead_closure_name}
                                                {...fieldPresentation('vehicle_lead_closure_id', activeStepErrors)}
                                            />
                                            <EditField label="Installation Date" value={vehicle.vehicle_installation_date} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_installation_date', value)} type="date" max={new Date().toISOString().split('T')[0]} {...fieldPresentation('vehicle_installation_date', activeStepErrors)} />
                                        </>
                                    ) : (
                                        <>
                                            <div style={fieldStyle}><strong>Installation Type</strong><span>{renderValue(vehicle.vehicle_installation_type)}</span></div>
                                            <div style={fieldStyle}><strong>Installation Person</strong><span>{renderValue(vehicle.vehicle_installation_person)}</span></div>
                                            <div style={fieldStyle}><strong>Installation Person Type</strong><span>{renderValue(vehicle.vehicle_installation_person_type)}</span></div>
                                            <div style={fieldStyle}><strong>Lead Closure By</strong><span>{renderValue(vehicle.vehicle_lead_closure)}</span></div>
                                            <div style={fieldStyle}><strong>Installation Date</strong><span>{renderValue(vehicle.vehicle_installation_date)}</span></div>
                                        </>
                                    )}
                                </div>
                            </div>
                            )}

                            {(!isEditMode || activeEditStep === 4) && (
                            <div style={{ marginTop: '22px', borderTop: '1px solid #e5e7eb', paddingTop: '18px' }}>
                                <div className="customer-section-title" style={{ marginBottom: '14px' }}>
                                    <Wallet size={18} />
                                    <h3>Payment Details - Part 1</h3>
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                                    {isEditMode ? (
                                        <>
                                            <EditSelect
                                                label="Total Sale Amount"
                                                value={vehicle.vehicle_total_sale_amount}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_total_sale_amount', value)}
                                                options={getSaleAmountOptions(vehicle.vehicle_total_sale_amount)}
                                                getValue={(option) => option.sale_amount}
                                                getLabel={(option) => `₹${Number(option.sale_amount).toLocaleString('en-IN')}`}
                                                placeholder="Select Sale Amount"
                                                {...fieldPresentation('vehicle_total_sale_amount', activeStepErrors)}
                                            />
                                            <EditField label="Transaction ID" value={vehicle.vehicle_transaction_id} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_transaction_id', value)} digitsOnly {...fieldPresentation('vehicle_transaction_id', activeStepErrors)} />
                                            <EditSelect
                                                label="Payment Mode"
                                                value={vehicle.vehicle_payment_mode}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_payment_mode', value)}
                                                options={[...new Set([...PAYMENT_MODES, vehicle.vehicle_payment_mode].filter(Boolean))]}
                                                getValue={(option) => option}
                                                getLabel={(option) => option}
                                                {...fieldPresentation('vehicle_payment_mode', activeStepErrors)}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <div style={fieldStyle}><strong>Total Sale Amount</strong><span>{renderValue(vehicle.vehicle_total_sale_amount)}</span></div>
                                            <div style={fieldStyle}><strong>Transaction ID</strong><span>{renderValue(vehicle.vehicle_transaction_id)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Mode</strong><span>{renderValue(vehicle.vehicle_payment_mode)}</span></div>
                                        </>
                                    )}
                                </div>
                            </div>
                            )}

                            {(!isEditMode || activeEditStep === 5) && (
                            <div style={{ marginTop: '22px', borderTop: '1px solid #e5e7eb', paddingTop: '18px' }}>
                                <div className="customer-section-title" style={{ marginBottom: '14px' }}>
                                    <Wallet size={18} />
                                    <h3>Payment Details - Part 2</h3>
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                                    {isEditMode ? (
                                        <>
                                            {[
                                                ['Device Charge', 'vehicle_device_charge'],
                                                ['Software Charge', 'vehicle_software_charge'],
                                                ['Technician Charge', 'vehicle_technician_charge'],
                                                ['SIM Charge', 'vehicle_sim_charge'],
                                                ['Courier Charge', 'vehicle_courier_charge']
                                            ].map(([label, field]) => (
                                                <EditField
                                                    key={field}
                                                    label={label}
                                                    value={!hasCompletedPaymentPart2(vehicle) && Number(vehicle[field]) === 0 ? '' : vehicle[field]}
                                                    onChange={(value) => updatePaymentPart2Field(vehicle.id, field, value)}
                                                    {...fieldPresentation(field, activeStepErrors)}
                                                />
                                            ))}
                                            <div style={fieldStyle}>
                                                <strong>Total Amount</strong>
                                                <input
                                                    id="edit-field-vehicle_total_amount"
                                                    type="number"
                                                    value={getPaymentPart2Summary(vehicle).chargeTotal}
                                                    readOnly
                                                    style={inputStyle}
                                                    onBlur={() => markFieldTouched('vehicle_total_amount')}
                                                />
                                                {(validationAttempted || touchedFields.vehicle_total_amount) && activeStepErrors.vehicle_total_amount && <small style={{ color: '#dc2626', display: 'block', marginTop: '4px' }}>✕ {activeStepErrors.vehicle_total_amount}</small>}
                                            </div>
                                            <div style={fieldStyle}>
                                                <strong>Cash Collection Recipient</strong>
                                                <label><input type="checkbox" checked={vehicle.vehicle_cash_recipient_type === 'Technician'} onChange={() => updateVehicleField(vehicle.id, 'vehicle_cash_recipient_type', vehicle.vehicle_cash_recipient_type === 'Technician' ? '' : 'Technician')} /> Cash to Technician</label>
                                                <label><input type="checkbox" checked={vehicle.vehicle_cash_recipient_type === 'Dealer'} onChange={() => updateVehicleField(vehicle.id, 'vehicle_cash_recipient_type', vehicle.vehicle_cash_recipient_type === 'Dealer' ? '' : 'Dealer')} /> Cash to Dealer</label>
                                                <label><input type="checkbox" checked={vehicle.vehicle_cash_recipient_type === 'ET'} onChange={() => updateVehicleField(vehicle.id, 'vehicle_cash_recipient_type', vehicle.vehicle_cash_recipient_type === 'ET' ? '' : 'ET')} /> Direct Cash to ET</label>
                                            </div>
                                            <EditField
                                                label="Amount Paid"
                                                value={vehicle.vehicle_amount_paid}
                                                onChange={(value) => updatePaymentPart2Field(vehicle.id, 'vehicle_amount_paid', value)}
                                                {...fieldPresentation('vehicle_amount_paid', activeStepErrors)}
                                            />
                                            <div style={fieldStyle}><strong>Amount Pending</strong><span>{getPaymentPart2Summary(vehicle).amountPending.toFixed(2)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Status</strong><span>{getPaymentPart2Summary(vehicle).paymentStatus}</span></div>
                                            <div style={fieldStyle}><strong>Cash Recipient Name</strong><span>{renderValue(vehicle.vehicle_cash_recipient_name)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Mode</strong><span>{renderValue(vehicle.vehicle_payment_mode)}</span></div>
                                            <div style={fieldStyle}><strong>Transaction ID</strong><span>{renderValue(vehicle.vehicle_transaction_id)}</span></div>
                                        </>
                                    ) : (
                                        hasCompletedPaymentPart2(vehicle) ? <>
                                            <div style={fieldStyle}><strong>Device Charge</strong><span>{renderValue(vehicle.vehicle_device_charge)}</span></div>
                                            <div style={fieldStyle}><strong>Software Charge</strong><span>{renderValue(vehicle.vehicle_software_charge)}</span></div>
                                            <div style={fieldStyle}><strong>Technician Charge</strong><span>{renderValue(vehicle.vehicle_technician_charge)}</span></div>
                                            <div style={fieldStyle}><strong>SIM Charge</strong><span>{renderValue(vehicle.vehicle_sim_charge)}</span></div>
                                            <div style={fieldStyle}><strong>Courier Charge</strong><span>{renderValue(vehicle.vehicle_courier_charge)}</span></div>
                                            <div style={fieldStyle}><strong>Total Amount</strong><span>{renderValue(vehicle.vehicle_total_amount)}</span></div>
                                            <div style={fieldStyle}><strong>Cash Collection Recipient</strong><span>{renderValue(vehicle.vehicle_cash_recipient_name)}</span></div>
                                            <div style={fieldStyle}><strong>Amount Paid</strong><span>{renderValue(vehicle.vehicle_amount_paid)}</span></div>
                                            <div style={fieldStyle}><strong>Amount Pending</strong><span>{renderValue(vehicle.vehicle_amount_pending)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Status</strong><span>{renderValue(vehicle.vehicle_payment_status)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Mode</strong><span>{renderValue(vehicle.vehicle_payment_mode)}</span></div>
                                            <div style={fieldStyle}><strong>Transaction ID</strong><span>{renderValue(vehicle.vehicle_transaction_id)}</span></div>
                                        </>
                                        : <div className="alert alert-warning" style={{ gridColumn: '1 / -1', margin: 0 }}>
                                            Payment Part 2 has not been completed. Edit this customer to enter the charge breakdown.
                                        </div>
                                    )}
                                </div>
                            </div>
                            )}
                        </div>
                    ))}
                    {vehicles.length === 0 && (!isEditMode || activeEditStep === 2) && (
                        <div className="card" style={sectionCardStyle}>
                            No vehicle details have been added for this customer.
                        </div>
                    )}

                    {isEditMode && (
                        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '12px', marginTop: '8px' }}>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={handlePreviousStep}
                                disabled={activeEditStep === 1 || saving || loading}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => handleSave({ advance: activeEditStep < 5 })}
                                disabled={saving || loading}
                            >
                                {saving ? 'Saving...' : activeEditStep < 5 ? 'Update & Next' : 'Update Customer'}
                            </button>
                        </div>
                    )}

                </>
            )}
        </div>
    );
};

export default CustomerViewPage;
