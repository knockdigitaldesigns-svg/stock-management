import { useEffect, useState } from 'react';
import { Plus, Eye, Edit, Trash2 } from 'lucide-react';

import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const VehicleTypesPage = () => {
    const { hasPermission } = useAuth();

    // =========================
    // DATA
    // =========================
    const [vehicleTypes, setVehicleTypes] = useState([]);
    const [loading, setLoading] = useState(true);

    // =========================
    // FILTERS
    // =========================
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    // =========================
    // ADD / EDIT
    // =========================
    const [showModal, setShowModal] = useState(false);
    const [editingVehicleType, setEditingVehicleType] = useState(null);

    const [vehicleType, setVehicleType] = useState('');
    const [status, setStatus] = useState('Active');

    // =========================
    // DELETE
    // =========================
    const [deleteTarget, setDeleteTarget] = useState(null);

    // =========================
    // VIEW
    // =========================
    const [viewingVehicleType, setViewingVehicleType] = useState(null);

    // =========================
    // MESSAGES
    // =========================
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    // =========================
    // SAVE STATE
    // =========================
    const [saving, setSaving] = useState(false);

    // =========================
    // FETCH VEHICLE TYPES
    // =========================
    const fetchVehicleTypes = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await api.get(
                '/vehicle_types/list.php'
            );

            if (response.data.success) {
                setVehicleTypes(
                    response.data.data?.vehicle_types || []
                );
            } else {
                triggerError(
                    response.data.message ||
                    'Unable to load vehicle types.'
                );
            }
        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to load vehicle types.'
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchVehicleTypes();
    }, []);

    // =========================
    // OPEN ADD MODAL
    // =========================
    const openAddModal = () => {
        setEditingVehicleType(null);
        setVehicleType('');
        setStatus('Active');
        setError('');
        setMessage('');
        setShowModal(true);
    };

    // =========================
    // OPEN EDIT MODAL
    // =========================
    const openEditModal = (item) => {
        setEditingVehicleType(item);
        setVehicleType(item.vehicle_type || '');
        setStatus(item.status || 'Active');
        setError('');
        setMessage('');
        setShowModal(true);
    };

    // =========================
    // CLOSE MODAL
    // =========================
    const closeModal = () => {
        if (saving) return;

        setShowModal(false);
        setEditingVehicleType(null);
        setVehicleType('');
        setStatus('Active');
        setError('');
    };

    // =========================
    // SAVE / UPDATE
    // =========================
    const handleSave = async () => {
        const trimmedValue = vehicleType.trim();

        if (!trimmedValue) {
            triggerError('Vehicle type is required.');
            return;
        }

        if (!['Active', 'Inactive'].includes(status)) {
            triggerError('Please select a valid status.');
            return;
        }

        setSaving(true);
        setError('');

        try {
            let response;

            if (editingVehicleType) {
                response = await api.post(
                    '/vehicle_types/update.php',
                    {
                        id: editingVehicleType.id,
                        vehicle_type: trimmedValue,
                        status: status
                    }
                );
            } else {
                response = await api.post(
                    '/vehicle_types/create.php',
                    {
                        vehicle_type: trimmedValue,
                        status: status
                    }
                );
            }

            if (!response.data.success) {
                triggerError(
                    response.data.message ||
                    'Unable to save vehicle type.'
                );
                return;
            }

            setShowModal(false);
            setEditingVehicleType(null);
            setVehicleType('');
            setStatus('Active');

            setMessage(
                response.data.message ||
                'Vehicle type saved successfully.'
            );

            await fetchVehicleTypes();

        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to save vehicle type.'
            );
        } finally {
            setSaving(false);
        }
    };

    // =========================
    // DELETE
    // =========================
    const handleDelete = async () => {
        if (!deleteTarget) return;

        setError('');

        try {
            const response = await api.post(
                '/vehicle_types/delete.php',
                {
                    id: deleteTarget.id
                }
            );

            if (!response.data.success) {
                triggerError(
                    response.data.message ||
                    'Unable to delete vehicle type.'
                );
                return;
            }

            setDeleteTarget(null);

            setMessage(
                response.data.message ||
                'Vehicle type deleted successfully.'
            );

            await fetchVehicleTypes();

        } catch (err) {
            triggerError(
                err.response?.data?.message ||
                'Unable to delete vehicle type.'
            );
        }
    };

    // =========================
    // FILTER
    // =========================
    const filteredVehicleTypes = vehicleTypes.filter(
        (item) => {
            const name =
                item.vehicle_type || '';

            const itemStatus =
                item.status || '';

            const matchesSearch =
                name
                    .toLowerCase()
                    .includes(search.toLowerCase());

            const matchesStatus =
                !statusFilter ||
                itemStatus === statusFilter;

            return (
                matchesSearch &&
                matchesStatus
            );
        }
    );

    // =========================
    // PAGINATION
    // =========================
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(
        filteredVehicleTypes,
        10,
        [search, statusFilter]
    );

    // =========================
    // RESET FILTERS
    // =========================
    const resetFilters = () => {
        setSearch('');
        setStatusFilter('');
        setPage(1);
    };

    // =========================
    // RENDER
    // =========================
    return (
        <div className="page-container">

            {/* =========================
                PAGE HEADER
            ========================== */}
            <div className="page-header">

                <h2>Vehicle Types</h2>

                <div className="header-actions">

                    {hasPermission(
                        'vehicle_types.add'
                    ) && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={openAddModal}
                        >
                            <Plus size={16} />
                            Add Vehicle Type
                        </button>
                    )}

                </div>

            </div>

            {/* =========================
                SUCCESS MESSAGE
            ========================== */}
            {message && (
                <div className="alert alert-success">
                    {message}
                </div>
            )}

            {/* =========================
                ERROR MESSAGE
            ========================== */}
            {error && !showModal && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* =========================
                TABLE CARD
            ========================== */}
            <div className="card">

                {/* =========================
                    FILTERS
                ========================== */}
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '12px',
                        padding: '20px',
                        flexWrap: 'wrap'
                    }}
                >

                    {/* SEARCH */}
                    <input
                        type="text"
                        className="form-control"
                        placeholder="Search vehicle type..."
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                        style={{
                            width: '260px',
                            flex: '0 0 260px'
                        }}
                    />

                    {/* STATUS */}
                    <select
                        className="form-control"
                        value={statusFilter}
                        onChange={(e) => {
                            setStatusFilter(
                                e.target.value
                            );
                            setPage(1);
                        }}
                        style={{
                            width: '170px',
                            flex: '0 0 170px'
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
                        onClick={resetFilters}
                    >
                        Reset Filters
                    </button>

                </div>

                {/* =========================
                    TABLE
                ========================== */}
                <div className="table-container">

                    <table>

                        <thead>
                            <tr>
                                <th>
                                    Vehicle Type
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
                                        Loading vehicle types...
                                    </td>
                                </tr>

                            ) : paginatedItems.length === 0 ? (

                                <tr>
                                    <td
                                        colSpan="3"
                                        className="text-center empty-state"
                                    >
                                        No vehicle types found.
                                    </td>
                                </tr>

                            ) : (

                                paginatedItems.map(
                                    (item) => (
                                        <tr
                                            key={item.id}
                                        >

                                            {/* VEHICLE TYPE */}
                                            <td
                                                title={
                                                    item.vehicle_type
                                                }
                                                style={{
                                                    fontWeight: 500
                                                }}
                                            >
                                                {
                                                    item.vehicle_type
                                                }
                                            </td>

                                            {/* STATUS */}
                                            <td>
                                                <span
                                                    className={
                                                        item.status ===
                                                        'Active'
                                                            ? 'status-badge status-active'
                                                            : 'status-badge status-inactive'
                                                    }
                                                >
                                                    {
                                                        item.status
                                                    }
                                                </span>
                                            </td>

                                            {/* ACTIONS */}
                                            <td>

                                                <div className="action-buttons">

                                                    {/* VIEW */}
                                                    {hasPermission(
                                                        'vehicle_types.view'
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            className="icon-btn view"
                                                            title="View"
                                                            onClick={() =>
                                                                setViewingVehicleType(
                                                                    item
                                                                )
                                                            }
                                                        >
                                                            <Eye size={16} />
                                                        </button>
                                                    )}

                                                    {/* EDIT */}
                                                    {hasPermission(
                                                        'vehicle_types.edit'
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
                                                        'vehicle_types.delete'
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
                                    )
                                )

                            )}

                        </tbody>

                    </table>

                </div>

                {/* =========================
                    PAGINATION
                ========================== */}
                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="vehicle types"
                />

            </div>

            {/* =========================
                ADD / EDIT MODAL
            ========================== */}
            {showModal && (

                <Modal
                    isOpen
                    title={
                        editingVehicleType
                            ? 'Edit Vehicle Type'
                            : 'Add Vehicle Type'
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
                                type="button"
                                className="btn btn-primary"
                                onClick={handleSave}
                                disabled={saving}
                            >
                                {saving
                                    ? 'Saving...'
                                    : editingVehicleType
                                        ? 'Update Vehicle Type'
                                        : 'Save Vehicle Type'
                                }
                            </button>
                        </>
                    }
                >

                    {/* VEHICLE TYPE */}
                    <div className="form-group">

                        <label className="form-label">
                            Vehicle Type *
                        </label>

                        <input
                            type="text"
                            className="form-control"
                            placeholder="Enter vehicle type"
                            value={vehicleType}
                            onChange={(e) =>
                                setVehicleType(
                                    e.target.value
                                )
                            }
                            autoFocus
                        />

                    </div>

                    {/* STATUS */}
                    <div className="form-group">

                        <label className="form-label">
                            Status *
                        </label>

                        <select
                            className="form-control"
                            value={status}
                            onChange={(e) =>
                                setStatus(
                                    e.target.value
                                )
                            }
                        >
                            <option value="Active">
                                Active
                            </option>

                            <option value="Inactive">
                                Inactive
                            </option>
                        </select>

                    </div>

                    {/* MODAL ERROR */}
                    {error && (
                        <div className="alert alert-danger">
                            {error}
                        </div>
                    )}

                </Modal>

            )}

            {/* =========================
                DELETE MODAL
            ========================== */}
            {deleteTarget && (

                <Modal
                    isOpen
                    title="Delete Vehicle Type"
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
                        this vehicle type?
                    </p>

                </Modal>

            )}

            {/* =========================
                VIEW MODAL
            ========================== */}
            {viewingVehicleType && (

                <RecordViewModal
                    isOpen
                    onClose={() =>
                        setViewingVehicleType(null)
                    }
                    title="Vehicle Type Details"
                    record={viewingVehicleType}
                    fields={[
                        {
                            label: 'Vehicle Type',
                            key: 'vehicle_type'
                        },
                        {
                            label: 'Status',
                            key: 'status'
                        }
                    ]}
                />

            )}

        </div>
    );
};

export default VehicleTypesPage;