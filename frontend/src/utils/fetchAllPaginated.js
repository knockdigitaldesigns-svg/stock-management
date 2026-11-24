export const fetchAllPaginated = async (fetchPage, collectionKey, pageSize = 100) => {
    const firstResponse = await fetchPage(1, pageSize);
    if (!firstResponse.data?.success) {
        throw new Error(firstResponse.data?.message || 'Unable to load paginated records.');
    }

    const firstData = firstResponse.data.data || {};
    const firstPage = firstData[collectionKey];
    if (!Array.isArray(firstPage)) {
        throw new Error(`The paginated response is missing its ${collectionKey} collection.`);
    }

    const totalPages = Math.max(1, Number(firstData.pagination?.total_pages) || 1);
    const records = [...firstPage];
    for (let page = 2; page <= totalPages; page += 1) {
        const response = await fetchPage(page, pageSize);
        if (!response.data?.success) {
            throw new Error(response.data?.message || 'Unable to load paginated records.');
        }
        const pageRecords = response.data.data?.[collectionKey];
        if (!Array.isArray(pageRecords)) {
            throw new Error(`The paginated response is missing its ${collectionKey} collection.`);
        }
        records.push(...pageRecords);
    }

    return records;
};
