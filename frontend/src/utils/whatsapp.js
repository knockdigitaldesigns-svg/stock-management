export const normalizeWhatsAppNumber = (value) => {
    const raw = String(value ?? '').trim();
    if (!raw || !/^[\d\s()+.-]+$/.test(raw)) return '';

    const compact = raw.replace(/[\s().-]/g, '');
    const plusCount = (compact.match(/\+/g) || []).length;
    if (plusCount > 1 || (plusCount === 1 && !compact.startsWith('+'))) return '';

    let digits = compact.replace(/^\+/, '');
    const explicitInternationalPrefix = plusCount === 1 || digits.startsWith('00');
    if (digits.startsWith('00')) digits = digits.slice(2);

    if (/^[6-9]\d{9}$/.test(digits)) return `91${digits}`;
    if (/^0[6-9]\d{9}$/.test(digits)) return `91${digits.slice(1)}`;
    if (digits.startsWith('91')) {
        return /^91[6-9]\d{9}$/.test(digits) ? digits : '';
    }

    if (!explicitInternationalPrefix && digits.length < 11) return '';
    if (digits.length < 8 || digits.length > 15 || digits.startsWith('0')) return '';
    return digits;
};
