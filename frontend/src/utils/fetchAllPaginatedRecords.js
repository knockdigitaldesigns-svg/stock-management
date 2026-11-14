import api from '../services/api';

const PAGE_SIZE = 100;

export const fetchAllPaginatedRecords = async (endpoint, recordsKey) => {
    const records = [];
    let page = 1;
    let totalPages = 1;

    while (page <= totalPages) {
        const response = await api.get(endpoint, {
            params: { page, page_size: PAGE_SIZE }
        });
        const payload = response.data;

        if (!payload?.success) {
            throw new Error(payload?.message || `Unable to load records from ${endpoint}.`);
        }

        const pageRecords = payload.data?.[recordsKey];
        const pagination = payload.data?.pagination;

        if (!Array.isArray(pageRecords) || !Number.isInteger(Number(pagination?.total_pages))) {
            throw new Error(`The records response from ${endpoint} has invalid pagination data.`);
        }

        records.push(...pageRecords);
        totalPages = Math.max(1, Number(pagination.total_pages));
        page += 1;
    }

    return records;
};
