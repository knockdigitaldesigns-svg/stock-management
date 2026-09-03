import { useState, useMemo, useEffect } from 'react';

export const usePagination = (items = [], initialPageSize = 10, resetDependencies = []) => {
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(initialPageSize);

    // Reset to page 1 whenever search, filters, or dependencies change
    useEffect(() => {
        setPage(1);
    }, resetDependencies);

    const totalItems = items.length;
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
        const start = (page - 1) * pageSize;
        return items.slice(start, start + pageSize);
    }, [items, page, pageSize]);

    return {
        page,
        setPage,
        pageSize,
        setPageSize: handlePageSizeChange,
        totalItems,
        totalPages,
        paginatedItems
    };
};

export default usePagination;
