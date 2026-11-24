import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Search, Users } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import { formatDate } from '../../utils/date';
import './LeadDistributionPage.css';

const LeadDistributionPage = () => {
    const { confirm, confirmationDialog } = useConfirmation();
    const [leads, setLeads] = useState([]);
    const [leadOptions, setLeadOptions] = useState({ pipelines: [], campaigns: [] });
    const [employees, setEmployees] = useState([]);
    const [assignmentStatus, setAssignmentStatus] = useState('unassigned');
    const [selectedLeadIds, setSelectedLeadIds] = useState(() => new Set());
    const [selectedLeadRecords, setSelectedLeadRecords] = useState({});
    const [selectedEmployeeId, setSelectedEmployeeId] = useState('');
    const [nextFollowupDate, setNextFollowupDate] = useState('');
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState({
        pipeline: '',
        campaign: '',
        source: ''
    });
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [totalItems, setTotalItems] = useState(0);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    const loadLeads = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/crm_leads/distribution_list.php', {
                params: {
                    assignment_status: assignmentStatus,
                    search,
                    pipeline_id: filters.pipeline,
                    campaign_id: filters.campaign,
                    lead_source: filters.source,
                    page,
                    page_size: pageSize
                }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load leads for distribution.');
                return;
            }
            setLeads(response.data.data?.leads || []);
            setTotalItems(Number(response.data.data?.pagination?.total || 0));
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to load leads for distribution.');
        } finally {
            setLoading(false);
        }
    }, [assignmentStatus, search, filters, page, pageSize]);

    const loadEmployees = async () => {
        try {
            const response = await api.get('/crm_leads/distribution_employees.php');
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load eligible employees.');
                return;
            }
            setEmployees(response.data.data?.employees || []);
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to load eligible employees.');
        }
    };

    useEffect(() => {
        setPage(1);
        setSelectedLeadIds(new Set());
        setSelectedLeadRecords({});
    }, [assignmentStatus, search, filters]);

    useEffect(() => {
        loadLeads();
    }, [loadLeads]);

    useEffect(() => {
        loadEmployees();
    }, []);

    useEffect(() => {
        const loadLeadOptions = async () => {
            try {
                const response = await api.get('/crm_leads/options.php');
                if (!response.data?.success) {
                    setError(response.data?.message || 'Unable to load lead filter options.');
                    return;
                }
                setLeadOptions({
                    pipelines: response.data.data?.pipelines || [],
                    campaigns: response.data.data?.campaigns || []
                });
            } catch (requestError) {
                setError(requestError.response?.data?.message || 'Unable to load lead filter options.');
            }
        };
        loadLeadOptions();
    }, []);

    const visibleLeads = leads;
    const selectedLeads = Object.values(selectedLeadRecords)
        .filter((lead) => selectedLeadIds.has(Number(lead.id)));
    const selectedAssignedCount = selectedLeads.filter((lead) => lead.assigned_user_id !== null).length;
    const selectedUnassignedCount = selectedLeads.length - selectedAssignedCount;
    const allFilteredSelected = visibleLeads.length > 0 &&
        visibleLeads.every((lead) => selectedLeadIds.has(Number(lead.id)));
    const employeeOptions = employees.map((employee) => ({
        value: String(employee.id),
        label: `${employee.employee_name} (${employee.role_name})`
    }));

    const setFilter = (name, value) => {
        setFilters((current) => ({ ...current, [name]: value }));
    };

    const toggleLead = (leadId) => {
        const selectedLead = leads.find((lead) => Number(lead.id) === Number(leadId));
        if (selectedLeadIds.has(Number(leadId))) {
            setSelectedLeadIds((current) => {
                const updated = new Set(current);
                updated.delete(Number(leadId));
                return updated;
            });
            setSelectedLeadRecords((records) => {
                const nextRecords = { ...records };
                delete nextRecords[Number(leadId)];
                return nextRecords;
            });
            return;
        }
        setSelectedLeadIds((current) => {
            const updated = new Set(current);
            updated.add(Number(leadId));
            return updated;
        });
        if (selectedLead) {
            setSelectedLeadRecords((records) => ({ ...records, [Number(leadId)]: selectedLead }));
        }
    };

    const toggleAllFiltered = () => {
        setSelectedLeadIds((current) => {
            const updated = new Set(current);
            visibleLeads.forEach((lead) => {
                if (allFilteredSelected) updated.delete(Number(lead.id));
                else updated.add(Number(lead.id));
            });
            return updated;
        });
        setSelectedLeadRecords((records) => {
            const updated = { ...records };
            visibleLeads.forEach((lead) => {
                if (allFilteredSelected) delete updated[Number(lead.id)];
                else updated[Number(lead.id)] = lead;
            });
            return updated;
        });
    };

    const resetFilters = () => {
        setSearch('');
        setFilters({ pipeline: '', campaign: '', source: '' });
    };

    const assignSelected = async () => {
        if (selectedLeadIds.size === 0 || !selectedEmployeeId) {
            setError('Select at least one lead and an eligible employee.');
            return;
        }
        const employee = employees.find((item) => String(item.id) === selectedEmployeeId);
        const confirmed = await confirm({
            title: selectedAssignedCount > 0 ? 'Confirm Lead Reassignment' : 'Confirm Lead Assignment',
            message: `Assign ${selectedLeadIds.size} selected lead(s) to ${employee?.employee_name || 'the selected employee'} and schedule follow-up for ${nextFollowupDate ? formatDate(nextFollowupDate) : 'the assignment date'}?${selectedAssignedCount > 0 ? ` This will reassign ${selectedAssignedCount} lead(s) and preserve their assignment history.` : ''}`,
            confirmLabel: saving ? 'Saving...' : 'Confirm Assignment',
            confirmClassName: 'btn-primary'
        });
        if (!confirmed) return;

        setSaving(true);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_leads/assign.php', {
                lead_ids: [...selectedLeadIds],
                employee_id: Number(selectedEmployeeId),
                next_followup_date: nextFollowupDate || ''
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to assign selected leads.');
                return;
            }
            const result = response.data.data || {};
            const summary = [];
            if (result.assigned) summary.push(`${result.assigned} assigned`);
            if (result.reassigned) summary.push(`${result.reassigned} reassigned`);
            if (result.unchanged) summary.push(`${result.unchanged} already assigned`);
            if (result.followups_scheduled) summary.push(`${result.followups_scheduled} follow-ups scheduled`);
            if (result.followups_already_scheduled) summary.push(`${result.followups_already_scheduled} follow-ups already scheduled`);
            setMessage(`Assignment saved: ${summary.join(', ') || 'no changes'}.`);
            setSelectedLeadIds(new Set());
            setSelectedLeadRecords({});
            setNextFollowupDate('');
            window.dispatchEvent(new Event('crm:lead-updated'));
            await loadLeads();
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to assign selected leads.');
        } finally {
            setSaving(false);
        }
    };

    const sourceOptions = [...new Set([...leads.map((lead) => lead.lead_source), 'Manual', 'Facebook', 'Google', 'Instagram', 'Website', 'Other'].filter(Boolean))].sort();
    const pipelineOptions = [...new Map([...leadOptions.pipelines, ...leads.map((lead) => ({
        id: lead.pipeline_id,
        name: lead.pipeline_name
    }))].map((pipeline) => [String(pipeline.id), {
        id: pipeline.id,
        name: pipeline.name || pipeline.pipeline_name
    }])).values()];
    const campaignOptions = [...new Map([...leadOptions.campaigns, ...leads.filter((lead) => lead.campaign_id).map((lead) => ({
        id: lead.campaign_id,
        pipeline_id: lead.pipeline_id,
        name: lead.campaign_name
    }))].map((campaign) => [String(campaign.id), {
        id: campaign.id,
        pipeline_id: campaign.pipeline_id,
        name: campaign.name || campaign.campaign_name
    }])).values()];
    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Lead Distribution</h2>
                    <p className="page-subtitle">Assign unassigned leads or reassign leads to eligible employees.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={() => { loadLeads(); loadEmployees(); }} disabled={loading || saving}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>

            {message && <div className="alert alert-success distribution-message">{message}</div>}
            {error && <div className="alert alert-danger distribution-message" role="alert">{error}</div>}

            <div className="card distribution-card">
                <div className="distribution-controls">
                    <div className="distribution-assignment">
                        <div className="distribution-assignee-field">
                            <label className="distribution-employee-label">Assign To</label>
                            <SearchableDropdown
                                options={employeeOptions}
                                value={selectedEmployeeId}
                                onChange={(value) => setSelectedEmployeeId(String(value || ''))}
                                placeholder={employees.length ? 'Search employees...' : 'No eligible employees'}
                                disabled={employees.length === 0 || saving}
                            />
                        </div>
                        <label className="distribution-followup-date">
                            <span>Next Follow-up Date</span>
                            <input
                                type="date"
                                className="form-control"
                                value={nextFollowupDate}
                                onChange={(event) => setNextFollowupDate(event.target.value)}
                                disabled={saving}
                                aria-label="Next follow-up date"
                            />
                            <small>{nextFollowupDate ? `Selected: ${formatDate(nextFollowupDate)}` : 'Leave blank to use the assignment date.'}</small>
                        </label>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={assignSelected}
                            disabled={saving || selectedLeadIds.size === 0 || !selectedEmployeeId}
                        >
                            <Users size={16} />
                            {saving ? 'Saving...' : selectedAssignedCount > 0 ? 'Reassign Selected Leads' : 'Assign Selected Leads'}
                        </button>
                    </div>
                    <div className="distribution-selection-summary">
                        {selectedLeadIds.size} selected
                        {selectedAssignedCount > 0 && ` · ${selectedAssignedCount} to reassign`}
                        {selectedUnassignedCount > 0 && ` · ${selectedUnassignedCount} unassigned`}
                    </div>
                </div>

                <div className="distribution-filters">
                    <label className="distribution-search">
                        <Search size={17} aria-hidden="true" />
                        <input
                            type="search"
                            className="form-control"
                            placeholder="Search by lead name or mobile..."
                            aria-label="Search leads by name or mobile"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                    <select className="form-control" aria-label="Assignment status" value={assignmentStatus} onChange={(event) => setAssignmentStatus(event.target.value)}>
                        <option value="unassigned">Unassigned Leads</option>
                        <option value="assigned">Assigned Leads (Reassign)</option>
                        <option value="all">All Leads</option>
                    </select>
                    <select className="form-control" aria-label="Filter by pipeline" value={filters.pipeline} onChange={(event) => setFilter('pipeline', event.target.value)}>
                        <option value="">All Pipelines</option>
                        {pipelineOptions.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.name}</option>)}
                    </select>
                    <select className="form-control" aria-label="Filter by campaign" value={filters.campaign} onChange={(event) => setFilter('campaign', event.target.value)}>
                        <option value="">All Campaigns</option>
                        {campaignOptions.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}
                    </select>
                    <select className="form-control" aria-label="Filter by lead source" value={filters.source} onChange={(event) => setFilter('source', event.target.value)}>
                        <option value="">All Lead Sources</option>
                        {sourceOptions.map((source) => <option key={source} value={source}>{source}</option>)}
                    </select>
                    <button type="button" className="btn btn-outline distribution-reset" onClick={resetFilters}>Reset</button>
                </div>

                <div className="table-container distribution-table-container">
                    <table className="distribution-table">
                        <thead>
                            <tr>
                                <th className="distribution-checkbox-cell">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all leads on this page"
                                        checked={allFilteredSelected}
                                        onChange={toggleAllFiltered}
                                        disabled={loading || visibleLeads.length === 0}
                                    />
                                </th>
                                <th>S.No</th>
                                <th>Lead Name</th>
                                <th>Mobile Number</th>
                                <th>Lead Source</th>
                                <th>Campaign Name</th>
                                <th>Pipeline</th>
                                <th>Priority</th>
                                <th>Created Date</th>
                                <th>Assigned Employee</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="10" className="distribution-empty">Loading leads...</td></tr>
                            ) : visibleLeads.length === 0 ? (
                                <tr>
                                    <td colSpan="10" className="distribution-empty">
                                        {leads.length === 0
                                            ? assignmentStatus === 'unassigned'
                                                ? 'There are no unassigned leads.'
                                                : 'There are no leads for this assignment filter.'
                                            : 'No leads match the current search and filters.'}
                                    </td>
                                </tr>
                            ) : visibleLeads.map((lead, index) => (
                                <tr key={lead.id}>
                                    <td className="distribution-checkbox-cell">
                                        <input
                                            type="checkbox"
                                            aria-label={`Select ${lead.lead_name}`}
                                            checked={selectedLeadIds.has(Number(lead.id))}
                                            onChange={() => toggleLead(lead.id)}
                                        />
                                    </td>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td className="distribution-lead-name">{lead.lead_name}</td>
                                    <td>{lead.mobile_no}</td>
                                    <td>{lead.lead_source}</td>
                                    <td>{lead.campaign_name || '-'}</td>
                                    <td>{lead.pipeline_name}</td>
                                    <td>{lead.priority || '-'}</td>
                                    <td>{formatDate(lead.created_at)}</td>
                                    <td>{lead.assigned_user_id ? lead.assigned_employee : 'Unassigned'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={(size) => { setPageSize(size); setPage(1); }}
                    itemName="leads"
                />
            </div>
            {confirmationDialog}
        </div>
    );
};

export default LeadDistributionPage;
