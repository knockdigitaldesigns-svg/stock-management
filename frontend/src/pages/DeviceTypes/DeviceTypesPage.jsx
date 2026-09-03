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

const DeviceTypesPage = () => {
    const { hasPermission } = useAuth();
    const [deviceTypes, setDeviceTypes] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);
    const [modal, setModal] = useState(null);
    const [showModal, setShowModal] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [value, setValue] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [viewingType, setViewingType] = useState(null);

    const fetchDeviceTypes = async () => {
        setLoading(true);
        try {
            const response = await api.get('/device_types/list.php');
            if (response.data.success) setDeviceTypes(response.data.data?.device_types || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load device types.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchDeviceTypes();
    }, []);

    const openModal = (type = null) => {
        setModal(type);
        setShowModal(true);
        setValue(type?.device_type || '');
        setError('');
    };

    const save = async () => {
        const deviceType = value.trim();
        if (!deviceType) {
            setError('Device type is required.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            const response = modal
                ? await api.post('/device_types/update.php', { id: modal.id, device_type: deviceType })
                : await api.post('/device_types/create.php', { device_type: deviceType });
            if (!response.data.success) {
                setError(response.data.message || 'Unable to save device type.');
                return;
            }
            setShowModal(false);
            setModal(null);
            setValue('');
            setMessage(response.data.message);
            await fetchDeviceTypes();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save device type.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        try {
            const response = await api.post('/device_types/delete.php', { id: deleteTarget.id });
            if (!response.data.success) {
                setError(response.data.message || 'Unable to delete device type.');
                return;
            }
            setDeleteTarget(null);
            setMessage(response.data.message);
            await fetchDeviceTypes();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to delete device type.');
        }
    };

    const filtered = filterTableRows(deviceTypes, filters, { dateKeys: ['created_at'], searchKeys: ['device_type'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filtered, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Device Types</h2>
                <div className="header-actions">
                    {hasPermission('device_types.add') && (
                        <button className="btn btn-primary" onClick={() => openModal()}>
                            <Plus size={16} /> Add Device Type
                        </button>
                    )}
                </div>
            </div>

            {message && <div className="alert alert-success">{message}</div>}
            {error && !modal && <div className="alert alert-danger">{error}</div>}

            <div className="card">
                <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={deviceTypes} dateKeys={['created_at']} searchPlaceholder="Search device type..." />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Device Type</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="3" className="text-center">
                                        Loading device types...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="3" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((type) => (
                                    <tr key={type.id}>
                                        <td className="truncate-cell" title={type.device_type} style={{ fontWeight: 500 }}>{type.device_type}</td>
                                        <td>{formatDate(type.created_at)}</td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View device type" title="View" onClick={() => setViewingType(type)}><Eye size={16} /></button>
                                                {hasPermission('device_types.edit') && (
                                                    <button
                                                        className="icon-btn edit"
                                                        type="button"
                                                        aria-label="Edit device type"
                                                        onClick={() => openModal(type)}
                                                    >
                                                        <Edit size={16} />
                                                    </button>
                                                )}
                                                {hasPermission('device_types.delete') && (
                                                    <button
                                                        className="icon-btn delete"
                                                        type="button"
                                                        aria-label="Delete device type"
                                                        onClick={() => setDeleteTarget(type)}
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

                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="device types"
                />
            </div>

            {showModal && (
                <Modal
                    isOpen
                    title={modal ? 'Edit Device Type' : 'Add Device Type'}
                    onClose={() => setShowModal(false)}
                    maxWidth="480px"
                    footer={
                        <>
                            <button className="btn btn-outline" onClick={() => setShowModal(false)} disabled={saving}>
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save} disabled={saving}>
                                {saving ? 'Saving...' : modal ? 'Update Device Type' : 'Save Device Type'}
                            </button>
                        </>
                    }
                >
                    <div className="form-group">
                        <label className="form-label">Device Type *</label>
                        <input
                            className="form-control"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            autoFocus
                        />
                        {error && <div className="text-danger">{error}</div>}
                    </div>
                </Modal>
            )}

            {deleteTarget && (
                <Modal
                    isOpen
                    title="Delete Device Type"
                    onClose={() => setDeleteTarget(null)}
                    maxWidth="420px"
                    footer={
                        <>
                            <button className="btn btn-outline" onClick={() => setDeleteTarget(null)}>
                                Cancel
                            </button>
                            <button className="btn btn-danger" onClick={remove}>
                                Delete
                            </button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this device type?</p>
                </Modal>
            )}
            {viewingType && <RecordViewModal isOpen onClose={() => setViewingType(null)} title="Device Type Details" record={viewingType} fetchRecord={async (row) => (await api.get('/device_types/list.php')).data.data.device_types.find((item) => String(item.id) === String(row.id)) || row} fields={[{ label: 'Device Type', key: 'device_type' }, { label: 'Created Date', key: 'created_at' }]} />}
        </div>
    );
};

export default DeviceTypesPage;
