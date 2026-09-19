import { useEffect, useMemo, useState } from 'react';

import {
    useLocation,
    useNavigate,
    useParams
} from 'react-router-dom';

import {
    ArrowLeft,
    ArrowRight,
    RefreshCw,
    Car
} from 'lucide-react';

import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';


const initialForm = {
    vehicle_no: '',
    vehicle_type_id: '',
    imei_no: '',
    sim_no_1: '',
    sim_no_2: '',
    device_model_id: '',
    validity_id: ''
};


const CustomerVehicleDetailsPage = () => {

    const navigate = useNavigate();

    const location = useLocation();

    const { customerId } = useParams();

    const { hasPermission } = useAuth();


    const [form, setForm] = useState(initialForm);

    const [vehicleTypes, setVehicleTypes] = useState([]);

    const [deviceTypes, setDeviceTypes] = useState([]);

    const [simValidities, setSimValidities] = useState([]);


    const [loading, setLoading] = useState(true);

    const [saving, setSaving] = useState(false);


    const [imeiLookupLoading, setImeiLookupLoading] =
        useState(false);

    const [imeiLookupStatus, setImeiLookupStatus] =
        useState('');

    const [imeiLookupMessage, setImeiLookupMessage] =
        useState('');
    const [simLookup, setSimLookup] = useState({ sim_no_1: '', sim_no_2: '' });


    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const [success, setSuccess] = useState('');


    const [vehicleRecordId, setVehicleRecordId] =
        useState(null);

    const [existingVehicleImei, setExistingVehicleImei] =
        useState('');
    const [existingVehicleSim1, setExistingVehicleSim1] =
        useState('');
    const [existingVehicleSim2, setExistingVehicleSim2] =
        useState('');


    const [loadedVehicleFromApi, setLoadedVehicleFromApi] =
        useState(false);

    const [showStep5BlockedModal, setShowStep5BlockedModal] =
        useState(false);

    const canAddCustomer =
        hasPermission('customers.add');

    const canEditCustomer =
        hasPermission('customers.edit');


    /*
    |--------------------------------------------------------------------------
    | EXPLICIT EDIT ROUTE CHECK
    |--------------------------------------------------------------------------
    */

    const routeLooksLikeEdit =
        location.pathname.endsWith('/edit') ||
        location.pathname.includes('/edit/');

    const [appendVehicle, setAppendVehicle] = useState(false);


    /*
    |--------------------------------------------------------------------------
    | EDIT MODE
    |--------------------------------------------------------------------------
    |
    | A customerId exists in both create flow and edit flow.
    | Therefore customerId alone MUST NOT decide edit mode.
    |
    | Existing vehicle record OR explicit /edit route indicates edit.
    |--------------------------------------------------------------------------
    */

    const addNewVehicleMode =
        !routeLooksLikeEdit &&
        (appendVehicle || new URLSearchParams(location.search).get('mode') === 'add');

    const editVehicleMode =
        !addNewVehicleMode && (Boolean(vehicleRecordId) || routeLooksLikeEdit);

    const isEditMode = editVehicleMode;


    const isDeviceModelLocked =
        imeiLookupStatus === 'verified' ||
        (
            Boolean(existingVehicleImei) &&
            form.imei_no === existingVehicleImei &&
            Boolean(form.device_model_id)
        );


    /*
    |--------------------------------------------------------------------------
    | SESSION KEY
    |--------------------------------------------------------------------------
    */

    const storageKey =
        customerId
            ? `customer_creation_${customerId}`
            : 'customer_creation_new';

    /*
    |--------------------------------------------------------------------------
    | HELPER
    |--------------------------------------------------------------------------
    */

    const extractArray = (
        response,
        possibleKeys = []
    ) => {

        const responseData =
            response?.data;


        if (
            Array.isArray(responseData)
        ) {
            return responseData;
        }


        const dataObject =
            responseData?.data;


        if (
            Array.isArray(dataObject)
        ) {
            return dataObject;
        }


        for (
            const key of possibleKeys
        ) {

            if (
                Array.isArray(
                    dataObject?.[key]
                )
            ) {
                return dataObject[key];
            }


            if (
                Array.isArray(
                    responseData?.[key]
                )
            ) {
                return responseData[key];
            }

        }


        return [];
    };


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE VALIDITY
    |--------------------------------------------------------------------------
    */

    const normalizeValidityList = (
        validityData
    ) => {

        return validityData
            .map(item => {

                if (!item) {
                    return null;
                }


                const id =
                    item.id ??
                    item.validity_id ??
                    item.sim_validity_id;


                let months =
                    item.validity_months ??
                    item.months ??
                    item.validity ??
                    item.month ??
                    item.duration_months ??
                    item.duration;


                if (
                    typeof months === 'string'
                ) {

                    const match =
                        months.match(/\d+/);

                    if (match) {
                        months =
                            Number(match[0]);
                    }

                }


                return {
                    ...item,

                    id,

                    months:
                        Number(months)
                };

            })
            .filter(item => {

                if (!item) {
                    return false;
                }


                if (
                    !item.id ||
                    !item.months ||
                    Number.isNaN(
                        Number(item.months)
                    )
                ) {
                    return false;
                }


                if (
                    item.status !== undefined &&
                    item.status !== null &&
                    item.status !== ''
                ) {

                    return (
                        String(item.status)
                            .toLowerCase() ===
                        'active'
                    );

                }


                return true;

            })
            .sort(
                (a, b) =>
                    Number(a.months) -
                    Number(b.months)
            );

    };


    /*
    |--------------------------------------------------------------------------
    | LOAD MASTER DATA
    |--------------------------------------------------------------------------
    */

    const loadMasterData = async () => {

        const [
            vehicleResponse,
            deviceResponse,
            validityResponse
        ] = await Promise.all([

            api.get(
                '/vehicle_types/list.php'
            ),

            api.get(
                '/device_types/list.php'
            ),

            api.get(
                '/sim_validities/list.php'
            )

        ]);


        /*
        |--------------------------------------------------------------------------
        | VEHICLE TYPES
        |--------------------------------------------------------------------------
        */

        const vehicleData =
            extractArray(
                vehicleResponse,
                [
                    'vehicle_types',
                    'vehicleTypes',
                    'vehicle_type',
                    'vehicleTypesList'
                ]
            );


        const activeVehicleTypes =
            vehicleData.filter(
                item => {

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
                        String(
                            item.status
                        ).toLowerCase() ===
                        'active'
                    );

                }
            );


        setVehicleTypes(
            activeVehicleTypes
        );


        /*
        |--------------------------------------------------------------------------
        | DEVICE TYPES
        |--------------------------------------------------------------------------
        */

        const deviceData =
            extractArray(
                deviceResponse,
                [
                    'device_types',
                    'deviceTypes',
                    'device_type',
                    'deviceTypesList'
                ]
            );


        const activeDeviceTypes =
            deviceData.filter(
                item => {

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
                        String(
                            item.status
                        ).toLowerCase() ===
                        'active'
                    );

                }
            );


        setDeviceTypes(
            activeDeviceTypes
        );


        /*
        |--------------------------------------------------------------------------
        | SIM VALIDITY
        |--------------------------------------------------------------------------
        */

        const validityData =
            extractArray(
                validityResponse,
                [
                    'sim_validities',
                    'validities',
                    'simValidity',
                    'sim_validity',
                    'validity',
                    'validity_options',
                    'simValidityOptions'
                ]
            );


        const normalizedValidityData =
            normalizeValidityList(
                validityData
            );


        setSimValidities(
            normalizedValidityData
        );


        return {
            vehicleTypes:
                activeVehicleTypes,

            deviceTypes:
                activeDeviceTypes,

            simValidities:
                normalizedValidityData
        };

    };


    /*
    |--------------------------------------------------------------------------
    | LOAD SAVED SESSION
    |--------------------------------------------------------------------------
    */

    const getStoredCustomerData = () => {

        try {

            return JSON.parse(
                sessionStorage.getItem(
                    storageKey
                ) || '{}'
            );

        } catch (err) {

            console.error(
                'Session parse error:',
                err
            );

            return {};

        }

    };


    /*
    |--------------------------------------------------------------------------
    | MAP API VEHICLE
    |--------------------------------------------------------------------------
    */

    const mapVehicleToForm = (
        vehicle,
        validityList = []
    ) => {

        if (!vehicle) {

            return {
                ...initialForm
            };

        }


        let validityId =
            vehicle.validity_id ??
            vehicle.sim_validity_id ??
            '';


        /*
        |--------------------------------------------------------------------------
        | If API returns only validity_months,
        | find corresponding master ID.
        |--------------------------------------------------------------------------
        */

        if (
            !validityId &&
            vehicle.validity_months
        ) {

            const matchedValidity =
                validityList.find(
                    item =>
                        Number(item.months) ===
                        Number(
                            vehicle.validity_months
                        )
                );


            if (matchedValidity) {

                validityId =
                    matchedValidity.id;

            }

        }


        return {

            vehicle_no:
                vehicle.vehicle_no ??
                '',

            vehicle_type_id:
                vehicle.vehicle_type_id ??
                '',

            imei_no:
                vehicle.imei_no ??
                '',

            sim_no_1:
                vehicle.sim_no_1 ??
                '',

            sim_no_2:
                vehicle.sim_no_2 ??
                '',

            device_model_id:
                vehicle.device_model_id ??
                '',

            validity_id:
                validityId
                ? String(validityId)
                : ''

        };

    };


    /*
    |--------------------------------------------------------------------------
    | LOAD VEHICLE DATA
    |--------------------------------------------------------------------------
    */

    const loadSavedVehicleData = async (
        masterData
    ) => {

        const stored =
            getStoredCustomerData();

        const requestedAddNewVehicle =
            !routeLooksLikeEdit &&
            (Boolean(stored.append_vehicle) || new URLSearchParams(location.search).get('mode') === 'add');

        if (requestedAddNewVehicle) {
            setAppendVehicle(true);
            setVehicleRecordId(null);
            setExistingVehicleImei('');
            setExistingVehicleSim1('');
            setExistingVehicleSim2('');
            setLoadedVehicleFromApi(false);
            setImeiLookupStatus('');
            setImeiLookupMessage('');
            setForm({ ...initialForm });
            return;
        }

        setAppendVehicle(false);


        /*
        |--------------------------------------------------------------------------
        | SESSION STEP 2
        |--------------------------------------------------------------------------
        |
        | This is important for:
        |
        | Step 2 → Step 3 → Back
        |
        | so entered values are preserved.
        |--------------------------------------------------------------------------
        */

        if (
            stored?.step2
        ) {

            const sessionForm = {

                vehicle_no:
                    stored.step2.vehicle_no ??
                    '',

                vehicle_type_id:
                    stored.step2.vehicle_type_id ??
                    '',

                imei_no:
                    stored.step2.imei_no ??
                    '',

                sim_no_1:
                    stored.step2.sim_no_1 ??
                    '',

                sim_no_2:
                    stored.step2.sim_no_2 ??
                    '',

                device_model_id:
                    stored.step2.device_model_id ??
                    '',

                validity_id:
                    stored.step2.validity_id ??
                    ''

            };


            setExistingVehicleImei(
                stored.existing_vehicle_imei ||
                sessionForm.imei_no ||
                ''
            );
            setExistingVehicleSim1(
                stored.existing_vehicle_sim_1 ||
                sessionForm.sim_no_1 ||
                ''
            );
            setExistingVehicleSim2(
                stored.existing_vehicle_sim_2 ||
                sessionForm.sim_no_2 ||
                ''
            );

            if (stored.vehicle_record_id) {

                setVehicleRecordId(
                    Number(
                        stored.vehicle_record_id
                    )
                );

                setForm(sessionForm);

                return;

            }

            setForm(sessionForm);

        }


        /*
        |--------------------------------------------------------------------------
        | NO CUSTOMER ID
        |--------------------------------------------------------------------------
        */

        if (!customerId) {

            setForm(
                initialForm
            );

            return;

        }


        /*
        |--------------------------------------------------------------------------
        | FETCH CUSTOMER DETAILS
        |--------------------------------------------------------------------------
        */

        try {

            const response =
                await api.get(
                    `/customers/details.php?customer_id=${encodeURIComponent(customerId)}`
                );


            if (
                !response.data?.success
            ) {

                return;

            }


            const vehicle =
                response.data?.data?.vehicle ||
                response.data?.data?.vehicle_details ||
                response.data?.vehicle ||
                null;


            if (!vehicle) {

                return;

            }


            const nextForm =
                mapVehicleToForm(
                    vehicle,
                    masterData.simValidities
                );


            setVehicleRecordId(
                vehicle.id
                    ? Number(vehicle.id)
                    : null
            );


            setExistingVehicleImei(
                vehicle.imei_no ||
                ''
            );
            setExistingVehicleSim1(
                vehicle.sim_no_1 ||
                ''
            );
            setExistingVehicleSim2(
                vehicle.sim_no_2 ||
                ''
            );


            setForm(
                nextForm
            );


            setLoadedVehicleFromApi(
                true
            );


            /*
            |--------------------------------------------------------------------------
            | Save fetched existing data into session.
            |--------------------------------------------------------------------------
            */

            const existingStored =
                getStoredCustomerData();


            sessionStorage.setItem(
                storageKey,
                JSON.stringify({

                    ...existingStored,

                    customer_id:
                        Number(customerId),

                    vehicle_record_id:
                        vehicle.id
                            ? Number(vehicle.id)
                            : null,

                    existing_vehicle_imei:
                        vehicle.imei_no ||
                        '',

                    existing_vehicle_sim_1:
                        vehicle.sim_no_1 ||
                        '',

                    existing_vehicle_sim_2:
                        vehicle.sim_no_2 ||
                        '',

                    step2:
                        nextForm

                })
            );


            /*
            |--------------------------------------------------------------------------
            | Existing IMEI should not appear invalid.
            |--------------------------------------------------------------------------
            */

            if (
                vehicle.imei_no
            ) {

                setImeiLookupStatus(
                    'verified'
                );

                setImeiLookupMessage(
                    '✓ Existing IMEI verified'
                );

            }

        } catch (err) {

            console.error(
                'Vehicle details fetch error:',
                err
            );

        }

    };


    /*
    |--------------------------------------------------------------------------
    | INITIAL LOAD
    |--------------------------------------------------------------------------
    */

    useEffect(() => {

        let mounted = true;


        const initialise = async () => {

            try {

                setLoading(true);

                setError('');

                const storedCustomerData = getStoredCustomerData();
                const openingAddNewVehicle =
                    !routeLooksLikeEdit &&
                    (Boolean(storedCustomerData.append_vehicle) || new URLSearchParams(location.search).get('mode') === 'add');

                setAppendVehicle(openingAddNewVehicle);
                if (openingAddNewVehicle) {
                    setVehicleRecordId(null);
                    setExistingVehicleImei('');
                    setExistingVehicleSim1('');
                    setExistingVehicleSim2('');
                    setForm({ ...initialForm });
                }


                const masterData =
                    await loadMasterData();


                if (!mounted) {
                    return;
                }


                await loadSavedVehicleData(
                    masterData
                );


            } catch (err) {

                console.error(
                    'Vehicle page initialisation error:',
                    err
                );


                if (mounted) {

                    triggerError(
                        err.response?.data?.message ||
                        err.message ||
                        'Failed to load vehicle details.'
                    );

                }

            } finally {

                if (mounted) {

                    setLoading(false);

                }

            }

        };


        initialise();


        return () => {

            mounted = false;

        };

    }, [customerId]);


    /*
    |--------------------------------------------------------------------------
    | IMEI LOOKUP
    |--------------------------------------------------------------------------
    */

    useEffect(() => {

        let active = true;

        const imei =
            form.imei_no.trim();


        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        if (!imei) {

            setImeiLookupLoading(false);

            setImeiLookupStatus('');

            setImeiLookupMessage('');

            return () => {

                active = false;

            };

        }


        /*
        |--------------------------------------------------------------------------
        | Existing customer's own IMEI
        |--------------------------------------------------------------------------
        |
        | It must remain valid during edit.
        |--------------------------------------------------------------------------
        */

        if (
            existingVehicleImei &&
            imei === existingVehicleImei &&
            form.device_model_id
        ) {

            setImeiLookupLoading(false);

            setImeiLookupStatus(
                'verified'
            );

            setImeiLookupMessage(
                '✓ Existing IMEI verified'
            );

            return () => {

                active = false;

            };

        }


        /*
        |--------------------------------------------------------------------------
        | Only lookup at 15 digits
        |--------------------------------------------------------------------------
        */

        if (
            !/^\d{15}$/.test(imei)
        ) {

            setImeiLookupLoading(false);

            setImeiLookupStatus('');

            setImeiLookupMessage('');

            return () => {

                active = false;

            };

        }


        const timer =
            setTimeout(
                async () => {

                    setImeiLookupLoading(
                        true
                    );

                    setImeiLookupStatus(
                        'checking'
                    );

                    setImeiLookupMessage(
                        'Checking IMEI...'
                    );


                    try {

                        const response =
                            await api.get(
                                `/devices/get_by_imei.php?imei=${encodeURIComponent(imei)}`
                            );


                        if (!active) {
                            return;
                        }


                        const device =
                            response.data?.data ||
                            response.data?.device ||
                            {};


                        if (
                            !response.data?.success ||
                            !device?.id
                        ) {

                            throw new Error(
                                response.data?.message ||
                                'IMEI not found in device stock.'
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Auto populate Device Model
                        |--------------------------------------------------------------------------
                        */

                        setForm(
                            previous => ({

                                ...previous,

                                device_model_id:
                                    device.device_model_id
                                        ? String(
                                            device.device_model_id
                                        )
                                        : ''

                            })
                        );


                        /*
                        |--------------------------------------------------------------------------
                        | Current customer's own IMEI
                        |--------------------------------------------------------------------------
                        */

                        if (
                            existingVehicleImei &&
                            imei === existingVehicleImei
                        ) {

                            setImeiLookupStatus(
                                'verified'
                            );

                            setImeiLookupMessage(
                                '✓ Existing IMEI verified'
                            );

                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Already used
                        |--------------------------------------------------------------------------
                        */

                        const deviceStatus = String(device.status || '').toLowerCase();
                        const ownerType = String(device.owner_type || '').toLowerCase();
                        const ownerInstallationStatus = String(device.owner_installation_status || '').toLowerCase();
                        const ownerEligible = ownerType === 'technician' || (ownerType === 'dealer' && ['onsite', 'offsite'].includes(ownerInstallationStatus));
                        const deviceUsed = Boolean(device.is_used) || ['et', 'technician', 'dealer'].includes(String(device.usage_type || '').toLowerCase());

                        if (deviceUsed || deviceStatus === 'used') {

                            setImeiLookupStatus(
                                'used'
                            );

                            setImeiLookupMessage(
                                'This IMEI is already used for a customer.'
                            );

                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Available
                        |--------------------------------------------------------------------------
                        */

                        if (deviceStatus === 'available' || (deviceStatus === 'allocated' && ownerEligible)) {

                            setImeiLookupStatus(
                                'verified'
                            );

                            setImeiLookupMessage(
                                '✓ IMEI verified'
                            );

                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Other status
                        |--------------------------------------------------------------------------
                        */

                        setImeiLookupStatus(
                            'unavailable'
                        );

                        setImeiLookupMessage(
                            'This IMEI is not available for allocation.'
                        );

                    } catch (err) {

                        if (!active) {
                            return;
                        }


                        setForm(
                            previous => ({

                                ...previous,

                                device_model_id: ''

                            })
                        );


                        setImeiLookupStatus(
                            'error'
                        );


                        setImeiLookupMessage(
                            err.response?.data?.message ||
                            err.message ||
                            'IMEI not found in device stock.'
                        );

                    } finally {

                        if (active) {

                            setImeiLookupLoading(
                                false
                            );

                        }

                    }

                },

                250

            );


        return () => {

            clearTimeout(timer);

            active = false;

        };

    }, [
        form.imei_no,
        existingVehicleImei,
        form.device_model_id
    ]);

    useEffect(() => {
        let active = true;
        const fields = ['sim_no_1', 'sim_no_2'];
        const checkSims = async () => {
            const next = { sim_no_1: '', sim_no_2: '' };
            for (const field of fields) {
                const value = form[field].trim();
                if (!value) continue;
                if (!/^\d{10}$/.test(value) && !/^\d{13}$/.test(value)) {
                    next[field] = `✕ ${field === 'sim_no_1' ? 'SIM No 1' : 'SIM No 2'} must contain exactly 10 or 13 digits.`;
                    continue;
                }
                const isExistingSim =
                    (field === 'sim_no_1' && existingVehicleSim1 && value === existingVehicleSim1) ||
                    (field === 'sim_no_2' && existingVehicleSim2 && value === existingVehicleSim2);

                if (isExistingSim) {
                    next[field] = '✓ Existing SIM verified';
                    continue;
                }

                try {
                    const response = await api.get(`/sims/list.php?search=${encodeURIComponent(value)}`);
                    const sims = response.data?.data?.sims || [];
                    const found = sims.find((sim) => String(sim.sim_no) === value);
                    if (!found) {
                        next[field] = `✕ ${field === 'sim_no_1' ? 'SIM No 1' : 'SIM No 2'} does not exist in SIM Maintenance.`;
                    } else {
                        const simStatus = String(found.status || '').toLowerCase();
                        if (simStatus === 'used') {
                            next[field] = `✕ ${field === 'sim_no_1' ? 'SIM No 1' : 'SIM No 2'} is already used by another customer.`;
                        } else {
                            next[field] = '✓ SIM verified';
                        }
                    }
                } catch {
                    next[field] = `✕ ${field === 'sim_no_1' ? 'SIM No 1' : 'SIM No 2'} does not exist in SIM Maintenance.`;
                }
            }
            if (active) setSimLookup(next);
        };
        const timer = setTimeout(checkSims, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [form.sim_no_1, form.sim_no_2, existingVehicleSim1, existingVehicleSim2]);


    /*
    |--------------------------------------------------------------------------
    | INPUT CHANGE
    |--------------------------------------------------------------------------
    */

    const handleChange = (
        event
    ) => {

        const {
            name,
            value
        } = event.target;


        let updatedValue =
            value;


        /*
        |--------------------------------------------------------------------------
        | VEHICLE NUMBER
        |--------------------------------------------------------------------------
        */

        if (
            name === 'vehicle_no'
        ) {

            updatedValue =
                value
                    .toUpperCase()
                    .replace(/^\s+/, '')
                    .slice(0, 20);

        }


        /*
        |--------------------------------------------------------------------------
        | IMEI
        |--------------------------------------------------------------------------
        */

        if (
            name === 'imei_no'
        ) {

            updatedValue =
                value
                    .replace(/\D/g, '')
                    .slice(0, 15);


            setImeiLookupLoading(
                false
            );

            setImeiLookupStatus('');

            setImeiLookupMessage('');


            /*
            |--------------------------------------------------------------------------
            | If IMEI changed from current customer IMEI,
            | clear device model so new model must be fetched.
            |--------------------------------------------------------------------------
            */

            if (
                updatedValue !==
                existingVehicleImei
            ) {

                setForm(
                    previous => ({

                        ...previous,

                        device_model_id: ''

                    })
                );

            }

        }


        /*
        |--------------------------------------------------------------------------
        | SIM
        |--------------------------------------------------------------------------
        */

        if (
            name === 'sim_no_1' ||
            name === 'sim_no_2'
        ) {

            updatedValue =
                value
                    .replace(/\D/g, '')
                    .slice(0, 13);

        }


        setForm(
            previous => ({

                ...previous,

                [name]:
                    updatedValue

            })
        );


        setError('');

        setSuccess('');

    };


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    const validateForm = () => {

        if (
            !form.vehicle_no.trim()
        ) {

            return (
                'Vehicle No is required.'
            );

        }


        if (
            !form.vehicle_type_id
        ) {

            return (
                'Please select Vehicle Type.'
            );

        }


        if (
            !form.imei_no
        ) {

            return (
                'IMEI No is required.'
            );

        }


        if (
            !/^\d{15}$/.test(
                form.imei_no
            )
        ) {

            return (
                'IMEI No must contain exactly 15 digits.'
            );

        }


        if (
            imeiLookupStatus === 'used' &&
            form.imei_no !== existingVehicleImei
        ) {

            return (
                'This IMEI is already used for a customer.'
            );

        }


        if (
            imeiLookupStatus === 'error'
        ) {

            return (
                'Please enter a valid IMEI from Device Maintenance.'
            );

        }


        if (
            imeiLookupStatus === 'unavailable'
        ) {

            return (
                'This IMEI is not available for allocation.'
            );

        }


        if (
            !form.sim_no_1
        ) {

            return (
                'SIM No 1 is required.'
            );

        }

        if (simLookup.sim_no_1 && simLookup.sim_no_1.startsWith('✕')) return simLookup.sim_no_1.replace('✕ ', '');


        if (
            !/^\d{10}$/.test(
                form.sim_no_1
            ) &&
            !/^\d{13}$/.test(
                form.sim_no_1
            )
        ) {

            return (
                'SIM No 1 must contain exactly 10 or 13 digits.'
            );

        }


        if (
            form.sim_no_2
        ) {

            if (
                !/^\d{10}$/.test(
                    form.sim_no_2
                ) &&
                !/^\d{13}$/.test(
                    form.sim_no_2
                )
            ) {

                return (
                    'SIM No 2 must contain exactly 10 or 13 digits.'
                );

            }

            if (simLookup.sim_no_2 && simLookup.sim_no_2.startsWith('✕')) return simLookup.sim_no_2.replace('✕ ', '');


            if (
                form.sim_no_1 ===
                form.sim_no_2
            ) {

                return (
                    'SIM No 1 and SIM No 2 cannot be the same.'
                );

            }

        }


        if (
            !form.device_model_id
        ) {

            return (
                'Please select Device Model.'
            );

        }


        if (
            !form.validity_id
        ) {

            return (
                'Please select Validity.'
            );

        }


        return '';

    };


    /*
    |--------------------------------------------------------------------------
    | SAVE STEP 2 TO SESSION
    |--------------------------------------------------------------------------
    */

    const saveStep2ToSession = (
        payload,
        savedRecordId = null
    ) => {

        try {

            const existing =
                getStoredCustomerData();


            sessionStorage.setItem(
                storageKey,
                JSON.stringify({

                    ...existing,

                    customer_id:
                        Number(customerId),

                    append_vehicle: false,

                    vehicle_record_id:
                        savedRecordId ||
                        vehicleRecordId ||
                        existing.vehicle_record_id ||
                        null,

                    existing_vehicle_imei:
                        existingVehicleImei ||
                        form.imei_no,

                    existing_vehicle_sim_1:
                        existingVehicleSim1 ||
                        form.sim_no_1,

                    existing_vehicle_sim_2:
                        existingVehicleSim2 ||
                        form.sim_no_2,

                    step2:
                        payload

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
    | SAVE / UPDATE
    |--------------------------------------------------------------------------
    */

    const handleSaveAndNext = async (
        event
    ) => {

        event.preventDefault();


        setError('');

        setSuccess('');


        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        const validationError =
            validateForm();


        if (validationError) {

            triggerError(
                validationError
            );

            return;

        }


        /*
        |--------------------------------------------------------------------------
        | PERMISSION
        |--------------------------------------------------------------------------
        */

        if (
            isEditMode &&
            !canEditCustomer
        ) {

            triggerError(
                'You do not have permission to edit customers.'
            );

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


        /*
        |--------------------------------------------------------------------------
        | CUSTOMER ID
        |--------------------------------------------------------------------------
        */

        if (!customerId) {

            triggerError(
                'Customer ID is missing.'
            );

            return;

        }


        try {

            setSaving(true);


            const payload = {

                customer_id:
                    Number(customerId),

                append_vehicle:
                    appendVehicle,

                vehicle_no:
                    form.vehicle_no.trim(),

                vehicle_type_id:
                    Number(
                        form.vehicle_type_id
                    ),

                imei_no:
                    form.imei_no.trim(),

                sim_no_1:
                    form.sim_no_1.trim(),

                sim_no_2:
                    form.sim_no_2.trim() ||
                    null,

                device_model_id:
                    Number(
                        form.device_model_id
                    ),

                validity_id:
                    Number(
                        form.validity_id
                    )

            };


            let response;


            /*
            |--------------------------------------------------------------------------
            | EDIT EXISTING VEHICLE
            |--------------------------------------------------------------------------
            */

            if (
                vehicleRecordId
            ) {

                response =
                    await api.put(
                        '/customers/vehicle_details/update.php',
                        {
                            id:
                                Number(
                                    vehicleRecordId
                                ),

                            ...payload
                        }
                    );

            }

            /*
            |--------------------------------------------------------------------------
            | CREATE NEW VEHICLE
            |--------------------------------------------------------------------------
            */

            else {

                response =
                    await api.post(
                        '/customers/vehicle_details/create.php',
                        payload
                    );

            }


            if (
                !response.data?.success
            ) {

                throw new Error(
                    response.data?.message ||
                    'Failed to save vehicle details.'
                );

            }


            /*
            |--------------------------------------------------------------------------
            | GET SAVED RECORD ID
            |--------------------------------------------------------------------------
            */

            const savedRecordId =
                Number(
                    vehicleRecordId ||
                    response.data?.data?.id ||
                    response.data?.data?.vehicle_id ||
                    response.data?.data?.vehicle_record_id ||
                    0
                );


            if (
                savedRecordId
            ) {

                setVehicleRecordId(
                    savedRecordId
                );

            }


            /*
            |--------------------------------------------------------------------------
            | Existing IMEI
            |--------------------------------------------------------------------------
            */

            setExistingVehicleImei(
                form.imei_no
            );
            setExistingVehicleSim1(
                form.sim_no_1
            );
            setExistingVehicleSim2(
                form.sim_no_2
            );


            /*
            |--------------------------------------------------------------------------
            | SAVE SESSION
            |--------------------------------------------------------------------------
            */

            saveStep2ToSession(
                payload,
                savedRecordId || null
            );


            setSuccess(
                isEditMode
                    ? 'Vehicle details updated successfully.'
                    : 'Vehicle details saved successfully.'
            );


            /*
            |--------------------------------------------------------------------------
            | NEXT STEP
            |--------------------------------------------------------------------------
            */

            setTimeout(
                () => {

                    navigate(
                        `/customer-management/details/installation/${customerId}`
                    );

                },

                300
            );


        } catch (err) {

            console.error(
                'Vehicle details save error:',
                err
            );


            triggerError(
                err.response?.data?.message ||
                err.message ||
                'Failed to save vehicle details.'
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

        const confirmed =
            window.confirm(
                'Are you sure you want to reset the vehicle details?'
            );


        if (!confirmed) {
            return;
        }


        setForm(
            initialForm
        );


        setImeiLookupLoading(
            false
        );

        setImeiLookupStatus('');

        setImeiLookupMessage('');

        setError('');

        setSuccess('');


        /*
        |--------------------------------------------------------------------------
        | Important:
        | Do not delete the existing vehicle record ID in edit mode.
        | Reset only clears current form values.
        |--------------------------------------------------------------------------
        */

        try {

            const existing =
                getStoredCustomerData();


            const nextStored = {
                ...existing,

                step2: {
                    ...initialForm
                }
            };


            sessionStorage.setItem(
                storageKey,
                JSON.stringify(
                    nextStored
                )
            );

        } catch (err) {

            console.error(
                'Reset session error:',
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

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | Do NOT reset Step 2.
        |--------------------------------------------------------------------------
        */

        navigate(
            `/customer-management/details/${customerId}`
        );

    };


    /*
    |--------------------------------------------------------------------------
    | DEVICE TYPES FOR SELECT
    |--------------------------------------------------------------------------
    |
    | If current saved device model became inactive,
    | keep the current value visible during edit.
    |--------------------------------------------------------------------------
    */

    const deviceTypesForSelect =
        useMemo(() => {

            const result =
                [...deviceTypes];


            if (
                form.device_model_id &&
                !result.some(
                    item =>
                        String(item.id) ===
                        String(
                            form.device_model_id
                        )
                )
            ) {

                /*
                |--------------------------------------------------------------------------
                | We don't know the inactive model name here.
                | Keep existing select value if available.
                |--------------------------------------------------------------------------
                */

                result.push({
                    id:
                        form.device_model_id,

                    device_type:
                        'Current Device Model'
                });

            }


            return result;

        }, [
            deviceTypes,
            form.device_model_id
        ]);


    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */

    return (

        <div className="page-container">


            {/* =========================================================
                HEADER
            ========================================================= */}

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
                        Vehicle Details
                    </h2>

                    <p className="page-subtitle">
                        {isEditMode
                            ? 'Edit vehicle, device and SIM information'
                            : 'Add vehicle, device and SIM information'}
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

                    <ArrowLeft
                        size={16}
                    />

                    Back

                </button>

            </div>


            {/* =========================================================
                STEPPER
            ========================================================= */}

            <div
                className="card customer-step-card"
                style={{
                    marginBottom: '20px'
                }}
            >

                <div
                    className="customer-stepper"
                >

                    {/* STEP 1 */}

                    <div
                        className="customer-step completed"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
                                navigate(`/customer-management/details/${customerId}`);
                            }
                        }}
                    >

                        <div className="step-circle">
                            ✓
                        </div>

                        <span>
                            Customer Details
                        </span>

                    </div>


                    <div
                        className="step-line active-line"
                    />


                    {/* STEP 2 */}

                    <div
                        className="customer-step active"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
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


                    <div
                        className="step-line"
                    />


                    {/* STEP 3 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
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


                    <div
                        className="step-line"
                    />


                    {/* STEP 4 */}

                    <div
                        className="customer-step disabled"
                        style={{ cursor: customerId ? 'pointer' : 'default' }}
                        onClick={() => {
                            if (customerId) {
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


                    <div
                        className="step-line"
                    />


                    {/* STEP 5 */}

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

                        <div className="step-circle">
                            5
                        </div>

                        <span>
                            Payment Details
                        </span>

                    </div>

                </div>

            </div>


            {/* =========================================================
                ERROR
            ========================================================= */}

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


            {/* =========================================================
                SUCCESS
            ========================================================= */}

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


            {/* =========================================================
                FORM CARD
            ========================================================= */}

            <div className="card">

                <div
                    className="customer-section-title"
                >

                    <Car
                        size={20}
                    />

                    <h3>
                        Vehicle & Tracking Information
                    </h3>

                </div>


                {loading ? (

                    <div
                        style={{
                            padding: '45px',
                            textAlign: 'center',
                            color: '#64748b'
                        }}
                    >
                        Loading vehicle details...
                    </div>

                ) : (

                    <form
                        onSubmit={
                            handleSaveAndNext
                        }
                    >

                        <div
                            className="customer-vehicle-grid"
                        >


                            {/* =================================================
                                VEHICLE NO
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    Vehicle No

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <input
                                    type="text"
                                    name="vehicle_no"
                                    value={
                                        form.vehicle_no
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter vehicle number"
                                    maxLength={20}
                                    disabled={saving}
                                />

                            </div>


                            {/* =================================================
                                VEHICLE TYPE
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    Vehicle Type

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <select
                                    name="vehicle_type_id"
                                    value={
                                        form.vehicle_type_id
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    disabled={
                                        loading ||
                                        saving
                                    }
                                >

                                    <option value="">

                                        {loading
                                            ? 'Loading vehicle types...'
                                            : 'Select Vehicle Type'}

                                    </option>


                                    {vehicleTypes.map(
                                        item => (

                                            <option
                                                key={
                                                    item.id
                                                }
                                                value={
                                                    item.id
                                                }
                                            >

                                                {
                                                    item.vehicle_type
                                                }

                                            </option>

                                        )
                                    )}

                                </select>

                            </div>


                            {/* =================================================
                                IMEI
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    IMEI No

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="imei_no"
                                    value={
                                        form.imei_no
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter 15 digit IMEI"
                                    maxLength={15}
                                    disabled={saving}
                                />


                                {imeiLookupLoading && (

                                    <small
                                        className="form-help"
                                        style={{
                                            color: '#2563eb'
                                        }}
                                    >
                                        Checking IMEI...
                                    </small>

                                )}


                                {!imeiLookupLoading &&
                                    imeiLookupMessage && (

                                    <small
                                        className="form-help"
                                        style={{
                                            color:
                                                (
                                                    imeiLookupStatus === 'error' ||
                                                    imeiLookupStatus === 'used' ||
                                                    imeiLookupStatus === 'unavailable'
                                                )
                                                    ? '#dc2626'
                                                    : '#16a34a'
                                        }}
                                    >
                                        {
                                            imeiLookupMessage
                                        }
                                    </small>

                                )}


                            </div>


                            {/* =================================================
                                SIM 1
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    SIM No 1

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="sim_no_1"
                                    value={
                                        form.sim_no_1
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Enter 10 or 13 digit SIM"
                                    maxLength={13}
                                    disabled={saving}
                                />
                                {simLookup.sim_no_1 && <small style={{ color: simLookup.sim_no_1.startsWith('✕') ? '#dc2626' : '#16a34a', display: 'block', marginTop: '4px' }}>{simLookup.sim_no_1}</small>}


                            </div>


                            {/* =================================================
                                SIM 2
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">
                                    SIM No 2
                                </label>


                                <input
                                    type="text"
                                    inputMode="numeric"
                                    name="sim_no_2"
                                    value={
                                        form.sim_no_2
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    placeholder="Optional"
                                    maxLength={13}
                                    disabled={saving}
                                />
                                {simLookup.sim_no_2 && <small style={{ color: simLookup.sim_no_2.startsWith('✕') ? '#dc2626' : '#16a34a', display: 'block', marginTop: '4px' }}>{simLookup.sim_no_2}</small>}


                            </div>


                            {/* =================================================
                                DEVICE MODEL
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    Device Model

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <select
                                    name="device_model_id"
                                    value={
                                        form.device_model_id
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    disabled={
                                        loading ||
                                        saving ||
                                        isDeviceModelLocked
                                    }
                                >

                                    <option value="">

                                        {loading
                                            ? 'Loading device models...'
                                            : 'Select Device Model'}

                                    </option>


                                    {deviceTypesForSelect.map(
                                        item => (

                                            <option
                                                key={
                                                    item.id
                                                }
                                                value={
                                                    item.id
                                                }
                                            >

                                                {
                                                    item.device_type
                                                }

                                            </option>

                                        )
                                    )}

                                </select>


                                {isDeviceModelLocked && (

                                    <small
                                        className="form-help"
                                    >
                                        Device Model is automatically linked to the IMEI.
                                    </small>

                                )}

                            </div>


                            {/* =================================================
                                VALIDITY
                            ================================================= */}

                            <div
                                className="form-group"
                            >

                                <label className="form-label">

                                    Validity

                                    <span className="required">
                                        *
                                    </span>

                                </label>


                                <select
                                    name="validity_id"
                                    value={
                                        form.validity_id
                                    }
                                    onChange={
                                        handleChange
                                    }
                                    className="form-control"
                                    disabled={
                                        loading ||
                                        saving
                                    }
                                >

                                    <option value="">

                                        {loading
                                            ? 'Loading validity...'
                                            : simValidities.length === 0
                                                ? 'No validity available'
                                                : 'Select Validity'}

                                    </option>


                                    {simValidities.map(
                                        item => (

                                            <option
                                                key={
                                                    item.id
                                                }
                                                value={
                                                    item.id
                                                }
                                            >

                                                {
                                                    item.months
                                                }
                                                {' '}
                                                Months

                                            </option>

                                        )
                                    )}


                                    {form.validity_id &&
                                        !simValidities.some(
                                            item =>
                                                String(item.id) ===
                                                String(form.validity_id)
                                        ) && (

                                        <option
                                            value={
                                                form.validity_id
                                            }
                                        >
                                            Current Saved Validity
                                        </option>

                                    )}

                                </select>


                                {!loading &&
                                    simValidities.length === 0 && (

                                    <small
                                        className="form-help"
                                        style={{
                                            color: '#dc2626'
                                        }}
                                    >
                                        No active SIM validity found.
                                        Please add validity from
                                        Settings → SIM Validity.
                                    </small>

                                )}

                            </div>

                        </div>


                        {/* =====================================================
                            FOOTER ACTIONS
                        ===================================================== */}

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

                            {/* BACK */}

                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={
                                    handleBack
                                }
                                disabled={saving}
                            >

                                <ArrowLeft
                                    size={16}
                                />

                                Back

                            </button>


                            {/* RESET */}

                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={
                                    handleReset
                                }
                                disabled={saving}
                            >

                                <RefreshCw
                                    size={16}
                                />

                                Reset

                            </button>


                            {/* SAVE / UPDATE */}

                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={
                                    saving ||
                                    loading ||
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


export default CustomerVehicleDetailsPage;