import { useState, useEffect } from 'react';
import api from '../../services/api';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Modal from '../../components/Modal/Modal';
import DateInput from '../../components/DateInput';
import { softwareDropdownOptions } from '../../constants/software';
import { PAYMENT_MODES } from '../../constants/paymentModes';
import { showGlobalError } from '../../context/ErrorContext';
import { getTodayDate } from '../../utils/date';
import { UserPlus, Car, Wrench, CreditCard, DollarSign, MapPin } from 'lucide-react';

const getDealerAssignedSoftware = (dealer) => {
    if (!dealer) return [];
    if (Array.isArray(dealer.software_list) && dealer.software_list.length > 0) {
        return dealer.software_list.map(s => String(s).trim()).filter(Boolean);
    }
    if (typeof dealer.software === 'string' && dealer.software.trim() !== '') {
        return dealer.software.split(',').map(s => s.trim()).filter(Boolean);
    }
    return [];
};

const currencyToCents = (value) => {
    const amount = String(value ?? '').trim();
    if (amount === '') return 0;
    if (!/^-?\d+(?:\.\d*)?$/.test(amount)) return null;

    const negative = amount.startsWith('-');
    const [wholeValue, fractionValue = ''] = (negative ? amount.slice(1) : amount).split('.');
    const whole = wholeValue.replace(/^0+(?=\d)/, '');
    if (whole.length > 10 || (whole.length === 10 && whole > '9999999999')) return null;
    const fraction = fractionValue.padEnd(3, '0');
    let cents = (Number(whole) * 100) + Number(fraction.slice(0, 2));
    if (Number(fraction[2]) >= 5) cents += 1;
    return negative ? -cents : cents;
};

const formatInrCents = (cents) => new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
}).format(cents / 100);

const getPaymentMismatchError = (form) => {
    const saleCents = currencyToCents(form.totalSaleAmount);
    const chargeCents = [
        form.deviceCharge,
        form.softwareCharge,
        form.technicianCharge,
        form.simCharge,
        form.courierCharge
    ].map(currencyToCents);
    if (saleCents === null || chargeCents.some((amount) => amount === null)) return '';

    const totalChargesCents = chargeCents.reduce((total, amount) => total + amount, 0);
    if (saleCents === totalChargesCents) return '';
    return `Payment amount mismatch! Payment Part 1 Total Amount (${formatInrCents(saleCents)}) must match Payment Part 2 Total Charges (${formatInrCents(totalChargesCents)}). Please correct the amounts before saving.`;
};

const initialNewCustomerForm = {
    // Step 1 - Basic Customer Information
    platform_id: '',
    username: '',
    primary_mobile_no: '',
    secondary_mobile_no: '',
    email: '',
    location: '',
    pincode: '',

    // Step 2 - Vehicle Details
    vehicle_no: '',
    vehicle_type_id: '',
    vehicle_device_model_id: '',
    vehicle_imei_no: '',
    vehicle_sim_no_1: '',
    vehicle_sim_no_2: '',
    validity_id: '',

    // Step 3 - Installation Details
    installation_person_type: 'Direct',
    installation_person_id: '',
    installation_date: getTodayDate(),
    lead_closure_id: '',

    // Step 4 - Payment Part 1
    totalSaleAmount: '',
    payment_step4_transaction_id: '',
    payment_step4_mode: '',

    // Step 5 - Payment Part 2
    deviceCharge: '',
    softwareCharge: '',
    technicianCharge: '',
    simCharge: '',
    courierCharge: '',
    totalAmount: '',
    cashToTechnician: false,
    cashToDealer: false,
    amountPaid: '0',
    amountPending: '',
    paymentStatus: 'Not Paid',
    payment_step5_mode: '',
    payment_step5_transaction_id: ''
};

const calculatePaymentTotals = (form) => {
    const totalSaleAmountCents = currencyToCents(form.totalSaleAmount) || 0;
    const totalChargesCents = [
        form.deviceCharge,
        form.softwareCharge,
        form.technicianCharge,
        form.simCharge,
        form.courierCharge
    ].reduce((total, charge) => total + (currencyToCents(charge) || 0), 0);
    const totalAmountCents = totalChargesCents > 0 ? totalChargesCents : totalSaleAmountCents;
    const amountPaidCents = currencyToCents(form.amountPaid) || 0;
    const amountPendingCents = Math.max(0, totalAmountCents - amountPaidCents);
    const pendingOnly = totalChargesCents === 0 && totalSaleAmountCents > 0 && amountPaidCents <= 0 &&
        !form.payment_step4_mode && !form.payment_step4_transaction_id;
    const paymentStatus = pendingOnly
        ? 'Pending'
        : amountPaidCents <= 0
            ? 'Not Paid'
            : amountPaidCents < totalAmountCents
                ? 'Partially Paid'
                : 'Paid';

    return {
        totalAmount: totalAmountCents ? String(Number((totalAmountCents / 100).toFixed(2))) : '',
        amountPending: totalAmountCents ? String(Number((amountPendingCents / 100).toFixed(2))) : '',
        paymentStatus
    };
};

const initialDeliveryAddress = {
    delivery_recipient_name: '',
    delivery_mobile_no: '',
    delivery_address_line1: '',
    delivery_address_line2: '',
    delivery_city: '',
    delivery_district: '',
    delivery_state: '',
    delivery_pincode: '',
    delivery_notes: ''
};

const initialCourierPayment = {
    payment_amount: '',
    payment_status: 'Pending',
    payment_method: '',
    payment_transaction_id: '',
    payment_date: '',
    payment_notes: ''
};

const CourierModal = ({ isOpen, onClose, onSuccess, editData = null }) => {
    const isEdit = Boolean(editData);

    const personOptions = [
        { value: 'Dealer', label: 'Dealer' },
        { value: 'Technician', label: 'Technician' },
        { value: 'Customer', label: 'Existing Customer' }
    ];

    const assetTypeOptions = [
        { value: 'device', label: 'Device' },
        { value: 'sim', label: 'SIM' },
        { value: 'both', label: 'Both Devices & SIMs' }
    ];

    // Form State
    const [courierToPerson, setCourierToPerson] = useState('Dealer');
    const [selectedDealer, setSelectedDealer]   = useState('');
    const [selectedTechnician, setSelectedTechnician] = useState('');
    const [selectedCustomer, setSelectedCustomer] = useState('');
    const [assetType, setAssetType]             = useState('device');

    // New Customer Form State
    const [newCustomerForm, setNewCustomerForm] = useState(initialNewCustomerForm);

    // Device Fields
    const [deviceCount, setDeviceCount]         = useState(1);
    const [deviceRows, setDeviceRows]           = useState([
        { id: 1, device_model_id: '', device_id: '' }
    ]);
    
    // SIM Fields
    const [simCount, setSimCount]               = useState(1);
    const [simRows, setSimRows]                 = useState([
        { id: 1, sim_type: '', sim_id: '' }
    ]);

    // Shared / Metadata Fields
    const [software, setSoftware]               = useState('');
    const [courierDate, setCourierDate]         = useState(getTodayDate());
    const [notes, setNotes]                     = useState('');
    const [deliveryAddress, setDeliveryAddress] = useState(initialDeliveryAddress);
    const [sameAsCustomerDetailsAddress, setSameAsCustomerDetailsAddress] = useState(false);
    const [paymentDetails, setPaymentDetails]   = useState(initialCourierPayment);

    // Available Stock & Dropdowns Data
    const [dealersList, setDealersList]         = useState([]);
    const [techniciansList, setTechniciansList] = useState([]);
    const [customersList, setCustomersList]     = useState([]);
    const [deviceModels, setDeviceModels]       = useState([]);
    const [allAvailableImeis, setAllAvailableImeis] = useState([]);
    const [simTypesList, setSimTypesList]       = useState([]);
    const [allAvailableSims, setAllAvailableSims]   = useState([]);
    
    // Master data for new customer flow
    const [platformsList, setPlatformsList]     = useState([]);
    const [vehicleTypesList, setVehicleTypesList] = useState([]);
    const [validitiesList, setValiditiesList]   = useState([]);
    const [leadClosuresList, setLeadClosuresList] = useState([]);
    const [saleAmountsList, setSaleAmountsList] = useState([]);
    
    const [loadingStock, setLoadingStock]       = useState(false);
    const [saving, setSaving]                   = useState(false);
    const [error, setError]                     = useState('');

    const isCreatingNewCustomer = (courierToPerson === 'Customer' && selectedCustomer === '__create_new__');
    const isExistingCustomer = courierToPerson === 'Customer' && selectedCustomer && selectedCustomer !== '__create_new__';
    const showCustomerWorkflowSections = isCreatingNewCustomer || isExistingCustomer;
    const showPaymentWorkflowSections = showCustomerWorkflowSections ||
        courierToPerson === 'Dealer' || courierToPerson === 'Technician';
    const selectedCustomerDetails = customersList.find((customer) => String(customer.id) === String(selectedCustomer));
    const paymentMismatchError = getPaymentMismatchError(newCustomerForm);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const getCustomerDetailsAddress = () => {
        if (isCreatingNewCustomer) {
            return {
                delivery_city: newCustomerForm.location.trim(),
                delivery_pincode: newCustomerForm.pincode.trim()
            };
        }

        const customer = customersList.find((item) => String(item.id) === String(selectedCustomer));
        return {
            delivery_city: customer?.location || '',
            delivery_pincode: customer?.pincode || ''
        };
    };

    const getNewCustomerPlatformId = () => {
        const matchingPlatform = platformsList.find(
            (platform) => String(platform.name || '').trim().toLowerCase() === String(software || '').trim().toLowerCase()
        );
        return matchingPlatform?.id || newCustomerForm.platform_id || '';
    };

    const updateDeliveryAddress = (event) => {
        const { name, value } = event.target;
        setDeliveryAddress((previous) => ({ ...previous, [name]: value }));
    };

    const handleSameAsCustomerDetailsAddressChange = (event) => {
        const checked = event.target.checked;
        setSameAsCustomerDetailsAddress(checked);
        if (checked) {
            setDeliveryAddress((previous) => ({
                ...previous,
                ...getCustomerDetailsAddress()
            }));
        }
    };

    const populateDeliveryAddress = (type, id) => {
        const person = type === 'Dealer'
            ? dealersList.find((item) => String(item.id) === String(id))
            : type === 'Technician'
                ? techniciansList.find((item) => String(item.id) === String(id))
                : customersList.find((item) => String(item.id) === String(id));
        if (!person) {
            setDeliveryAddress(initialDeliveryAddress);
            setSameAsCustomerDetailsAddress(false);
            return;
        }
        setDeliveryAddress({
            ...initialDeliveryAddress,
            delivery_recipient_name: person.dealer_name || person.technician_name || person.username || '',
            delivery_mobile_no: person.mobile_no || '',
            delivery_city: person.location || '',
            delivery_pincode: person.pincode || ''
        });
        setSameAsCustomerDetailsAddress(
            type === 'Customer' && Boolean(person.location || person.pincode)
        );
    };

    const handleCustomerSelection = (value) => {
        setSelectedCustomer(value);
        if (value === '__create_new__') {
            setNewCustomerForm(initialNewCustomerForm);
            setDeliveryAddress({
                ...initialDeliveryAddress,
                delivery_city: '',
                delivery_pincode: ''
            });
            setSameAsCustomerDetailsAddress(true);
            return;
        }

        const customer = customersList.find((item) => String(item.id) === String(value));
        if (!customer) {
            setNewCustomerForm(initialNewCustomerForm);
            setDeliveryAddress(initialDeliveryAddress);
            setSameAsCustomerDetailsAddress(false);
            return;
        }

        setNewCustomerForm({
            ...initialNewCustomerForm,
            platform_id: customer.platform_id ? String(customer.platform_id) : '',
            username: customer.username || '',
            primary_mobile_no: customer.mobile_no || '',
            secondary_mobile_no: customer.secondary_mobile_no || '',
            email: customer.email || '',
            location: customer.location || '',
            pincode: customer.pincode || '',
            installation_person_type: 'Direct'
        });
        populateDeliveryAddress('Customer', value);
    };

    const handleRecipientTypeChange = (type) => {
        setCourierToPerson(type);
        setSelectedDealer('');
        setSelectedTechnician('');
        setSelectedCustomer('');
        setDeliveryAddress(initialDeliveryAddress);
        setSameAsCustomerDetailsAddress(false);
    };

    const handleNewCustomerChange = (e) => {
        const { name, value, type, checked } = e.target;
        let val = type === 'checkbox' ? checked : value;

        if (name === 'primary_mobile_no' || name === 'secondary_mobile_no') {
            val = String(value).replace(/\D/g, '').slice(0, 10);
        }
        if (name === 'pincode') {
            val = String(value).replace(/\D/g, '').slice(0, 6);
        }
        if (name === 'payment_step4_transaction_id' || name === 'payment_step5_transaction_id') {
            val = String(value).replace(/\D/g, '').slice(0, 6);
        }
        if (name === 'username') {
            val = String(value).replace(/\s/g, '').slice(0, 100);
        }

        setNewCustomerForm((prev) => {
            const updated = { ...prev, [name]: val };
            if (name === 'payment_step4_mode') updated.payment_step5_mode = val;
            if (name === 'payment_step5_mode') updated.payment_step4_mode = val;
            if (name === 'payment_step4_transaction_id') updated.payment_step5_transaction_id = val;
            if (name === 'payment_step5_transaction_id') updated.payment_step4_transaction_id = val;
            const paymentFields = [
                'totalSaleAmount', 'deviceCharge', 'softwareCharge', 'technicianCharge',
                'simCharge', 'courierCharge', 'amountPaid'
            ];
            if (paymentFields.includes(name)) {
                Object.assign(updated, calculatePaymentTotals(updated));
            }
            return updated;
        });
        if (sameAsCustomerDetailsAddress && (name === 'location' || name === 'pincode')) {
            setDeliveryAddress((previous) => ({
                ...previous,
                [name === 'location' ? 'delivery_city' : 'delivery_pincode']: val
            }));
        }
        setError('');
    };

    const handleDeviceCountChange = (val) => {
        setDeviceCount(val);
        if (val === '') return;
        const count = parseInt(val, 10);
        if (isNaN(count) || count < 1) return;

        setDeviceRows((prev) => {
            if (prev.length === count) return prev;
            if (count < prev.length) {
                return prev.slice(0, count);
            } else {
                const newRows = [...prev];
                for (let i = prev.length; i < count; i++) {
                    newRows.push({
                        id: Date.now() + i,
                        device_model_id: '',
                        device_id: ''
                    });
                }
                return newRows;
            }
        });
    };

    const handleSimCountChange = (val) => {
        setSimCount(val);
        if (val === '') return;
        const count = parseInt(val, 10);
        if (isNaN(count) || count < 1) return;

        setSimRows((prev) => {
            if (prev.length === count) return prev;
            if (count < prev.length) {
                return prev.slice(0, count);
            } else {
                const newRows = [...prev];
                for (let i = prev.length; i < count; i++) {
                    newRows.push({
                        id: Date.now() + i,
                        sim_type: '',
                        sim_id: ''
                    });
                }
                return newRows;
            }
        });
    };

    const handleRowDeviceModelChange = (index, modelId) => {
        setDeviceRows((prev) =>
            prev.map((row, idx) => idx === index ? { ...row, device_model_id: modelId, device_id: '' } : row)
        );
    };

    const handleRowDeviceIdChange = (index, devId) => {
        setDeviceRows((prev) =>
            prev.map((row, idx) => idx === index ? { ...row, device_id: devId } : row)
        );
    };

    const handleRowSimTypeChange = (index, typeVal) => {
        setSimRows((prev) =>
            prev.map((row, idx) => idx === index ? { ...row, sim_type: typeVal, sim_id: '' } : row)
        );
    };

    const handleRowSimIdChange = (index, sId) => {
        setSimRows((prev) =>
            prev.map((row, idx) => idx === index ? { ...row, sim_id: sId } : row)
        );
    };

    // Load available stock and master data
    useEffect(() => {
        const fetchStockData = async () => {
            setLoadingStock(true);
            try {
                const res = await api.get('/courier/available_stock.php');
                if (res.data?.success) {
                    const data = res.data.data;
                    setDealersList(data.dealers || []);
                    setTechniciansList(data.technicians || []);
                    setCustomersList(data.customers || []);
                    setDeviceModels(data.device_models || []);
                    setAllAvailableImeis(data.available_imeis || []);
                    setSimTypesList(data.sim_types || []);
                    setAllAvailableSims(data.available_sims || []);
                    setPlatformsList(data.platforms || []);
                    setVehicleTypesList(data.vehicle_types || []);
                    setValiditiesList(data.validities || []);
                    setLeadClosuresList(data.lead_closures || []);
                    setSaleAmountsList(data.sale_amounts || []);
                }
            } catch (err) {
                console.error('Failed to fetch available stock for courier modal', err);
            } finally {
                setLoadingStock(false);
            }
        };

        if (isOpen) {
            fetchStockData();
        }
    }, [isOpen]);

    // Populate edit data
    useEffect(() => {
        if (editData && isOpen) {
            setSameAsCustomerDetailsAddress(false);
            const toPerson = editData.courier_to_person || 'Dealer';
            setCourierToPerson(toPerson);
            setSelectedDealer(editData.dealer_id ? String(editData.dealer_id) : '');
            setSelectedTechnician(editData.technician_id ? String(editData.technician_id) : '');
            let savedRequestData = {};
            try {
                savedRequestData = typeof editData.new_customer_data === 'string'
                    ? JSON.parse(editData.new_customer_data)
                    : (editData.new_customer_data || {});
            } catch (e) {
                console.error('Failed to parse saved courier workflow data', e);
            }
            const savedPayment = savedRequestData.payment_workflow || savedRequestData;
            if (toPerson === 'Dealer' || toPerson === 'Technician') {
                setNewCustomerForm({
                    ...initialNewCustomerForm,
                    totalSaleAmount: savedPayment.step4?.totalSaleAmount || '',
                    payment_step4_transaction_id: savedPayment.step4?.transactionId || '',
                    payment_step4_mode: savedPayment.step4?.paymentMode || '',
                    deviceCharge: savedPayment.step5?.deviceCharge || '',
                    softwareCharge: savedPayment.step5?.softwareCharge || '',
                    technicianCharge: savedPayment.step5?.technicianCharge || '',
                    simCharge: savedPayment.step5?.simCharge || '',
                    courierCharge: savedPayment.step5?.courierCharge || '',
                    amountPaid: savedPayment.step5?.amountPaid || '0',
                    payment_step5_mode: savedPayment.step4?.paymentMode || savedPayment.step5?.paymentMode || '',
                    payment_step5_transaction_id: savedPayment.step4?.transactionId || savedPayment.step5?.transactionId || ''
                });
            }
            
            if (editData.is_new_customer) {
                setSelectedCustomer('__create_new__');
                try {
                    const parsed = typeof editData.new_customer_data === 'string' 
                        ? JSON.parse(editData.new_customer_data) 
                        : (editData.new_customer_data || {});
                    
                    setNewCustomerForm({
                        ...initialNewCustomerForm,
                        ...(parsed.step1 || {}),
                        ...(parsed.step2 ? {
                            vehicle_no: parsed.step2.vehicle_no || '',
                            vehicle_type_id: parsed.step2.vehicle_type_id || '',
                            vehicle_device_model_id: parsed.step2.device_model_id || '',
                            vehicle_imei_no: parsed.step2.imei_no || '',
                            vehicle_sim_no_1: parsed.step2.sim_no_1 || '',
                            vehicle_sim_no_2: parsed.step2.sim_no_2 || '',
                            validity_id: parsed.step2.validity_id || ''
                        } : {}),
                        ...(parsed.step3 ? {
                            installation_person_type: 'Direct',
                            installation_person_id: '',
                            installation_date: parsed.step3.installation_date || getTodayDate(),
                            lead_closure_id: parsed.step3.lead_closure_id || ''
                        } : {}),
                        ...(parsed.step4 ? {
                            totalSaleAmount: parsed.step4.totalSaleAmount || '',
                            payment_step4_transaction_id: parsed.step4.transactionId || '',
                            payment_step4_mode: parsed.step4.paymentMode || ''
                        } : {}),
                        ...(parsed.step5 ? {
                            deviceCharge: parsed.step5.deviceCharge || '',
                            softwareCharge: parsed.step5.softwareCharge || '',
                            technicianCharge: parsed.step5.technicianCharge || '',
                            simCharge: parsed.step5.simCharge || '',
                            courierCharge: parsed.step5.courierCharge || '',
                            totalAmount: parsed.step5.totalAmount || '',
                            cashToTechnician: Boolean(parsed.step5.cashToTechnician),
                            cashToDealer: Boolean(parsed.step5.cashToDealer),
                            amountPaid: parsed.step5.amountPaid || '0',
                            amountPending: parsed.step5.amountPending || '',
                            paymentStatus: parsed.step5.paymentStatus || 'Not Paid',
                            payment_step5_mode: parsed.step4?.paymentMode || parsed.step5.paymentMode || '',
                            payment_step5_transaction_id: parsed.step4?.transactionId || parsed.step5.transactionId || ''
                        } : {})
                    });
                } catch (e) {
                    console.error('Failed to parse new_customer_data', e);
                }
            } else if (toPerson === 'Customer') {
                setSelectedCustomer(editData.customer_id ? String(editData.customer_id) : '');
                try {
                    const parsed = typeof editData.new_customer_data === 'string'
                        ? JSON.parse(editData.new_customer_data)
                        : (editData.new_customer_data || {});
                    setNewCustomerForm({
                        ...initialNewCustomerForm,
                        ...(parsed.step2 ? {
                            vehicle_no: parsed.step2.vehicle_no || '',
                            vehicle_type_id: parsed.step2.vehicle_type_id || '',
                            vehicle_device_model_id: parsed.step2.device_model_id || '',
                            vehicle_imei_no: parsed.step2.imei_no || '',
                            vehicle_sim_no_1: parsed.step2.sim_no_1 || '',
                            vehicle_sim_no_2: parsed.step2.sim_no_2 || '',
                            validity_id: parsed.step2.validity_id || ''
                        } : {}),
                        ...(parsed.step3 ? {
                            installation_person_type: parsed.step3.installation_person_type || 'Direct',
                            installation_person_id: parsed.step3.installation_person_id || '',
                            installation_date: parsed.step3.installation_date || getTodayDate(),
                            lead_closure_id: parsed.step3.lead_closure_id || ''
                        } : {}),
                        ...(parsed.step4 ? {
                            totalSaleAmount: parsed.step4.totalSaleAmount || '',
                            payment_step4_transaction_id: parsed.step4.transactionId || '',
                            payment_step4_mode: parsed.step4.paymentMode || ''
                        } : {}),
                        ...(parsed.step5 ? {
                            deviceCharge: parsed.step5.deviceCharge || '',
                            softwareCharge: parsed.step5.softwareCharge || '',
                            technicianCharge: parsed.step5.technicianCharge || '',
                            simCharge: parsed.step5.simCharge || '',
                            courierCharge: parsed.step5.courierCharge || '',
                            totalAmount: parsed.step5.totalAmount || '',
                            amountPaid: parsed.step5.amountPaid || '0',
                            amountPending: parsed.step5.amountPending || '',
                            paymentStatus: parsed.step5.paymentStatus || 'Not Paid',
                            payment_step5_mode: parsed.step4?.paymentMode || parsed.step5.paymentMode || '',
                            payment_step5_transaction_id: parsed.step4?.transactionId || parsed.step5.transactionId || ''
                        } : {})
                    });
                } catch (e) {
                    console.error('Failed to parse existing customer workflow data', e);
                }
            }

            setAssetType(editData.asset_type || 'device');
            setDeviceCount(editData.device_count ? Number(editData.device_count) : 1);
            setSimCount(editData.sim_count ? Number(editData.sim_count) : 1);

            setDeviceRows([
                { id: 1, device_model_id: editData.device_model_id ? String(editData.device_model_id) : '', device_id: editData.device_id ? String(editData.device_id) : '' }
            ]);
            setSimRows([
                { id: 1, sim_type: editData.sim_type || '', sim_id: editData.sim_id ? String(editData.sim_id) : '' }
            ]);

            setSoftware(editData.software || '');
            setCourierDate(editData.courier_date || getTodayDate());
            setNotes(editData.notes || '');
            setDeliveryAddress({
                ...initialDeliveryAddress,
                delivery_recipient_name: editData.delivery_recipient_name || '',
                delivery_mobile_no: editData.delivery_mobile_no || '',
                delivery_address_line1: editData.delivery_address_line1 || '',
                delivery_address_line2: editData.delivery_address_line2 || '',
                delivery_city: editData.delivery_city || '',
                delivery_district: editData.delivery_district || '',
                delivery_state: editData.delivery_state || '',
                delivery_pincode: editData.delivery_pincode || '',
                delivery_notes: editData.delivery_notes || ''
            });
            setPaymentDetails({
                ...initialCourierPayment,
                payment_amount: editData.payment_amount ?? '',
                payment_status: editData.payment_status || 'Pending',
                payment_method: editData.payment_method || '',
                payment_transaction_id: editData.payment_transaction_id || '',
                payment_date: editData.payment_date || '',
                payment_notes: editData.payment_notes || ''
            });
            setError('');
        } else if (!editData && isOpen) {
            setCourierToPerson('Dealer');
            setSelectedDealer('');
            setSelectedTechnician('');
            setSelectedCustomer('');
            setNewCustomerForm(initialNewCustomerForm);
            setSameAsCustomerDetailsAddress(false);
            setAssetType('device');
            setDeviceCount(1);
            setSimCount(1);
            setDeviceRows([
                { id: 1, device_model_id: '', device_id: '' }
            ]);
            setSimRows([
                { id: 1, sim_type: '', sim_id: '' }
            ]);
            setSoftware('');
            setCourierDate(getTodayDate());
            setNotes('');
            setDeliveryAddress(initialDeliveryAddress);
            setPaymentDetails(initialCourierPayment);
            setError('');
        }
    }, [editData, isOpen]);

    // Options mapping
    const dealerOptions = dealersList.map((d) => ({
        value: String(d.id),
        label: `${d.dealer_name}${d.installation_status ? ` - ${d.installation_status}` : ''}`
    }));

    const technicianOptions = techniciansList.map((t) => ({
        value: String(t.id),
        label: `${t.technician_name}${t.location ? ` (${t.location})` : ''}`
    }));

    const customerOptions = [
        { value: '__create_new__', label: '+ Create New Customer' },
        ...customersList.map((c) => ({
            value: String(c.id),
            label: `${c.username}${c.status ? ` - ${c.status}` : ''}`
        }))
    ];

    // Auto-select software when dealer is chosen
    const currentDealer = dealersList.find(d => String(d.id) === String(selectedDealer));
    const assignedSoftware = getDealerAssignedSoftware(currentDealer);
    const availableSoftwareOptions = (courierToPerson === 'Dealer' && currentDealer && assignedSoftware.length > 0)
        ? assignedSoftware.map(s => ({ value: s, label: s }))
        : softwareDropdownOptions;

    useEffect(() => {
        if (courierToPerson === 'Dealer' && currentDealer && assignedSoftware.length > 0) {
            if (!software || !assignedSoftware.includes(software)) {
                setSoftware(assignedSoftware[0]);
            }
        }
    }, [selectedDealer, courierToPerson]);

    const isApproved = isEdit && editData?.approval_status === 'Approved';
    const isSupportedPerson = true;

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (courierToPerson === 'Dealer' && !selectedDealer) {
            triggerError("Please select a Dealer.");
            return;
        }

        if (courierToPerson === 'Technician' && !selectedTechnician) {
            triggerError("Please select a Technician.");
            return;
        }

        if (courierToPerson === 'Customer' && !selectedCustomer) {
            triggerError("Please select a Customer or choose '+ Create New Customer'.");
            return;
        }

        if (isCreatingNewCustomer) {
            if (!getNewCustomerPlatformId()) {
                triggerError('Select a Software that matches an active Platform for the new customer.');
                return;
            }
            if (!newCustomerForm.username.trim()) {
                triggerError("Username is required for New Customer.");
                return;
            }
            if (!/^[a-zA-Z0-9._-]+$/.test(newCustomerForm.username.trim())) {
                triggerError("Username can contain only letters, numbers, dot, underscore and hyphen.");
                return;
            }
            if (!newCustomerForm.primary_mobile_no.trim()) {
                triggerError("Primary Mobile No is required for New Customer.");
                return;
            }
            if (!/^\d{10}$/.test(newCustomerForm.primary_mobile_no.trim())) {
                triggerError("Primary Mobile No must contain exactly 10 digits.");
                return;
            }
            if (newCustomerForm.secondary_mobile_no && !/^\d{10}$/.test(newCustomerForm.secondary_mobile_no.trim())) {
                triggerError("Secondary Mobile No must contain exactly 10 digits.");
                return;
            }
            if (newCustomerForm.secondary_mobile_no && newCustomerForm.secondary_mobile_no.trim() === newCustomerForm.primary_mobile_no.trim()) {
                triggerError("Secondary Mobile No cannot be the same as Primary Mobile No.");
                return;
            }
            if (newCustomerForm.pincode && !/^\d{6}$/.test(newCustomerForm.pincode.trim())) {
                triggerError("Pincode must contain exactly 6 digits.");
                return;
            }
        }
        if (isExistingCustomer && newCustomerForm.installation_person_type !== 'Direct' && !newCustomerForm.installation_person_id) {
            triggerError('Select an Installation Person for the chosen Installation Type.');
            return;
        }

        const saleAmountCents = currencyToCents(newCustomerForm.totalSaleAmount);
        const amountPaidCents = currencyToCents(newCustomerForm.amountPaid);
        const chargeValuesCents = [
            newCustomerForm.deviceCharge,
            newCustomerForm.softwareCharge,
            newCustomerForm.technicianCharge,
            newCustomerForm.simCharge,
            newCustomerForm.courierCharge
        ].map(currencyToCents);
        const saleAmount = Number(newCustomerForm.totalSaleAmount) || 0;
        const paymentMode = newCustomerForm.payment_step4_mode;
        const transactionId = newCustomerForm.payment_step4_transaction_id.trim();
        const chargeValues = [
            Number(newCustomerForm.deviceCharge) || 0,
            Number(newCustomerForm.softwareCharge) || 0,
            Number(newCustomerForm.technicianCharge) || 0,
            Number(newCustomerForm.simCharge) || 0,
            Number(newCustomerForm.courierCharge) || 0
        ];
        const totalChargesCents = chargeValuesCents.reduce((total, charge) => total + (charge || 0), 0);
        const totalCharges = totalChargesCents / 100;
        const amountPaid = Number(newCustomerForm.amountPaid) || 0;
        const hasPaymentData = saleAmount > 0 || paymentMode || transactionId ||
            totalCharges > 0 || amountPaid > 0;
        if (saleAmountCents === null || amountPaidCents === null || chargeValuesCents.some((amount) => amount === null)) {
            triggerError('Payment amounts and charges must be valid currency amounts.');
            return;
        }
        if (saleAmount < 0 || chargeValues.some((charge) => charge < 0) || amountPaid < 0) {
            triggerError('Payment amounts and charges cannot be negative.');
            return;
        }
        if (paymentMismatchError) {
            triggerError(paymentMismatchError);
            return;
        }
        if (hasPaymentData && saleAmount <= 0) {
            triggerError('Total Sale Amount is required when entering payment details.');
            return;
        }
        if (chargeValuesCents.some((charge) => charge > saleAmountCents)) {
            triggerError('An individual charge cannot exceed Total Sale Amount.');
            return;
        }
        if (amountPaidCents > (totalChargesCents > 0 ? totalChargesCents : saleAmountCents)) {
            triggerError('Amount Paid cannot exceed Total Amount.');
            return;
        }
        if (transactionId && !/^\d{6}$/.test(transactionId)) {
            triggerError('Transaction ID must contain exactly 6 digits.');
            return;
        }
        if (transactionId && !paymentMode) {
            triggerError('Payment Mode is required when Transaction ID is entered.');
            return;
        }
        if (paymentMode && paymentMode !== 'Cash' && !transactionId) {
            triggerError('Transaction ID is required for the selected Payment Mode.');
            return;
        }

        const numDeviceCount = parseInt(deviceCount, 10);
        if ((assetType === 'device' || assetType === 'both') && (isNaN(numDeviceCount) || numDeviceCount < 1)) {
            triggerError("Device Count must be a positive integer (minimum 1).");
            return;
        }

        const numSimCount = parseInt(simCount, 10);
        if ((assetType === 'sim' || assetType === 'both') && (isNaN(numSimCount) || numSimCount < 1)) {
            triggerError("SIM Count must be a positive integer (minimum 1).");
            return;
        }

        if ((assetType === 'device' || assetType === 'both')) {
            for (let i = 0; i < deviceRows.length; i++) {
                if (!deviceRows[i].device_model_id || !deviceRows[i].device_id) {
                    triggerError(`Please select Device Model and matching IMEI for Device ${i + 1}.`);
                    return;
                }
            }
        }

        if ((assetType === 'sim' || assetType === 'both')) {
            for (let i = 0; i < simRows.length; i++) {
                if (!simRows[i].sim_type || !simRows[i].sim_id) {
                    triggerError(`Please select SIM Type and matching SIM No for SIM ${i + 1}.`);
                    return;
                }
            }
        }

        const addressPayload = {
            ...deliveryAddress,
            delivery_recipient_name: deliveryAddress.delivery_recipient_name || (isCreatingNewCustomer ? newCustomerForm.username.trim() : ''),
            delivery_mobile_no: deliveryAddress.delivery_mobile_no || (isCreatingNewCustomer ? newCustomerForm.primary_mobile_no.trim() : '')
        };
        if (!addressPayload.delivery_recipient_name.trim()) {
            triggerError('Recipient Name is required for delivery.');
            return;
        }
        const mobileDigits = addressPayload.delivery_mobile_no.replace(/\D/g, '');
        if (!/^\+?[\d\s()-]+$/.test(addressPayload.delivery_mobile_no) || mobileDigits.length < 8 || mobileDigits.length > 15) {
            triggerError('Mobile Number must contain 8 to 15 digits.');
            return;
        }
        if (!addressPayload.delivery_address_line1.trim()) {
            triggerError('Address Line 1 is required for delivery.');
            return;
        }
        if (!addressPayload.delivery_city.trim()) {
            triggerError('City is required for delivery.');
            return;
        }
        if (!addressPayload.delivery_state.trim()) {
            triggerError('State is required for delivery.');
            return;
        }
        if (!/^\d{6}$/.test(addressPayload.delivery_pincode.trim())) {
            triggerError('PIN Code must contain exactly 6 digits.');
            return;
        }

        if (!courierDate) {
            triggerError("Please select Courier Date.");
            return;
        }

        setSaving(true);
        setError('');

        try {
            const devicesPayload = (assetType === 'device' || assetType === 'both')
                ? deviceRows.map(r => ({ device_model_id: Number(r.device_model_id), device_id: Number(r.device_id) }))
                : [];

            const simsPayload = (assetType === 'sim' || assetType === 'both')
                ? simRows.map(r => ({ sim_type: r.sim_type, sim_id: Number(r.sim_id) }))
                : [];

            const paymentWorkflowData = {
                step4: {
                    totalSaleAmount: newCustomerForm.totalSaleAmount,
                    transactionId,
                    paymentMode
                },
                step5: {
                    deviceCharge: newCustomerForm.deviceCharge,
                    softwareCharge: newCustomerForm.softwareCharge,
                    technicianCharge: newCustomerForm.technicianCharge,
                    simCharge: newCustomerForm.simCharge,
                    courierCharge: newCustomerForm.courierCharge,
                    totalAmount: calculatePaymentTotals(newCustomerForm).totalAmount,
                    amountPaid: String(amountPaid),
                    amountPending: calculatePaymentTotals(newCustomerForm).amountPending,
                    paymentStatus: calculatePaymentTotals(newCustomerForm).paymentStatus,
                    paymentMode,
                    transactionId
                }
            };
            const customerWorkflowData = showCustomerWorkflowSections ? {
                step1: {
                    platform_id: isCreatingNewCustomer
                        ? (getNewCustomerPlatformId() ? Number(getNewCustomerPlatformId()) : null)
                        : (selectedCustomerDetails?.platform_id ? Number(selectedCustomerDetails.platform_id) : null),
                    username: isCreatingNewCustomer ? newCustomerForm.username.trim() : selectedCustomerDetails?.username || '',
                    primary_mobile_no: isCreatingNewCustomer ? newCustomerForm.primary_mobile_no.trim() : selectedCustomerDetails?.mobile_no || '',
                    secondary_mobile_no: isCreatingNewCustomer ? newCustomerForm.secondary_mobile_no.trim() : selectedCustomerDetails?.secondary_mobile_no || '',
                    email: isCreatingNewCustomer ? newCustomerForm.email.trim() : selectedCustomerDetails?.email || '',
                    location: isCreatingNewCustomer ? newCustomerForm.location.trim() : selectedCustomerDetails?.location || '',
                    pincode: isCreatingNewCustomer ? newCustomerForm.pincode.trim() : selectedCustomerDetails?.pincode || ''
                },
                step2: {
                    vehicle_no: newCustomerForm.vehicle_no.trim(),
                    vehicle_type_id: newCustomerForm.vehicle_type_id ? Number(newCustomerForm.vehicle_type_id) : null,
                    device_model_id: newCustomerForm.vehicle_device_model_id ? Number(newCustomerForm.vehicle_device_model_id) : null,
                    imei_no: newCustomerForm.vehicle_imei_no.trim(),
                    sim_no_1: newCustomerForm.vehicle_sim_no_1.trim(),
                    sim_no_2: newCustomerForm.vehicle_sim_no_2.trim(),
                    validity_id: newCustomerForm.validity_id ? Number(newCustomerForm.validity_id) : null
                },
                step3: {
                    installation_person_type: isCreatingNewCustomer ? 'Direct' : newCustomerForm.installation_person_type,
                    installation_person_id: isCreatingNewCustomer || !newCustomerForm.installation_person_id
                        ? null
                        : Number(newCustomerForm.installation_person_id),
                    installation_date: newCustomerForm.installation_date,
                    lead_closure_id: newCustomerForm.lead_closure_id ? Number(newCustomerForm.lead_closure_id) : null
                },
                ...paymentWorkflowData
            } : null;
            const requestWorkflowData = customerWorkflowData ||
                ((courierToPerson === 'Dealer' || courierToPerson === 'Technician') && (hasPaymentData || isEdit)
                    ? { payment_workflow: paymentWorkflowData }
                    : null);

            const payload = {
                courier_to_person: courierToPerson,
                dealer_id: courierToPerson === 'Dealer' ? Number(selectedDealer) : null,
                technician_id: courierToPerson === 'Technician' ? Number(selectedTechnician) : null,
                customer_id: isCreatingNewCustomer ? null : (courierToPerson === 'Customer' ? Number(selectedCustomer) : null),
                is_new_customer: isCreatingNewCustomer,
                username: isCreatingNewCustomer ? newCustomerForm.username.trim() : null,
                primary_mobile_no: isCreatingNewCustomer ? newCustomerForm.primary_mobile_no.trim() : null,
                new_customer_data: requestWorkflowData,
                asset_type: assetType,
                device_count: (assetType === 'device' || assetType === 'both') ? numDeviceCount : 1,
                devices: devicesPayload,
                device_model_id: devicesPayload.length > 0 ? devicesPayload[0].device_model_id : null,
                device_id: devicesPayload.length > 0 ? devicesPayload[0].device_id : null,
                sim_count: (assetType === 'sim' || assetType === 'both') ? numSimCount : 1,
                sims: simsPayload,
                sim_type: simsPayload.length > 0 ? simsPayload[0].sim_type : null,
                sim_id: simsPayload.length > 0 ? simsPayload[0].sim_id : null,
                software: software,
                request_date: courierDate || getTodayDate(),
                courier_date: courierDate || getTodayDate(),
                notes: notes,
                ...addressPayload,
                ...paymentDetails
            };

            let res;
            if (isEdit) {
                payload.id = editData.id;
                res = await api.post('/courier/update.php', payload);
            } else {
                res = await api.post('/courier/create.php', payload);
            }

            if (res.data?.success) {
                onClose();
                if (onSuccess) onSuccess();
            } else {
                triggerError(res.data?.message || 'Failed to save courier request');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'An error occurred while saving courier request');
        } finally {
            setSaving(false);
        }
    };

    if (!isOpen) return null;

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={isEdit ? `Edit Courier Request (${editData?.request_code || ''})` : "Courier Request"}
            maxWidth={isCreatingNewCustomer ? "960px" : "920px"}
        >
            <div style={{ maxHeight: '78vh', overflowY: 'auto', paddingRight: '4px' }}>
                <form onSubmit={handleSubmit} className="courier-modal-form">
                    
                    {error && <div className="alert alert-danger mb-3">{error}</div>}

                    <section className="card p-3 mb-3 bg-light border">
                    <h6 className="font-weight-bold text-primary mb-3">Recipient Details</h6>
                    <div className="form-group mb-3">
                        <label className="form-label font-weight-bold">
                            Recipient Type <span className="text-danger">*</span>
                        </label>
                        <select
                            className="form-control"
                            value={courierToPerson}
                            onChange={(e) => handleRecipientTypeChange(e.target.value)}
                            disabled={isEdit || saving}
                        >
                            {personOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {courierToPerson === 'Dealer' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Dealer <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={dealerOptions}
                                value={selectedDealer}
                                onChange={(val) => {
                                    setSelectedDealer(val);
                                    populateDeliveryAddress('Dealer', val);
                                }}
                                placeholder="Search & select dealer..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}

                    {courierToPerson === 'Technician' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Technician <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={technicianOptions}
                                value={selectedTechnician}
                                onChange={(val) => {
                                    setSelectedTechnician(val);
                                    populateDeliveryAddress('Technician', val);
                                }}
                                placeholder="Search & select technician..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}

                    {courierToPerson === 'Customer' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Customer <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={customerOptions}
                                value={selectedCustomer}
                                onChange={handleCustomerSelection}
                                placeholder="Search & select customer or create new..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}
                    </section>

                    {/* ======================================================
                        NEW CUSTOMER CREATION FLOW SECTIONS
                    ====================================================== */}
                    {showPaymentWorkflowSections && (
                        <div className="new-customer-courier-sections mb-4">
                            {showCustomerWorkflowSections && <>
                            
                            {/* SECTION 1: BASIC CUSTOMER INFORMATION */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <UserPlus size={18} />
                                    <span>STEP 1 – BASIC CUSTOMER INFORMATION</span>
                                </div>
                                {isCreatingNewCustomer ? (
                                    <div className="row">
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">
                                            Username <span className="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="username"
                                            placeholder="Enter username"
                                            value={newCustomerForm.username}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                            required
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">
                                            Primary Mobile No <span className="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="primary_mobile_no"
                                            placeholder="10 digit mobile number"
                                            value={newCustomerForm.primary_mobile_no}
                                            onChange={handleNewCustomerChange}
                                            maxLength={10}
                                            disabled={saving}
                                            required
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Secondary Mobile No</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="secondary_mobile_no"
                                            placeholder="10 digit mobile number (optional)"
                                            value={newCustomerForm.secondary_mobile_no}
                                            onChange={handleNewCustomerChange}
                                            maxLength={10}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Email</label>
                                        <input
                                            type="email"
                                            className="form-control"
                                            name="email"
                                            placeholder="Enter email address"
                                            value={newCustomerForm.email}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Location</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="location"
                                            placeholder="Enter location"
                                            value={newCustomerForm.location}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Pincode</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="pincode"
                                            placeholder="6 digit pincode"
                                            value={newCustomerForm.pincode}
                                            onChange={handleNewCustomerChange}
                                            maxLength={6}
                                            disabled={saving}
                                        />
                                    </div>
                                    </div>
                                ) : (
                                    <div className="row">
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Customer Username</label>
                                            <input className="form-control" value={selectedCustomerDetails?.username || ''} readOnly />
                                        </div>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Primary Mobile No</label>
                                            <input className="form-control" value={selectedCustomerDetails?.mobile_no || ''} readOnly />
                                        </div>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Secondary Mobile No</label>
                                            <input className="form-control" value={selectedCustomerDetails?.secondary_mobile_no || ''} readOnly />
                                        </div>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Email</label>
                                            <input className="form-control" value={selectedCustomerDetails?.email || ''} readOnly />
                                        </div>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Location</label>
                                            <input className="form-control" value={selectedCustomerDetails?.location || ''} readOnly />
                                        </div>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Pincode</label>
                                            <input className="form-control" value={selectedCustomerDetails?.pincode || ''} readOnly />
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* SECTION 2: VEHICLE DETAILS */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <Car size={18} />
                                    <span>STEP 2 – VEHICLE DETAILS</span>
                                </div>
                                <div className="row">
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Vehicle No</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="vehicle_no"
                                            placeholder="e.g. TN01AB1234"
                                            value={newCustomerForm.vehicle_no}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Vehicle Type</label>
                                        <select
                                            className="form-control"
                                            name="vehicle_type_id"
                                            value={newCustomerForm.vehicle_type_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Vehicle Type</option>
                                            {vehicleTypesList.map(v => (
                                                <option key={v.id} value={v.id}>{v.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Device Model</label>
                                        <select
                                            className="form-control"
                                            name="vehicle_device_model_id"
                                            value={newCustomerForm.vehicle_device_model_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Device Model</option>
                                            {deviceModels.map(m => (
                                                <option key={m.id} value={m.id}>{m.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">IMEI No</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="vehicle_imei_no"
                                            placeholder="15 digit IMEI"
                                            value={newCustomerForm.vehicle_imei_no}
                                            onChange={handleNewCustomerChange}
                                            maxLength={15}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">SIM No 1</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="vehicle_sim_no_1"
                                            placeholder="SIM No 1"
                                            value={newCustomerForm.vehicle_sim_no_1}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">SIM No 2</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="vehicle_sim_no_2"
                                            placeholder="SIM No 2 (optional)"
                                            value={newCustomerForm.vehicle_sim_no_2}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Validity</label>
                                        <select
                                            className="form-control"
                                            name="validity_id"
                                            value={newCustomerForm.validity_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Validity</option>
                                            {validitiesList.map(val => (
                                                <option key={val.id} value={val.id}>{val.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            </div>

                            {/* SECTION 3: INSTALLATION DETAILS */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <Wrench size={18} />
                                    <span>STEP 3 – INSTALLATION DETAILS</span>
                                </div>
                                <div className="row">
                                    {isCreatingNewCustomer && <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Installation Type</label>
                                        <select
                                            className="form-control"
                                            name="installation_person_type"
                                            value={newCustomerForm.installation_person_type}
                                            disabled={saving}
                                        >
                                            <option value="Direct">Direct</option>
                                        </select>
                                    </div>}
                                    {isExistingCustomer && <>
                                        <div className="col-md-6 mb-3">
                                            <label className="form-label small font-weight-bold">Installation Type</label>
                                            <select
                                                className="form-control"
                                                name="installation_person_type"
                                                value={newCustomerForm.installation_person_type}
                                                onChange={(event) => setNewCustomerForm((prev) => ({
                                                    ...prev,
                                                    installation_person_type: event.target.value,
                                                    installation_person_id: ''
                                                }))}
                                                disabled={saving}
                                            >
                                                <option value="Direct">Direct</option>
                                                <option value="Technician">Technician</option>
                                                <option value="Onsite Dealer">Onsite Dealer</option>
                                                <option value="Offsite Dealer">Offsite Dealer</option>
                                            </select>
                                        </div>
                                        {newCustomerForm.installation_person_type !== 'Direct' && (
                                            <div className="col-md-6 mb-3">
                                                <label className="form-label small font-weight-bold">Select Installation Person</label>
                                                <select
                                                    className="form-control"
                                                    name="installation_person_id"
                                                    value={newCustomerForm.installation_person_id}
                                                    onChange={handleNewCustomerChange}
                                                    disabled={saving}
                                                >
                                                    <option value="">Select Person</option>
                                                    {(newCustomerForm.installation_person_type === 'Technician'
                                                        ? techniciansList.map((technician) => ({
                                                            id: technician.id,
                                                            name: technician.technician_name
                                                        }))
                                                        : dealersList
                                                            .filter((dealer) => String(dealer.installation_status || '').toLowerCase() ===
                                                                (newCustomerForm.installation_person_type === 'Onsite Dealer' ? 'onsite' : 'offsite'))
                                                            .map((dealer) => ({ id: dealer.id, name: dealer.dealer_name }))
                                                    ).map((person) => (
                                                        <option key={person.id} value={person.id}>{person.name}</option>
                                                    ))}
                                                </select>
                                            </div>
                                        )}
                                    </>}
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Installation Date</label>
                                        <DateInput
                                            className="form-control"
                                            value={newCustomerForm.installation_date}
                                            onChange={(val) => setNewCustomerForm(prev => ({ ...prev, installation_date: val }))}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Lead Closure By</label>
                                        <select
                                            className="form-control"
                                            name="lead_closure_id"
                                            value={newCustomerForm.lead_closure_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Lead Closure</option>
                                            {leadClosuresList.map(lc => (
                                                <option key={lc.id} value={lc.id}>{lc.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            </div>

                            </>}
                            {/* SECTION 4: PAYMENT – PART 1 */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <CreditCard size={18} />
                                    <span>STEP 4 – PAYMENT (PART 1)</span>
                                </div>
                                <div className="row">
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Total Sale Amount</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="totalSaleAmount"
                                            placeholder="Enter amount"
                                            value={newCustomerForm.totalSaleAmount}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Payment Mode</label>
                                        <select
                                            className="form-control"
                                            name="payment_step4_mode"
                                            value={newCustomerForm.payment_step4_mode}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Mode</option>
                                            {PAYMENT_MODES.map(m => (
                                                <option key={m} value={m}>{m}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Transaction ID</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="payment_step4_transaction_id"
                                            placeholder="Transaction ID"
                                            value={newCustomerForm.payment_step4_transaction_id}
                                            onChange={handleNewCustomerChange}
                                            inputMode="numeric"
                                            maxLength={6}
                                            pattern="[0-9]{6}"
                                            disabled={saving}
                                        />
                                    </div>
                                </div>
                                {paymentMismatchError && (
                                    <div className="alert alert-danger py-2 mb-0" role="alert">
                                        {paymentMismatchError}
                                    </div>
                                )}
                            </div>

                            {/* SECTION 5: PAYMENT DETAILS – PART 2 */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <DollarSign size={18} />
                                    <span>STEP 5 – PAYMENT DETAILS (PART 2)</span>
                                </div>
                                <div className="row">
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Device Charge</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="deviceCharge"
                                            placeholder="0.00"
                                            value={newCustomerForm.deviceCharge}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Software Charge</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="softwareCharge"
                                            placeholder="0.00"
                                            value={newCustomerForm.softwareCharge}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Technician Charge</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="technicianCharge"
                                            placeholder="0.00"
                                            value={newCustomerForm.technicianCharge}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">SIM Charge</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="simCharge"
                                            placeholder="0.00"
                                            value={newCustomerForm.simCharge}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Courier Charge</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={`form-control${paymentMismatchError ? ' is-invalid' : ''}`}
                                            name="courierCharge"
                                            placeholder="0.00"
                                            value={newCustomerForm.courierCharge}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Total Amount</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="totalAmount"
                                            placeholder="0.00"
                                            value={calculatePaymentTotals(newCustomerForm).totalAmount}
                                            readOnly
                                        />
                                    </div>

                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Amount Paid</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className="form-control"
                                            name="amountPaid"
                                            placeholder="0.00"
                                            value={newCustomerForm.amountPaid}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Amount Pending</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="amountPending"
                                            placeholder="0.00"
                                            value={calculatePaymentTotals(newCustomerForm).amountPending}
                                            readOnly
                                            style={{ backgroundColor: '#f1f5f9' }}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Payment Status</label>
                                        <select
                                            className="form-control"
                                            name="paymentStatus"
                                            value={calculatePaymentTotals(newCustomerForm).paymentStatus}
                                            disabled
                                        >
                                            <option value="Paid">Paid</option>
                                            <option value="Partially Paid">Partially Paid</option>
                                            <option value="Not Paid">Not Paid</option>
                                            <option value="Pending">Pending</option>
                                        </select>
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Payment Mode (Part 2)</label>
                                        <select
                                            className="form-control"
                                            name="payment_step5_mode"
                                            value={newCustomerForm.payment_step5_mode}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Mode</option>
                                            {PAYMENT_MODES.map(m => (
                                                <option key={m} value={m}>{m}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Transaction ID (Part 2)</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            name="payment_step5_transaction_id"
                                            placeholder="Transaction ID"
                                            value={newCustomerForm.payment_step5_transaction_id}
                                            onChange={handleNewCustomerChange}
                                            inputMode="numeric"
                                            maxLength={6}
                                            pattern="[0-9]{6}"
                                            disabled={saving}
                                        />
                                    </div>
                                </div>
                            </div>

                        </div>
                    )}

                    <section className="card p-3 mb-3 bg-light border">
                        <div className="d-flex align-items-center gap-2 mb-3">
                            <MapPin size={18} className="text-primary" />
                            <h6 className="font-weight-bold text-primary mb-0">Delivery Address</h6>
                        </div>
                        <div className="row">
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">Recipient Type</label>
                                <select className="form-control" value={courierToPerson} disabled>
                                    {personOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                            {courierToPerson === 'Customer' && selectedCustomer && (
                                <div className="col-12 mb-3">
                                    <label className="d-inline-flex align-items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={sameAsCustomerDetailsAddress}
                                            onChange={handleSameAsCustomerDetailsAddressChange}
                                            disabled={saving}
                                        />
                                        <span>Same as Customer Details Address</span>
                                    </label>
                                </div>
                            )}
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    Recipient Name <span className="text-danger">*</span>
                                </label>
                                <input
                                    className="form-control"
                                    name="delivery_recipient_name"
                                    value={deliveryAddress.delivery_recipient_name || (isCreatingNewCustomer ? newCustomerForm.username : '')}
                                    onChange={updateDeliveryAddress}
                                    disabled={saving}
                                />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    Mobile Number <span className="text-danger">*</span>
                                </label>
                                <input
                                    className="form-control"
                                    name="delivery_mobile_no"
                                    value={deliveryAddress.delivery_mobile_no || (isCreatingNewCustomer ? newCustomerForm.primary_mobile_no : '')}
                                    onChange={updateDeliveryAddress}
                                    maxLength={20}
                                    disabled={saving}
                                />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    Address Line 1 <span className="text-danger">*</span>
                                </label>
                                <input className="form-control" name="delivery_address_line1" value={deliveryAddress.delivery_address_line1} onChange={updateDeliveryAddress} disabled={saving} />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">Address Line 2</label>
                                <input className="form-control" name="delivery_address_line2" value={deliveryAddress.delivery_address_line2} onChange={updateDeliveryAddress} disabled={saving} />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    City <span className="text-danger">*</span>
                                </label>
                                <input className="form-control" name="delivery_city" value={deliveryAddress.delivery_city} onChange={updateDeliveryAddress} disabled={saving || sameAsCustomerDetailsAddress} />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">District</label>
                                <input className="form-control" name="delivery_district" value={deliveryAddress.delivery_district} onChange={updateDeliveryAddress} disabled={saving} />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    State <span className="text-danger">*</span>
                                </label>
                                <input className="form-control" name="delivery_state" value={deliveryAddress.delivery_state} onChange={updateDeliveryAddress} disabled={saving} />
                            </div>
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    PIN Code <span className="text-danger">*</span>
                                </label>
                                <input className="form-control" name="delivery_pincode" value={deliveryAddress.delivery_pincode} onChange={updateDeliveryAddress} maxLength={6} inputMode="numeric" disabled={saving || sameAsCustomerDetailsAddress} />
                            </div>
                            <div className="col-12 mb-0">
                                <label className="form-label font-weight-bold">Delivery Notes</label>
                                <textarea className="form-control" name="delivery_notes" rows="2" value={deliveryAddress.delivery_notes} onChange={updateDeliveryAddress} disabled={saving} />
                            </div>
                        </div>
                    </section>

                    <h6 className="font-weight-bold text-primary mb-3">Device Allocation</h6>
                    {isSupportedPerson && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                What are you allocating? <span className="text-danger">*</span>
                            </label>
                            <select
                                className="form-control"
                                value={assetType}
                                onChange={(e) => {
                                    setAssetType(e.target.value);
                                }}
                                disabled={isApproved || saving}
                            >
                                {assetTypeOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {/* ======================================================
                        4. DEVICE ALLOCATION SECTION (DYNAMIC ROWS)
                    ====================================================== */}
                    {isSupportedPerson && (assetType === 'device' || assetType === 'both') && (
                        <div className="card p-3 mb-3 bg-light border">
                            <h6 className="font-weight-bold mb-3 text-primary">DEVICE ALLOCATION</h6>
                            
                            <div className="form-group mb-3">
                                <label className="form-label font-weight-bold">
                                    Device Count <span className="text-danger">*</span>
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="form-control"
                                    value={deviceCount}
                                    onChange={(e) => {
                                        const val = e.target.value;
                                        handleDeviceCountChange(val);
                                    }}
                                    onKeyDown={(e) => {
                                        if (['.', '-', 'e', 'E', '+'].includes(e.key)) {
                                            e.preventDefault();
                                        }
                                    }}
                                    disabled={isApproved || saving}
                                />
                            </div>

                            {deviceRows.map((row, index) => {
                                const selectedInOtherRows = deviceRows
                                    .filter((r, idx) => idx !== index && r.device_id)
                                    .map(r => String(r.device_id));

                                let rowImeiOptions = allAvailableImeis
                                    .filter(item => 
                                        String(item.device_model_id) === String(row.device_model_id) &&
                                        (!selectedInOtherRows.includes(String(item.id)) || String(item.id) === String(row.device_id))
                                    )
                                    .map(item => ({
                                        value: String(item.id),
                                        label: item.imei_no
                                    }));

                                if (editData && editData.device_id && editData.imei_no && String(editData.device_model_id) === String(row.device_model_id) && index === 0) {
                                    if (!rowImeiOptions.some(opt => opt.value === String(editData.device_id))) {
                                        rowImeiOptions.unshift({
                                            value: String(editData.device_id),
                                            label: `${editData.imei_no} (Current Reserved)`
                                        });
                                    }
                                }

                                return (
                                    <div key={row.id || index} className="p-2 mb-3 bg-white rounded border">
                                        <div className="font-weight-bold text-secondary mb-2">Device {index + 1}</div>
                                        <div className="row">
                                            <div className="col-md-6 mb-2">
                                                <label className="form-label font-weight-bold small">
                                                    Device Model <span className="text-danger">*</span>
                                                </label>
                                                <SearchableDropdown
                                                    options={deviceModels.map(m => ({ value: String(m.id), label: m.name }))}
                                                    value={row.device_model_id}
                                                    onChange={(val) => handleRowDeviceModelChange(index, val)}
                                                    placeholder="Select Device Model"
                                                    disabled={isApproved || saving}
                                                />
                                            </div>

                                            <div className="col-md-6 mb-2">
                                                <label className="form-label font-weight-bold small">
                                                    IMEI No <span className="text-danger">*</span>
                                                </label>
                                                {!row.device_model_id ? (
                                                    <div className="text-muted small mt-2">Select Device Model first</div>
                                                ) : (
                                                    <SearchableDropdown
                                                        options={rowImeiOptions}
                                                        value={row.device_id}
                                                        onChange={(val) => handleRowDeviceIdChange(index, val)}
                                                        placeholder={rowImeiOptions.length === 0 ? "No available IMEIs" : "Search & select IMEI"}
                                                        disabled={isApproved || saving || rowImeiOptions.length === 0}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* ======================================================
                        5. SIM ALLOCATION SECTION (DYNAMIC ROWS)
                    ====================================================== */}
                    {isSupportedPerson && (assetType === 'sim' || assetType === 'both') && (
                        <div className="card p-3 mb-3 bg-light border">
                            <h6 className="font-weight-bold mb-3 text-primary">SIM ALLOCATION</h6>
                            
                            <div className="form-group mb-3">
                                <label className="form-label font-weight-bold">
                                    SIM Count <span className="text-danger">*</span>
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="form-control"
                                    value={simCount}
                                    onChange={(e) => {
                                        const val = e.target.value;
                                        handleSimCountChange(val);
                                    }}
                                    onKeyDown={(e) => {
                                        if (['.', '-', 'e', 'E', '+'].includes(e.key)) {
                                            e.preventDefault();
                                        }
                                    }}
                                    disabled={isApproved || saving}
                                />
                            </div>

                            {simRows.map((row, index) => {
                                const selectedInOtherRows = simRows
                                    .filter((r, idx) => idx !== index && r.sim_id)
                                    .map(r => String(r.sim_id));

                                let rowSimOptions = allAvailableSims
                                    .filter(item => 
                                        String(item.sim_type).toLowerCase() === String(row.sim_type).toLowerCase() &&
                                        (!selectedInOtherRows.includes(String(item.id)) || String(item.id) === String(row.sim_id))
                                    )
                                    .map(item => ({
                                        value: String(item.id),
                                        label: item.sim_no
                                    }));

                                if (editData && editData.sim_id && editData.sim_no && String(editData.sim_type).toLowerCase() === String(row.sim_type).toLowerCase() && index === 0) {
                                    if (!rowSimOptions.some(opt => opt.value === String(editData.sim_id))) {
                                        rowSimOptions.unshift({
                                            value: String(editData.sim_id),
                                            label: `${editData.sim_no} (Current Reserved)`
                                        });
                                    }
                                }

                                return (
                                    <div key={row.id || index} className="p-2 mb-3 bg-white rounded border">
                                        <div className="font-weight-bold text-secondary mb-2">SIM {index + 1}</div>
                                        <div className="row">
                                            <div className="col-md-6 mb-2">
                                                <label className="form-label font-weight-bold small">
                                                    SIM Type <span className="text-danger">*</span>
                                                </label>
                                                <SearchableDropdown
                                                    options={simTypesList.map(t => ({ value: t, label: t }))}
                                                    value={row.sim_type}
                                                    onChange={(val) => handleRowSimTypeChange(index, val)}
                                                    placeholder="Select SIM Type"
                                                    disabled={isApproved || saving}
                                                />
                                            </div>

                                            <div className="col-md-6 mb-2">
                                                <label className="form-label font-weight-bold small">
                                                    SIM No <span className="text-danger">*</span>
                                                </label>
                                                {!row.sim_type ? (
                                                    <div className="text-muted small mt-2">Select SIM Type first</div>
                                                ) : (
                                                    <SearchableDropdown
                                                        options={rowSimOptions}
                                                        value={row.sim_id}
                                                        onChange={(val) => handleRowSimIdChange(index, val)}
                                                        placeholder={rowSimOptions.length === 0 ? "No available SIMs" : "Search & select SIM No"}
                                                        disabled={isApproved || saving || rowSimOptions.length === 0}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <section className="card p-3 mb-3 bg-light border">
                        <h6 className="font-weight-bold text-primary mb-3">Courier Details</h6>
                    {isSupportedPerson && (
                        <div className="row mb-3">
                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">Software</label>
                                <SearchableDropdown
                                    options={availableSoftwareOptions}
                                    value={software}
                                    onChange={(val) => setSoftware(val)}
                                    placeholder="Select Software"
                                    disabled={saving}
                                />
                            </div>

                            <div className="col-md-6 mb-3">
                                <label className="form-label font-weight-bold">
                                    Courier Date <span className="text-danger">*</span>
                                </label>
                                <DateInput
                                    className="form-control"
                                    value={courierDate}
                                    onChange={(val) => setCourierDate(val)}
                                    disabled={saving}
                                />
                            </div>

                            <div className="col-md-12 mb-3">
                                <label className="form-label font-weight-bold">Notes</label>
                                <textarea
                                    className="form-control"
                                    rows="2"
                                    placeholder="Enter notes..."
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    disabled={saving}
                                />
                            </div>
                        </div>
                    )}
                    </section>

                    {/* Modal Actions */}
                    <div className="d-flex justify-content-end gap-2 mt-4">
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={onClose}
                            disabled={saving}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={saving}
                        >
                            {saving ? 'Saving...' : isEdit ? 'Update Request' : 'Submit Courier Request'}
                        </button>
                    </div>

                </form>
            </div>
        </Modal>
    );
};

export default CourierModal;
