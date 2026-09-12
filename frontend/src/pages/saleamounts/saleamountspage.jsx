import { useEffect, useMemo, useState } from 'react';
import { Plus, Eye, Edit, Trash2 } from 'lucide-react';

import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const SaleAmountsPage = () => {
    const { hasPermission } = useAuth();

    // =========================================================
    // STATE
    // =========================================================

    const [saleAmounts, setSaleAmounts] = useState([]);
    const [loading, setLoading] = useState(false);

    // Filters
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    // Modal
    const [showModal, setShowModal] = useState(false);
    const [editingSaleAmount, setEditingSaleAmount] =
        useState(null);

    // Form
    const [saleAmount, setSaleAmount] = useState('');
    const [status, setStatus] = useState('Active');

    // View / Delete
    const [viewingSaleAmount, setViewingSaleAmount] =
        useState(null);

    const [deleteTarget, setDeleteTarget] =
        useState(null);

    // Messages
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    // =========================================================
    // FETCH
    // =========================================================

    const fetchSaleAmounts = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await api.get(
                '/sale_amounts/list.php'
            );

            if (response.data?.success) {
                setSaleAmounts(
                    response.data?.data?.sale_amounts || []
                );
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to load sale amounts.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to load sale amounts.'
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSaleAmounts();
    }, []);

    // =========================================================
    // FILTER
    // =========================================================

    const filteredSaleAmounts = useMemo(() => {
        const searchValue =
            search.trim().toLowerCase();

        return saleAmounts.filter((item) => {
            const amount =
                String(item.sale_amount ?? '');

            const matchesSearch =
                !searchValue ||
                amount.includes(searchValue);

            const matchesStatus =
                !statusFilter ||
                item.status === statusFilter;

            return (
                matchesSearch &&
                matchesStatus
            );
        });
    }, [
        saleAmounts,
        search,
        statusFilter
    ]);

    // =========================================================
    // PAGINATION
    // =========================================================

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems,
    } = usePagination(
        filteredSaleAmounts,
        10,
        [search, statusFilter]
    );

    // =========================================================
    // RESET FORM
    // =========================================================

    const resetForm = () => {
        setSaleAmount('');
        setStatus('Active');
        setEditingSaleAmount(null);
    };

    // =========================================================
    // OPEN ADD
    // =========================================================

    const openAddModal = () => {
        resetForm();

        setError('');
        setMessage('');

        setShowModal(true);
    };

    // =========================================================
    // OPEN EDIT
    // =========================================================

    const openEditModal = (item) => {
        setEditingSaleAmount(item);

        setSaleAmount(
            item.sale_amount !== undefined &&
            item.sale_amount !== null
                ? String(item.sale_amount)
                : ''
        );

        setStatus(
            item.status || 'Active'
        );

        setError('');
        setMessage('');

        setShowModal(true);
    };

    // =========================================================
    // CLOSE MODAL
    // =========================================================

    const closeModal = () => {
        if (saving) return;

        setShowModal(false);
        resetForm();
        setError('');
    };

    // =========================================================
    // SAVE / UPDATE
    // =========================================================

    const handleSave = async (event) => {
        event.preventDefault();

        setError('');
        setMessage('');

        const amount =
            saleAmount.trim();

        if (!amount) {
            triggerError(
                'Sale amount is required.'
            );
            return;
        }

        if (!/^\d+(\.\d{1,2})?$/.test(amount)) {
            triggerError(
                'Enter a valid sale amount.'
            );
            return;
        }

        const numericAmount =
            Number(amount);

        if (
            !Number.isFinite(numericAmount) ||
            numericAmount <= 0
        ) {
            triggerError(
                'Sale amount must be greater than 0.'
            );
            return;
        }

        if (
            !['Active', 'Inactive'].includes(
                status
            )
        ) {
            triggerError(
                'Invalid status.'
            );
            return;
        }

        setSaving(true);

        try {
            const payload = {
                sale_amount:
                    numericAmount,
                status,
            };

            let response;

            if (editingSaleAmount) {
                response = await api.post(
                    '/sale_amounts/update.php',
                    {
                        id:
                            editingSaleAmount.id,
                        ...payload,
                    }
                );
            } else {
                response = await api.post(
                    '/sale_amounts/create.php',
                    payload
                );
            }

            if (response.data?.success) {
                setShowModal(false);
                resetForm();

                setMessage(
                    response.data?.message ||
                    (
                        editingSaleAmount
                            ? 'Sale amount updated successfully.'
                            : 'Sale amount added successfully.'
                    )
                );

                await fetchSaleAmounts();

                setPage(1);
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to save sale amount.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to save sale amount.'
            );
        } finally {
            setSaving(false);
        }
    };

    // =========================================================
    // DELETE
    // =========================================================

    const handleDelete = async () => {
        if (!deleteTarget) return;

        setError('');

        try {
            const response = await api.post(
                '/sale_amounts/delete.php',
                {
                    id: deleteTarget.id,
                }
            );

            if (response.data?.success) {
                setDeleteTarget(null);

                setMessage(
                    response.data?.message ||
                    'Sale amount deleted successfully.'
                );

                await fetchSaleAmounts();

                setPage(1);
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to delete sale amount.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to delete sale amount.'
            );
        }
    };

    // =========================================================
    // RESET FILTERS
    // =========================================================

    const resetFilters = () => {
        setSearch('');
        setStatusFilter('');
        setPage(1);
    };

    // =========================================================
    // FORMAT AMOUNT
    // =========================================================

    const formatAmount = (amount) => {
        const numericAmount =
            Number(amount);

        if (!Number.isFinite(numericAmount)) {
            return amount;
        }

        return `₹${numericAmount.toLocaleString(
            'en-IN',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }
        )}`;
    };

    // =========================================================
    // RENDER
    // =========================================================

    return (
        <div className="page-container">

            {/* =================================================
                HEADER
            ================================================= */}

            <div className="page-header">

                <div>
                    <h2>Sale Amount</h2>

                    <p>
                        Manage sale amount options
                        for customer payments.
                    </p>
                </div>

                <div className="header-actions">

                    {hasPermission(
                        'sale_amounts.add'
                    ) && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={
                                openAddModal
                            }
                        >
                            <Plus size={16} />
                            Add Sale Amount
                        </button>
                    )}

                </div>

            </div>

            {/* =================================================
                SUCCESS MESSAGE
            ================================================= */}

            {message && (
                <div className="alert alert-success">
                    {message}
                </div>
            )}

            {/* =================================================
                ERROR MESSAGE
            ================================================= */}

            {error && !showModal && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* =================================================
                FILTER + TABLE CARD
            ================================================= */}

            <div className="card">

                {/* FILTERS */}

                <div
                    className="filters-container"
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '12px',
                        flexWrap: 'wrap',
                        padding: '16px',
                    }}
                >

                    {/* SEARCH */}

                    <input
                        type="text"
                        className="form-control"
                        placeholder="Search Sale Amount"
                        value={search}
                        onChange={(event) => {
                            setSearch(
                                event.target.value
                            );
                            setPage(1);
                        }}
                        style={{
                            width: '260px',
                        }}
                    />

                    {/* STATUS */}

                    <select
                        className="form-control"
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(
                                event.target.value
                            );
                            setPage(1);
                        }}
                        style={{
                            width: '170px',
                        }}
                    >

                        <option value="">
                            All Statuses
                        </option>

                        <option value="Active">
                            Active
                        </option>

                        <option value="Inactive">
                            Inactive
                        </option>

                    </select>

                    {/* RESET */}

                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={
                            resetFilters
                        }
                    >
                        Reset Filters
                    </button>

                </div>

                {/* =================================================
                    TABLE
                ================================================= */}

                <div className="table-container">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Sale Amount
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            {loading ? (

                                <tr>

                                    <td
                                        colSpan="3"
                                        className="text-center"
                                    >
                                        Loading sale amounts...
                                    </td>

                                </tr>

                            ) : paginatedItems.length === 0 ? (

                                <tr>

                                    <td
                                        colSpan="3"
                                        className="text-center empty-state"
                                    >
                                        No sale amount
                                        records found.
                                    </td>

                                </tr>

                            ) : (

                                paginatedItems.map(
                                    (item) => (

                                        <tr
                                            key={
                                                item.id
                                            }
                                        >

                                            <td
                                                style={{
                                                    fontWeight:
                                                        500,
                                                }}
                                            >
                                                {
                                                    formatAmount(
                                                        item.sale_amount
                                                    )
                                                }
                                            </td>

                                            <td>

                                                <span
                                                    className={
                                                        item.status ===
                                                        'Active'
                                                            ? 'status-badge active'
                                                            : 'status-badge inactive'
                                                    }
                                                >
                                                    {
                                                        item.status
                                                    }
                                                </span>

                                            </td>

                                            <td>

                                                <div className="action-buttons">

                                                    {/* VIEW */}

                                                    <button
                                                        type="button"
                                                        className="icon-btn view"
                                                        title="View"
                                                        onClick={() =>
                                                            setViewingSaleAmount(
                                                                item
                                                            )
                                                        }
                                                    >
                                                        <Eye
                                                            size={
                                                                16
                                                            }
                                                        />
                                                    </button>

                                                    {/* EDIT */}

                                                    {hasPermission(
                                                        'sale_amounts.edit'
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            className="icon-btn edit"
                                                            title="Edit"
                                                            onClick={() =>
                                                                openEditModal(
                                                                    item
                                                                )
                                                            }
                                                        >
                                                            <Edit
                                                                size={
                                                                    16
                                                                }
                                                            />
                                                        </button>
                                                    )}

                                                    {/* DELETE */}

                                                    {hasPermission(
                                                        'sale_amounts.delete'
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            className="icon-btn delete"
                                                            title="Delete"
                                                            onClick={() =>
                                                                setDeleteTarget(
                                                                    item
                                                                )
                                                            }
                                                        >
                                                            <Trash2
                                                                size={
                                                                    16
                                                                }
                                                            />
                                                        </button>
                                                    )}

                                                </div>

                                            </td>

                                        </tr>

                                    )
                                )

                            )}

                        </tbody>

                    </table>

                </div>

                {/* =================================================
                    PAGINATION
                ================================================= */}

                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={
                        setPageSize
                    }
                    itemName="sale amounts"
                />

            </div>

            {/* =====================================================
                ADD / EDIT MODAL
            ===================================================== */}

            {showModal && (

                <Modal
                    isOpen
                    title={
                        editingSaleAmount
                            ? 'Edit Sale Amount'
                            : 'Add Sale Amount'
                    }
                    onClose={
                        closeModal
                    }
                    maxWidth="480px"
                    footer={
                        <>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={
                                    closeModal
                                }
                                disabled={
                                    saving
                                }
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                form="saleAmountForm"
                                className="btn btn-primary"
                                disabled={
                                    saving
                                }
                            >
                                {saving
                                    ? 'Saving...'
                                    : editingSaleAmount
                                        ? 'Update Sale Amount'
                                        : 'Save Sale Amount'}
                            </button>
                        </>
                    }
                >

                    <form
                        id="saleAmountForm"
                        onSubmit={
                            handleSave
                        }
                    >

                        {/* MODAL ERROR */}

                        {error && (
                            <div className="alert alert-danger">
                                {error}
                            </div>
                        )}

                        {/* SALE AMOUNT */}

                        <div className="form-group">

                            <label className="form-label">

                                Sale Amount

                                <span className="required">
                                    *
                                </span>

                            </label>

                            <input
                                type="number"
                                className="form-control"
                                value={
                                    saleAmount
                                }
                                onChange={(event) =>
                                    setSaleAmount(
                                        event.target.value
                                    )
                                }
                                placeholder="Enter sale amount"
                                min="0.01"
                                step="0.01"
                                required
                                autoFocus
                            />

                        </div>

                        {/* STATUS */}

                        <div className="form-group">

                            <label className="form-label">

                                Status

                                <span className="required">
                                    *
                                </span>

                            </label>

                            <select
                                className="form-control"
                                value={
                                    status
                                }
                                onChange={(event) =>
                                    setStatus(
                                        event.target.value
                                    )
                                }
                                required
                            >

                                <option value="Active">
                                    Active
                                </option>

                                <option value="Inactive">
                                    Inactive
                                </option>

                            </select>

                        </div>

                    </form>

                </Modal>

            )}

            {/* =====================================================
                DELETE MODAL
            ===================================================== */}

            {deleteTarget && (

                <Modal
                    isOpen
                    title="Delete Sale Amount"
                    onClose={() =>
                        setDeleteTarget(
                            null
                        )
                    }
                    maxWidth="420px"
                    footer={
                        <>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={() =>
                                    setDeleteTarget(
                                        null
                                    )
                                }
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                className="btn btn-danger"
                                onClick={
                                    handleDelete
                                }
                            >
                                Delete
                            </button>
                        </>
                    }
                >

                    <p>

                        Are you sure you want
                        to delete

                        <strong>
                            {' '}
                            {
                                formatAmount(
                                    deleteTarget.sale_amount
                                )
                            }
                        </strong>
                        ?

                    </p>

                </Modal>

            )}

            {/* =====================================================
                VIEW MODAL
            ===================================================== */}

            {viewingSaleAmount && (

                <RecordViewModal
                    isOpen={
                        !!viewingSaleAmount
                    }
                    onClose={() =>
                        setViewingSaleAmount(
                            null
                        )
                    }
                    title="Sale Amount Details"
                    record={
                        viewingSaleAmount
                    }
                    fields={[
                        {
                            label:
                                'Sale Amount',
                            key:
                                'sale_amount',
                            render: (value) =>
                                formatAmount(
                                    value
                                ),
                        },
                        {
                            label:
                                'Status',
                            key:
                                'status',
                        },
                    ]}
                />

            )}

        </div>
    );
};

export default SaleAmountsPage;