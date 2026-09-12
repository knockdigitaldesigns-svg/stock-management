import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import {
    ArrowLeft,
    Check,
    UserRound,
    CarFront,
    Wrench,
    Wallet
} from 'lucide-react';

import api from '../../services/api';
import { showGlobalError } from '../../context/ErrorContext';

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

const renderValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return value;
};

const EditField = ({ label, value, onChange, type = 'text' }) => (
    <div style={fieldStyle}>
        <strong>{label}</strong>
        <input
            type={type}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            style={inputStyle}
        />
    </div>
);

const CustomerViewPage = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { customerId } = useParams();

    const isViewMode = location.pathname.endsWith('/view');
    const isEditMode = location.pathname.endsWith('/edit');

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const [customerData, setCustomerData] = useState({
        customer: null,
        vehicle: null,
        installation: null,
        payment_part1: null,
        payment_part2: null
    });

    const loadCustomerDetails = async () => {
        if (!customerId) {
            setLoading(false);
            triggerError('Customer ID is missing.');
            return;
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

            setCustomerData({
                customer: data.customer || null,
                vehicle: data.vehicle || null,
                installation: data.installation || null,
                payment_part1: data.payment_part1 || null,
                payment_part2: data.payment_part2 || null
            });
        } catch (err) {
            console.error('Customer view loading error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to load customer details.'
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadCustomerDetails();
    }, [customerId]);

    const updateCustomerField = (field, value) => {
        setCustomerData((previous) => ({
            ...previous,
            customer: {
                ...(previous.customer || {}),
                [field]: value
            }
        }));
    };

    const updateVehicleField = (field, value) => {
        setCustomerData((previous) => ({
            ...previous,
            vehicle: {
                ...(previous.vehicle || {}),
                [field]: value
            }
        }));
    };

    const updateInstallationField = (field, value) => {
        setCustomerData((previous) => ({
            ...previous,
            installation: {
                ...(previous.installation || {}),
                [field]: value
            }
        }));
    };

    const updatePaymentPart1Field = (field, value) => {
        setCustomerData((previous) => ({
            ...previous,
            payment_part1: {
                ...(previous.payment_part1 || {}),
                [field]: value
            }
        }));
    };

    const updatePaymentPart2Field = (field, value) => {
        setCustomerData((previous) => ({
            ...previous,
            payment_part2: {
                ...(previous.payment_part2 || {}),
                [field]: value
            }
        }));
    };

    const handleBack = () => {
        navigate('/customer-management/details');
    };

    const handleEdit = () => {
        navigate(`/customer-management/details/${customerId}/edit`);
    };

    const handleCancelEdit = () => {
        navigate(`/customer-management/details/${customerId}/view`);
    };

    const handleSave = async () => {
        const customer = customerData.customer || {};
        const vehicle = customerData.vehicle || {};
        const installation = customerData.installation || {};
        const paymentPart1 = customerData.payment_part1 || {};
        const paymentPart2 = customerData.payment_part2 || {};

        try {
            setSaving(true);
            setError('');
            setSuccess('');

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

            await api.post('/customers/installations/create.php', {
                customer_id: Number(customerId),
                installation_person_type: installation.installation_person_type || 'Technician',
                installation_person_id: Number(installation.installation_person_id || 0),
                lead_closure_id: Number(installation.lead_closure_id || 0),
                installation_date: installation.installation_date || ''
            });

            await api.post('/customers/payments/create.php', {
                customer_id: Number(customerId),
                total_sale_amount: Number(paymentPart1.total_sale_amount || 0),
                transaction_id: paymentPart1.transaction_id || null,
                payment_mode: paymentPart1.payment_mode || null,
                device_charge: Number(paymentPart2.device_charge || 0),
                software_charge: Number(paymentPart2.software_charge || 0),
                technician_charge: Number(paymentPart2.technician_charge || 0),
                sim_charge: Number(paymentPart2.sim_charge || 0),
                courier_charge: Number(paymentPart2.courier_charge || 0),
                total_amount: Number(paymentPart2.total_amount || 0),
                amount_paid: Number(paymentPart2.amount_paid || 0),
                amount_pending: Number(paymentPart2.amount_pending || 0),
                payment_status: paymentPart2.payment_status || 'Not Paid'
            });

            setSuccess('Customer details updated successfully.');
            setTimeout(() => {
                navigate(`/customer-management/details/${customerId}/view`);
            }, 250);
        } catch (err) {
            console.error('Customer update error:', err);
            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to update customer details.'
            );
        } finally {
            setSaving(false);
        }
    };

    const customer = customerData.customer || {};
    const vehicle = customerData.vehicle || {};
    const installation = customerData.installation || {};
    const paymentPart1 = customerData.payment_part1 || {};
    const paymentPart2 = customerData.payment_part2 || {};

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

                    {isEditMode && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleSave}
                            disabled={saving}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '7px'
                            }}
                        >
                            <Check size={16} />
                            {saving ? 'Saving...' : 'Save Changes'}
                        </button>
                    )}
                </div>
            </div>

            <div
                className="card customer-step-card"
                style={{
                    marginBottom: '20px'
                }}
            >
                <div className="customer-stepper">
                    <div className="customer-step completed">
                        <div className="step-circle">✓</div>
                        <span>Customer Details</span>
                    </div>

                    <div className="step-line active-line" />

                    <div className="customer-step completed">
                        <div className="step-circle">✓</div>
                        <span>Vehicle Details</span>
                    </div>

                    <div className="step-line active-line" />

                    <div className="customer-step completed">
                        <div className="step-circle">✓</div>
                        <span>Installation</span>
                    </div>

                    <div className="step-line active-line" />

                    <div className="customer-step completed">
                        <div className="step-circle">✓</div>
                        <span>Payment</span>
                    </div>

                    <div className="step-line active-line" />

                    <div className="customer-step completed">
                        <div className="step-circle">✓</div>
                        <span>Payment Details</span>
                    </div>
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
                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <UserRound size={20} />
                            <h3>Customer Details</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Username" value={customer.username} onChange={(value) => updateCustomerField('username', value)} />
                                    <EditField label="Platform ID" value={customer.platform_id} onChange={(value) => updateCustomerField('platform_id', value)} type="number" />
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

                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <CarFront size={20} />
                            <h3>Vehicle Details</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Vehicle No" value={vehicle.vehicle_no} onChange={(value) => updateVehicleField('vehicle_no', value)} />
                                    <EditField label="Vehicle Type ID" value={vehicle.vehicle_type_id} onChange={(value) => updateVehicleField('vehicle_type_id', value)} type="number" />
                                    <EditField label="Device Model ID" value={vehicle.device_model_id} onChange={(value) => updateVehicleField('device_model_id', value)} type="number" />
                                    <EditField label="IMEI No" value={vehicle.imei_no} onChange={(value) => updateVehicleField('imei_no', value)} />
                                    <EditField label="SIM No 1" value={vehicle.sim_no_1} onChange={(value) => updateVehicleField('sim_no_1', value)} />
                                    <EditField label="SIM No 2" value={vehicle.sim_no_2} onChange={(value) => updateVehicleField('sim_no_2', value)} />
                                    <EditField label="Validity ID" value={vehicle.validity_id} onChange={(value) => updateVehicleField('validity_id', value)} type="number" />
                                </>
                            ) : (
                                <>
                                    <div style={fieldStyle}><strong>Vehicle No</strong><span>{renderValue(vehicle.vehicle_no)}</span></div>
                                    <div style={fieldStyle}><strong>Vehicle Type</strong><span>{renderValue(vehicle.vehicle_type)}</span></div>
                                    <div style={fieldStyle}><strong>Device Model</strong><span>{renderValue(vehicle.device_model)}</span></div>
                                    <div style={fieldStyle}><strong>IMEI No</strong><span>{renderValue(vehicle.imei_no)}</span></div>
                                    <div style={fieldStyle}><strong>SIM No 1</strong><span>{renderValue(vehicle.sim_no_1)}</span></div>
                                    <div style={fieldStyle}><strong>SIM No 2</strong><span>{renderValue(vehicle.sim_no_2)}</span></div>
                                    <div style={fieldStyle}><strong>Validity Months</strong><span>{renderValue(vehicle.validity_months)}</span></div>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <Wrench size={20} />
                            <h3>Installation</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Installation Person ID" value={installation.installation_person_id} onChange={(value) => updateInstallationField('installation_person_id', value)} type="number" />
                                    <EditField label="Lead Closure ID" value={installation.lead_closure_id} onChange={(value) => updateInstallationField('lead_closure_id', value)} type="number" />
                                    <EditField label="Installation Date" value={installation.installation_date} onChange={(value) => updateInstallationField('installation_date', value)} />
                                </>
                            ) : (
                                <>
                                    <div style={fieldStyle}><strong>Installation Person</strong><span>{renderValue(installation.installation_person)}</span></div>
                                    <div style={fieldStyle}><strong>Lead Closure</strong><span>{renderValue(installation.lead_closure)}</span></div>
                                    <div style={fieldStyle}><strong>Installation Date</strong><span>{renderValue(installation.installation_date)}</span></div>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <Wallet size={20} />
                            <h3>Payment Details - Part 1</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Total Sale Amount" value={paymentPart1.total_sale_amount} onChange={(value) => updatePaymentPart1Field('total_sale_amount', value)} type="number" />
                                    <EditField label="Transaction ID" value={paymentPart1.transaction_id} onChange={(value) => updatePaymentPart1Field('transaction_id', value)} />
                                    <EditField label="Payment Mode" value={paymentPart1.payment_mode} onChange={(value) => updatePaymentPart1Field('payment_mode', value)} />
                                </>
                            ) : (
                                <>
                                    <div style={fieldStyle}><strong>Total Sale Amount</strong><span>{renderValue(paymentPart1.total_sale_amount)}</span></div>
                                    <div style={fieldStyle}><strong>Transaction ID</strong><span>{renderValue(paymentPart1.transaction_id)}</span></div>
                                    <div style={fieldStyle}><strong>Payment Mode</strong><span>{renderValue(paymentPart1.payment_mode)}</span></div>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="card" style={sectionCardStyle}>
                        <div className="customer-section-title" style={{ marginBottom: '18px' }}>
                            <Wallet size={20} />
                            <h3>Payment Details - Part 2</h3>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px' }}>
                            {isEditMode ? (
                                <>
                                    <EditField label="Device Charge" value={paymentPart2.device_charge} onChange={(value) => updatePaymentPart2Field('device_charge', value)} type="number" />
                                    <EditField label="Software Charge" value={paymentPart2.software_charge} onChange={(value) => updatePaymentPart2Field('software_charge', value)} type="number" />
                                    <EditField label="Technician Charge" value={paymentPart2.technician_charge} onChange={(value) => updatePaymentPart2Field('technician_charge', value)} type="number" />
                                    <EditField label="SIM Charge" value={paymentPart2.sim_charge} onChange={(value) => updatePaymentPart2Field('sim_charge', value)} type="number" />
                                    <EditField label="Courier Charge" value={paymentPart2.courier_charge} onChange={(value) => updatePaymentPart2Field('courier_charge', value)} type="number" />
                                    <EditField label="Total Amount" value={paymentPart2.total_amount} onChange={(value) => updatePaymentPart2Field('total_amount', value)} type="number" />
                                    <EditField label="Amount Paid" value={paymentPart2.amount_paid} onChange={(value) => updatePaymentPart2Field('amount_paid', value)} type="number" />
                                    <EditField label="Amount Pending" value={paymentPart2.amount_pending} onChange={(value) => updatePaymentPart2Field('amount_pending', value)} type="number" />
                                    <EditField label="Payment Status" value={paymentPart2.payment_status} onChange={(value) => updatePaymentPart2Field('payment_status', value)} />
                                </>
                            ) : (
                                <>
                                    <div style={fieldStyle}><strong>Device Charge</strong><span>{renderValue(paymentPart2.device_charge)}</span></div>
                                    <div style={fieldStyle}><strong>Software Charge</strong><span>{renderValue(paymentPart2.software_charge)}</span></div>
                                    <div style={fieldStyle}><strong>Technician Charge</strong><span>{renderValue(paymentPart2.technician_charge)}</span></div>
                                    <div style={fieldStyle}><strong>SIM Charge</strong><span>{renderValue(paymentPart2.sim_charge)}</span></div>
                                    <div style={fieldStyle}><strong>Courier Charge</strong><span>{renderValue(paymentPart2.courier_charge)}</span></div>
                                    <div style={fieldStyle}><strong>Total Amount</strong><span>{renderValue(paymentPart2.total_amount)}</span></div>
                                    <div style={fieldStyle}><strong>Amount Paid</strong><span>{renderValue(paymentPart2.amount_paid)}</span></div>
                                    <div style={fieldStyle}><strong>Amount Pending</strong><span>{renderValue(paymentPart2.amount_pending)}</span></div>
                                    <div style={fieldStyle}><strong>Payment Status</strong><span>{renderValue(paymentPart2.payment_status)}</span></div>
                                </>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};

export default CustomerViewPage;
