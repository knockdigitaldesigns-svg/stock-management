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
    if (!draftVehicle.changed?.installation) {
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
