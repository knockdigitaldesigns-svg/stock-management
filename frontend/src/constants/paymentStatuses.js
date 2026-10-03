export const PAYMENT_STATUS_FILTERS = Object.freeze([
    { value: 'paid', label: 'Paid' },
    { value: 'partially_paid', label: 'Partially Paid' },
    { value: 'pending', label: 'Pending' },
    { value: 'not_paid', label: 'Not Paid' }
]);

export const PAYMENT_STATUS_OPTIONS = Object.freeze([
    ...PAYMENT_STATUS_FILTERS.map(({ label }) => label),
    'No Payment Required'
]);