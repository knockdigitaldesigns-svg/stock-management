export const installationTypes = [
    'Technician',
    'Onsite Dealer',
    'Offsite Dealer',
    'Direct'
];

export const isInstallationPersonRequired = (installationType) =>
    installationType !== 'Direct';

export const isInstallationDetailsComplete = (installation) => Boolean(
    installation?.installation_person_type &&
    installation?.lead_closure_id &&
    installation?.installation_date &&
    (!isInstallationPersonRequired(installation.installation_person_type) ||
        installation.installation_person_id)
);

export const mergeVehicleDraft = (vehicle, draftVehicle) => {
    if (!draftVehicle) {
        return vehicle;
    }

    const mergedVehicle = { ...vehicle, ...draftVehicle };
    const installation = draftVehicle.installation;
    if (installation && typeof installation === 'object' && Object.keys(installation).length > 0) {
        mergedVehicle.vehicle_installation_type = installation.installation_person_type ||
            installation.vehicle_installation_type ||
            '';
        mergedVehicle.vehicle_installation_person_id = installation.installation_person_id ||
            installation.vehicle_installation_person_id ||
            '';
        mergedVehicle.vehicle_lead_closure_id = installation.lead_closure_id ||
            installation.vehicle_lead_closure_id ||
            '';
        mergedVehicle.vehicle_installation_date = installation.installation_date ||
            installation.vehicle_installation_date ||
            '';
    } else {
        for (const field of [
            'vehicle_installation_type',
            'vehicle_installation_person_type',
            'vehicle_installation_person_id',
            'vehicle_installation_person',
            'vehicle_lead_closure_id',
            'vehicle_lead_closure',
            'vehicle_installation_date'
        ]) {
            mergedVehicle[field] = vehicle[field];
        }
    }

    const paymentPart1 = draftVehicle.payment_part1;
    if (paymentPart1 && typeof paymentPart1 === 'object' && Object.keys(paymentPart1).length > 0) {
        mergedVehicle.vehicle_total_sale_amount = paymentPart1.total_sale_amount ??
            paymentPart1.totalSaleAmount ??
            mergedVehicle.vehicle_total_sale_amount;
        mergedVehicle.vehicle_transaction_id = paymentPart1.transaction_id ??
            paymentPart1.transactionId ??
            mergedVehicle.vehicle_transaction_id;
        mergedVehicle.vehicle_payment_mode = paymentPart1.payment_mode ??
            paymentPart1.paymentMode ??
            mergedVehicle.vehicle_payment_mode;
    }

    const paymentPart2 = draftVehicle.payment_part2;
    if (paymentPart2 && typeof paymentPart2 === 'object' && Object.keys(paymentPart2).length > 0) {
        for (const [draftField, vehicleField] of [
            ['device_charge', 'vehicle_device_charge'],
            ['software_charge', 'vehicle_software_charge'],
            ['technician_charge', 'vehicle_technician_charge'],
            ['sim_charge', 'vehicle_sim_charge'],
            ['courier_charge', 'vehicle_courier_charge'],
            ['total_amount', 'vehicle_total_amount'],
            ['amount_paid', 'vehicle_amount_paid'],
            ['amount_pending', 'vehicle_amount_pending'],
            ['payment_status', 'vehicle_payment_status']
        ]) {
            if (paymentPart2[draftField] !== undefined) {
                mergedVehicle[vehicleField] = paymentPart2[draftField];
            }
        }
        mergedVehicle.vehicle_transaction_id = paymentPart2.transaction_id ??
            paymentPart2.transactionId ??
            mergedVehicle.vehicle_transaction_id;
        mergedVehicle.vehicle_payment_mode = paymentPart2.payment_mode ??
            paymentPart2.paymentMode ??
            mergedVehicle.vehicle_payment_mode;
    }

    return mergedVehicle;
};

export const resolveInstallationFormValues = ({
    stored = {},
    installation = {},
    ownerType = '',
    ownerId = ''
}) => {
    const storedType = stored.installation_person_type;
    const savedType = installation.installation_type;
    const installationPersonType = installationTypes.includes(storedType)
        ? storedType
        : installationTypes.includes(savedType)
            ? savedType
            : installationTypes.includes(installation.installation_person_type)
                ? installation.installation_person_type
                : ownerType;

    return {
        installation_person_type: installationPersonType,
        installation_person_id: installationPersonType === 'Direct'
            ? ''
            : stored.installation_person_id ||
                installation.installation_person_id ||
                ownerId ||
                '',
        lead_closure_id: stored.lead_closure_id ||
            installation.lead_closure_id ||
            '',
        installation_date: stored.installation_date ||
            installation.installation_date ||
            ''
    };
};
