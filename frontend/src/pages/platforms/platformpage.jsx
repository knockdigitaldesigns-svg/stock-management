import { useEffect, useState } from 'react';
import { Plus, Eye, Edit, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { showGlobalError } from '../../context/ErrorContext';

const PlatformPage = () => {
    const { hasPermission } = useAuth();

    // =========================
    // DATA
    // =========================
    const [platforms, setPlatforms] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);

    // =========================
    // ADD / EDIT MODAL
    // =========================
    const [modal, setModal] = useState(null);
    const [showModal, setShowModal] = useState(false);

    const [value, setValue] = useState('');
    const [status, setStatus] = useState('Active');

    // =========================
    // DELETE / VIEW
    // =========================
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [viewingPlatform, setViewingPlatform] = useState(null);

    // =========================
    // MESSAGES
    // =========================
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    // =========================
    // FETCH PLATFORMS
    // =========================
    const fetchPlatforms = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await api.get('/platforms/list.php');

            if (response.data.success) {
                setPlatforms(response.data.data?.platforms || []);
            } else {
                triggerError(response.data.message || 'Unable to load platforms.');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to load platforms.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchPlatforms();
    }, []);

    // =========================
    // OPEN ADD / EDIT MODAL
    // =========================
    const openModal = (platform = null) => {
        setModal(platform);
        setShowModal(true);
        setValue(platform?.platform_name || '');
        setStatus(platform?.status || 'Active');
        setError('');
        setMessage('');
    };

    // =========================
    // CLOSE ADD / EDIT MODAL
    // =========================
    const closeModal = () => {
        if (saving) return;

        setShowModal(false);
        setModal(null);
        setValue('');
        setStatus('Active');
        setError('');
    };

    // =========================
    // SAVE / UPDATE PLATFORM
    // =========================
    const save = async () => {
        const platformName = value.trim();

        if (!platformName) {
            triggerError('Platform name is required.');
            return;
        }

        if (!['Active', 'Inactive'].includes(status)) {
            triggerError('Please select a valid status.');
            return;
        }

        setSaving(true);
        setError('');

        try {
            const response = modal
                ? await api.post('/platforms/update.php', {
                    id: modal.id,
                    platform_name: platformName,
                    status: status
                })
                : await api.post('/platforms/create.php', {
                    platform_name: platformName,
                    status: status
                });

            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to save platform.');
                return;
            }

            setShowModal(false);
            setModal(null);
            setValue('');
            setStatus('Active');
            setMessage(response.data.message || 'Platform saved successfully.');
            await fetchPlatforms();
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to save platform.');
        } finally {
            setSaving(false);
        }
    };

    // =========================
    // DELETE PLATFORM
    // =========================
    const remove = async () => {
        if (!deleteTarget) return;

        setError('');

        try {
            const response = await api.post('/platforms/delete.php', {
                id: deleteTarget.id
            });

            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to delete platform.');
                return;
            }

            setDeleteTarget(null);
            setMessage(response.data.message || 'Platform deleted successfully.');
            await fetchPlatforms();
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to delete platform.');
        }
    };

    // =========================
    // FILTER DATA
    // =========================
    const filtered = filterTableRows(platforms, filters, {
        dateKeys: ['created_at'],
        searchKeys: ['platform_name']
    });

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
    } = usePagination(filtered, 10, [filters]);

    // =========================
    // RENDER
    // =========================
    return (
        <div className="page-container">
            {/* =========================
                PAGE HEADER
            ========================== */}
            <div className="page-header">
                <h2>Platform</h2>
                <div className="header-actions">
                    {hasPermission('platforms.add') && (
                        <button
                            className="btn btn-primary"
                            type="button"
                            onClick={() => openModal()}
                        >
                            <Plus size={16} /> Add Platform
                        </button>
                    )}
                </div>
            </div>

            {/* =========================
                SUCCESS MESSAGE
            ========================== */}
            {message && <div className="alert alert-success">{message}</div>}

            {/* =========================
                ERROR MESSAGE
            ========================== */}
            {error && !modal && <div className="alert alert-danger">{error}</div>}

            {/* =========================
                PLATFORM TABLE CARD
            ========================== */}
            <div className="card">
                {/* =========================
                    FILTERS
                ========================== */}
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={platforms}
                    dateKeys={['created_at']}
                    showStatus
                    statusOptions={['Active', 'Inactive']}
                    searchPlaceholder="Search platform..."
                />

                {/* =========================
                    TABLE
                ========================== */}
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Platform Name</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            {/* LOADING */}
                            {loading ? (
                                <tr>
                                    <td colSpan="4" className="text-center">
                                        Loading platforms...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                /* EMPTY */
                                <tr>
                                    <td colSpan="4" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                /* DATA */
                                paginatedItems.map((platform) => (
                                    <tr key={platform.id}>
                                        {/* PLATFORM NAME */}
                                        <td
                                            className="truncate-cell"
                                            title={platform.platform_name}
                                            style={{ fontWeight: 500 }}
                                        >
                                            {platform.platform_name}
                                        </td>

                                        {/* STATUS */}
                                        <td>
                                            <span
                                                className={`badge ${
                                                    platform.status === 'Active'
                                                        ? 'badge-success'
                                                        : 'badge-danger'
                                                }`}
                                            >
                                                {platform.status || 'Active'}
                                            </span>
                                        </td>

                                        {/* CREATED DATE */}
                                        <td>{formatDate(platform.created_at)}</td>

                                        {/* ACTIONS */}
                                        <td>
                                            <div className="action-buttons">
                                                {/* VIEW */}
                                                <button
                                                    className="icon-btn view"
                                                    type="button"
                                                    aria-label="View platform"
                                                    title="View"
                                                    onClick={() => setViewingPlatform(platform)}
                                                >
                                                    <Eye size={16} />
                                                </button>

                                                {/* EDIT */}
                                                {hasPermission('platforms.edit') && (
                                                    <button
                                                        className="icon-btn edit"
                                                        type="button"
                                                        aria-label="Edit platform"
                                                        title="Edit"
                                                        onClick={() => openModal(platform)}
                                                    >
                                                        <Edit size={16} />
                                                    </button>
                                                )}

                                                {/* DELETE */}
                                                {hasPermission('platforms.delete') && (
                                                    <button
                                                        className="icon-btn delete"
                                                        type="button"
                                                        aria-label="Delete platform"
                                                        title="Delete"
                                                        onClick={() => setDeleteTarget(platform)}
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

                {/* =========================
                    PAGINATION
                ========================== */}
                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="platforms"
                />
            </div>

            {/* =========================
                ADD / EDIT MODAL
            ========================== */}
            {showModal && (
                <Modal
                    isOpen
                    title={modal ? 'Edit Platform' : 'Add Platform'}
                    onClose={closeModal}
                    maxWidth="480px"
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                type="button"
                                onClick={closeModal}
                                disabled={saving}
                            >
                                Cancel
                            </button>

                            <button
                                className="btn btn-primary"
                                type="button"
                                onClick={save}
                                disabled={saving}
                            >
                                {saving
                                    ? 'Saving...'
                                    : modal
                                    ? 'Update Platform'
                                    : 'Save Platform'}
                            </button>
                        </>
                    }
                >
                    {/* PLATFORM NAME */}
                    <div className="form-group">
                        <label className="form-label">Platform Name *</label>

                        <input
                            type="text"
                            className="form-control"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            placeholder="Enter platform name"
                            autoFocus
                        />

                        {error && <div className="text-danger">{error}</div>}
                    </div>

                    {/* STATUS */}
                    <div className="form-group">
                        <label className="form-label">Status *</label>

                        <select
                            className="form-control"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                        >
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </Modal>
            )}

            {/* =========================
                DELETE MODAL
            ========================== */}
            {deleteTarget && (
                <Modal
                    isOpen
                    title="Delete Platform"
                    onClose={() => setDeleteTarget(null)}
                    maxWidth="420px"
                    footer={
                        <>
                            <button
                                className="btn btn-outline"
                                type="button"
                                onClick={() => setDeleteTarget(null)}
                            >
                                Cancel
                            </button>

                            <button
                                className="btn btn-danger"
                                type="button"
                                onClick={remove}
                            >
                                Delete
                            </button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this platform?</p>
                </Modal>
            )}

            {/* =========================
                VIEW MODAL
            ========================== */}
            {viewingPlatform && (
                <RecordViewModal
                    isOpen
                    onClose={() => setViewingPlatform(null)}
                    title="Platform Details"
                    record={viewingPlatform}
                    fetchRecord={async (row) =>
                        (await api.get('/platforms/list.php')).data.data?.platforms?.find(
                            (item) => String(item.id) === String(row.id)
                        ) || row
                    }
                    fields={[
                        {
                            label: 'Platform Name',
                            key: 'platform_name'
                        },
                        {
                            label: 'Status',
                            key: 'status'
                        },
                        {
                            label: 'Created Date',
                            key: 'created_at',
                            format: (val) => formatDate(val)
                        }
                    ]}
                />
            )}
        </div>
    );
};

export default PlatformPage;