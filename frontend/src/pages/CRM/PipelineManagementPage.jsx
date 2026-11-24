import { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Power, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';
import { formatDate } from '../../utils/date';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters } from '../../components/TableFilterBar/TableFilterBar';
import './PipelineManagementPage.css';

const initialForm = {
    pipeline_name: '',
    description: '',
    status: 'Active',
    display_order: 0
};

const PipelineManagementPage = () => {
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const [pipelines, setPipelines] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [modal, setModal] = useState(null);
    const [showModal, setShowModal] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(pipelines, 10, [filters], true);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const fetchPipelines = useCallback(async () => {
        setLoading(true);
        setError('');

        try {
            const response = await api.get('/crm_pipelines/list.php', {
                params: { page, page_size: pageSize, search: filters.search, status: filters.status }
            });
            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to load pipeline records.');
                return;
            }

            setPipelines(response.data.data?.pipelines || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to load pipeline records.');
        } finally {
            setLoading(false);
        }
    }, [filters.search, filters.status, page, pageSize, setTotalItems]);

    useEffect(() => {
        fetchPipelines();
    }, [fetchPipelines]);

    const openModal = (pipeline = null) => {
        setModal(pipeline);
        setShowModal(true);
        setError('');
        setMessage('');
        setFormValues({
            pipeline_name: pipeline?.pipeline_name || '',
            description: pipeline?.description || '',
            status: pipeline?.status || 'Active',
            display_order: pipeline?.display_order ?? 0
        });
    };

    const closeModal = () => {
        if (saving) return;

        setShowModal(false);
        setModal(null);
        setFormValues(initialForm);
        setError('');
    };

    const [formValues, setFormValues] = useState(initialForm);

    const handleInputChange = (event) => {
        const { name, value } = event.target;
        setFormValues((current) => ({
            ...current,
            [name]: value
        }));
    };

    const savePipeline = async () => {
        const pipelineName = (formValues.pipeline_name || '').trim();
        if (!pipelineName) {
            triggerError('Pipeline name is required.');
            return;
        }

        if (!['Active', 'Inactive'].includes(formValues.status)) {
            triggerError('Please choose a valid pipeline status.');
            return;
        }

        setSaving(true);
        setError('');

        try {
            const payload = {
                id: modal?.id,
                pipeline_name: pipelineName,
                description: formValues.description,
                status: formValues.status,
                display_order: Number(formValues.display_order || 0)
            };

            const response = modal
                ? await api.post('/crm_pipelines/update.php', payload)
                : await api.post('/crm_pipelines/create.php', payload);

            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to save pipeline.');
                return;
            }

            setShowModal(false);
            setModal(null);
            setFormValues(initialForm);
            setMessage(response.data.message || 'Pipeline saved successfully.');
            await fetchPipelines();
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to save pipeline.');
        } finally {
            setSaving(false);
        }
    };

    const togglePipelineStatus = async (pipeline, nextStatus) => {
        const confirmed = await confirm({
            title: `${nextStatus === 'Inactive' ? 'Deactivate' : 'Activate'} Pipeline`,
            message: `Mark "${pipeline.pipeline_name}" as ${nextStatus}?`,
            confirmLabel: nextStatus === 'Inactive' ? 'Deactivate' : 'Activate',
            confirmClassName: nextStatus === 'Inactive' ? 'btn-danger' : 'btn-primary'
        });

        if (!confirmed) {
            return;
        }

        try {
            const response = await api.post('/crm_pipelines/toggle.php', {
                id: pipeline.id,
                status: nextStatus
            });

            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to update pipeline status.');
                return;
            }

            setMessage(response.data.message || 'Pipeline status updated successfully.');
            await fetchPipelines();
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to update pipeline status.');
        }
    };

    const removePipeline = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post('/crm_pipelines/delete.php', {
                id: deleteTarget.id
            });

            if (!response.data.success) {
                triggerError(response.data.message || 'Unable to delete pipeline.');
                return;
            }

            setDeleteTarget(null);
            setMessage(response.data.message || 'Pipeline deleted successfully.');
            await fetchPipelines();
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to delete pipeline.');
        }
    };

    const canAddPipeline = hasPermission('crm_pipelines.add');
    const canEditPipeline = hasPermission('crm_pipelines.edit');
    const canDeletePipeline = hasPermission('crm_pipelines.delete');

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Pipeline Management</h2>
                    <p className="page-subtitle">Manage CRM pipelines for lead routing and distribution.</p>
                </div>

                <div className="header-actions">
                    {canAddPipeline && (
                        <button type="button" className="btn btn-primary" onClick={() => openModal()}>
                            <Plus size={16} /> Add Pipeline
                        </button>
                    )}
                </div>
            </div>

            {message && <div className="alert alert-success">{message}</div>}
            {error && !showModal && <div className="alert alert-danger">{error}</div>}

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={pipelines}
                    showStatus
                    statusOptions={['Active', 'Inactive']}
                    searchPlaceholder="Search pipelines..."
                    className="pipeline-filters"
                />

                <div className="table-container pipeline-table-container">
                    <table className="pipeline-table">
                        <thead>
                            <tr>
                                <th>Pipeline</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Display Order</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="6" className="pipeline-table-empty">Loading pipelines...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="6" className="pipeline-table-empty">
                                        No pipelines found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((pipeline) => (
                                    <tr key={pipeline.id}>
                                        <td className="pipeline-table-name">{pipeline.pipeline_name}</td>
                                        <td className="pipeline-table-description">{pipeline.description || '—'}</td>
                                        <td>
                                            <span className={`badge ${pipeline.status === 'Active' ? 'badge-success' : 'badge-warning'}`}>
                                                {pipeline.status}
                                            </span>
                                        </td>
                                        <td>{pipeline.display_order ?? 0}</td>
                                        <td>{formatDate(pipeline.created_at)}</td>
                                        <td>
                                            <div className="pipeline-table-actions">
                                                {canEditPipeline && (
                                                    <button
                                                        type="button"
                                                        className="pipeline-action-btn edit"
                                                        onClick={() => openModal(pipeline)}
                                                        aria-label={`Edit ${pipeline.pipeline_name}`}
                                                        title="Edit pipeline"
                                                    >
                                                        <Pencil size={17} />
                                                    </button>
                                                )}

                                                {canEditPipeline && (
                                                    <button
                                                        type="button"
                                                        className={`pipeline-action-btn ${pipeline.status === 'Active' ? 'deactivate' : 'activate'}`}
                                                        onClick={() => togglePipelineStatus(pipeline, pipeline.status === 'Active' ? 'Inactive' : 'Active')}
                                                        aria-label={`${pipeline.status === 'Active' ? 'Deactivate' : 'Activate'} ${pipeline.pipeline_name}`}
                                                        title={pipeline.status === 'Active' ? 'Deactivate pipeline' : 'Activate pipeline'}
                                                    >
                                                        <Power size={17} />
                                                    </button>
                                                )}

                                                {canDeletePipeline && (
                                                    <button
                                                        type="button"
                                                        className="pipeline-action-btn delete"
                                                        onClick={() => setDeleteTarget(pipeline)}
                                                        aria-label={`Delete ${pipeline.pipeline_name}`}
                                                        title="Delete pipeline"
                                                    >
                                                        <Trash2 size={17} />
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
                    page={page}
                    pageSize={pageSize}
                    totalItems={totalItems}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                />
            </div>

            <Modal
                isOpen={showModal}
                onClose={closeModal}
                title={modal ? 'Edit Pipeline' : 'Add Pipeline'}
                maxWidth="640px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeModal} disabled={saving}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-primary" onClick={savePipeline} disabled={saving}>
                            {saving ? 'Saving...' : modal ? 'Update Pipeline' : 'Save Pipeline'}
                        </button>
                    </>
                )}
            >
                <div className="pipeline-form-grid">
                    <div className="pipeline-form-group pipeline-form-full-width">
                        <label htmlFor="pipeline_name">Pipeline Name</label>
                        <input
                            id="pipeline_name"
                            name="pipeline_name"
                            type="text"
                            maxLength={100}
                            value={formValues.pipeline_name}
                            onChange={handleInputChange}
                            placeholder="Enter pipeline name"
                        />
                    </div>

                    <div className="pipeline-form-group">
                        <label htmlFor="status">Status</label>
                        <select id="status" name="status" value={formValues.status} onChange={handleInputChange}>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <div className="pipeline-form-group">
                        <label htmlFor="display_order">Display Order</label>
                        <input
                            id="display_order"
                            name="display_order"
                            type="number"
                            min="0"
                            value={formValues.display_order}
                            onChange={handleInputChange}
                        />
                    </div>

                    <div className="pipeline-form-group pipeline-form-full-width">
                        <label htmlFor="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            rows="4"
                            value={formValues.description}
                            onChange={handleInputChange}
                            placeholder="Brief description of the pipeline"
                        />
                    </div>
                </div>
            </Modal>

            <Modal
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                title="Delete Pipeline"
                maxWidth="460px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger" onClick={removePipeline}>
                            Confirm Delete
                        </button>
                    </>
                )}
            >
                <p>
                    Are you sure you want to delete <strong>{deleteTarget?.pipeline_name}</strong>? This action cannot be undone.
                </p>
            </Modal>
            {confirmationDialog}
        </div>
    );
};

export default PipelineManagementPage;
