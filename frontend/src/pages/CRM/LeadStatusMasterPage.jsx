import { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Power } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import { useAuth } from '../../context/AuthContext';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters } from '../../components/TableFilterBar/TableFilterBar';
import { formatDate } from '../../utils/date';
import './LeadStatusMasterPage.css';

const initialForm = {
    status_name: '',
    status_type: 'Open',
    color: '#6c757d',
    display_order: '0',
    status: 'Active'
};

const statusTypes = ['Open', 'Active', 'Resolved', 'Lost'];

const LeadStatusMasterPage = () => {
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const [statuses, setStatuses] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [form, setForm] = useState(initialForm);
    const [editingStatus, setEditingStatus] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(statuses, 10, [filters], true);

    const loadStatuses = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/crm_lead_status/list.php', {
                params: { page, page_size: pageSize, search: filters.search, status: filters.status }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load lead statuses.');
                return;
            }
            setStatuses(response.data.data?.statuses || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load lead statuses.');
        } finally {
            setLoading(false);
        }
    }, [filters.search, filters.status, page, pageSize, setTotalItems]);

    useEffect(() => {
        loadStatuses();
    }, [loadStatuses]);

    const openForm = (status = null) => {
        setEditingStatus(status);
        setForm(status ? {
            status_name: status.status_name || '',
            status_type: status.status_type || 'Open',
            color: status.color || '#6c757d',
            display_order: String(status.display_order ?? 0),
            status: status.status || 'Active'
        } : initialForm);
        setError('');
        setMessage('');
        setModalOpen(true);
    };

    const closeForm = () => {
        if (saving) return;
        setModalOpen(false);
        setEditingStatus(null);
        setForm(initialForm);
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
    };

    const saveStatus = async (event) => {
        event.preventDefault();
        const displayOrder = Number(form.display_order);
        if (!form.status_name.trim() || !form.status_type || !form.color) {
            setError('Status name, type, and color are required.');
            return;
        }
        if (!Number.isInteger(displayOrder) || displayOrder < 0) {
            setError('Display order must be a whole number greater than or equal to zero.');
            return;
        }

        setSaving(true);
        setError('');
        try {
            const payload = {
                status_name: form.status_name.trim(),
                status_type: form.status_type,
                color: form.color,
                display_order: displayOrder,
                status: form.status
            };
            const response = editingStatus
                ? await api.post('/crm_lead_status/update.php', { ...payload, id: editingStatus.id })
                : await api.post('/crm_lead_status/create.php', payload);

            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to save lead status.');
                return;
            }

            closeForm();
            setMessage(response.data.message || 'Lead status saved successfully.');
            await loadStatuses();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save lead status.');
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (statusRow) => {
        const nextStatus = statusRow.status === 'Active' ? 'Inactive' : 'Active';
        const confirmed = await confirm({
            title: `${nextStatus === 'Inactive' ? 'Deactivate' : 'Activate'} Lead Status`,
            message: nextStatus === 'Inactive'
                ? `Deactivate "${statusRow.status_name}"? Existing leads and historical records will keep this status.`
                : `Activate "${statusRow.status_name}" so it can be selected for new records?`,
            confirmLabel: nextStatus === 'Inactive' ? 'Deactivate' : 'Activate',
            confirmClassName: nextStatus === 'Inactive' ? 'btn-danger' : 'btn-primary'
        });
        if (!confirmed) return;

        try {
            const response = await api.post('/crm_lead_status/toggle.php', {
                id: statusRow.id,
                status: nextStatus
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to update lead status.');
                return;
            }
            setMessage(response.data.message || 'Lead status updated successfully.');
            await loadStatuses();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update lead status.');
        }
    };

    const canAdd = hasPermission('crm_lead_status.add');
    const canEdit = hasPermission('crm_lead_status.edit');
    const canActivate = hasPermission('crm_lead_status.activate');

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Lead Status Master</h2>
                    <p className="page-subtitle">Configure the lead statuses available to CRM workflows.</p>
                </div>
                {canAdd && (
                    <div className="header-actions">
                        <button type="button" className="btn btn-primary" onClick={() => openForm()}>
                            <Plus size={16} /> Add Status
                        </button>
                    </div>
                )}
            </div>

            {message && <div className="alert alert-success lead-status-message">{message}</div>}
            {error && !modalOpen && <div className="alert alert-danger lead-status-message" role="alert">{error}</div>}

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={statuses}
                    showStatus
                    statusOptions={['Active', 'Inactive']}
                    searchPlaceholder="Search statuses..."
                    className="lead-status-filters"
                />

                <div className="table-container lead-status-table-container">
                    <table className="lead-status-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Status Name</th>
                                <th>Status Type</th>
                                <th>Color</th>
                                <th>Display Order</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="8" className="lead-status-table-empty">Loading lead statuses...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="8" className="lead-status-table-empty">
                                        {error
                                            ? 'Lead statuses could not be loaded.'
                                            : statuses.length === 0
                                                ? 'No lead statuses have been configured yet.'
                                                : 'No statuses match the selected filters.'}
                                    </td>
                                </tr>
                            ) : paginatedItems.map((statusRow, index) => (
                                <tr key={statusRow.id}>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td className="lead-status-name">{statusRow.status_name}</td>
                                    <td>{statusRow.status_type}</td>
                                    <td>
                                        <span className="lead-status-color">
                                            <span className="lead-status-color-swatch" style={{ backgroundColor: statusRow.color }} />
                                            {statusRow.color}
                                        </span>
                                    </td>
                                    <td>{Number(statusRow.display_order)}</td>
                                    <td>
                                        <span className={`badge ${statusRow.status === 'Active' ? 'badge-success' : 'badge-warning'}`}>
                                            {statusRow.status}
                                        </span>
                                    </td>
                                    <td>{formatDate(statusRow.created_at)}</td>
                                    <td>
                                        <div className="lead-status-actions">
                                            {canEdit && (
                                                <button
                                                    type="button"
                                                    className="lead-status-action-btn edit"
                                                    onClick={() => openForm(statusRow)}
                                                    aria-label={`Edit ${statusRow.status_name}`}
                                                    title="Edit status"
                                                >
                                                    <Pencil size={17} />
                                                </button>
                                            )}
                                            {canActivate && (
                                                <button
                                                    type="button"
                                                    className={`lead-status-action-btn ${statusRow.status === 'Active' ? 'deactivate' : 'activate'}`}
                                                    onClick={() => changeStatus(statusRow)}
                                                    aria-label={`${statusRow.status === 'Active' ? 'Deactivate' : 'Activate'} ${statusRow.status_name}`}
                                                    title={`${statusRow.status === 'Active' ? 'Deactivate' : 'Activate'} status`}
                                                >
                                                    <Power size={17} />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={totalItems}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="statuses"
                />
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={closeForm}
                title={editingStatus ? 'Edit Lead Status' : 'Add Lead Status'}
                maxWidth="620px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeForm} disabled={saving}>Cancel</button>
                        <button type="submit" form="lead-status-form" className="btn btn-primary" disabled={saving}>
                            {saving ? 'Saving...' : editingStatus ? 'Update Status' : 'Save Status'}
                        </button>
                    </>
                )}
            >
                {error && modalOpen && <div className="alert alert-danger lead-status-modal-error" role="alert">{error}</div>}
                <form id="lead-status-form" className="lead-status-form" onSubmit={saveStatus}>
                    <div className="lead-status-form-group">
                        <label htmlFor="status_name">Status Name <span className="required">*</span></label>
                        <input
                            id="status_name"
                            name="status_name"
                            className="form-control"
                            maxLength={100}
                            value={form.status_name}
                            onChange={handleChange}
                            required
                        />
                    </div>
                    <div className="lead-status-form-group">
                        <label htmlFor="status_type">Status Type <span className="required">*</span></label>
                        <select id="status_type" name="status_type" className="form-control" value={form.status_type} onChange={handleChange} required>
                            {statusTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </div>
                    <div className="lead-status-form-group">
                        <label htmlFor="color">Color <span className="required">*</span></label>
                        <div className="lead-status-color-input">
                            <input id="color" name="color" type="color" value={form.color} onChange={handleChange} required />
                            <span>{form.color}</span>
                        </div>
                    </div>
                    <div className="lead-status-form-group">
                        <label htmlFor="display_order">Display Order</label>
                        <input
                            id="display_order"
                            name="display_order"
                            type="number"
                            min="0"
                            step="1"
                            className="form-control"
                            value={form.display_order}
                            onChange={handleChange}
                        />
                    </div>
                    <div className="lead-status-form-group lead-status-form-wide">
                        <label htmlFor="status">Status</label>
                        <select
                            id="status"
                            name="status"
                            className="form-control"
                            value={form.status}
                            onChange={handleChange}
                            disabled={Boolean(editingStatus) && !canActivate}
                        >
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </form>
            </Modal>

            {confirmationDialog}
        </div>
    );
};

export default LeadStatusMasterPage;
