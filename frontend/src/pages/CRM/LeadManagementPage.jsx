import { useCallback, useEffect, useMemo, useState } from 'react';
import { Eye, Plus, RefreshCw, Search, Pencil, Clock3, FileCheck2, Trash2, ArrowRightLeft, History } from 'lucide-react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import FollowupModal from './FollowupModal';
import './LeadManagementPage.css';

const leadSources = ['Manual', 'Facebook', 'Google', 'Instagram', 'Website', 'Other'];
const initialFilters = {
    pipeline: '',
    campaign: '',
    source: '',
    stage: '',
    status: '',
    assignment: 'me',
    assignedUser: '',
    followup: 'all',
    followupFrom: '',
    followupTo: '',
    includeClosed: false
};
const followupFilterOptions = [
    ['all', 'All follow-ups'],
    ['none', 'No Follow-Up'],
    ['overdue', 'Overdue / Missed'],
    ['today', 'Due Today'],
    ['tomorrow', 'Tomorrow'],
    ['next_7_days', 'Next 7 Days'],
    ['next_30_days', 'Next 30 Days'],
    ['upcoming', 'Upcoming Follow-Ups'],
    ['any', 'Any Follow-Up'],
    ['custom', 'Custom Date Range']
];
const initialForm = {
    lead_name: '',
    mobile_no: '',
    alternate_mobile_no: '',
    email: '',
    location: '',
    pincode: '',
    pipeline_id: '',
    campaign_id: '',
    lead_source: 'Manual',
    customer_need: '',
    priority: '',
    remarks: ''
};
const duplicateMobileMessage = 'This mobile number already exists in the selected campaign. Please use a different number or select another campaign.';

const normalizeMobileNumber = (value) => {
    const raw = String(value || '').trim();
    if (!raw || !/^[0-9 +()-]+$/.test(raw)) return '';
    const normalized = raw.replace(/[ +()-]/g, '');
    return /^\d{10}$/.test(normalized) ? normalized : '';
};

const LeadManagementPage = ({ scope = 'all' }) => {
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const isMyLeads = scope === 'my';
    const [leads, setLeads] = useState([]);
    const [options, setOptions] = useState({ pipelines: [], campaigns: [], statuses: [], employees: [] });
    const [leadStages, setLeadStages] = useState([]);
    const [canViewAllLeads, setCanViewAllLeads] = useState(false);
    const [loading, setLoading] = useState(true);
    const [optionsLoading, setOptionsLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [deletingLeadId, setDeletingLeadId] = useState(null);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [modalError, setModalError] = useState('');
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState(initialFilters);
    const [appliedFilters, setAppliedFilters] = useState(initialFilters);
    const [appliedSearch, setAppliedSearch] = useState('');
    const [form, setForm] = useState(initialForm);
    const [duplicateCheck, setDuplicateCheck] = useState({ key: '', status: 'idle' });
    const [editingLead, setEditingLead] = useState(null);
    const [selectedLead, setSelectedLead] = useState(null);
    const [followupLead, setFollowupLead] = useState(null);
    const [selectedTransferLeads, setSelectedTransferLeads] = useState({});
    const [transferOpen, setTransferOpen] = useState(false);
    const [transferType, setTransferType] = useState('single');
    const [transferLeads, setTransferLeads] = useState([]);
    const [transferEmployees, setTransferEmployees] = useState([]);
    const [transferForm, setTransferForm] = useState({ employee_id: '', reason: '' });
    const [transferLoading, setTransferLoading] = useState(false);
    const [transferOptionsLoading, setTransferOptionsLoading] = useState(false);
    const [transferError, setTransferError] = useState('');
    const [historyLead, setHistoryLead] = useState(null);
    const [transferHistory, setTransferHistory] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyError, setHistoryError] = useState('');
    const [historyPage, setHistoryPage] = useState(1);
    const [historyPageSize, setHistoryPageSize] = useState(10);
    const [historyTotal, setHistoryTotal] = useState(0);
    const [formOpen, setFormOpen] = useState(false);
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(
        leads,
        10,
        [isMyLeads ? appliedSearch : search, isMyLeads ? appliedFilters : filters, scope],
        true
    );

    const loadOptions = async () => {
        setOptionsLoading(true);
        try {
            const response = await api.get('/crm_leads/options.php');
            const stageResponse = await api.get('/crm_lead_stage/options.php');
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load lead form options.');
                return;
            }
            if (!stageResponse.data?.success) {
                setError(stageResponse.data?.message || 'Unable to load active lead stages.');
                return;
            }
            setOptions({
                pipelines: response.data.data?.pipelines || [],
                campaigns: response.data.data?.campaigns || [],
                statuses: response.data.data?.statuses || [],
                employees: response.data.data?.employees || []
            });
            setCanViewAllLeads(Boolean(response.data.data?.can_view_all_leads));
            setLeadStages(stageResponse.data.data?.stages || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load lead form options and stages.');
        } finally {
            setOptionsLoading(false);
        }
    };

    const loadLeads = useCallback(async (resetMyFilters = false) => {
        setLoading(true);
        setError('');
        try {
            const params = { scope, page, page_size: pageSize };
            if (isMyLeads) {
                const filtersToApply = resetMyFilters ? initialFilters : appliedFilters;
                Object.assign(params, {
                    search: resetMyFilters ? '' : appliedSearch,
                    pipeline_id: filtersToApply.pipeline,
                    campaign_id: filtersToApply.campaign,
                    lead_source: filtersToApply.source,
                    lead_stage_id: filtersToApply.stage,
                    lead_status_id: filtersToApply.status,
                    include_closed: filtersToApply.includeClosed ? '1' : '0',
                    assignment_filter: filtersToApply.assignment,
                    assigned_user_id: filtersToApply.assignedUser,
                    followup_filter: filtersToApply.followup,
                    followup_from: filtersToApply.followupFrom,
                    followup_to: filtersToApply.followupTo
                });
            } else {
                Object.assign(params, {
                    search,
                    pipeline_id: filters.pipeline,
                    campaign_id: filters.campaign,
                    lead_source: filters.source,
                    lead_stage_id: filters.stage
                });
            }
            const response = await api.get('/crm_leads/list.php', { params });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load leads.');
                return;
            }
            setLeads(response.data.data?.leads || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load leads.');
        } finally {
            setLoading(false);
        }
    }, [scope, isMyLeads, appliedFilters, appliedSearch, search, filters, page, pageSize, setTotalItems]);

    useEffect(() => {
        loadLeads();
    }, [loadLeads]);

    useEffect(() => {
        loadOptions();
    }, [scope]);

    useEffect(() => {
        const refreshLeads = () => loadLeads();
        window.addEventListener('crm:lead-updated', refreshLeads);
        return () => window.removeEventListener('crm:lead-updated', refreshLeads);
    }, [loadLeads]);

    const availableCampaigns = options.campaigns.filter(
        (campaign) => String(campaign.pipeline_id) === String(form.pipeline_id)
    );
    const newLeadStage = leadStages.find(
        (stage) => String(stage.stage_name || '').trim().toLowerCase() === 'new lead'
    );
    const normalizedFormMobile = normalizeMobileNumber(form.mobile_no);
    const currentDuplicateCheckKey = form.campaign_id && normalizedFormMobile
        ? `${form.campaign_id}:${normalizedFormMobile}:${editingLead?.id || ''}`
        : '';
    const duplicateCheckIsCurrent = currentDuplicateCheckKey !== '' &&
        duplicateCheck.key === currentDuplicateCheckKey;
    const duplicateCheckStatus = duplicateCheckIsCurrent
        ? duplicateCheck.status
        : (formOpen && currentDuplicateCheckKey ? 'checking' : 'idle');

    useEffect(() => {
        if (!formOpen || !form.campaign_id || !normalizedFormMobile) {
            return undefined;
        }

        const key = `${form.campaign_id}:${normalizedFormMobile}:${editingLead?.id || ''}`;
        let cancelled = false;
        const timeout = window.setTimeout(async () => {
            try {
                const response = await api.get('/crm_leads/check_duplicate.php', {
                    params: {
                        campaign_id: form.campaign_id,
                        mobile_no: normalizedFormMobile,
                        exclude_lead_id: editingLead?.id || undefined
                    }
                });
                if (!cancelled) {
                    setDuplicateCheck({
                        key,
                        status: response.data?.data?.duplicate ? 'duplicate' : 'clear'
                    });
                }
            } catch {
                if (!cancelled) {
                    setDuplicateCheck({ key, status: 'error' });
                }
            }
        }, 300);

        return () => {
            cancelled = true;
            window.clearTimeout(timeout);
        };
    }, [formOpen, form.campaign_id, normalizedFormMobile, editingLead?.id]);

    const canAdd = hasPermission('crm_leads.add');
    const canEdit = hasPermission('crm_leads.edit');
    const canDelete = hasPermission('crm_leads.delete');
    const canAddFollowup = isMyLeads && hasPermission('crm_followups.add');
    const canConvertLeads = hasPermission('crm_closures.add');
    const canTransfer = hasPermission('crm_leads.transfer');
    const canBulkTransfer = hasPermission('crm_leads.transfer_bulk');
    const canViewTransferHistory = hasPermission('crm_leads.transfer_history');
    const availableTransferEmployees = transferEmployees.filter((employee) =>
        !transferLeads.some((lead) => String(lead.assigned_user_id) === String(employee.id))
    );

    const resetMessages = () => {
        setError('');
        setMessage('');
        setModalError('');
    };

    const openForm = (lead = null) => {
        resetMessages();
        setDuplicateCheck({ key: '', status: 'idle' });
        setEditingLead(lead);
        setForm(lead ? {
            lead_name: lead.lead_name || '',
            mobile_no: lead.mobile_no || '',
            alternate_mobile_no: lead.alternate_mobile_no || '',
            email: lead.email || '',
            location: lead.location || '',
            pincode: lead.pincode || '',
            pipeline_id: String(lead.pipeline_id || ''),
            campaign_id: lead.campaign_id ? String(lead.campaign_id) : '',
            lead_source: lead.lead_source || 'Manual',
            customer_need: lead.customer_need || '',
            priority: lead.priority || '',
            remarks: lead.remarks || ''
        } : {
            ...initialForm
        });
        setFormOpen(true);
    };

    const closeForm = () => {
        if (saving) return;
        setFormOpen(false);
        setDuplicateCheck({ key: '', status: 'idle' });
        setEditingLead(null);
        setForm(initialForm);
        setModalError('');
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setModalError('');
        setForm((current) => ({
            ...current,
            [name]: value,
            ...(name === 'pipeline_id' ? { campaign_id: '' } : {})
        }));
    };

    const saveLead = async (event) => {
        event.preventDefault();
        const normalizedMobile = normalizeMobileNumber(form.mobile_no);
        if (!form.lead_name.trim() || !normalizedMobile || !form.location.trim() ||
            !form.pipeline_id || !form.lead_source ||
            (!editingLead && !newLeadStage)) {
            if (!editingLead && !newLeadStage) {
                setModalError('An active "New Lead" stage is required before creating leads.');
                return;
            }
            setModalError('Enter a valid 10-digit mobile number and complete all required lead fields.');
            return;
        }
        setSaving(true);
        setModalError('');
        try {
            if (form.campaign_id) {
                let duplicateResponse;
                try {
                    duplicateResponse = await api.get('/crm_leads/check_duplicate.php', {
                        params: {
                            campaign_id: form.campaign_id,
                            mobile_no: normalizedMobile,
                            exclude_lead_id: editingLead?.id || undefined
                        }
                    });
                } catch (err) {
                    setModalError(err.response?.data?.message || 'Unable to validate the mobile number for this campaign. Please try again.');
                    return;
                }
                if (!duplicateResponse.data?.success) {
                    setModalError(duplicateResponse.data?.message || 'Unable to validate the mobile number for this campaign.');
                    return;
                }
                if (duplicateResponse.data?.data?.duplicate) {
                    const key = `${form.campaign_id}:${normalizedMobile}:${editingLead?.id || ''}`;
                    setDuplicateCheck({ key, status: 'duplicate' });
                    setModalError(duplicateMobileMessage);
                    return;
                }
            }
            const payload = {
                ...form,
                lead_name: form.lead_name.trim(),
                mobile_no: normalizedMobile,
                alternate_mobile_no: form.alternate_mobile_no.trim(),
                email: form.email.trim(),
                location: form.location.trim(),
                pincode: form.pincode.trim(),
                pipeline_id: Number(form.pipeline_id),
                campaign_id: form.campaign_id ? Number(form.campaign_id) : null,
                lead_source: form.lead_source,
                customer_need: form.customer_need.trim(),
                priority: form.priority.trim(),
                remarks: form.remarks.trim()
            };
            const response = editingLead
                ? await api.post('/crm_leads/update.php', { ...payload, id: editingLead.id })
                : await api.post('/crm_leads/create.php', payload);
            if (!response.data?.success) {
                setModalError(response.data?.message || 'Unable to save lead.');
                return;
            }
            setFormOpen(false);
            setEditingLead(null);
            setForm(initialForm);
            setMessage(response.data.message || 'Lead saved successfully.');
            if (isMyLeads && !editingLead) {
                setSearch('');
                setFilters(initialFilters);
                setAppliedSearch('');
                setAppliedFilters(initialFilters);
                await loadLeads(true);
            } else {
                await loadLeads();
            }
        } catch (err) {
            setModalError(err.response?.data?.message || 'Unable to save lead.');
        } finally {
            setSaving(false);
        }
    };

    const deleteLead = async (lead) => {
        const confirmed = await confirm({
            title: 'Archive Lead',
            message: `Archive "${lead.lead_name}"? It will be removed from active lead views, while assignment and follow-up history are preserved.`,
            confirmLabel: 'Archive Lead',
            confirmClassName: 'btn-danger'
        });
        if (!confirmed) return;

        setDeletingLeadId(Number(lead.id));
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_leads/delete.php', { id: lead.id });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to archive lead.');
                return;
            }
            setMessage(response.data.message || 'Lead archived successfully.');
            await loadLeads();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to archive lead.');
        } finally {
            setDeletingLeadId(null);
        }
    };

    const openTransfer = async (leads, type) => {
        setTransferLeads(leads);
        setTransferType(type);
        setTransferForm({ employee_id: '', reason: '' });
        setTransferError('');
        setTransferEmployees([]);
        setTransferOpen(true);
        setTransferOptionsLoading(true);
        try {
            const response = await api.get('/crm_leads/transfer_options.php');
            if (!response.data?.success) {
                setTransferError(response.data?.message || 'Unable to load eligible Employees.');
                return;
            }
            setTransferEmployees(response.data.data?.employees || []);
        } catch (err) {
            setTransferError(err.response?.data?.message || 'Unable to load eligible Employees.');
        } finally {
            setTransferOptionsLoading(false);
        }
    };

    const closeTransfer = () => {
        if (transferLoading) return;
        setTransferOpen(false);
        setTransferLeads([]);
        setTransferForm({ employee_id: '', reason: '' });
        setTransferError('');
    };

    const submitTransfer = async (event) => {
        event.preventDefault();
        if (!transferForm.employee_id || !transferForm.reason.trim()) {
            setTransferError('Select an Employee and enter a transfer reason.');
            return;
        }
        setTransferLoading(true);
        try {
            const confirmed = await confirm({
                title: transferType === 'single' ? 'Confirm Lead Transfer' : 'Confirm Bulk Lead Transfer',
                message: transferType === 'single'
                    ? `Transfer "${transferLeads[0]?.lead_name}" to the selected Employee?`
                    : `Transfer all ${transferLeads.length} selected leads to the selected Employee?`,
                confirmLabel: transferType === 'single' ? 'Transfer Lead' : 'Transfer Leads',
                confirmClassName: 'btn-primary'
            });
            if (!confirmed) return;
            setTransferError('');
            const isBulk = transferType === 'bulk';
            const payload = {
                employee_id: Number(transferForm.employee_id),
                reason: transferForm.reason.trim()
            };
            const response = isBulk
                ? await api.post('/crm_leads/transfer_bulk.php', {
                    ...payload,
                    lead_ids: transferLeads.map((lead) => Number(lead.id))
                })
                : await api.post('/crm_leads/transfer.php', {
                    ...payload,
                    lead_id: Number(transferLeads[0]?.id)
                });
            if (!response.data?.success) {
                const failures = response.data?.data?.failures || [];
                const failureDetails = failures.map((failure) =>
                    `${failure.lead_name || `Lead #${failure.lead_id}`}: ${failure.reason}`
                ).join('\n');
                setTransferError([
                    response.data?.message || 'Unable to transfer the selected leads.',
                    failureDetails
                ].filter(Boolean).join('\n'));
                return;
            }
            const transferredCount = Number(response.data.data?.transferred || 0);
            setTransferOpen(false);
            setSelectedTransferLeads({});
            setMessage(transferredCount === 1
                ? 'Lead transferred successfully.'
                : `${transferredCount} leads transferred successfully.`);
            await loadLeads();
        } catch (err) {
            const failures = err.response?.data?.data?.failures || [];
            const failureDetails = failures.map((failure) =>
                `${failure.lead_name || `Lead #${failure.lead_id}`}: ${failure.reason}`
            ).join('\n');
            setTransferError([
                err.response?.data?.message || 'Unable to transfer the selected leads.',
                failureDetails
            ].filter(Boolean).join('\n'));
        } finally {
            setTransferLoading(false);
        }
    };

    const loadTransferHistory = async (lead, page = 1, pageSize = historyPageSize) => {
        setHistoryError('');
        setHistoryLoading(true);
        try {
            const response = await api.get('/crm_leads/transfer_history.php', {
                params: { lead_id: Number(lead.id), page, page_size: pageSize }
            });
            if (!response.data?.success) {
                setHistoryError(response.data?.message || 'Unable to load transfer history.');
                return;
            }
            setTransferHistory(response.data.data?.history || []);
            setHistoryPage(Number(response.data.data?.pagination?.page || page));
            setHistoryPageSize(Number(response.data.data?.pagination?.page_size || pageSize));
            setHistoryTotal(Number(response.data.data?.total || 0));
        } catch (err) {
            setHistoryError(err.response?.data?.message || 'Unable to load transfer history.');
        } finally {
            setHistoryLoading(false);
        }
    };

    const openTransferHistory = async (lead) => {
        setHistoryLead(lead);
        setTransferHistory([]);
        setHistoryTotal(0);
        setHistoryPage(1);
        await loadTransferHistory(lead, 1, historyPageSize);
    };

    const toggleLeadSelection = (lead, selected) => {
        setSelectedTransferLeads((current) => {
            const next = { ...current };
            if (selected) {
                next[String(lead.id)] = lead;
            } else {
                delete next[String(lead.id)];
            }
            return next;
        });
    };

    const toggleCurrentPageSelection = (selected) => {
        setSelectedTransferLeads((current) => {
            const next = { ...current };
            paginatedItems.forEach((lead) => {
                if (selected) {
                    next[String(lead.id)] = lead;
                } else {
                    delete next[String(lead.id)];
                }
            });
            return next;
        });
    };

    const resetFilters = () => {
        setSearch('');
        const clearedFilters = { ...initialFilters };
        setFilters(clearedFilters);
        if (isMyLeads) {
            setAppliedSearch('');
            setAppliedFilters(clearedFilters);
        }
    };

    const applyMyLeadFilters = (event) => {
        event.preventDefault();
        if (filters.followup === 'custom') {
            if (!filters.followupFrom || !filters.followupTo) {
                setError('Select both From and To dates for a custom follow-up range.');
                return;
            }
            if (filters.followupFrom > filters.followupTo) {
                setError('Follow-up From Date must be on or before To Date.');
                return;
            }
        }
        setError('');
        setLoading(true);
        setAppliedSearch(search);
        setAppliedFilters({ ...filters });
    };

    const activePipelineIds = new Set(options.pipelines.map((item) => String(item.id)));
    const activeCampaignIds = new Set(options.campaigns.map((item) => String(item.id)));
    const pipelineChoices = [...options.pipelines];
    const campaignChoices = availableCampaigns.slice();

    if (editingLead && !activePipelineIds.has(String(editingLead.pipeline_id))) {
        pipelineChoices.push({
            id: editingLead.pipeline_id,
            pipeline_name: `${editingLead.pipeline_name} (Inactive)`,
            status: editingLead.pipeline_state
        });
    }
    const filterStageOptions = useMemo(() => {
        const byId = new Map();
        leadStages.forEach((stage) => byId.set(String(stage.id), stage));
        leads.forEach((lead) => {
            if (lead.lead_stage_id && !byId.has(String(lead.lead_stage_id))) {
                byId.set(String(lead.lead_stage_id), {
                    id: lead.lead_stage_id,
                    stage_name: lead.lead_stage_name || 'Inactive stage'
                });
            }
        });
        return [...byId.values()];
    }, [leadStages, leads]);
    if (editingLead && String(form.pipeline_id) === String(editingLead.pipeline_id) &&
        editingLead.campaign_id && !activeCampaignIds.has(String(editingLead.campaign_id))) {
        campaignChoices.push({
            id: editingLead.campaign_id,
            pipeline_id: editingLead.pipeline_id,
            campaign_name: `${editingLead.campaign_name || 'Campaign'} (Inactive)`,
            status: editingLead.campaign_state
        });
    }
    const filterCampaignOptions = useMemo(() => {
        const byId = new Map();
        options.campaigns.forEach((campaign) => byId.set(String(campaign.id), campaign));
        leads.forEach((lead) => {
            if (lead.campaign_id && !byId.has(String(lead.campaign_id))) {
                byId.set(String(lead.campaign_id), {
                    id: lead.campaign_id,
                    campaign_name: lead.campaign_name || 'Inactive campaign'
                });
            }
        });
        return [...byId.values()];
    }, [options.campaigns, leads]);

    const filterPipelineOptions = useMemo(() => {
        const byId = new Map();
        options.pipelines.forEach((pipeline) => byId.set(String(pipeline.id), pipeline));
        leads.forEach((lead) => {
            if (lead.pipeline_id && !byId.has(String(lead.pipeline_id))) {
                byId.set(String(lead.pipeline_id), {
                    id: lead.pipeline_id,
                    pipeline_name: lead.pipeline_name || 'Inactive pipeline'
                });
            }
        });
        return [...byId.values()];
    }, [options.pipelines, leads]);

    const filterCampaignChoices = filterCampaignOptions.filter((campaign) =>
        !filters.pipeline || String(campaign.pipeline_id) === String(filters.pipeline)
    );
    const hasAppliedMyFilters = Boolean(
        appliedSearch ||
        appliedFilters.pipeline ||
        appliedFilters.campaign ||
        appliedFilters.source ||
        appliedFilters.stage ||
        appliedFilters.status ||
        appliedFilters.includeClosed ||
        (appliedFilters.assignment && appliedFilters.assignment !== 'me') ||
        appliedFilters.assignedUser ||
        (appliedFilters.followup && appliedFilters.followup !== 'all') ||
        appliedFilters.followupFrom ||
        appliedFilters.followupTo
    );

    const title = isMyLeads ? 'My Leads' : 'All Leads';

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>{title}</h2>
                    <p className="page-subtitle">
                        {isMyLeads
                        ? 'Leads available within your authorized assignment scope.'
                            : 'Create, review, and manage CRM leads.'}
                    </p>
                </div>
                <div className="lead-page-header-actions">
                    {canBulkTransfer && Object.keys(selectedTransferLeads).length > 0 && (
                        <button
                            type="button"
                            className="btn btn-outline"
                            onClick={() => openTransfer(Object.values(selectedTransferLeads), 'bulk')}
                            disabled={transferLoading}
                        >
                            <ArrowRightLeft size={16} /> Bulk Transfer ({Object.keys(selectedTransferLeads).length})
                        </button>
                    )}
                    <button type="button" className="btn btn-outline" onClick={() => loadLeads()} disabled={loading}>
                        <RefreshCw size={16} /> Refresh
                    </button>
                    {canAdd && (
                        <button type="button" className="btn btn-primary" onClick={() => openForm()}>
                            <Plus size={16} /> Add Lead
                        </button>
                    )}
                </div>
            </div>

            {message && <div className="alert alert-success lead-message">{message}</div>}
            {error && <div className="alert alert-danger lead-message" role="alert">{error}</div>}

            <div className="card lead-list-card">
                {isMyLeads ? (
                    <form className="lead-filters lead-my-filter-form" onSubmit={applyMyLeadFilters}>
                        <div className="lead-filter-grid">
                            <label className="lead-filter-field lead-filter-search">
                                <span>Search Leads</span>
                                <div className="lead-search">
                                    <Search size={17} aria-hidden="true" />
                                    <input
                                        type="search"
                                        className="form-control"
                                        placeholder="Lead name, mobile number, or email..."
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        aria-label="Search by lead name, mobile number, or email"
                                    />
                                </div>
                            </label>
                            <label className="lead-filter-field">
                                <span>Pipeline</span>
                                <select
                                    className="form-control"
                                    aria-label="Filter by pipeline"
                                    value={filters.pipeline}
                                    onChange={(event) => setFilters((current) => ({
                                        ...current,
                                        pipeline: event.target.value,
                                        campaign: ''
                                    }))}
                                >
                                    <option value="">All Pipelines</option>
                                    {filterPipelineOptions.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.pipeline_name}</option>)}
                                </select>
                            </label>
                            <label className="lead-filter-field">
                                <span>Campaign</span>
                                <select
                                    className="form-control"
                                    aria-label="Filter by campaign"
                                    value={filters.campaign}
                                    onChange={(event) => setFilters((current) => ({ ...current, campaign: event.target.value }))}
                                    disabled={optionsLoading || filterCampaignChoices.length === 0}
                                >
                                    <option value="">All Campaigns</option>
                                    {filterCampaignChoices.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.campaign_name}</option>)}
                                </select>
                            </label>
                            <label className="lead-filter-field">
                                <span>Lead Source</span>
                                <select className="form-control" aria-label="Filter by lead source" value={filters.source} onChange={(event) => setFilters((current) => ({ ...current, source: event.target.value }))}>
                                    <option value="">All Lead Sources</option>
                                    {leadSources.map((source) => <option key={source} value={source}>{source}</option>)}
                                </select>
                            </label>
                            <label className="lead-filter-field">
                                <span>Lead Stage</span>
                                <select className="form-control" aria-label="Filter by lead stage" value={filters.stage} onChange={(event) => setFilters((current) => ({ ...current, stage: event.target.value }))} disabled={optionsLoading}>
                                    <option value="">All Lead Stages</option>
                                    {leadStages.map((stage) => <option key={stage.id} value={stage.id}>{stage.stage_name}</option>)}
                                </select>
                            </label>
                            <label className="lead-filter-field">
                                <span>Lead Status</span>
                                <select className="form-control" aria-label="Filter by lead status" value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))} disabled={optionsLoading}>
                                    <option value="">All Lead Statuses</option>
                                    {options.statuses.map((status) => <option key={status.id} value={status.id}>{status.status_name}</option>)}
                                </select>
                            </label>
                            <label className="lead-filter-checkbox">
                                <input
                                    type="checkbox"
                                    checked={filters.includeClosed}
                                    onChange={(event) => setFilters((current) => ({ ...current, includeClosed: event.target.checked }))}
                                />
                                <span>Include Converted / Lost leads</span>
                            </label>
                            <label className="lead-filter-field">
                                <span>Assignment</span>
                                <select
                                    className="form-control"
                                    aria-label="Filter by lead assignment"
                                    value={filters.assignment}
                                    onChange={(event) => setFilters((current) => ({
                                        ...current,
                                        assignment: event.target.value,
                                        assignedUser: ''
                                    }))}
                                >
                                    <option value="me">Assigned to me</option>
                                    {canViewAllLeads && (
                                        <>
                                            <option value="unassigned">Unassigned</option>
                                            <option value="other">Assigned to another employee</option>
                                            <option value="all">All assignments</option>
                                            <option value="user">Selected employee</option>
                                        </>
                                    )}
                                </select>
                            </label>
                            {canViewAllLeads && filters.assignment === 'user' && (
                                <label className="lead-filter-field">
                                    <span>Assigned Employee</span>
                                    <select className="form-control" aria-label="Filter by assigned employee" value={filters.assignedUser} onChange={(event) => setFilters((current) => ({ ...current, assignedUser: event.target.value }))} disabled={optionsLoading}>
                                        <option value="">Select employee</option>
                                        {options.employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.employee_name} ({employee.role_name})</option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="lead-filter-field">
                                <span>Follow-up</span>
                                <select className="form-control" aria-label="Filter by pending follow-up schedule" value={filters.followup} onChange={(event) => setFilters((current) => ({ ...current, followup: event.target.value, followupFrom: '', followupTo: '' }))}>
                                    {followupFilterOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                            </label>
                            {filters.followup === 'custom' && (
                                <>
                                    <label className="lead-filter-field">
                                        <span>Follow-up From</span>
                                        <input type="date" className="form-control" value={filters.followupFrom} onChange={(event) => setFilters((current) => ({ ...current, followupFrom: event.target.value }))} />
                                    </label>
                                    <label className="lead-filter-field">
                                        <span>Follow-up To</span>
                                        <input type="date" className="form-control" value={filters.followupTo} onChange={(event) => setFilters((current) => ({ ...current, followupTo: event.target.value }))} />
                                    </label>
                                </>
                            )}
                        </div>
                        <div className="lead-filter-actions">
                            <span>{totalItems} {totalItems === 1 ? 'lead' : 'leads'}{hasAppliedMyFilters ? ' matching filters' : ''}</span>
                            <div>
                                <button type="button" className="btn btn-outline" onClick={resetFilters} disabled={loading}>Reset</button>
                                <button type="submit" className="btn btn-primary" disabled={loading}>Search</button>
                            </div>
                        </div>
                    </form>
                ) : (
                    <div className="lead-filters">
                        <label className="lead-search">
                            <Search size={17} aria-hidden="true" />
                            <input
                                type="search"
                                className="form-control"
                                placeholder="Search by lead name or mobile..."
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                aria-label="Search by lead name or mobile number"
                            />
                        </label>
                        <select className="form-control" aria-label="Filter by pipeline" value={filters.pipeline} onChange={(event) => setFilters((current) => ({ ...current, pipeline: event.target.value }))}>
                            <option value="">All Pipelines</option>
                            {filterPipelineOptions.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.pipeline_name}</option>)}
                        </select>
                        <select className="form-control" aria-label="Filter by campaign" value={filters.campaign} onChange={(event) => setFilters((current) => ({ ...current, campaign: event.target.value }))}>
                            <option value="">All Campaigns</option>
                            {filterCampaignOptions.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.campaign_name}</option>)}
                        </select>
                        <select className="form-control" aria-label="Filter by lead source" value={filters.source} onChange={(event) => setFilters((current) => ({ ...current, source: event.target.value }))}>
                            <option value="">All Lead Sources</option>
                            {leadSources.map((source) => <option key={source} value={source}>{source}</option>)}
                        </select>
                        <select className="form-control" aria-label="Filter by lead stage" value={filters.stage} onChange={(event) => setFilters((current) => ({ ...current, stage: event.target.value }))}>
                            <option value="">All Lead Stages</option>
                            {filterStageOptions.map((stage) => <option key={stage.id} value={stage.id}>{stage.stage_name}</option>)}
                        </select>
                        <button type="button" className="btn btn-outline lead-reset-filters" onClick={resetFilters}>Reset</button>
                    </div>
                )}
                {isMyLeads && hasAppliedMyFilters && (
                    <div className="lead-filter-chips" aria-label="Applied filters">
                        {appliedSearch && <span className="lead-filter-chip">Search: {appliedSearch}</span>}
                        {appliedFilters.pipeline && <span className="lead-filter-chip">Pipeline: {filterPipelineOptions.find((item) => String(item.id) === appliedFilters.pipeline)?.pipeline_name || appliedFilters.pipeline}</span>}
                        {appliedFilters.campaign && <span className="lead-filter-chip">Campaign: {filterCampaignOptions.find((item) => String(item.id) === appliedFilters.campaign)?.campaign_name || appliedFilters.campaign}</span>}
                        {appliedFilters.source && <span className="lead-filter-chip">Source: {appliedFilters.source}</span>}
                        {appliedFilters.stage && <span className="lead-filter-chip">Stage: {filterStageOptions.find((item) => String(item.id) === appliedFilters.stage)?.stage_name || appliedFilters.stage}</span>}
                        {appliedFilters.status && <span className="lead-filter-chip">Status: {options.statuses.find((item) => String(item.id) === appliedFilters.status)?.status_name || appliedFilters.status}</span>}
                        {appliedFilters.includeClosed && <span className="lead-filter-chip">Includes Converted / Lost leads</span>}
                        {appliedFilters.assignment !== 'me' && <span className="lead-filter-chip">Assignment: {appliedFilters.assignment === 'user'
                            ? options.employees.find((item) => String(item.id) === appliedFilters.assignedUser)?.employee_name || 'Selected employee'
                            : appliedFilters.assignment === 'unassigned' ? 'Unassigned'
                                : appliedFilters.assignment === 'other' ? 'Assigned to another employee' : 'All assignments'}</span>}
                        {appliedFilters.followup !== 'all' && <span className="lead-filter-chip">Follow-up: {followupFilterOptions.find(([value]) => value === appliedFilters.followup)?.[1]}</span>}
                        {appliedFilters.followup === 'custom' && <span className="lead-filter-chip">{formatDate(appliedFilters.followupFrom)} – {formatDate(appliedFilters.followupTo)}</span>}
                    </div>
                )}

                <div className="table-container lead-table-container">
                    <table className="lead-table">
                        <thead>
                            <tr>
                                {canBulkTransfer && (
                                    <th>
                                        <input
                                            type="checkbox"
                                            aria-label="Select all leads on this page"
                                            checked={paginatedItems.length > 0 && paginatedItems.every((lead) => Boolean(selectedTransferLeads[String(lead.id)]))}
                                            onChange={(event) => toggleCurrentPageSelection(event.target.checked)}
                                        />
                                    </th>
                                )}
                                <th>S.No</th>
                                <th>Lead Name</th>
                                <th>Mobile Number</th>
                                <th>Lead Source</th>
                                <th>Campaign Name</th>
                                <th>Pipeline</th>
                                <th>Assigned Employee</th>
                                <th>Lead Stage</th>
                                <th>Next Follow-up Date</th>
                                {/* <th>Closure Reason</th> */}
                                <th>Priority</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan={canBulkTransfer ? 13 : 12} className="lead-table-empty">Loading leads...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan={canBulkTransfer ? 13 : 12} className="lead-table-empty">
                                        {leads.length === 0
                                            ? (isMyLeads
                                                ? (hasAppliedMyFilters ? 'No leads match the current search and filters.' : 'No leads are available in your current view.')
                                                : 'No leads found. Add a lead to get started.')
                                            : 'No leads match the current search and filters.'}
                                    </td>
                                </tr>
                            ) : paginatedItems.map((lead, index) => (
                                <tr key={lead.id}>
                                    {canBulkTransfer && (
                                        <td>
                                            <input
                                                type="checkbox"
                                                aria-label={`Select ${lead.lead_name}`}
                                                checked={Boolean(selectedTransferLeads[String(lead.id)])}
                                                onChange={(event) => toggleLeadSelection(lead, event.target.checked)}
                                            />
                                        </td>
                                    )}
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td className="lead-name-cell">{lead.lead_name}</td>
                                    <td>{lead.mobile_no}</td>
                                    <td>{lead.lead_source}</td>
                                    <td>{lead.campaign_name || '-'}</td>
                                    <td>{lead.pipeline_name}</td>
                                    <td>{lead.assigned_user_id ? lead.assigned_employee : 'Unassigned'}</td>
                                    <td>{lead.lead_stage_name || '-'}</td>
                                    <td>{formatDate(lead.next_followup_date)}</td>
                                    {/* <td>{lead.closure_reason || '—'}</td> */}
                                    <td>{lead.priority || '-'}</td>
                                    <td>{formatDate(lead.created_at)}</td>
                                    <td>
                                        <div className="lead-row-actions">
                                            <button type="button" className="lead-action-btn view" onClick={() => setSelectedLead(lead)} aria-label={`View ${lead.lead_name}`} title="View lead details">
                                                <Eye size={17} />
                                            </button>
                                            {canTransfer && Boolean(lead.assigned_user_id) && (
                                                <button
                                                    type="button"
                                                    className="lead-action-btn transfer"
                                                    onClick={() => openTransfer([lead], 'single')}
                                                    aria-label={`Transfer ${lead.lead_name}`}
                                                    title="Transfer lead"
                                                >
                                                    <ArrowRightLeft size={17} />
                                                </button>
                                            )}
                                            {canViewTransferHistory && (
                                                <button
                                                    type="button"
                                                    className="lead-action-btn view"
                                                    onClick={() => openTransferHistory(lead)}
                                                    aria-label={`View transfer history for ${lead.lead_name}`}
                                                    title="Transfer history"
                                                >
                                                    <History size={17} />
                                                </button>
                                            )}
                                            {canEdit && (
                                                <button type="button" className="lead-action-btn edit" onClick={() => openForm(lead)} aria-label={`Edit ${lead.lead_name}`} title="Edit lead">
                                                    <Pencil size={17} />
                                                </button>
                                            )}
                                            {canDelete && (
                                                <button
                                                    type="button"
                                                    className="lead-action-btn delete"
                                                    onClick={() => deleteLead(lead)}
                                                    aria-label={`Archive ${lead.lead_name}`}
                                                    title="Archive lead"
                                                    disabled={deletingLeadId === Number(lead.id)}
                                                >
                                                    <Trash2 size={17} />
                                                </button>
                                            )}
                                            {canAddFollowup && lead.lead_status_type !== 'Lost' && (
                                                <button type="button" className="lead-action-btn view" onClick={() => setFollowupLead(lead)} aria-label={`Record follow-up for ${lead.lead_name}`} title="Record follow-up">
                                                    <Clock3 size={17} />
                                                </button>
                                            )}
                                            {canConvertLeads && lead.lead_status_type === 'Resolved' && lead.lead_status_state === 'Active' && (
                                                <Link className="lead-action-btn view" to="/crm/lead-converter" state={{ leadId: Number(lead.id) }} aria-label={`Convert ${lead.lead_name}`} title="Convert lead">
                                                    <FileCheck2 size={17} />
                                                </Link>
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
                    itemName="leads"
                />
            </div>

            <Modal
                isOpen={formOpen}
                onClose={closeForm}
                title={editingLead ? 'Edit Lead' : 'Add Lead'}
                maxWidth="900px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeForm} disabled={saving}>Cancel</button>
                        <button type="submit" form="lead-form" className="btn btn-primary" disabled={saving || optionsLoading}>
                            {saving ? 'Saving...' : editingLead ? 'Update Lead' : 'Save Lead'}
                        </button>
                    </>
                )}
            >
                {modalError && <div className="alert alert-danger lead-modal-error" role="alert">{modalError}</div>}
                {optionsLoading ? (
                    <p className="lead-form-notice">Loading pipelines, campaigns, and lead stages...</p>
                ) : options.pipelines.length === 0 ? (
                    <div className="alert alert-warning lead-form-notice" role="alert">
                        No active pipelines are available. Configure an active pipeline before creating a lead.
                    </div>
                ) : !newLeadStage && !editingLead ? (
                    <div className="alert alert-warning lead-form-notice" role="alert">
                        An active "New Lead" stage is required before creating leads. Add or activate it in Lead Stage Master.
                        {(hasPermission('crm_lead_stage.view') || hasPermission('crm_lead_stage.add') ||
                            hasPermission('crm_lead_stage.edit') || hasPermission('crm_lead_stage.activate')) &&
                            <> <Link to="/crm/lead-stages">Open Lead Stage Master</Link>.</>}
                    </div>
                ) : null}
                <form id="lead-form" className="lead-form" onSubmit={saveLead}>
                    <div className="lead-form-group">
                        <label htmlFor="lead_name">Lead Name <span className="required">*</span></label>
                        <input id="lead_name" name="lead_name" className="form-control" maxLength={150} value={form.lead_name} onChange={handleChange} required />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="mobile_no">Mobile Number <span className="required">*</span></label>
                        <input id="mobile_no" name="mobile_no" className="form-control" type="tel" inputMode="tel" maxLength={30} value={form.mobile_no} onChange={handleChange} required aria-invalid={Boolean(form.mobile_no && !normalizedFormMobile) || (duplicateCheckIsCurrent && duplicateCheck.status === 'duplicate')} />
                        <small>Enter 10 digits. Spaces, +, hyphens, and parentheses are ignored.</small>
                        {form.mobile_no && !normalizedFormMobile && <small className="text-danger" role="alert">Enter a valid 10-digit mobile number.</small>}
                        {duplicateCheckStatus === 'checking' && <small>Checking this campaign...</small>}
                        {duplicateCheckStatus === 'duplicate' && <small className="text-danger" role="alert">{duplicateMobileMessage}</small>}
                        {duplicateCheckStatus === 'error' && <small className="text-danger" role="alert">Unable to validate this mobile number for the campaign.</small>}
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="alternate_mobile_no">Alternate Mobile</label>
                        <input id="alternate_mobile_no" name="alternate_mobile_no" className="form-control" type="tel" inputMode="numeric" maxLength={10} pattern="[0-9]{10}" value={form.alternate_mobile_no} onChange={handleChange} />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="email">Email</label>
                        <input id="email" name="email" type="email" className="form-control" maxLength={150} value={form.email} onChange={handleChange} />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="location">Location <span className="required">*</span></label>
                        <input id="location" name="location" className="form-control" maxLength={150} value={form.location} onChange={handleChange} required />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="pincode">Pincode</label>
                        <input id="pincode" name="pincode" className="form-control" inputMode="numeric" maxLength={6} pattern="[0-9]{6}" value={form.pincode} onChange={handleChange} />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="pipeline_id">Pipeline <span className="required">*</span></label>
                        <select id="pipeline_id" name="pipeline_id" className="form-control" value={form.pipeline_id} onChange={handleChange} required>
                            <option value="">Select pipeline</option>
                            {pipelineChoices.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.pipeline_name}</option>)}
                        </select>
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="campaign_id">Campaign</label>
                        <select id="campaign_id" name="campaign_id" className="form-control" value={form.campaign_id} onChange={handleChange} disabled={!form.pipeline_id}>
                            <option value="">No campaign</option>
                            {campaignChoices.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.campaign_name}</option>)}
                        </select>
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="lead_source">Lead Source <span className="required">*</span></label>
                        <select id="lead_source" name="lead_source" className="form-control" value={form.lead_source} onChange={handleChange} required>
                            {leadSources.map((source) => <option key={source} value={source}>{source}</option>)}
                        </select>
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="lead_stage_id">Lead Stage</label>
                        <input
                            id="lead_stage_id"
                            className="form-control"
                            value={editingLead?.lead_stage_name || newLeadStage?.stage_name || 'New Lead'}
                            readOnly
                        />
                    </div>
                    <div className="lead-form-group">
                        <label htmlFor="priority">Priority</label>
                        <input id="priority" name="priority" className="form-control" maxLength={50} value={form.priority} onChange={handleChange} placeholder="Optional" />
                    </div>
                    <div className="lead-form-group lead-form-wide">
                        <label htmlFor="customer_need">Customer Need / Requirement</label>
                        <textarea id="customer_need" name="customer_need" className="form-control" rows="3" value={form.customer_need} onChange={handleChange} />
                    </div>
                    <div className="lead-form-group lead-form-wide">
                        <label htmlFor="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" className="form-control" rows="3" value={form.remarks} onChange={handleChange} />
                    </div>
                </form>
            </Modal>

            <Modal
                isOpen={transferOpen}
                onClose={closeTransfer}
                title={transferType === 'single' ? 'Transfer Lead' : 'Bulk Transfer Leads'}
                maxWidth="680px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" onClick={closeTransfer} disabled={transferLoading}>Cancel</button>
                        <button
                            type="submit"
                            form="lead-transfer-form"
                            className="btn btn-primary"
                            disabled={transferLoading || transferOptionsLoading || availableTransferEmployees.length === 0}
                        >
                            {transferLoading ? 'Transferring...' : transferType === 'single' ? 'Confirm Transfer' : 'Confirm Bulk Transfer'}
                        </button>
                    </>
                )}
            >
                {transferError && <div className="alert alert-danger lead-modal-error" role="alert" style={{ whiteSpace: 'pre-line' }}>{transferError}</div>}
                {transferType === 'single' && transferLeads[0] ? (
                    <div className="lead-transfer-summary">
                        <Detail label="Lead Name" value={transferLeads[0].lead_name} />
                        <Detail label="Lead ID" value={transferLeads[0].id} />
                        <Detail label="Current Assigned Employee" value={transferLeads[0].assigned_employee} />
                    </div>
                ) : (
                    <div className="lead-transfer-selection">
                        <p><strong>{transferLeads.length}</strong> leads selected</p>
                        <div className="lead-transfer-selection-list">
                            {transferLeads.map((lead) => (
                                <div key={lead.id}>
                                    <span>{lead.lead_name} <small>#{lead.id}</small></span>
                                    <span>{lead.assigned_employee || 'Unassigned'}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
                {transferOptionsLoading ? (
                    <p className="lead-form-notice">Loading eligible Employees...</p>
                ) : availableTransferEmployees.length === 0 ? (
                    <div className="alert alert-warning lead-form-notice" role="alert">
                        No different active Employee account is available as a transfer destination.
                    </div>
                ) : null}
                <form id="lead-transfer-form" className="lead-transfer-form" onSubmit={submitTransfer}>
                    <label className="lead-form-group" htmlFor="lead-transfer-employee">
                        <span>Transfer To <span className="required">*</span></span>
                        <select
                            id="lead-transfer-employee"
                            className="form-control"
                            value={transferForm.employee_id}
                            onChange={(event) => setTransferForm((current) => ({ ...current, employee_id: event.target.value }))}
                            required
                            disabled={transferOptionsLoading}
                        >
                            <option value="">Select Employee</option>
                            {availableTransferEmployees.map((employee) => (
                                    <option key={employee.id} value={employee.id}>{employee.employee_name}</option>
                                ))}
                        </select>
                    </label>
                    <label className="lead-form-group" htmlFor="lead-transfer-reason">
                        <span>Transfer Reason <span className="required">*</span></span>
                        <textarea
                            id="lead-transfer-reason"
                            className="form-control"
                            rows="3"
                            maxLength={1000}
                            value={transferForm.reason}
                            onChange={(event) => setTransferForm((current) => ({ ...current, reason: event.target.value }))}
                            required
                        />
                    </label>
                </form>
            </Modal>

            <Modal
                isOpen={Boolean(historyLead)}
                onClose={() => {
                    if (historyLoading) return;
                    setHistoryLead(null);
                    setTransferHistory([]);
                    setHistoryError('');
                    setHistoryTotal(0);
                    setHistoryPage(1);
                }}
                title={`Transfer History${historyLead ? ` — ${historyLead.lead_name}` : ''}`}
                maxWidth="820px"
            >
                {historyError && <div className="alert alert-danger lead-modal-error" role="alert">{historyError}</div>}
                {historyLoading ? (
                    <p className="lead-form-notice">Loading transfer history...</p>
                ) : transferHistory.length === 0 ? (
                    <p className="lead-form-notice">No transfer history is recorded for this lead.</p>
                ) : (
                    <div className="table-container lead-transfer-history-container">
                        <table className="lead-table lead-transfer-history-table">
                            <thead>
                                <tr>
                                    <th>Transferred At</th>
                                    <th>Previous Employee</th>
                                    <th>New Employee</th>
                                    <th>Transferred By</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transferHistory.map((entry) => (
                                    <tr key={entry.id}>
                                        <td>{entry.transferred_at}</td>
                                        <td>{entry.previous_employee} <small>#{entry.previous_employee_id}</small></td>
                                        <td>{entry.new_employee} <small>#{entry.new_employee_id}</small></td>
                                        <td>{entry.transferred_by}</td>
                                        <td>{entry.transfer_type}</td>
                                        <td>{entry.transfer_reason}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {!historyLoading && historyTotal > 0 && (
                    <Pagination
                        currentPage={historyPage}
                        totalItems={historyTotal}
                        pageSize={historyPageSize}
                        pageSizeOptions={[10, 25, 50]}
                        onPageChange={(page) => loadTransferHistory(historyLead, page)}
                        onPageSizeChange={(pageSize) => {
                            setHistoryPageSize(pageSize);
                            setHistoryPage(1);
                            loadTransferHistory(historyLead, 1, pageSize);
                        }}
                        itemName="transfers"
                    />
                )}
            </Modal>

            <Modal
                isOpen={Boolean(selectedLead)}
                onClose={() => setSelectedLead(null)}
                title="Lead Details"
                maxWidth="760px"
            >
                {selectedLead && (
                    <div className="lead-details-grid">
                        <Detail label="Lead Name" value={selectedLead.lead_name} />
                        <Detail label="Mobile Number" value={selectedLead.mobile_no} />
                        <Detail label="Alternate Mobile" value={selectedLead.alternate_mobile_no} />
                        <Detail label="Email" value={selectedLead.email} />
                        <Detail label="Location" value={selectedLead.location} />
                        <Detail label="Pincode" value={selectedLead.pincode} />
                        <Detail label="Pipeline" value={selectedLead.pipeline_name} />
                        <Detail label="Campaign" value={selectedLead.campaign_name} />
                        <Detail label="Lead Source" value={selectedLead.lead_source} />
                        <Detail label="Lead Stage" value={selectedLead.lead_stage_name} />
                        {/* <Detail label="Closure Reason" value={selectedLead.closure_reason} /> */}
                        <Detail label="Priority" value={selectedLead.priority} />
                        <Detail label="Assigned Employee" value={selectedLead.assigned_user_id ? selectedLead.assigned_employee : 'Unassigned'} />
                        <Detail label="Next Follow-up Date" value={formatDate(selectedLead.next_followup_date)} />
                        <Detail label="Customer Need / Requirement" value={selectedLead.customer_need} wide />
                        <Detail label="Remarks" value={selectedLead.remarks} wide />
                        <Detail label="Created Date" value={formatDate(selectedLead.created_at)} />
                    </div>
                )}
            </Modal>
            {confirmationDialog}

            {followupLead && canAddFollowup && (
                <FollowupModal
                    lead={followupLead}
                    mode="record"
                    onClose={() => setFollowupLead(null)}
                    onSaved={(successMessage) => {
                        setFollowupLead(null);
                        setMessage(successMessage);
                    }}
                />
            )}
        </div>
    );
};

const Detail = ({ label, value, wide = false }) => (
    <div className={`lead-detail${wide ? ' lead-detail-wide' : ''}`}>
        <span>{label}</span>
        <strong>{value || '-'}</strong>
    </div>
);

export default LeadManagementPage;
