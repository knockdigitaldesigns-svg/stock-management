import { useEffect, useMemo, useState } from 'react';
import { Plus, Eye, Edit, Trash2 } from 'lucide-react';

import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const LeadClosuresPage = () => {
    const { hasPermission } = useAuth();

    // ==============================
    // STATE
    // ==============================

    const [leadClosures, setLeadClosures] = useState([]);
    const [loading, setLoading] = useState(false);

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    const [showModal, setShowModal] = useState(false);
    const [editingLeadClosure, setEditingLeadClosure] = useState(null);

    const [leadClosureName, setLeadClosureName] = useState('');
    const [mobileNo, setMobileNo] = useState('');
    const [location, setLocation] = useState('');
    const [status, setStatus] = useState('Active');

    const [deleteTarget, setDeleteTarget] = useState(null);
    const [viewingLeadClosure, setViewingLeadClosure] = useState(null);

    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    // ==============================
    // FETCH
    // ==============================

    const fetchLeadClosures = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await api.get('/lead_closures/list.php');

            if (response.data?.success) {
                setLeadClosures(
                    response.data?.data?.lead_closures || []
                );
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to load lead closures.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to load lead closures.'
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLeadClosures();
    }, []);

    // ==============================
    // FILTER
    // ==============================

    const filteredLeadClosures = useMemo(() => {
        const value = search.trim().toLowerCase();

        return leadClosures.filter((item) => {
            const matchesSearch =
                !value ||
                String(item.lead_closure_name || '')
                    .toLowerCase()
                    .includes(value) ||
                String(item.mobile_no || '')
                    .toLowerCase()
                    .includes(value) ||
                String(item.location || '')
                    .toLowerCase()
                    .includes(value);

            const matchesStatus =
                !statusFilter ||
                item.status === statusFilter;

            return matchesSearch && matchesStatus;
        });
    }, [leadClosures, search, statusFilter]);

    // ==============================
    // PAGINATION
    // ==============================

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems,
    } = usePagination(
        filteredLeadClosures,
        10,
        [search, statusFilter]
    );

    // ==============================
    // FORM RESET
    // ==============================

    const resetForm = () => {
        setLeadClosureName('');
        setMobileNo('');
        setLocation('');
        setStatus('Active');
        setEditingLeadClosure(null);
    };

    // ==============================
    // ADD
    // ==============================

    const openAddModal = () => {
        resetForm();
        setError('');
        setMessage('');
        setShowModal(true);
    };

    // ==============================
    // EDIT
    // ==============================

    const openEditModal = (item) => {
        setEditingLeadClosure(item);

        setLeadClosureName(
            item.lead_closure_name || ''
        );

        setMobileNo(
            item.mobile_no || ''
        );

        setLocation(
            item.location || ''
        );

        setStatus(
            item.status || 'Active'
        );

        setError('');
        setMessage('');
        setShowModal(true);
    };

    // ==============================
    // CLOSE MODAL
    // ==============================

    const closeModal = () => {
        if (saving) return;

        setShowModal(false);
        resetForm();
        setError('');
    };

    // ==============================
    // SAVE / UPDATE
    // ==============================

    const handleSave = async (event) => {
        event.preventDefault();

        setError('');
        setMessage('');

        const name = leadClosureName.trim();
        const mobile = mobileNo.trim();
        const place = location.trim();

        if (!name) {
            triggerError('Lead closure name is required.');
            return;
        }

        if (!/^[0-9]{10}$/.test(mobile)) {
            triggerError(
                'Mobile number must contain exactly 10 digits.'
            );
            return;
        }

        if (!place) {
            triggerError('Location is required.');
            return;
        }

        if (!['Active', 'Inactive'].includes(status)) {
            triggerError('Invalid status.');
            return;
        }

        setSaving(true);

        try {
            const payload = {
                lead_closure_name: name,
                mobile_no: mobile,
                location: place,
                status,
            };

            const response = editingLeadClosure
                ? await api.post(
                    '/lead_closures/update.php',
                    {
                        id: editingLeadClosure.id,
                        ...payload,
                    }
                )
                : await api.post(
                    '/lead_closures/create.php',
                    payload
                );

            if (response.data?.success) {
                setShowModal(false);
                resetForm();

                setMessage(
                    response.data?.message ||
                    (
                        editingLeadClosure
                            ? 'Lead closure updated successfully.'
                            : 'Lead closure added successfully.'
                    )
                );

                await fetchLeadClosures();
                setPage(1);
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to save lead closure.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to save lead closure.'
            );
        } finally {
            setSaving(false);
        }
    };

    // ==============================
    // DELETE
    // ==============================

    const handleDelete = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post(
                '/lead_closures/delete.php',
                {
                    id: deleteTarget.id,
                }
            );

            if (response.data?.success) {
                setDeleteTarget(null);

                setMessage(
                    response.data?.message ||
                    'Lead closure deleted successfully.'
                );

                await fetchLeadClosures();
                setPage(1);
            } else {
                triggerError(
                    response.data?.message ||
                    'Unable to delete lead closure.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to delete lead closure.'
            );
        }
    };

    // ==============================
    // RESET FILTERS
    // ==============================

    const resetFilters = () => {
        setSearch('');
        setStatusFilter('');
        setPage(1);
    };

    // ==============================
    // PAGE
    // ==============================

    return (
        <div className="page-container">

            {/* HEADER */}
            <div className="page-header">
                <div>
                    <h2>Lead Closure</h2>
                    <p>
                        Manage lead closure persons
                        and their details.
                    </p>
                </div>

                <div className="header-actions">
                    {hasPermission('lead_closures.add') && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={openAddModal}
                        >
                            <Plus size={16} />
                            Add Lead Closure
                        </button>
                    )}
                </div>
            </div>

            {/* SUCCESS */}
            {message && (
                <div className="alert alert-success">
                    {message}
                </div>
            )}

            {/* ERROR */}
            {error && !showModal && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* FILTERS */}
            <div className="card">
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
                    <input
                        type="text"
                        className="form-control"
                        placeholder="Search Lead Closure"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        style={{
                            width: '260px',
                        }}
                    />

                    <select
                        className="form-control"
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(event.target.value);
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

                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={resetFilters}
                    >
                        Reset Filters
                    </button>
                </div>

                {/* TABLE */}
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Lead Closure Name</th>
                                <th>Mobile No</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            {loading ? (
                                <tr>
                                    <td
                                        colSpan="5"
                                        className="text-center"
                                    >
                                        Loading lead closures...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan="5"
                                        className="text-center empty-state"
                                    >
                                        No lead closure
                                        records found.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((item) => (
                                    <tr key={item.id}>

                                        <td>
                                            {item.lead_closure_name}
                                        </td>

                                        <td>
                                            {item.mobile_no}
                                        </td>

                                        <td>
                                            {item.location}
                                        </td>

                                        <td>
                                            <span
                                                className={
                                                    item.status === 'Active'
                                                        ? 'status-badge active'
                                                        : 'status-badge inactive'
                                                }
                                            >
                                                {item.status}
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
                                                        setViewingLeadClosure(
                                                            item
                                                        )
                                                    }
                                                >
                                                    <Eye size={16} />
                                                </button>

                                                {/* EDIT */}
                                                {hasPermission(
                                                    'lead_closures.edit'
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
                                                        <Edit size={16} />
                                                    </button>
                                                )}

                                                {/* DELETE */}
                                                {hasPermission(
                                                    'lead_closures.delete'
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
                                                        <Trash2 size={16} />
                                                    </button>
                                                )}

                                            </div>
                                        </td>

                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* PAGINATION */}
                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="lead closures"
                />
            </div>

            {/* ==========================================
                ADD / EDIT MODAL
            ========================================== */}

            {showModal && (
                <Modal
                    isOpen
                    title={
                        editingLeadClosure
                            ? 'Edit Lead Closure'
                            : 'Add Lead Closure'
                    }
                    onClose={closeModal}
                    maxWidth="480px"
                    footer={
                        <>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={closeModal}
                                disabled={saving}
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                form="leadClosureForm"
                                className="btn btn-primary"
                                disabled={saving}
                            >
                                {saving
                                    ? 'Saving...'
                                    : editingLeadClosure
                                        ? 'Update Lead Closure'
                                        : 'Save Lead Closure'}
                            </button>
                        </>
                    }
                >
                    <form
                        id="leadClosureForm"
                        onSubmit={handleSave}
                    >

                        {error && (
                            <div className="alert alert-danger">
                                {error}
                            </div>
                        )}

                        {/* NAME */}
                        <div className="form-group">
                            <label className="form-label">
                                Lead Closure Name
                                <span className="required">*</span>
                            </label>

                            <input
                                type="text"
                                className="form-control"
                                value={leadClosureName}
                                onChange={(event) =>
                                    setLeadClosureName(
                                        event.target.value
                                    )
                                }
                                placeholder="Enter lead closure name"
                                maxLength={100}
                                autoFocus
                                required
                            />
                        </div>

                        {/* MOBILE */}
                        <div className="form-group">
                            <label className="form-label">
                                Mobile No
                                <span className="required">*</span>
                            </label>

                            <input
                                type="text"
                                className="form-control"
                                inputMode="numeric"
                                value={mobileNo}
                                onChange={(event) =>
                                    setMobileNo(
                                        event.target.value
                                            .replace(/\D/g, '')
                                            .slice(0, 10)
                                    )
                                }
                                placeholder="Enter 10 digit mobile number"
                                maxLength={10}
                                required
                            />
                        </div>

                        {/* LOCATION */}
                        <div className="form-group">
                            <label className="form-label">
                                Location
                                <span className="required">*</span>
                            </label>

                            <input
                                type="text"
                                className="form-control"
                                value={location}
                                onChange={(event) =>
                                    setLocation(
                                        event.target.value
                                    )
                                }
                                placeholder="Enter location"
                                maxLength={150}
                                required
                            />
                        </div>

                        {/* STATUS */}
                        <div className="form-group">
                            <label className="form-label">
                                Status
                                <span className="required">*</span>
                            </label>

                            <select
                                className="form-control"
                                value={status}
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

            {/* ==========================================
                DELETE MODAL
            ========================================== */}

            {deleteTarget && (
                <Modal
                    isOpen
                    title="Delete Lead Closure"
                    onClose={() =>
                        setDeleteTarget(null)
                    }
                    maxWidth="420px"
                    footer={
                        <>
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={() =>
                                    setDeleteTarget(null)
                                }
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                className="btn btn-danger"
                                onClick={handleDelete}
                            >
                                Delete
                            </button>
                        </>
                    }
                >
                    <p>
                        Are you sure you want to delete
                        <strong>
                            {' '}
                            {deleteTarget.lead_closure_name}
                        </strong>
                        ?
                    </p>
                </Modal>
            )}

            {/* ==========================================
                VIEW MODAL
            ========================================== */}

            {viewingLeadClosure && (
                <RecordViewModal
                    isOpen={!!viewingLeadClosure}
                    onClose={() =>
                        setViewingLeadClosure(null)
                    }
                    title="Lead Closure Details"
                    record={viewingLeadClosure}
                    fields={[
                        {
                            label: 'Lead Closure Name',
                            key: 'lead_closure_name',
                        },
                        {
                            label: 'Mobile No',
                            key: 'mobile_no',
                        },
                        {
                            label: 'Location',
                            key: 'location',
                        },
                        {
                            label: 'Status',
                            key: 'status',
                        },
                    ]}
                />
            )}

        </div>
    );
};

export default LeadClosuresPage;