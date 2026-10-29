import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeBulkUploadErrors } from '../src/utils/bulkUploadErrors.js';

test('normalizes structured validation details without dropping rows', () => {
    const errors = Array.from({ length: 8 }, (_, index) => ({
        row_number: index + 2,
        field: 'SIM No',
        value: `57543726179${index}`,
        message: `Row ${index + 2}: SIM No '${index}' already exists.`
    }));

    const messages = normalizeBulkUploadErrors({
        data: {
            errors: errors.map((error) => error.message),
            validation_error_details: errors,
            validation_error_count: errors.length
        }
    });

    assert.equal(messages.length, 8);
    assert.match(messages[7], /^Row 9:/);
});

test('keeps compatibility with legacy string and row_errors responses', () => {
    assert.deepEqual(normalizeBulkUploadErrors({
        data: { errors: ['Row 2: IMEI No is invalid.', 'Row 3: Dealer is missing.'] }
    }), ['Row 2: IMEI No is invalid.', 'Row 3: Dealer is missing.']);

    assert.deepEqual(normalizeBulkUploadErrors({
        data: { row_errors: ['Row 2: SIM No is invalid.'] }
    }), ['Row 2: SIM No is invalid.']);
});

test('normalizes newline-separated legacy messages', () => {
    assert.deepEqual(
        normalizeBulkUploadErrors({ message: 'Row 2: Email is invalid.\nRow 3: Pincode is invalid.' }),
        ['Row 2: Email is invalid.', 'Row 3: Pincode is invalid.']
    );
});

test('includes structured field and value when a message does not repeat them', () => {
    assert.deepEqual(normalizeBulkUploadErrors({
        data: {
            validation_error_details: [{
                row_number: 225,
                field: 'Email',
                value: 'rahul@',
                message: 'format is invalid.'
            }]
        }
    }), ["Row 225: Email - format is invalid. Value: 'rahul@'."]);
});
