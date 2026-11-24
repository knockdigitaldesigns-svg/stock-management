import assert from 'node:assert/strict';
import test from 'node:test';

import { fetchAllPaginated } from '../src/utils/fetchAllPaginated.js';

const makePageFetcher = (records, requestedPages) => async (page, pageSize) => {
    requestedPages.push({ page, pageSize });
    const start = (page - 1) * pageSize;
    return {
        data: {
            success: true,
            data: {
                users: records.slice(start, start + pageSize),
                pagination: {
                    total: records.length,
                    page,
                    page_size: pageSize,
                    total_pages: Math.max(1, Math.ceil(records.length / pageSize))
                }
            }
        }
    };
};

for (const [total, pageSize, expectedPageLengths] of [
    [12, 10, [10, 2]],
    [25, 10, [10, 10, 5]],
    [12, 5, [5, 5, 2]],
    [0, 10, [0]]
]) {
    test(`loads all ${total} records in pages of ${pageSize}`, async () => {
        const records = Array.from({ length: total }, (_, index) => ({ id: index + 1 }));
        const requestedPages = [];
        const loaded = await fetchAllPaginated(
            makePageFetcher(records, requestedPages),
            'users',
            pageSize
        );

        assert.deepEqual(loaded, records);
        assert.deepEqual(
            requestedPages.map(({ page, pageSize: requestedPageSize }) => ({
                page,
                pageSize: requestedPageSize,
                records: expectedPageLengths[page - 1] ?? 0
            })),
            expectedPageLengths.map((count, index) => ({
                page: index + 1,
                pageSize,
                records: count
            }))
        );
    });
}
