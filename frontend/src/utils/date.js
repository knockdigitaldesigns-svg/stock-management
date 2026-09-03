const pad = (value) => String(value).padStart(2, '0');

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

    const text = String(value);
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) {
        const [, year, month, day] = match;
        const date = new Date(Number(year), Number(month) - 1, Number(day));
        if (date.getFullYear() === Number(year) && date.getMonth() === Number(month) - 1 && date.getDate() === Number(day)) {
            return `${pad(day)}-${pad(month)}-${year}`;
        }
        return '-';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return `${pad(date.getDate())}-${pad(date.getMonth() + 1)}-${date.getFullYear()}`;
};

export const parseDate = (value) => {
    if (value === null || value === undefined || value === '') return '';
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
    }

    if (typeof value === 'number' || (!Number.isNaN(Number(value)) && Number(value) > 20000 && Number(value) < 80000 && !String(value).includes('-') && !String(value).includes('/'))) {
        const utcDays = Math.floor(Number(value) - 25569);
        const date = new Date(utcDays * 86400 * 1000);
        if (!Number.isNaN(date.getTime())) {
            return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
        }
    }

    const text = String(value).trim();
    let match = text.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (match) return `${match[3]}-${pad(match[2])}-${pad(match[1])}`;
    match = text.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
    if (match) return `${match[1]}-${pad(match[2])}-${pad(match[3])}`;

    const date = new Date(text);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

export const isDateKey = (key) => /(^|_)(date|at)$|date/i.test(String(key));
