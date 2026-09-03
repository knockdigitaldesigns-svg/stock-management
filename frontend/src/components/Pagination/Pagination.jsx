import React from 'react';
import './Pagination.css';

const Pagination = ({
    currentPage = 1,
    totalItems = 0,
    pageSize = 10,
    onPageChange,
    onPageSizeChange,
    pageSizeOptions = [10, 25, 50, 100],
    itemName = 'records'
}) => {
    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
    const validCurrentPage = Math.min(Math.max(1, currentPage), totalPages);

    const startItem = totalItems === 0 ? 0 : (validCurrentPage - 1) * pageSize + 1;
    const endItem = Math.min(validCurrentPage * pageSize, totalItems);

    const getPageNumbers = () => {
        const pages = [];
        const maxVisible = 5;

        if (totalPages <= maxVisible + 2) {
            for (let i = 1; i <= totalPages; i++) {
                pages.push(i);
            }
        } else {
            pages.push(1);

            let start = Math.max(2, validCurrentPage - 1);
            let end = Math.min(totalPages - 1, validCurrentPage + 1);

            if (validCurrentPage <= 3) {
                start = 2;
                end = 4;
            } else if (validCurrentPage >= totalPages - 2) {
                start = totalPages - 3;
                end = totalPages - 1;
            }

            if (start > 2) {
                pages.push('...');
            }

            for (let i = start; i <= end; i++) {
                pages.push(i);
            }

            if (end < totalPages - 1) {
                pages.push('...');
            }

            pages.push(totalPages);
        }

        return pages;
    };

    return (
        <div className="pagination-container">
            <div className="pagination-left">
                <span className="pagination-info">
                    Showing {startItem}–{endItem} of {totalItems} {itemName}
                </span>

                {onPageSizeChange && (
                    <div className="pagination-size-selector">
                        <label htmlFor="page-size-select">Rows per page:</label>
                        <select
                            id="page-size-select"
                            className="pagination-size-select"
                            value={pageSize}
                            onChange={(e) => onPageSizeChange(Number(e.target.value))}
                        >
                            {pageSizeOptions.map((opt) => (
                                <option key={opt} value={opt}>
                                    {opt}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </div>

            <div className="pagination-controls">
                <button
                    type="button"
                    className="pagination-btn"
                    disabled={validCurrentPage <= 1}
                    onClick={() => onPageChange(validCurrentPage - 1)}
                    aria-label="Previous page"
                >
                    Previous
                </button>

                {totalItems > 0 && getPageNumbers().map((page, idx) =>
                    page === '...' ? (
                        <span key={`ellipsis-${idx}`} className="pagination-ellipsis">
                            ...
                        </span>
                    ) : (
                        <button
                            key={`page-${page}`}
                            type="button"
                            className={`pagination-btn ${validCurrentPage === page ? 'active' : ''}`}
                            onClick={() => onPageChange(page)}
                            aria-label={`Page ${page}`}
                            aria-current={validCurrentPage === page ? 'page' : undefined}
                        >
                            {page}
                        </button>
                    )
                )}

                <button
                    type="button"
                    className="pagination-btn"
                    disabled={validCurrentPage >= totalPages}
                    onClick={() => onPageChange(validCurrentPage + 1)}
                    aria-label="Next page"
                >
                    Next
                </button>
            </div>
        </div>
    );
};

export default Pagination;
