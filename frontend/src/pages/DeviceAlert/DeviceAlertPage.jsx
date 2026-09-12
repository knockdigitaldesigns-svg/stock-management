import { useEffect, useMemo, useState } from 'react';
import { Eye, Edit, Trash2 } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import Modal from '../../components/Modal/Modal';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { showGlobalError } from '../../context/ErrorContext';

const getStatusClass = (status) => {
    if (status === 'ALERT') return 'badge-danger';
    if (status === 'WARNING') return 'badge-warning';
    return 'badge-success';
};

const isWholeNumberString = (value) => /^[0-9]+$/.test(String(value).trim());

const DeviceAlertPage = () => {
    const { hasPermission } = useAuth();
    const [owners, setOwners] = useState([]);
    const [ownerOptions, setOwnerOptions] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };
    const [success, setSuccess] = useState('');
    const [isEditing, setIsEditing] = useState(false);
    const [editingConfigId, setEditingConfigId] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [viewingOwner, setViewingOwner] = useState(null);
    const [form, setForm] = useState({
        owner_type: 'dealer',
        owner_id: '',
        minimum_device_count: '',
        minimum_sim_count: '',
        notes: '',
    });

    const canEdit = hasPermission('device_alert.edit');
    const canDelete = hasPermission('device_alert.delete');

    const isEligibleDealer = (status) => {
        const s = String(status || '').trim().toLowerCase();
        return s === 'onsite' || s === 'offsite';
    };

    const fetchOwnerOptions = async () => {
        try {
            const [dealerRes, technicianRes] = await Promise.all([
                api.get('/dealers/list.php?for_alert=1'),
                api.get('/technicians/list.php')
            ]);

            const dealerOptions = (dealerRes?.data?.data?.dealers || [])
                .filter((dealer) => isEligibleDealer(dealer.installation_status))
                .map((dealer) => ({
                    value: String(dealer.id),
                    label: `Dealer - ${dealer.dealer_name}`,
                    owner_type: 'dealer',
                    installation_status: dealer.installation_status,
                }));

            const technicianOptions = (technicianRes?.data?.data?.technicians || []).map((tech) => ({
                value: String(tech.id),
                label: `Technician - ${tech.technician_name}`,
                owner_type: 'technician',
            }));

            setOwnerOptions([...dealerOptions, ...technicianOptions]);
        } catch (err) {
            console.error('Failed to fetch owner list', err);
        }
    };

    const fetchAlerts = async () => {
        try {
            setLoading(true);
            const response = await api.get('/device_alert/list.php');
            if (response.data.success) {
                setOwners(response.data.data?.owners || []);
                setError('');
            } else {
                triggerError(response.data.message || 'Unable to load device alert list');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to load device alert list');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchOwnerOptions();
        fetchAlerts();
    }, []);

    const filteredOwnerOptions = useMemo(() => {
        const options = ownerOptions.filter((option) => option.owner_type === form.owner_type);
        if (isEditing && form.owner_id && !options.some((opt) => opt.value === String(form.owner_id))) {
            const currentConfig = owners.find((o) => o.id === editingConfigId);
            if (currentConfig) {
                options.unshift({
                    value: String(form.owner_id),
                    label: `${currentConfig.owner_type === 'dealer' ? 'Dealer' : 'Technician'} - ${currentConfig.owner_name} (${currentConfig.installation_status || 'Not Willing'})`,
                    owner_type: currentConfig.owner_type,
                    installation_status: currentConfig.installation_status,
                });
            }
        }
        return options;
    }, [ownerOptions, form.owner_type, isEditing, form.owner_id, editingConfigId, owners]);

    const isDealerCurrentlyNotWilling = isEditing && form.owner_type === 'dealer' && (() => {
        const currentConfig = owners.find((o) => o.id === editingConfigId);
        if (!currentConfig || currentConfig.owner_type !== 'dealer') return false;
        const s = String(currentConfig.installation_status || '').trim().toLowerCase();
        return s === 'not willing';
    })();

    const resetForm = () => {
        setIsEditing(false);
        setEditingConfigId(null);
        setForm({
            owner_type: 'dealer',
            owner_id: '',
            minimum_device_count: '',
            minimum_sim_count: '',
            notes: '',
        });
    };

    const handleEditConfig = (owner) => {
        setIsEditing(true);
        setEditingConfigId(owner.id);
        setForm({
            owner_type: owner.owner_type,
            owner_id: String(owner.owner_id),
            minimum_device_count: String(owner.minimum_device_count),
            minimum_sim_count: String(owner.minimum_sim_count),
            notes: owner.notes || '',
        });
        setError('');
        setSuccess('');
    };

    const handleDeleteConfig = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.delete('/device_alert/delete.php', { data: { id: deleteTarget.id } });
            if (response.data.success) {
                setDeleteTarget(null);
                setSuccess(response.data.message || 'Alert configuration deleted successfully.');
                setError('');
                await fetchAlerts();
            } else {
                triggerError(response.data.message || 'Unable to delete alert configuration');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to delete alert configuration');
        }
    };

    const saveConfiguration = async () => {
        const deviceCountValue = String(form.minimum_device_count).trim();
        const simCountValue = String(form.minimum_sim_count).trim();

        if (!form.owner_type || !form.owner_id) {
            triggerError('Please select an owner.');
            setSuccess('');
            return;
        }

        if (!isWholeNumberString(deviceCountValue)) {
            triggerError('Minimum device count must be a valid number.');
            setSuccess('');
            return;
        }

        if (!isWholeNumberString(simCountValue)) {
            triggerError('Minimum SIM count must be a valid number.');
            setSuccess('');
            return;
        }

        const minimumDeviceCount = Number(deviceCountValue);
        const minimumSimCount = Number(simCountValue);

        if (minimumDeviceCount < 0 || minimumSimCount < 0) {
            triggerError('Minimum counts cannot be negative.');
            setSuccess('');
            return;
        }

        try {
            setSaving(true);
            setError('');
            setSuccess('');

            const payload = isEditing && editingConfigId
                ? {
                    id: Number(editingConfigId),
                    minimum_device_count: minimumDeviceCount,
                    minimum_sim_count: minimumSimCount,
                    notes: form.notes,
                }
                : {
                    owner_type: form.owner_type,
                    owner_id: Number(form.owner_id),
                    minimum_device_count: minimumDeviceCount,
                    minimum_sim_count: minimumSimCount,
                    notes: form.notes,
                };

            const response = await api.post('/device_alert/update.php', payload);

            if (response.data.success) {
                setSuccess(response.data.message || (isEditing ? 'Alert configuration updated successfully.' : 'Alert configuration saved successfully.'));
                resetForm();
                await fetchAlerts();
            } else {
                triggerError(response.data.message || (isEditing ? 'Unable to update alert configuration' : 'Unable to save alert configuration'));
            }
        } catch (err) {
            triggerError(err.response?.data?.message || (isEditing ? 'Unable to update alert configuration' : 'Unable to save alert configuration'));
        } finally {
            setSaving(false);
        }
    };

    const summary = useMemo(() => {
        const totals = { ALERT: 0, WARNING: 0, SAFE: 0 };
        owners.forEach((owner) => {
            if (totals[owner.status] !== undefined) {
                totals[owner.status] += 1;
            }
        });
        return totals;
    }, [owners]);

    const filteredOwners = filterTableRows(owners, filters, {
        searchKeys: ['owner_name', 'notes']
    });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredOwners, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Device Alert</h2>
            </div>

            <div className="card">
                <h3 style={{ marginTop: 0 }}>Configure Low Stock Alert</h3>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '1rem' }}>
                    <div className="form-group" style={{ margin: 0 }}>
                        <label className="form-label">Owner Type *</label>
                        <select
                            className="form-control"
                            value={form.owner_type}
                            onChange={(e) => setForm({ ...form, owner_type: e.target.value, owner_id: '' })}
                            disabled={isEditing}
                        >
                            <option value="dealer">Dealer</option>
                            <option value="technician">Technician</option>
                        </select>
                    </div>

                    <div className="form-group" style={{ margin: 0 }}>
                        <label className="form-label">Select Owner *</label>
                        <SearchableDropdown
                            options={filteredOwnerOptions}
                            value={form.owner_id}
                            onChange={(value) => setForm({ ...form, owner_id: value })}
                            placeholder="Select owner..."
                            disabled={isEditing}
                        />
                        {isDealerCurrentlyNotWilling && (
                            <small className="text-warning" style={{ display: 'block', marginTop: '4px' }}>
                                This dealer is currently marked as Not Willing. Updating will maintain historical settings.
                            </small>
                        )}
                    </div>

                    <div className="form-group" style={{ margin: 0 }}>
                        <label className="form-label">Minimum Device Count *</label>
                        <input
                            type="text"
                            className="form-control"
                            value={form.minimum_device_count}
                            onChange={(e) => setForm({ ...form, minimum_device_count: e.target.value })}
                            placeholder="e.g. 5"
                        />
                    </div>

                    <div className="form-group" style={{ margin: 0 }}>
                        <label className="form-label">Minimum SIM Count *</label>
                        <input
                            type="text"
                            className="form-control"
                            value={form.minimum_sim_count}
                            onChange={(e) => setForm({ ...form, minimum_sim_count: e.target.value })}
                            placeholder="e.g. 5"
                        />
                    </div>
                </div>

                <div className="form-group" style={{ marginTop: '1rem', marginBottom: '1rem' }}>
                    <label className="form-label">Notes</label>
                    <textarea
                        className="form-control"
                        rows="2"
                        value={form.notes}
                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                        placeholder="Optional notes regarding alert settings..."
                    />
                </div>

                <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
                    <button className="btn btn-primary" type="button" onClick={saveConfiguration} disabled={saving}>
                        {saving ? 'Saving...' : isEditing ? 'Update Configuration' : 'Save Configuration'}
                    </button>
                    {isEditing && (
                        <button className="btn btn-outline" type="button" onClick={resetForm}>
                            Cancel
                        </button>
                    )}
                    {error && <span className="text-danger">{error}</span>}
                    {success && <span className="text-success">{success}</span>}
                </div>
            </div>

            <div className="card">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '1rem', marginBottom: '1rem' }}>
                    <h3 style={{ margin: 0 }}>Configured Owners</h3>
                </div>
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={owners}
                    showOwnerType
                    showDeviceAlert
                    deviceAlertOptions={['ALERT', 'WARNING', 'SAFE']}
                    searchPlaceholder="Search configured owner or notes..."
                />

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '1rem', marginBottom: '1.25rem' }}>
                    <div className="badge badge-danger" style={{ padding: '0.85rem 1rem', textAlign: 'center' }}>
                        Alert: {summary.ALERT || 0}
                    </div>
                    <div className="badge badge-warning" style={{ padding: '0.85rem 1rem', textAlign: 'center' }}>
                        Warning: {summary.WARNING || 0}
                    </div>
                    <div className="badge badge-success" style={{ padding: '0.85rem 1rem', textAlign: 'center' }}>
                        Safe: {summary.SAFE || 0}
                    </div>
                </div>

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Type</th>
                                <th>Min Device</th>
                                <th>Available Device</th>
                                <th>Device Status</th>
                                <th>Min SIM</th>
                                <th>Available SIM</th>
                                <th>SIM Status</th>
                                <th style={{ textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="9" className="text-center">Loading configured owners...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="9" className="text-center empty-state">No records found for the selected filters.</td>
                                </tr>
                            ) : (
                                paginatedItems.map((owner) => (
                                    <tr key={`${owner.owner_type}-${owner.owner_id}`}>
                                        <td className="truncate-cell" title={owner.owner_name} style={{ fontWeight: 600 }}>{owner.owner_name}</td>
                                        <td>
                                            <span className={`badge ${owner.owner_type === 'dealer' ? 'badge-primary' : 'badge-info'}`}>
                                                {owner.owner_type === 'dealer' ? 'Dealer' : 'Technician'}
                                            </span>
                                        </td>
                                        <td>{owner.minimum_device_count}</td>
                                        <td>{owner.available_device_count}</td>
                                        <td>
                                            <span className={`badge ${getStatusClass(owner.device_status)}`}>{owner.device_status}</span>
                                        </td>
                                        <td>{owner.minimum_sim_count}</td>
                                        <td>{owner.available_sim_count}</td>
                                        <td>
                                            <span className={`badge ${getStatusClass(owner.sim_status)}`}>{owner.sim_status}</span>
                                        </td>
                                        <td>
                                            <div className="action-buttons" style={{ justifyContent: 'flex-end' }}>
                                                <button className="icon-btn view" type="button" aria-label="View alert configuration" title="View" onClick={() => setViewingOwner(owner)}><Eye size={16} /></button>
                                                {canEdit && (
                                                    <button className="icon-btn edit" type="button" aria-label="Edit alert configuration" onClick={() => handleEditConfig(owner)}>
                                                        <Edit size={16} />
                                                    </button>
                                                )}
                                                {canDelete && (
                                                    <button className="icon-btn delete" type="button" aria-label="Delete alert configuration" onClick={() => setDeleteTarget(owner)}>
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
                    itemName="configured owners"
                />
            </div>

            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete alert configuration"
                    maxWidth="420px"
                    footer={
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={handleDeleteConfig}>Delete</button>
                        </>
                    }
                >
                    <p>
                        Are you sure you want to remove the alert configuration for {deleteTarget.owner_name}?
                    </p>
                </Modal>
            )}
            {viewingOwner && <RecordViewModal isOpen onClose={() => setViewingOwner(null)} title="Alert Configuration Details" record={viewingOwner} fetchRecord={async (row) => (await api.get('/device_alert/list.php')).data.data.owners.find((owner) => String(owner.owner_type) === String(row.owner_type) && String(owner.owner_id) === String(row.owner_id)) || row} fields={[{ label: 'Owner', key: 'owner_name' }, { label: 'Owner Type', key: 'owner_type' }, { label: 'Minimum Device Count', key: 'minimum_device_count' }, { label: 'Available Device Count', key: 'available_device_count' }, { label: 'Minimum SIM Count', key: 'minimum_sim_count' }, { label: 'Available SIM Count', key: 'available_sim_count' }, { label: 'Device Status', key: 'device_status' }, { label: 'SIM Status', key: 'sim_status' }, { label: 'Notes', key: 'notes' }]} />}
        </div>
    );
};

export default DeviceAlertPage;
