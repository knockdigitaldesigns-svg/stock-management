const pad = (value) => String(value).padStart(2, '0');

const isValidDateParts = (year, month, day) => {
    if (!Number.isInteger(year) || year < 1 || year > 9999 || month < 1 || month > 12) return false;
    const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const monthLengths = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    return day >= 1 && day <= monthLengths[month - 1];
};

const toIsoDate = (year, month, day) =>
    isValidDateParts(year, month, day) ? `${year}-${pad(month)}-${pad(day)}` : '';

const excelSerialToIso = (value) => {
    const serial = Number(value);
    if (!Number.isFinite(serial) || serial < 1) return '';

    const unixDays = Math.floor(serial) - 25569;
    const shiftedDays = unixDays + 719468;
    const era = Math.floor(shiftedDays / 146097);
    const dayOfEra = shiftedDays - era * 146097;
    const yearOfEra = Math.floor((dayOfEra - Math.floor(dayOfEra / 1460) + Math.floor(dayOfEra / 36524) - Math.floor(dayOfEra / 146096)) / 365);
    let year = yearOfEra + era * 400;
    const dayOfYear = dayOfEra - (365 * yearOfEra + Math.floor(yearOfEra / 4) - Math.floor(yearOfEra / 100));
    const monthPrime = Math.floor((5 * dayOfYear + 2) / 153);
    const day = dayOfYear - Math.floor((153 * monthPrime + 2) / 5) + 1;
    const month = monthPrime + (monthPrime < 10 ? 3 : -9);
    year += month <= 2 ? 1 : 0;

    return toIsoDate(year, month, day);
};

export const getTodayDate = () => {
    const today = new Date();
    return `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
};

export const isFutureDate = (value) => {
    const parsed = parseDate(value);
    return Boolean(parsed) && parsed > getTodayDate();
};

export const formatDate = (value) => {
    if (value === null || value === undefined || value === '') return '-';

    if (value instanceof Date) {
        if (Number.isNaN(value.getTime())) return '-';
        return `${pad(value.getDate())}-${pad(value.getMonth() + 1)}-${value.getFullYear()}`;
    }

    const text = String(value).trim();
    let match = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T\s])/);
    if (match) {
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        return isValidDateParts(year, month, day) ? `${pad(day)}-${pad(month)}-${year}` : '-';
    }

    match = text.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
    if (match) {
        const day = Number(match[1]);
        const month = Number(match[2]);
        const year = Number(match[3]);
        return isValidDateParts(year, month, day) ? `${pad(day)}-${pad(month)}-${year}` : '-';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return `${pad(date.getDate())}-${pad(date.getMonth() + 1)}-${date.getFullYear()}`;
};

export const parseDate = (value) => {
    if (value === null || value === undefined || value === '') return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return toIsoDate(value.getFullYear(), value.getMonth() + 1, value.getDate());
    }

    if (typeof value === 'number') return excelSerialToIso(value);

    const text = String(value).trim();
    if (/^\d+(?:\.\d+)?$/.test(text)) return excelSerialToIso(text);

    let match = text.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
    if (match) return toIsoDate(Number(match[3]), Number(match[2]), Number(match[1]));

    match = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
    if (match) return toIsoDate(Number(match[1]), Number(match[2]), Number(match[3]));

    return '';
};

export const isDateKey = (key) => /(^|_)(date|at)$|date/i.test(String(key));
