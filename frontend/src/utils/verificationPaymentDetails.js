const parseValue = (value) => {
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed) return null;
    if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return value;
    try {
        return JSON.parse(trimmed);
    } catch {
        return null;
    }
};

const asPaymentList = (value) => {
    const parsed = parseValue(value);
    if (Array.isArray(parsed)) {
        return parsed.map(parseValue).filter((entry) => entry && typeof entry === 'object' && !Array.isArray(entry));
    }
    return parsed && typeof parsed === 'object' ? [parsed] : [];
};

const vehicleKey = (payment) => String(payment.Vehicle || payment.vehicle_no || payment.vehicle_number || '')
    .trim()
    .toLowerCase();

const normalized = (value) => String(value ?? '').trim().toLowerCase();

const canMerge = (first, second) => {
    const firstVehicle = vehicleKey(first);
    const secondVehicle = vehicleKey(second);
    if (firstVehicle && secondVehicle && firstVehicle !== secondVehicle) return false;

    for (const field of ['Payment Mode', 'payment_mode', 'paymentMode', 'Transaction ID', 'transaction_id', 'transactionId']) {
        const firstValue = normalized(first[field]);
        const secondValue = normalized(second[field]);
        if (firstValue && secondValue && firstValue !== secondValue) return false;
    }
    return true;
};

const mergeRecord = (first, second) => {
    const merged = { ...first };
    for (const [field, value] of Object.entries(second)) {
        if (value === null || value === undefined || value === '') continue;
        const existing = merged[field];
        if (existing === null || existing === undefined || existing === '') {
            merged[field] = value;
        } else if (normalized(existing) !== normalized(value)) {
            merged[`${field} — Part 2`] = value;
        }
    }

    const saleAmount = Number(merged['Total Sale Amount'] ?? merged.total_sale_amount ?? merged.totalSaleAmount);
    const totalAmount = Number(merged['Total Amount'] ?? merged.total_amount ?? merged.totalAmount);
    if (Number.isFinite(saleAmount) && Number.isFinite(totalAmount) && saleAmount === totalAmount) {
        delete merged['Total Amount'];
        delete merged.total_amount;
        delete merged.totalAmount;
    }
    return merged;
};

export const buildVerificationPaymentDetails = (part1Data, part2Data) => {
    const part1 = asPaymentList(part1Data);
    const unmatchedPart2 = asPaymentList(part2Data).map((payment) => ({ ...payment }));
    const result = [];

    for (const payment1 of part1) {
        const firstVehicle = vehicleKey(payment1);
        let matchIndex = unmatchedPart2.findIndex((payment2) => (
            firstVehicle && vehicleKey(payment2) === firstVehicle && canMerge(payment1, payment2)
        ));
        if (matchIndex < 0 && part1.length === 1 && unmatchedPart2.length === 1
            && canMerge(payment1, unmatchedPart2[0])) {
            matchIndex = 0;
        }
        if (matchIndex < 0) {
            result.push({ ...payment1 });
            continue;
        }
        result.push(mergeRecord(payment1, unmatchedPart2.splice(matchIndex, 1)[0]));
    }

    result.push(...unmatchedPart2);
    return result;
};
