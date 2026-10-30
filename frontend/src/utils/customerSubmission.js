import api from '../services/api';

const ensureSuccess = (response, fallback) => {
    if (!response.data?.success) {
        throw new Error(response.data?.message || fallback);
    }
    return response.data?.data || {};
};

const paymentValue = (payment, snakeCase, camelCase, fallback = undefined) => {
    const value = payment?.[snakeCase] ?? payment?.[camelCase];
    return value === '' || value === null || value === undefined ? fallback : value;
};

export const buildPaymentPart2Payload = (paymentPart1 = {}, paymentPart2 = {}) => ({
    total_sale_amount: Number(paymentValue(
        paymentPart2,
        'total_sale_amount',
        'totalSaleAmount',
        paymentValue(paymentPart1, 'total_sale_amount', 'totalSaleAmount')
    )),
    transaction_id: paymentValue(
        paymentPart2,
        'transaction_id',
        'transactionId',
        paymentValue(paymentPart1, 'transaction_id', 'transactionId', null)
    ),
    payment_mode: paymentValue(
        paymentPart2,
        'payment_mode',
        'paymentMode',
        paymentValue(paymentPart1, 'payment_mode', 'paymentMode', null)
    ),
    device_charge: Number(paymentValue(paymentPart2, 'device_charge', 'deviceCharge', 0)),
    software_charge: Number(paymentValue(paymentPart2, 'software_charge', 'softwareCharge', 0)),
    technician_charge: Number(paymentValue(paymentPart2, 'technician_charge', 'technicianCharge', 0)),
    sim_charge: Number(paymentValue(paymentPart2, 'sim_charge', 'simCharge', 0)),
    courier_charge: Number(paymentValue(paymentPart2, 'courier_charge', 'courierCharge', 0)),
    ...(Object.prototype.hasOwnProperty.call(paymentPart2, 'cash_to_technician')
        || Object.prototype.hasOwnProperty.call(paymentPart2, 'cashToTechnician') ? {
        cash_to_technician: Boolean(paymentValue(paymentPart2, 'cash_to_technician', 'cashToTechnician', false)),
        cash_to_dealer: Boolean(paymentValue(paymentPart2, 'cash_to_dealer', 'cashToDealer', false)),
        cash_to_et: Boolean(paymentValue(paymentPart2, 'cash_to_et', 'cashToEt', false))
    } : {}),
    total_amount: Number(paymentValue(paymentPart2, 'total_amount', 'totalAmount', 0)),
    amount_paid: Number(paymentValue(paymentPart2, 'amount_paid', 'amountPaid', 0)),
    amount_pending: Number(paymentValue(paymentPart2, 'amount_pending', 'amountPending', 0)),
    payment_status: paymentValue(paymentPart2, 'payment_status', 'paymentStatus', '')
});

export const saveCustomerDraft = async (submissionId, existingCustomerId, draftData) => {
    const response = await api.post('/customers/verification/draft.php', {
        submission_id: Number(submissionId || 0),
        existing_customer_id: Number(existingCustomerId || 0),
        submission_data: draftData
    });
    const result = ensureSuccess(response, 'Failed to save customer draft.');
    const id = Number(result.submission_id || 0);
    if (!id) {
        throw new Error('Draft ID was not returned by the server.');
    }
    return id;
};

export const ensureCustomerSubmissionData = async (stored, customerId) => {
    if (stored.step1) {
        return stored;
    }
    const existingCustomerId = Number(stored.existing_customer_id || customerId || 0);
    if (existingCustomerId <= 0) {
        throw new Error('Customer details are missing from this draft.');
    }
    const response = await api.get(`/customers/details.php?customer_id=${encodeURIComponent(existingCustomerId)}`);
    const details = ensureSuccess(response, 'Failed to load customer details.');
    const customer = details.customer || {};
    return {
        ...stored,
        existing_customer_id: existingCustomerId,
        step1: {
            platform_id: Number(customer.platform_id),
            username: customer.username || '',
            primary_mobile_no: customer.primary_mobile_no || '',
            secondary_mobile_no: customer.secondary_mobile_no || '',
            email: customer.email || '',
            location: customer.location || '',
            pincode: customer.pincode || ''
        }
    };
};

export const buildCustomerSubmission = (stored, paymentPart2 = null) => ({
    customer: stored.step1 || {},
    existing_customer_id: Number(stored.existing_customer_id || 0) || null,
    customer_changed: Boolean(stored.customer_changed),
    vehicle: stored.step2
        ? {
            ...stored.step2,
            vehicle_record_id: Number(stored.vehicle_record_id || 0) || null,
            append_vehicle: Boolean(stored.append_vehicle)
        }
        : null,
    installation: stored.step3 || null,
    payment_part1: stored.step4 || null,
        payment_part2: paymentPart2
});

export const commitCustomerSubmission = async (submission, submissionId, onProgress = null) => {
    const existingCustomerId = Number(submission.existing_customer_id || 0);
    let customerId = existingCustomerId;

    if (customerId > 0 && submission.customer_changed) {
        ensureSuccess(await api.put('/customers/update.php', {
            id: customerId,
            ...submission.customer,
            verification_submission_id: submissionId
        }), 'Failed to save customer details.');
    } else if (customerId <= 0) {
        const result = ensureSuccess(await api.post('/customers/create.php', {
            ...submission.customer,
            verification_submission_id: submissionId
        }), 'Failed to create customer.');
        customerId = Number(result.customer_id || result.id || 0);
        if (!customerId) {
            throw new Error('Customer ID was not returned after creation.');
        }
    }
    await onProgress?.({ customer_id: customerId });

    const vehicles = Array.isArray(submission.vehicles)
        ? submission.vehicles
        : submission.vehicle
            ? [{
                ...submission.vehicle,
                changed: {
                    vehicle: true,
                    installation: true,
                    paymentPart1: Boolean(submission.payment_part1),
                    paymentPart2: Boolean(submission.payment_part2)
                },
                installation: submission.installation,
                payment_part1: submission.payment_part1,
                payment_part2: submission.payment_part2
            }]
            : [];
    let vehicleId = 0;
    let paymentId = 0;

    const savePaymentParts = async (currentVehicleId, paymentPart1, paymentPart2, changed) => {
        if (changed.paymentPart1 && paymentPart1) {
            const part1Result = ensureSuccess(await api.post('/customers/payments/create.php', {
                customer_id: customerId,
                vehicle_id: currentVehicleId,
                payment_step: 1,
                total_sale_amount: Number(paymentValue(paymentPart1, 'total_sale_amount', 'totalSaleAmount')),
                transaction_id: paymentValue(paymentPart1, 'transaction_id', 'transactionId', null),
                payment_mode: paymentValue(paymentPart1, 'payment_mode', 'paymentMode', null),
                payment_status: 'Pending',
                verification_submission_id: submissionId
            }), 'Failed to save Payment Part 1.');
            paymentId = Number(part1Result.payment_id || paymentId || 0);
            await onProgress?.({ customer_id: customerId, vehicle_id: currentVehicleId, payment_id: paymentId });
        }

        if (changed.paymentPart2 && paymentPart2) {
            const result = ensureSuccess(await api.post('/customers/payments/create.php', {
                customer_id: customerId,
                vehicle_id: currentVehicleId,
                payment_step: 2,
                ...buildPaymentPart2Payload(paymentPart1, paymentPart2),
                verification_submission_id: submissionId
            }), 'Failed to save Payment Part 2.');
            paymentId = Number(result.payment_id || paymentId || 0);
            await onProgress?.({ customer_id: customerId, vehicle_id: currentVehicleId, payment_id: paymentId });
        }
    };

    for (const vehicle of vehicles) {
        const changed = vehicle.changed || {};
        let currentVehicleId = Number(vehicle.vehicle_record_id || vehicle.id || 0);
        const hasMissingAssetRelationship = currentVehicleId > 0 && (
            Number(vehicle.device_id || 0) <= 0 ||
            Number(vehicle.sim_id_1 || 0) <= 0 ||
            (String(vehicle.sim_no_2 || '').trim() !== '' && Number(vehicle.sim_id_2 || 0) <= 0)
        );
        if (changed.vehicle || hasMissingAssetRelationship) {
            const vehiclePayload = {
                customer_id: customerId,
                append_vehicle: Boolean(vehicle.append_vehicle),
                vehicle_no: vehicle.vehicle_no,
                vehicle_type_id: Number(vehicle.vehicle_type_id),
                imei_no: vehicle.imei_no,
                sim_no_1: vehicle.sim_no_1,
                sim_no_2: vehicle.sim_no_2 || null,
                device_model_id: Number(vehicle.device_model_id),
                validity_id: Number(vehicle.validity_id || vehicle.validity_months)
            };
            if (currentVehicleId > 0) {
                ensureSuccess(await api.put('/customers/vehicle_details/update.php', {
                    id: currentVehicleId,
                    ...vehiclePayload,
                    verification_submission_id: submissionId
                }), 'Failed to save vehicle details.');
            } else {
                const result = ensureSuccess(await api.post('/customers/vehicle_details/create.php', {
                    ...vehiclePayload,
                    verification_submission_id: submissionId
                }), 'Failed to create vehicle details.');
                currentVehicleId = Number(result.vehicle_record_id || result.vehicle_id || result.id || 0);
                if (!currentVehicleId) {
                    throw new Error('Vehicle ID was not returned after creation.');
                }
            }
        }
        if (currentVehicleId > 0) {
            vehicleId = currentVehicleId;
            await onProgress?.({ customer_id: customerId, vehicle_id: currentVehicleId });
        }

        const installation = vehicle.installation || submission.installation;
        if (changed.installation && installation) {
            ensureSuccess(await api.post('/customers/installations/create.php', {
                customer_id: customerId,
                vehicle_id: currentVehicleId,
                installation_person_type: installation.installation_person_type,
                installation_person_id: installation.installation_person_id
                    ? Number(installation.installation_person_id)
                    : null,
                lead_closure_id: Number(installation.lead_closure_id),
                installation_date: installation.installation_date
            }), 'Failed to save installation details.');
        }

        const paymentPart1 = vehicle.payment_part1 || submission.payment_part1;
        const paymentPart2 = vehicle.payment_part2 || submission.payment_part2;
        await savePaymentParts(currentVehicleId, paymentPart1, paymentPart2, changed);
    }

    if (vehicles.length === 0) {
        await savePaymentParts(
            0,
            submission.payment_part1,
            submission.payment_part2,
            {
                paymentPart1: Boolean(submission.payment_part1),
                paymentPart2: Boolean(submission.payment_part2)
            }
        );
    }

    return { customerId, vehicleId, paymentId };
};
