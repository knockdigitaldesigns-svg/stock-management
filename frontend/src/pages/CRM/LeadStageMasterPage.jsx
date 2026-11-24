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
import './LeadStageMasterPage.css';

const initialForm = {
    stage_name: '',
    description: '',
    display_order: '0',
    status: 'Active'
};
const lifecycleStageNames = new Set([
    'new lead',
    'existing customer',
    'converted lead',
    'lost lead'
]);

const isLifecycleStage = (stage) =>
    lifecycleStageNames.has(String(stage?.stage_name || '').trim().toLowerCase());

const LeadStageMasterPage = () => {
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const [stages, setStages] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [form, setForm] = useState(initialForm);
    const [editingStage, setEditingStage] = useState(null);
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
    } = usePagination(stages, 10, [filters], true);

    const loadStages = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/crm_lead_stage/list.php', {
                params: { page, page_size: pageSize, search: filters.search, status: filters.status }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load lead stages.');
                return;
            }
            setStages(response.data.data?.stages || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load lead stages.');
        } finally {
            setLoading(false);
        }
    }, [filters.search, filters.status, page, pageSize, setTotalItems]);

    useEffect(() => {
        loadStages();
    }, [loadStages]);

    const openForm = (stage = null) => {
        setEditingStage(stage);
        setForm(stage ? {
            stage_name: stage.stage_name || '',
            description: stage.description || '',
            display_order: String(stage.display_order ?? 0),
            status: stage.status || 'Active'
        } : initialForm);
        setError('');
        setMessage('');
        setModalOpen(true);
    };

    const closeForm = () => {
        if (saving) return;
        setModalOpen(false);
        setEditingStage(null);
        setForm(initialForm);
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
    };

    const saveStage = async (event) => {
        event.preventDefault();
        const displayOrder = Number(form.display_order);
        if (!form.stage_name.trim()) {
            setError('Stage name is required.');
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
                stage_name: form.stage_name.trim(),
                description: form.description.trim(),
                display_order: displayOrder,
                status: form.status
            };
            const response = editingStage
                ? await api.post('/crm_lead_stage/update.php', { ...payload, id: editingStage.id })
                : await api.post('/crm_lead_stage/create.php', payload);

            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to save lead stage.');
                return;
            }

            setModalOpen(false);
            setEditingStage(null);
            setForm(initialForm);
            setMessage(response.data.message || 'Lead stage saved successfully.');
            await loadStages();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save lead stage.');
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (stage) => {
        const nextStatus = stage.status === 'Active' ? 'Inactive' : 'Active';
        if (nextStatus === 'Inactive' && isLifecycleStage(stage)) {
            setError('Required lifecycle stages cannot be deactivated.');
            return;
        }
        const confirmed = await confirm({
            title: `${nextStatus === 'Inactive' ? 'Deactivate' : 'Activate'} Lead Stage`,
            message: nextStatus === 'Inactive'
                ? `Deactivate "${stage.stage_name}"? Existing leads will keep their saved stage.`
                : `Activate "${stage.stage_name}" so automatic lifecycle transitions can use it?`,
            confirmLabel: nextStatus === 'Inactive' ? 'Deactivate' : 'Activate',
            confirmClassName: nextStatus === 'Inactive' ? 'btn-danger' : 'btn-primary'
        });
        if (!confirmed) return;

        try {
            const response = await api.post('/crm_lead_stage/toggle.php', {
                id: stage.id,
                status: nextStatus
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to update lead stage.');
                return;
            }
            setMessage(response.data.message || 'Lead stage updated successfully.');
            await loadStages();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update lead stage.');
        }
    };

    const canAdd = hasPermission('crm_lead_stage.add');
    const canEdit = hasPermission('crm_lead_stage.edit');
    const canActivate = hasPermission('crm_lead_stage.activate');

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Lead Stage Master</h2>
                    <p className="page-subtitle">Manage the stages used to track automatic lead lifecycle transitions.</p>
                </div>
                {canAdd && (
                    <div className="header-actions">
                        <button type="button" className="btn btn-primary" onClick={() => openForm()}>
                            <Plus size={16} /> Add Stage
                        </button>
                    </div>
                )}
            </div>

            {message && <div className="alert alert-success lead-stage-message">{message}</div>}
            {error && !modalOpen && <div className="alert alert-danger lead-stage-message" role="alert">{error}</div>}

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={stages}
                    showStatus
                    statusOptions={['Active', 'Inactive']}
                    searchPlaceholder="Search stages..."
                    className="lead-stage-filters"
                />

                <div className="table-container lead-stage-table-container">
                    <table className="lead-stage-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Stage Name</th>
                                <th>Description</th>
                                <th>Display Order</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="7" className="lead-stage-table-empty">Loading lead stages...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="lead-stage-table-empty">
                                        {error
                                            ? 'Lead stages could not be loaded.'
                                            : stages.length === 0
                                                ? 'No lead stages have been configured yet.'
                                                : 'No stages match the selected filters.'}
                                    </td>
                                </tr>
                            ) : paginatedItems.map((stage, index) => (
                                <tr key={stage.id}>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td className="lead-stage-name">{stage.stage_name}</td>
                                    <td>{stage.description || '—'}</td>
                                    <td>{Number(stage.display_order)}</td>
                                    <td>
                                        <span className={`badge ${stage.status === 'Active' ? 'badge-success' : 'badge-warning'}`}>
                                            {stage.status}
                                        </span>
                                    </td>
                                    <td>{formatDate(stage.created_at)}</td>
                                    <td>
                                        <div className="lead-stage-actions">
                                            {canEdit && (
                                                <button
                                                    type="button"
                                                    className="lead-stage-action-btn edit"
                                                    onClick={() => openForm(stage)}
                                                    aria-label={`Edit ${stage.stage_name}`}
                                                    title={isLifecycleStage(stage) ? 'Edit stage description or display order' : 'Edit stage'}
                                                >
                                                    <Pencil size={17} />
                                                </button>
                                            )}
                                            {canActivate && (
                                                <button
                                                    type="button"
                                                    className={`lead-stage-action-btn ${stage.status === 'Active' ? 'deactivate' : 'activate'}`}
                                                    onClick={() => changeStatus(stage)}
                                                    aria-label={`${stage.status === 'Active' ? 'Deactivate' : 'Activate'} ${stage.stage_name}`}
                                                    title={isLifecycleStage(stage) && stage.status === 'Active'
                                                        ? 'Required lifecycle stages cannot be deactivated'
                                                        : `${stage.status === 'Active' ? 'Deactivate' : 'Activate'} stage`}
                                                    disabled={stage.status === 'Active' && isLifecycleStage(stage)}
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
                    itemName="stages"
                />
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={closeForm}
                title={editingStage ? 'Edit Lead Stage' : 'Add Lead Stage'}
                maxWidth="620px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeForm} disabled={saving}>Cancel</button>
                        <button type="submit" form="lead-stage-form" className="btn btn-primary" disabled={saving}>
                            {saving ? 'Saving...' : editingStage ? 'Update Stage' : 'Save Stage'}
                        </button>
                    </>
                )}
            >
                {error && modalOpen && <div className="alert alert-danger lead-stage-modal-error" role="alert">{error}</div>}
                <form id="lead-stage-form" className="lead-stage-form" onSubmit={saveStage}>
                    <div className="lead-stage-form-group lead-stage-form-wide">
                        <label htmlFor="stage_name">Stage Name <span className="required">*</span></label>
                        <input
                            id="stage_name"
                            name="stage_name"
                            className="form-control"
                            maxLength={100}
                            value={form.stage_name}
                            onChange={handleChange}
                            required
                            readOnly={isLifecycleStage(editingStage)}
                        />
                    </div>
                    <div className="lead-stage-form-group lead-stage-form-wide">
                        <label htmlFor="stage-description">Description</label>
                        <textarea id="stage-description" name="description" className="form-control" rows="3" maxLength={65535} value={form.description} onChange={handleChange} />
                    </div>
                    <div className="lead-stage-form-group">
                        <label htmlFor="stage-display-order">Display Order</label>
                        <input id="stage-display-order" name="display_order" className="form-control" type="number" min="0" step="1" value={form.display_order} onChange={handleChange} />
                    </div>
                    <div className="lead-stage-form-group">
                        <label htmlFor="stage-status">Status</label>
                        <select
                            id="stage-status"
                            name="status"
                            className="form-control"
                            value={form.status}
                            onChange={handleChange}
                            disabled={!canActivate || isLifecycleStage(editingStage)}
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

export default LeadStageMasterPage;
