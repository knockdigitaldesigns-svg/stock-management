import { useState, useEffect } from 'react';
import api from '../../services/api';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Modal from '../../components/Modal/Modal';
import DateInput from '../../components/DateInput';
import { softwareDropdownOptions } from '../../constants/software';
import { PAYMENT_MODES } from '../../constants/paymentModes';
import { showGlobalError } from '../../context/ErrorContext';
import { getTodayDate } from '../../utils/date';
import { UserPlus, Car, Wrench, CreditCard, DollarSign } from 'lucide-react';

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
    installation_person_type: 'Technician',
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

const CourierModal = ({ isOpen, onClose, onSuccess, editData = null }) => {
    const isEdit = Boolean(editData);

    const personOptions = [
        { value: 'Dealer', label: 'Dealer' },
        { value: 'Technician', label: 'Technician' },
        { value: 'Customer', label: 'Customer' }
    ];

    const assetTypeOptions = [
        { value: 'device', label: 'Device' },
        { value: 'sim', label: 'SIM' },
        { value: 'both', label: 'Both Devices & SIMs' }
    ];

    const installationTypeOptions = [
        { value: 'Technician', label: 'Technician' },
        { value: 'Onsite Dealer', label: 'Onsite Dealer' },
        { value: 'Offsite Dealer', label: 'Offsite Dealer' },
        { value: 'Direct', label: 'Direct' }
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

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
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
        if (name === 'username') {
            val = String(value).replace(/\s/g, '').slice(0, 100);
        }

        setNewCustomerForm((prev) => {
            const updated = { ...prev, [name]: val };

            // Auto calculations for payment step 5
            if (['deviceCharge', 'softwareCharge', 'technicianCharge', 'simCharge', 'courierCharge', 'totalSaleAmount'].includes(name)) {
                const d = parseFloat(updated.deviceCharge) || 0;
                const s = parseFloat(updated.softwareCharge) || 0;
                const t = parseFloat(updated.technicianCharge) || 0;
                const sim = parseFloat(updated.simCharge) || 0;
                const c = parseFloat(updated.courierCharge) || 0;
                const sumCharges = d + s + t + sim + c;
                const calculatedTotal = sumCharges > 0 ? sumCharges : (parseFloat(updated.totalSaleAmount) || 0);
                
                updated.totalAmount = calculatedTotal > 0 ? String(calculatedTotal) : '';
                
                const paid = parseFloat(updated.amountPaid) || 0;
                const pending = Math.max(0, calculatedTotal - paid);
                updated.amountPending = calculatedTotal > 0 ? String(pending) : '';
                
                if (paid >= calculatedTotal && calculatedTotal > 0) {
                    updated.paymentStatus = 'Paid';
                } else if (paid > 0) {
                    updated.paymentStatus = 'Partial';
                } else {
                    updated.paymentStatus = 'Not Paid';
                }
            }

            if (name === 'amountPaid') {
                const total = parseFloat(updated.totalAmount) || (parseFloat(updated.totalSaleAmount) || 0);
                const paid = parseFloat(val) || 0;
                const pending = Math.max(0, total - paid);
                updated.amountPending = total > 0 ? String(pending) : '';
                if (paid >= total && total > 0) {
                    updated.paymentStatus = 'Paid';
                } else if (paid > 0) {
                    updated.paymentStatus = 'Partial';
                } else {
                    updated.paymentStatus = 'Not Paid';
                }
            }

            return updated;
        });
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
            const toPerson = editData.courier_to_person || 'Dealer';
            setCourierToPerson(toPerson);
            setSelectedDealer(editData.dealer_id ? String(editData.dealer_id) : '');
            setSelectedTechnician(editData.technician_id ? String(editData.technician_id) : '');
            
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
                            installation_person_type: parsed.step3.installation_person_type || 'Technician',
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
                            cashToTechnician: Boolean(parsed.step5.cashToTechnician),
                            cashToDealer: Boolean(parsed.step5.cashToDealer),
                            amountPaid: parsed.step5.amountPaid || '0',
                            amountPending: parsed.step5.amountPending || '',
                            paymentStatus: parsed.step5.paymentStatus || 'Not Paid',
                            payment_step5_mode: parsed.step5.paymentMode || '',
                            payment_step5_transaction_id: parsed.step5.transactionId || ''
                        } : {})
                    });
                } catch (e) {
                    console.error('Failed to parse new_customer_data', e);
                }
            } else {
                setSelectedCustomer(editData.customer_id ? String(editData.customer_id) : '');
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
            setError('');
        } else if (!editData && isOpen) {
            setCourierToPerson('Dealer');
            setSelectedDealer('');
            setSelectedTechnician('');
            setSelectedCustomer('');
            setNewCustomerForm(initialNewCustomerForm);
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

    // Filtered installation persons based on installation type in step 3
    const getInstallationPersonOptions = () => {
        const type = newCustomerForm.installation_person_type;
        if (type === 'Technician') {
            return techniciansList.map(t => ({
                value: String(t.id),
                label: `${t.technician_name}${t.location ? ` (${t.location})` : ''}`
            }));
        } else if (type === 'Onsite Dealer') {
            return dealersList
                .filter(d => String(d.installation_status || '').toLowerCase() === 'onsite')
                .map(d => ({
                    value: String(d.id),
                    label: `${d.dealer_name} (Onsite)`
                }));
        } else if (type === 'Offsite Dealer') {
            return dealersList
                .filter(d => String(d.installation_status || '').toLowerCase() === 'offsite')
                .map(d => ({
                    value: String(d.id),
                    label: `${d.dealer_name} (Offsite)`
                }));
        }
        return [];
    };

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

    const hasValidRecipient = 
        (courierToPerson === 'Dealer' && selectedDealer) || 
        (courierToPerson === 'Technician' && selectedTechnician) || 
        (courierToPerson === 'Customer' && selectedCustomer);

    const isDevicesValid = (assetType === 'device' || assetType === 'both')
        ? (deviceRows.length > 0 && deviceRows.every(r => r.device_model_id && r.device_id))
        : true;

    const isSimsValid = (assetType === 'sim' || assetType === 'both')
        ? (simRows.length > 0 && simRows.every(r => r.sim_type && r.sim_id))
        : true;

    const isNewCustomerValid = isCreatingNewCustomer
        ? (Boolean(newCustomerForm.username.trim()) && /^\d{10}$/.test(newCustomerForm.primary_mobile_no.trim()))
        : true;

    const canSubmit = hasValidRecipient && isDevicesValid && isSimsValid && isNewCustomerValid && courierDate;

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

            const payload = {
                courier_to_person: courierToPerson,
                dealer_id: courierToPerson === 'Dealer' ? Number(selectedDealer) : null,
                technician_id: courierToPerson === 'Technician' ? Number(selectedTechnician) : null,
                customer_id: isCreatingNewCustomer ? null : (courierToPerson === 'Customer' ? Number(selectedCustomer) : null),
                is_new_customer: isCreatingNewCustomer,
                username: isCreatingNewCustomer ? newCustomerForm.username.trim() : null,
                primary_mobile_no: isCreatingNewCustomer ? newCustomerForm.primary_mobile_no.trim() : null,
                new_customer_data: isCreatingNewCustomer ? {
                    step1: {
                        platform_id: newCustomerForm.platform_id ? Number(newCustomerForm.platform_id) : null,
                        username: newCustomerForm.username.trim(),
                        primary_mobile_no: newCustomerForm.primary_mobile_no.trim(),
                        secondary_mobile_no: newCustomerForm.secondary_mobile_no.trim(),
                        email: newCustomerForm.email.trim(),
                        location: newCustomerForm.location.trim(),
                        pincode: newCustomerForm.pincode.trim()
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
                        installation_person_type: newCustomerForm.installation_person_type,
                        installation_person_id: newCustomerForm.installation_person_id ? Number(newCustomerForm.installation_person_id) : null,
                        installation_date: newCustomerForm.installation_date,
                        lead_closure_id: newCustomerForm.lead_closure_id ? Number(newCustomerForm.lead_closure_id) : null
                    },
                    step4: {
                        totalSaleAmount: newCustomerForm.totalSaleAmount,
                        transactionId: newCustomerForm.payment_step4_transaction_id,
                        paymentMode: newCustomerForm.payment_step4_mode
                    },
                    step5: {
                        deviceCharge: newCustomerForm.deviceCharge,
                        softwareCharge: newCustomerForm.softwareCharge,
                        technicianCharge: newCustomerForm.technicianCharge,
                        simCharge: newCustomerForm.simCharge,
                        courierCharge: newCustomerForm.courierCharge,
                        totalAmount: newCustomerForm.totalAmount,
                        cashToTechnician: Boolean(newCustomerForm.cashToTechnician),
                        cashToDealer: Boolean(newCustomerForm.cashToDealer),
                        amountPaid: newCustomerForm.amountPaid,
                        amountPending: newCustomerForm.amountPending,
                        paymentStatus: newCustomerForm.paymentStatus,
                        paymentMode: newCustomerForm.payment_step5_mode,
                        transactionId: newCustomerForm.payment_step5_transaction_id
                    }
                } : null,
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
                notes: notes
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
            maxWidth={isCreatingNewCustomer ? "840px" : "640px"}
        >
            <div style={{ maxHeight: '78vh', overflowY: 'auto', paddingRight: '4px' }}>
                <form onSubmit={handleSubmit} className="courier-modal-form">
                    
                    {error && <div className="alert alert-danger mb-3">{error}</div>}

                    {/* ======================================================
                        1. COURIER TO PERSON
                    ====================================================== */}
                    <div className="form-group mb-3">
                        <label className="form-label font-weight-bold">
                            Select Courier To Person <span className="text-danger">*</span>
                        </label>
                        <select
                            className="form-control"
                            value={courierToPerson}
                            onChange={(e) => {
                                setCourierToPerson(e.target.value);
                                setSelectedDealer('');
                                setSelectedTechnician('');
                                setSelectedCustomer('');
                            }}
                            disabled={isEdit || saving}
                        >
                            {personOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* ======================================================
                        2A. SELECT DEALER (When Dealer is selected)
                    ====================================================== */}
                    {courierToPerson === 'Dealer' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Dealer <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={dealerOptions}
                                value={selectedDealer}
                                onChange={(val) => setSelectedDealer(val)}
                                placeholder="Search & select dealer..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}

                    {/* ======================================================
                        2B. SELECT TECHNICIAN (When Technician is selected)
                    ====================================================== */}
                    {courierToPerson === 'Technician' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Technician <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={technicianOptions}
                                value={selectedTechnician}
                                onChange={(val) => setSelectedTechnician(val)}
                                placeholder="Search & select technician..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}

                    {/* ======================================================
                        2C. SELECT CUSTOMER (When Customer is selected)
                    ====================================================== */}
                    {courierToPerson === 'Customer' && (
                        <div className="form-group mb-3">
                            <label className="form-label font-weight-bold">
                                Select Customer <span className="text-danger">*</span>
                            </label>
                            <SearchableDropdown
                                options={customerOptions}
                                value={selectedCustomer}
                                onChange={(val) => setSelectedCustomer(val)}
                                placeholder="Search & select customer or create new..."
                                disabled={isEdit || saving}
                            />
                        </div>
                    )}

                    {/* ======================================================
                        NEW CUSTOMER CREATION FLOW SECTIONS
                    ====================================================== */}
                    {isCreatingNewCustomer && (
                        <div className="new-customer-courier-sections mb-4">
                            
                            {/* SECTION 1: BASIC CUSTOMER INFORMATION */}
                            <div className="card p-3 mb-3 border shadow-sm" style={{ backgroundColor: '#f8fafc', borderColor: '#cbd5e1' }}>
                                <div className="d-flex align-items-center gap-2 mb-3 text-primary font-weight-bold" style={{ fontSize: '15px' }}>
                                    <UserPlus size={18} />
                                    <span>STEP 1 – BASIC CUSTOMER INFORMATION</span>
                                </div>
                                <div className="row">
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Platform</label>
                                        <select
                                            className="form-control"
                                            name="platform_id"
                                            value={newCustomerForm.platform_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="">Select Platform</option>
                                            {platformsList.map(p => (
                                                <option key={p.id} value={p.id}>{p.name}</option>
                                            ))}
                                        </select>
                                    </div>
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
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Installation Type</label>
                                        <select
                                            className="form-control"
                                            name="installation_person_type"
                                            value={newCustomerForm.installation_person_type}
                                            onChange={(e) => {
                                                handleNewCustomerChange(e);
                                                setNewCustomerForm(prev => ({ ...prev, installation_person_id: '' }));
                                            }}
                                            disabled={saving}
                                        >
                                            {installationTypeOptions.map(opt => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-6 mb-3">
                                        <label className="form-label small font-weight-bold">Installation Person</label>
                                        <select
                                            className="form-control"
                                            name="installation_person_id"
                                            value={newCustomerForm.installation_person_id}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving || newCustomerForm.installation_person_type === 'Direct'}
                                        >
                                            <option value="">Select Person</option>
                                            {getInstallationPersonOptions().map(opt => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </select>
                                    </div>
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
                                            className="form-control"
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
                                            disabled={saving}
                                        />
                                    </div>
                                </div>
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
                                            className="form-control"
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
                                            className="form-control"
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
                                            className="form-control"
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
                                            className="form-control"
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
                                            className="form-control"
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
                                            value={newCustomerForm.totalAmount}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        />
                                    </div>

                                    {/* Cash Collection Recipient Checkboxes */}
                                    <div className="col-md-12 mb-3">
                                        <label className="form-label small font-weight-bold d-block">Cash Collection Recipient</label>
                                        <div className="d-flex gap-4">
                                            <label className="d-inline-flex align-items-center gap-2 small cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    name="cashToTechnician"
                                                    checked={newCustomerForm.cashToTechnician}
                                                    onChange={handleNewCustomerChange}
                                                    disabled={saving}
                                                />
                                                <span>Cash to Technician</span>
                                            </label>
                                            <label className="d-inline-flex align-items-center gap-2 small cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    name="cashToDealer"
                                                    checked={newCustomerForm.cashToDealer}
                                                    onChange={handleNewCustomerChange}
                                                    disabled={saving}
                                                />
                                                <span>Cash to Dealer</span>
                                            </label>
                                        </div>
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
                                            value={newCustomerForm.amountPending}
                                            readOnly
                                            style={{ backgroundColor: '#f1f5f9' }}
                                        />
                                    </div>
                                    <div className="col-md-4 mb-3">
                                        <label className="form-label small font-weight-bold">Payment Status</label>
                                        <select
                                            className="form-control"
                                            name="paymentStatus"
                                            value={newCustomerForm.paymentStatus}
                                            onChange={handleNewCustomerChange}
                                            disabled={saving}
                                        >
                                            <option value="Paid">Paid</option>
                                            <option value="Partial">Partial</option>
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
                                            disabled={saving}
                                        />
                                    </div>
                                </div>
                            </div>

                        </div>
                    )}

                    {/* ======================================================
                        3. ASSET TYPE
                    ====================================================== */}
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

                    {/* ======================================================
                        6. COMMON ASSET METADATA (Software, Courier Date, Notes)
                    ====================================================== */}
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
                            disabled={!canSubmit || saving}
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
