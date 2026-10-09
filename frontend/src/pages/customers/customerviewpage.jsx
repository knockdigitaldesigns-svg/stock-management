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

const paymentModes = [
    'ET Gpay',
    'ET Phonepe',
    'ET Paytm',
    'ET Account',
    '8002 Gpay',
    '8002 Phonepe',
    '8002 Paytm',
    'Wati Gpay',
    'Wati Phonepe',
    'Wati Paytm',
    'PG Gateway',
    'Cash'
];

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

const EditField = ({ label, value, onChange, type = 'text', digitsOnly = false }) => (
    <div style={fieldStyle}>
        <strong>{label}</strong>
        <input
            type={type}
            value={value ?? ''}
            onChange={(event) => onChange(digitsOnly ? event.target.value.replace(/\D/g, '').slice(0, 6) : event.target.value)}
            inputMode={digitsOnly ? 'numeric' : undefined}
            maxLength={digitsOnly ? 6 : undefined}
            pattern={digitsOnly ? '[0-9]{6}' : undefined}
            style={inputStyle}
        />
    </div>
);

const EditSelect = ({ label, value, onChange, options, getValue, getLabel, placeholder = 'Select' }) => (
    <div style={fieldStyle}>
        <strong>{label}</strong>
        <select
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            style={inputStyle}
        >
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={getValue(option)} value={getValue(option)}>
                    {getLabel(option)}
                </option>
            ))}
        </select>
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
    const [customerChanged, setCustomerChanged] = useState(false);
    const [changedVehicles, setChangedVehicles] = useState({});
    const [activeEditStep, setActiveEditStep] = useState(1);
    const [savedPaymentModes, setSavedPaymentModes] = useState({});
    const [masterData, setMasterData] = useState({
        platforms: [],
        vehicleTypes: [],
        deviceTypes: [],
        validities: [],
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

            setCustomerData({
                customer: data.customer
                    ? { ...data.customer, vehicles }
                    : null,
                vehicles,
                installation: data.installation || null,
                payment_part1: data.payment_part1 || null,
                payment_part2: data.payment_part2 || null
            });
            setSelectedEditVehicleId((previous) => (
                vehicles.some((vehicle) => String(vehicle.id) === String(previous))
                    ? previous
                    : vehicles[0]?.id ?? null
            ));
            setSavedPaymentModes(Object.fromEntries(
                vehicles.map((vehicle) => [
                    vehicle.id,
                    Boolean(String(vehicle.vehicle_payment_mode || '').trim())
                ])
            ));
            setCustomerChanged(false);
            setChangedVehicles({});
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
                    platforms: list(platformsResponse, 'platforms'),
                    vehicleTypes: list(vehicleTypesResponse, 'vehicle_types'),
                    deviceTypes: list(deviceTypesResponse, 'device_types'),
                    validities: list(validitiesResponse, 'validities'),
                    technicians: list(techniciansResponse, 'technicians'),
                    dealers: list(dealersResponse, 'dealers'),
                    leadClosures: list(leadClosuresResponse, 'lead_closures')
                });
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
        setCustomerData((previous) => ({
            ...previous,
            customer: {
                ...(previous.customer || {}),
                [field]: value
            }
        }));
    };

    const updateVehicleField = (vehicleId, field, value) => {
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
                    ? { ...vehicle, [field]: value }
                    : vehicle
            )
        }));
    };

    const handleBack = () => {
        navigate('/customer-management/details');
    };

    const handleCancelEdit = () => {
        navigate(`/customer-management/details/${customerId}/view`);
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

            if (customerChanged) {
                await api.put('/customers/update.php', {
                    id: Number(customerId),
                    platform_id: Number(customer.platform_id || 0),
                    username: String(customer.username || '').trim(),
                    primary_mobile_no: String(customer.primary_mobile_no || '').trim(),
                    secondary_mobile_no: String(customer.secondary_mobile_no || '').trim(),
                    email: String(customer.email || '').trim(),
                    location: String(customer.location || '').trim(),
                    pincode: String(customer.pincode || '').trim(),
                    status: customer.status || 'Active'
                });
            }

            for (const vehicle of vehicles) {
                const changed = changedVehicles[vehicle.id] || {};
                if (changed.vehicle) {
                    await api.put('/customers/vehicle_details/update.php', {
                        id: Number(vehicle.id || 0),
                        customer_id: Number(customerId),
                        vehicle_no: String(vehicle.vehicle_no || '').trim(),
                        vehicle_type_id: Number(vehicle.vehicle_type_id || 0),
                        device_model_id: Number(vehicle.device_model_id || 0),
                        imei_no: String(vehicle.imei_no || '').trim(),
                        sim_no_1: String(vehicle.sim_no_1 || '').trim(),
                        sim_no_2: String(vehicle.sim_no_2 || '').trim(),
                        validity_id: Number(vehicle.validity_id || 0)
                    });
                }

                const installationValues = [
                    vehicle.vehicle_installation_type,
                    vehicle.vehicle_installation_person_id,
                    vehicle.vehicle_lead_closure_id,
                    vehicle.vehicle_installation_date
                ];
                const hasInstallationData = installationValues.some((value) => value !== null && value !== undefined && value !== '');
                const hasCompleteInstallation = installationValues.every((value) => value !== null && value !== undefined && value !== '');
                if (changed.installation && hasInstallationData && !hasCompleteInstallation) {
                    throw new Error(`Complete all installation fields for ${vehicle.vehicle_no || 'the vehicle'}.`);
                }
                if (changed.installation && hasCompleteInstallation) {
                    await api.post('/customers/installations/create.php', {
                        customer_id: Number(customerId),
                        vehicle_id: Number(vehicle.id),
                        installation_person_type: vehicle.vehicle_installation_type,
                        installation_person_id: Number(vehicle.vehicle_installation_person_id),
                        lead_closure_id: Number(vehicle.vehicle_lead_closure_id),
                        installation_date: vehicle.vehicle_installation_date
                    });
                }

                const saleAmount = Number(vehicle.vehicle_total_sale_amount || 0);
                if ((changed.paymentPart1 || changed.paymentPart2) && saleAmount <= 0) {
                    throw new Error(`Enter a total sale amount for ${vehicle.vehicle_no || 'the vehicle'}.`);
                }
                if (changed.paymentPart1 || changed.paymentPart2) {
                    await api.post('/customers/payments/create.php', {
                        customer_id: Number(customerId),
                        vehicle_id: Number(vehicle.id),
                        payment_step: changed.paymentPart2 ? 2 : 1,
                        total_sale_amount: saleAmount,
                        transaction_id: vehicle.vehicle_transaction_id || '',
                        payment_mode: vehicle.vehicle_payment_mode || '',
                        ...(changed.paymentPart2 ? {
                            device_charge: Number(vehicle.vehicle_device_charge || 0),
                            software_charge: Number(vehicle.vehicle_software_charge || 0),
                            technician_charge: Number(vehicle.vehicle_technician_charge || 0),
                            sim_charge: Number(vehicle.vehicle_sim_charge || 0),
                            courier_charge: Number(vehicle.vehicle_courier_charge || 0),
                            total_amount: Number(vehicle.vehicle_total_amount || 0),
                            amount_paid: Number(vehicle.vehicle_amount_paid || 0),
                            amount_pending: Number(vehicle.vehicle_amount_pending || 0),
                            payment_status: vehicle.vehicle_payment_status || 'Not Paid'
                        } : {}),
                        cash_to_technician: vehicle.vehicle_cash_recipient_type === 'Technician',
                        cash_to_dealer: vehicle.vehicle_cash_recipient_type === 'Dealer'
                    });
                }
            }

            if (advance || navigateToStep !== null) {
                if (!await loadCustomerDetails()) {
                    return false;
                }
            } else {
                setCustomerChanged(false);
                setChangedVehicles({});
            }
            if (advance && activeEditStep < 5) {
                setSuccess('Changes saved successfully.');
                setActiveEditStep((step) => step + 1);
            } else if (navigateToStep !== null) {
                setSuccess('');
                setActiveEditStep(navigateToStep);
            } else {
                setSuccess('Customer details updated successfully.');
                setTimeout(() => {
                    navigate(`/customer-management/details/${customerId}/view`);
                }, 250);
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

        setActiveEditStep(step);
        setError('');
        setSuccess('');
    };

    const customer = customerData.customer || {};
    const existingOption = (options, id, labelKey, value = 'Unknown') => {
        if (!id || options.some((option) => String(option.id) === String(id))) {
            return options;
        }
        return [...options, { id, [labelKey]: value }];
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
                                <button type="button" className="btn btn-primary" onClick={() => navigate(`/customer-management/details/${customerId}/edit`)}>
                                    Edit Customer
                                </button>
                            )}
                            <button type="button" className="btn btn-secondary" onClick={() => navigate('/customer-management/details')}>
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
                                onClick={() => setSelectedEditVehicleId(vehicle.id)}
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
                                    <EditField label="Username" value={customer.username} onChange={(value) => updateCustomerField('username', value)} />
                                    <EditSelect
                                        label="Platform"
                                        value={customer.platform_id}
                                        onChange={(value) => updateCustomerField('platform_id', value)}
                                        options={existingOption(masterData.platforms, customer.platform_id, 'platform_name', customer.platform_name)}
                                        getValue={(option) => option.id}
                                        getLabel={(option) => option.platform_name}
                                    />
                                    <EditField label="Primary Mobile" value={customer.primary_mobile_no} onChange={(value) => updateCustomerField('primary_mobile_no', value)} />
                                    <EditField label="Secondary Mobile" value={customer.secondary_mobile_no} onChange={(value) => updateCustomerField('secondary_mobile_no', value)} />
                                    <EditField label="Email" value={customer.email} onChange={(value) => updateCustomerField('email', value)} />
                                    <EditField label="Location" value={customer.location} onChange={(value) => updateCustomerField('location', value)} />
                                    <EditField label="Pincode" value={customer.pincode} onChange={(value) => updateCustomerField('pincode', value)} />
                                    <EditField label="Status" value={customer.status} onChange={(value) => updateCustomerField('status', value)} />
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
                                        <EditField label="Vehicle No" value={vehicle.vehicle_no} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_no', value)} />
                                        <EditSelect
                                            label="Vehicle Type"
                                            value={vehicle.vehicle_type_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_type_id', value)}
                                            options={existingOption(masterData.vehicleTypes, vehicle.vehicle_type_id, 'vehicle_type', vehicle.vehicle_type)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => option.vehicle_type}
                                        />
                                        <EditSelect
                                            label="Device Model"
                                            value={vehicle.device_model_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'device_model_id', value)}
                                            options={existingOption(masterData.deviceTypes, vehicle.device_model_id, 'device_type', vehicle.device_model)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => option.device_type}
                                        />
                                        <EditField label="IMEI No" value={vehicle.imei_no} onChange={(value) => updateVehicleField(vehicle.id, 'imei_no', value)} />
                                        <EditField label="SIM No 1" value={vehicle.sim_no_1} onChange={(value) => updateVehicleField(vehicle.id, 'sim_no_1', value)} />
                                        <EditField label="SIM No 2" value={vehicle.sim_no_2} onChange={(value) => updateVehicleField(vehicle.id, 'sim_no_2', value)} />
                                        <EditSelect
                                            label="Validity"
                                            value={vehicle.validity_id}
                                            onChange={(value) => updateVehicleField(vehicle.id, 'validity_id', value)}
                                            options={existingOption(masterData.validities, vehicle.validity_id, 'months', vehicle.validity_months)}
                                            getValue={(option) => option.id}
                                            getLabel={(option) => `${option.months} Months`}
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
                                                options={['Technician', 'Onsite Dealer', 'Offsite Dealer']}
                                                getValue={(option) => option}
                                                getLabel={(option) => option}
                                            />
                                            {vehicle.vehicle_installation_type === 'Technician' ? (
                                                <EditSelect
                                                    label="Installation Person"
                                                    value={vehicle.vehicle_installation_person_id}
                                                    onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_installation_person_id', value)}
                                                    options={existingOption(masterData.technicians, vehicle.vehicle_installation_person_id, 'technician_name', vehicle.vehicle_installation_person)}
                                                    getValue={(option) => option.id}
                                                    getLabel={(option) => option.technician_name}
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
                                                />
                                            )}
                                            <EditSelect
                                                label="Lead Closure By"
                                                value={vehicle.vehicle_lead_closure_id}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_lead_closure_id', value)}
                                                options={existingOption(masterData.leadClosures, vehicle.vehicle_lead_closure_id, 'lead_closure_name', vehicle.vehicle_lead_closure)}
                                                getValue={(option) => option.id}
                                                getLabel={(option) => option.lead_closure_name}
                                            />
                                            <EditField label="Installation Date" value={vehicle.vehicle_installation_date} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_installation_date', value)} type="date" />
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
                                            <EditField label="Total Sale Amount" value={vehicle.vehicle_total_sale_amount} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_total_sale_amount', value)} type="number" />
                                            <EditField label="Transaction ID" value={vehicle.vehicle_transaction_id} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_transaction_id', value)} digitsOnly />
                                            <EditSelect
                                                label="Payment Mode"
                                                value={vehicle.vehicle_payment_mode}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_payment_mode', value)}
                                                options={[...new Set([...paymentModes, vehicle.vehicle_payment_mode].filter(Boolean))]}
                                                getValue={(option) => option}
                                                getLabel={(option) => option}
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
                                            <EditField label="Device Charge" value={vehicle.vehicle_device_charge} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_device_charge', value)} type="number" />
                                            <EditField label="Software Charge" value={vehicle.vehicle_software_charge} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_software_charge', value)} type="number" />
                                            <EditField label="Technician Charge" value={vehicle.vehicle_technician_charge} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_technician_charge', value)} type="number" />
                                            <EditField label="SIM Charge" value={vehicle.vehicle_sim_charge} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_sim_charge', value)} type="number" />
                                            <EditField label="Courier Charge" value={vehicle.vehicle_courier_charge} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_courier_charge', value)} type="number" />
                                            <EditField label="Total Amount" value={vehicle.vehicle_total_amount} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_total_amount', value)} type="number" />
                                            <EditField label="Amount Paid" value={vehicle.vehicle_amount_paid} onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_amount_paid', value)} type="number" />
                                            <div style={fieldStyle}><strong>Amount Pending</strong><span>{renderValue(vehicle.vehicle_amount_pending)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Status</strong><span>{renderValue(vehicle.vehicle_payment_status)}</span></div>
                                            <EditSelect
                                                label="Cash Collection Recipient"
                                                value={vehicle.vehicle_cash_recipient_type || ''}
                                                onChange={(value) => updateVehicleField(vehicle.id, 'vehicle_cash_recipient_type', value)}
                                                options={['', 'Technician', 'Dealer']}
                                                getValue={(option) => option}
                                                getLabel={(option) => option || 'None'}
                                            />
                                            <div style={fieldStyle}><strong>Cash Recipient Name</strong><span>{renderValue(vehicle.vehicle_cash_recipient_name)}</span></div>
                                            <div style={fieldStyle}><strong>Payment Mode</strong><span>{renderValue(vehicle.vehicle_payment_mode)}</span></div>
                                            <div style={fieldStyle}><strong>Transaction ID</strong><span>{renderValue(vehicle.vehicle_transaction_id)}</span></div>
                                        </>
                                    ) : (
                                        <>
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
