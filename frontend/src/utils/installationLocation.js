export const normalizeInstallationLocation = (value) =>
    String(value || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');

const getInstallationLocations = (value) =>
    String(value || '')
        .split(/[,\r\n;]+/)
        .map(normalizeInstallationLocation)
        .filter(Boolean);

export const getInstallationLocationPriority = (personLocation, customerLocation) => {
    const personLocations = new Set(getInstallationLocations(personLocation));
    const customerLocations = getInstallationLocations(customerLocation);
    return customerLocations.some((location) => personLocations.has(location)) ? 0 : 1;
};

export const sortInstallersByLocation = (installers, customerLocation) =>
    [...installers].sort((a, b) => {
        const locationPriority =
            getInstallationLocationPriority(a.location, customerLocation) -
            getInstallationLocationPriority(b.location, customerLocation);
        if (locationPriority !== 0) return locationPriority;

        const nameOrder = String(a.person_name || '').localeCompare(
            String(b.person_name || ''),
            undefined,
            { sensitivity: 'base' }
        );
        return nameOrder || Number(a.person_id) - Number(b.person_id);
    });

export const formatInstallerOption = (installer, customerLocation) => {
    const priority = getInstallationLocationPriority(installer.location, customerLocation);
    const priorityLabel = priority === 0 ? 'Same location' : 'Other location';
    const location = installer.location || 'Location not recorded';
    return `${installer.person_type} — ${installer.person_name} — ${location} — ${priorityLabel}`;
};
