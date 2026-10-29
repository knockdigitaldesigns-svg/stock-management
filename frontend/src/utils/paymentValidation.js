export const normalizeCurrency = (value) => {
    if (value === '' || value === null || value === undefined) {
        return 0;
    }

    const parsed = Number(String(value).replace(/[^0-9.]/g, ''));
    return Number.isFinite(parsed) ? parsed : 0;
};
