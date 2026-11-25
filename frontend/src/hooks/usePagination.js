import { useState, useMemo, useEffect } from 'react';

export const usePagination = (items = [], initialPageSize = 10, resetDependencies = [], serverSide = false) => {
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(initialPageSize);
    const [serverTotal, setServerTotal] = useState(0);

    // Reset to page 1 whenever search, filters, or dependencies change
    useEffect(() => {
        setPage(1);
    }, resetDependencies);

    const totalItems = serverSide ? serverTotal : items.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));

    // Automatically clamp current page if items are deleted and page exceeds totalPages
    useEffect(() => {
        if (page > totalPages && totalPages > 0) {
            setPage(totalPages);
        }
    }, [totalPages, page]);

    const handlePageSizeChange = (newSize) => {
        setPageSize(newSize);
        setPage(1);
    };

    const paginatedItems = useMemo(() => {
        if (serverSide) return items;
        const start = (page - 1) * pageSize;
        return items.slice(start, start + pageSize);
    }, [items, page, pageSize, serverSide]);

    return {
        page,
        setPage,
        pageSize,
        setPageSize: handlePageSizeChange,
        setTotalItems: setServerTotal,
        totalItems,
        totalPages,
        paginatedItems
    };
};

export default usePagination;
