import { useCallback, useEffect, useState } from 'react';
import { Eye, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import { useAuth } from '../../context/AuthContext';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters } from '../../components/TableFilterBar/TableFilterBar';
import { formatDate } from '../../utils/date';
import './CampaignManagementPage.css';

const initialForm = {
    campaign_name: '',
    pipeline_id: '',
    manager_user_id: '',
    start_date: '',
    end_date: '',
    description: '',
    status: 'Active'
};

const CampaignManagementPage = () => {
    const navigate = useNavigate();
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const [campaigns, setCampaigns] = useState([]);
    const [pipelines, setPipelines] = useState([]);
    const [availableUsers, setAvailableUsers] = useState([]);
    const [stats, setStats] = useState(null);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [form, setForm] = useState(initialForm);
    const [editingCampaign, setEditingCampaign] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
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
    } = usePagination(campaigns, 10, [filters], true);

    const loadCampaigns = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/crm_campaigns/list.php', {
                params: {
                    page,
                    page_size: pageSize,
                    search: filters.search,
                    status: filters.status
                }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load campaigns.');
                return;
            }
            setCampaigns(response.data.data?.campaigns || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
            setStats(response.data.data?.stats || {
                total_campaigns: 0,
                active_campaigns: 0,
                total_leads: 0
            });
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load campaigns.');
        } finally {
            setLoading(false);
        }
    }, [filters.search, filters.status, page, pageSize, setTotalItems]);

    const loadPipelines = async () => {
        try {
            const response = await api.get('/crm_campaigns/pipelines.php');
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load pipeline options.');
                return;
            }
            setPipelines(response.data.data?.pipelines || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load pipeline options.');
        }
    };

    const loadManagers = async () => {
        try {
            const response = await api.get('/users/list.php?scope=campaign_users');
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load campaign users.');
                return;
            }
            setAvailableUsers(response.data.data?.users || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load campaign users.');
        }
    };

    useEffect(() => {
        loadCampaigns();
    }, [loadCampaigns]);

    useEffect(() => {
        loadPipelines();
        loadManagers();
    }, []);

    const openForm = (campaign = null) => {
        setEditingCampaign(campaign);
        setForm(campaign ? {
            campaign_name: campaign.campaign_name || '',
            pipeline_id: String(campaign.pipeline_id || ''),
            manager_user_id: campaign.manager_user_id ? String(campaign.manager_user_id) : '',
            start_date: campaign.start_date || '',
            end_date: campaign.end_date || '',
            description: campaign.description || '',
            status: campaign.status || 'Active'
        } : {
            ...initialForm,
            pipeline_id: String(pipelines.find((item) => item.status === 'Active')?.id || '')
        });
        setError('');
        setMessage('');
        setModalOpen(true);
    };

    const closeForm = () => {
        if (saving) return;
        setModalOpen(false);
        setEditingCampaign(null);
        setForm(initialForm);
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setForm((current) => ({ ...current, [name]: value }));
    };

    const saveCampaign = async (event) => {
        event.preventDefault();
        if (!form.campaign_name.trim() || !form.pipeline_id || !form.start_date) {
            setError('Campaign name, pipeline, and start date are required.');
            return;
        }
        if (form.end_date && form.end_date < form.start_date) {
            setError('End date cannot be earlier than start date.');
            return;
        }

        setSaving(true);
        setError('');
        try {
            const payload = {
                campaign_name: form.campaign_name.trim(),
                pipeline_id: Number(form.pipeline_id),
                manager_user_id: form.manager_user_id ? Number(form.manager_user_id) : null,
                start_date: form.start_date,
                end_date: form.end_date || '',
                description: form.description.trim(),
                status: form.status
            };
            const endpoint = editingCampaign ? '/crm_campaigns/update.php' : '/crm_campaigns/create.php';
            const response = await api.post(endpoint, { ...payload, id: editingCampaign?.id });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to save campaign.');
                return;
            }
            closeForm();
            setMessage(response.data.message || 'Campaign saved successfully.');
            await loadCampaigns();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save campaign.');
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (campaign) => {
        const nextStatus = campaign.status === 'Active' ? 'Inactive' : 'Active';
        const confirmed = await confirm({
            title: `${nextStatus === 'Inactive' ? 'Deactivate' : 'Activate'} Campaign`,
            message: `Mark "${campaign.campaign_name}" as ${nextStatus}? Existing campaign leads and attribution will be preserved.`,
            confirmLabel: nextStatus === 'Inactive' ? 'Deactivate' : 'Activate',
            confirmClassName: nextStatus === 'Inactive' ? 'btn-danger' : 'btn-primary'
        });
        if (!confirmed) return;
        try {
            const response = await api.post('/crm_campaigns/toggle.php', { id: campaign.id, status: nextStatus });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to update campaign status.');
                return;
            }
            setMessage(response.data.message || 'Campaign status updated.');
            await loadCampaigns();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update campaign status.');
        }
    };

    const deleteCampaign = async () => {
        if (!deleteTarget) return;
        try {
            const response = await api.post('/crm_campaigns/delete.php', { id: deleteTarget.id });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to delete campaign.');
                setDeleteTarget(null);
                return;
            }
            setDeleteTarget(null);
            setMessage(response.data.message || 'Campaign deleted.');
            await loadCampaigns();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to delete campaign.');
        }
    };

    const canAdd = hasPermission('crm_campaigns.add');
    const canEdit = hasPermission('crm_campaigns.edit');
    const canDelete = hasPermission('crm_campaigns.delete');
    const activePipelines = pipelines.filter((pipeline) => pipeline.status === 'Active');
    const formPipelines = editingCampaign
        ? pipelines.filter((pipeline) => pipeline.status === 'Active' || String(pipeline.id) === String(editingCampaign.pipeline_id))
        : activePipelines;

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Campaign Management</h2>
                    <p className="page-subtitle">Manage marketing campaigns and review their attributed leads.</p>
                </div>
                {canAdd && (
                    <div className="header-actions">
                        <button type="button" className="btn btn-primary" onClick={() => openForm()}>
                            <Plus size={16} /> Add Campaign
                        </button>
                    </div>
                )}
            </div>

            <div className="campaign-stats-grid">
                <div className="campaign-stat-card">
                    <span>Total Campaigns</span>
                    <strong>{stats ? Number(stats.total_campaigns || 0) : loading ? '…' : '—'}</strong>
                </div>
                <div className="campaign-stat-card">
                    <span>Active Campaigns</span>
                    <strong>{stats ? Number(stats.active_campaigns || 0) : loading ? '…' : '—'}</strong>
                </div>
                <div className="campaign-stat-card">
                    <span>Total Leads</span>
                    <strong>{stats ? Number(stats.total_leads || 0) : loading ? '…' : '—'}</strong>
                </div>
            </div>

            {message && <div className="alert alert-success campaign-message">{message}</div>}
            {error && !modalOpen && <div className="alert alert-danger campaign-message" role="alert">{error}</div>}

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={campaigns}
                    showStatus
                    statusOptions={['Active', 'Inactive']}
                    searchPlaceholder="Search campaigns..."
                    className="campaign-filters"
                />

                <div className="table-container campaign-table-container">
                    <table className="campaign-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Campaign Name</th>
                                <th>Pipeline</th>
                                <th>Managed By</th>
                                <th>Total Leads</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td className="campaign-table-empty" colSpan="9">Loading campaigns...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr><td className="campaign-table-empty" colSpan="9">
                                    {error ? 'Campaign records could not be loaded.' : 'No campaigns found for the selected filters.'}
                                </td></tr>
                            ) : paginatedItems.map((campaign, index) => (
                                <tr key={campaign.id}>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="campaign-name-link"
                                            onClick={() => navigate(`/crm/campaigns/${campaign.id}`)}
                                        >
                                            {campaign.campaign_name}
                                        </button>
                                    </td>
                                    <td>{campaign.pipeline_name}</td>
                                    <td>{campaign.managed_by || 'Unassigned'}</td>
                                    <td>{Number(campaign.total_leads || 0)}</td>
                                    <td>{formatDate(campaign.start_date)}</td>
                                    <td>{formatDate(campaign.end_date)}</td>
                                    <td><span className={`badge ${campaign.status === 'Active' ? 'badge-success' : 'badge-warning'}`}>{campaign.status}</span></td>
                                    <td>
                                        <div className="campaign-actions">
                                            <button
                                                type="button"
                                                className="campaign-action-btn view"
                                                onClick={() => navigate(`/crm/campaigns/${campaign.id}`)}
                                                aria-label={`View ${campaign.campaign_name} report`}
                                                title="View campaign report"
                                            >
                                                <Eye size={17} />
                                            </button>
                                            {canEdit && (
                                                <>
                                                    <button type="button" className="campaign-action-btn edit" onClick={() => openForm(campaign)} aria-label={`Edit ${campaign.campaign_name}`} title="Edit campaign">
                                                        <Pencil size={17} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={`campaign-action-btn ${campaign.status === 'Active' ? 'deactivate' : 'activate'}`}
                                                        onClick={() => changeStatus(campaign)}
                                                        aria-label={`${campaign.status === 'Active' ? 'Deactivate' : 'Activate'} ${campaign.campaign_name}`}
                                                        title={`${campaign.status === 'Active' ? 'Deactivate' : 'Activate'} campaign`}
                                                    >
                                                        <Power size={17} />
                                                    </button>
                                                </>
                                            )}
                                            {canDelete && (
                                                <button type="button" className="campaign-action-btn delete" onClick={() => setDeleteTarget(campaign)} aria-label={`Delete ${campaign.campaign_name}`} title="Delete campaign">
                                                    <Trash2 size={17} />
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
                />
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={closeForm}
                title={editingCampaign ? 'Edit Campaign' : 'Add Campaign'}
                maxWidth="760px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeForm} disabled={saving}>Cancel</button>
                        <button type="submit" form="campaign-form" className="btn btn-primary" disabled={saving || formPipelines.length === 0}>
                            {saving ? 'Saving...' : editingCampaign ? 'Update Campaign' : 'Save Campaign'}
                        </button>
                    </>
                )}
            >
                {error && modalOpen && <div className="alert alert-danger campaign-modal-error" role="alert">{error}</div>}
                <form id="campaign-form" className="campaign-form-grid" onSubmit={saveCampaign}>
                    <div className="campaign-form-group campaign-form-wide">
                        <label htmlFor="campaign_name">Campaign Name <span className="required">*</span></label>
                        <input id="campaign_name" name="campaign_name" className="form-control" maxLength={150} value={form.campaign_name} onChange={handleChange} required />
                    </div>
                    <div className="campaign-form-group">
                        <label htmlFor="pipeline_id">Pipeline <span className="required">*</span></label>
                        <select id="pipeline_id" name="pipeline_id" className="form-control" value={form.pipeline_id} onChange={handleChange} required>
                            <option value="">Select Pipeline</option>
                            {formPipelines.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.pipeline_name}{pipeline.status === 'Inactive' ? ' (Inactive)' : ''}</option>)}
                        </select>
                        {pipelines.length === 0 && <small className="campaign-field-note">No pipelines are available. Create and activate a pipeline first.</small>}
                    </div>
                    <div className="campaign-form-group">
                        <label htmlFor="manager_user_id">Managed By</label>
                        <select id="manager_user_id" name="manager_user_id" className="form-control" value={form.manager_user_id} onChange={handleChange}>
                            <option value="">Unassigned</option>
                            {availableUsers.map((user) => (
                                <option key={user.id} value={user.id}>{user.manager_name} ({user.role_name})</option>
                            ))}
                        </select>
                        {availableUsers.length === 0 && <small className="campaign-field-note">No active users are available. You can still save this campaign as unassigned.</small>}
                    </div>
                    <div className="campaign-form-group">
                        <label htmlFor="status">Status</label>
                        <select id="status" name="status" className="form-control" value={form.status} onChange={handleChange}>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div className="campaign-form-group">
                        <label htmlFor="start_date">Start Date <span className="required">*</span></label>
                        <input id="start_date" name="start_date" type="date" className="form-control" value={form.start_date} onChange={handleChange} required />
                    </div>
                    <div className="campaign-form-group">
                        <label htmlFor="end_date">End Date</label>
                        <input id="end_date" name="end_date" type="date" className="form-control" min={form.start_date || undefined} value={form.end_date} onChange={handleChange} />
                    </div>
                    <div className="campaign-form-group campaign-form-wide">
                        <label htmlFor="description">Description</label>
                        <textarea id="description" name="description" className="form-control" rows="4" value={form.description} onChange={handleChange} />
                    </div>
                </form>
            </Modal>

            <Modal
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                title="Delete Campaign"
                maxWidth="460px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                        <button type="button" className="btn btn-danger" onClick={deleteCampaign}>Delete Campaign</button>
                    </>
                )}
            >
                <p>Delete <strong>{deleteTarget?.campaign_name}</strong>? Campaigns that have leads cannot be deleted; deactivate them instead to preserve lead attribution.</p>
            </Modal>

            {confirmationDialog}
        </div>
    );
};

export default CampaignManagementPage;
