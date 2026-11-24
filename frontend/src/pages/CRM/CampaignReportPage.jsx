import { useEffect, useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import './CampaignManagementPage.css';

const CampaignReportPage = () => {
    const navigate = useNavigate();
    const { campaignId } = useParams();
    const { hasPermission } = useAuth();
    const [campaign, setCampaign] = useState(null);
    const [leads, setLeads] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [refreshVersion, setRefreshVersion] = useState(0);
    const {
        page: currentPage,
        setPage: setCurrentPage,
        pageSize: currentPageSize,
        setPageSize: setCurrentPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(leads, 10, [campaignId], true);

    useEffect(() => {
        let active = true;
        const loadReport = async () => {
            setLoading(true);
            setError('');
            try {
                const response = await api.get('/crm_campaigns/report.php', {
                    params: {
                        id: campaignId,
                        page: currentPage,
                        page_size: currentPageSize
                    }
                });
                if (!response.data?.success) {
                    if (active) setError(response.data?.message || 'Unable to load campaign report.');
                    return;
                }
                if (active) {
                    setCampaign(response.data.data?.campaign || null);
                    setLeads(response.data.data?.leads || []);
                    setTotalItems(Number(response.data.data?.pagination?.total || 0));
                }
            } catch (err) {
                if (active) setError(err.response?.data?.message || 'Unable to load campaign report.');
            } finally {
                if (active) setLoading(false);
            }
        };
        loadReport();
        return () => {
            active = false;
        };
    }, [campaignId, currentPage, currentPageSize, refreshVersion, setTotalItems]);

    useEffect(() => {
        const refreshReport = () => setRefreshVersion((version) => version + 1);
        window.addEventListener('crm:lead-updated', refreshReport);
        return () => window.removeEventListener('crm:lead-updated', refreshReport);
    }, []);

    if (!hasPermission('crm_campaigns.view')) {
        return <div className="page-container"><div className="alert alert-danger">You do not have permission to view campaign reports.</div></div>;
    }

    return (
        <div className="page-container">
            <div className="page-header campaign-report-header">
                <div>
                    <h2>{campaign?.campaign_name || 'Campaign Report'}</h2>
                    <p className="page-subtitle">Campaign details and its attributed lead records.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={() => navigate('/crm/campaigns')}>
                    <ArrowLeft size={16} /> Back to Campaigns
                </button>
            </div>

            {error && <div className="alert alert-danger campaign-message" role="alert">{error}</div>}

            {loading ? (
                <div className="card campaign-report-empty">Loading campaign report...</div>
            ) : campaign && (
                <>
                    <div className="campaign-summary-grid">
                        <div className="campaign-summary-card"><span>Pipeline</span><strong>{campaign.pipeline_name || '—'}</strong></div>
                        <div className="campaign-summary-card"><span>Managed By</span><strong>{campaign.managed_by || 'Unassigned'}</strong></div>
                        <div className="campaign-summary-card"><span>Start Date</span><strong>{formatDate(campaign.start_date)}</strong></div>
                        <div className="campaign-summary-card"><span>End Date</span><strong>{formatDate(campaign.end_date)}</strong></div>
                        <div className="campaign-summary-card"><span>Status</span><strong><span className={`badge ${campaign.status === 'Active' ? 'badge-success' : 'badge-warning'}`}>{campaign.status}</span></strong></div>
                        <div className="campaign-summary-card"><span>Total Leads</span><strong>{Number(campaign.total_leads || 0)}</strong></div>
                        <div className="campaign-summary-card"><span>Assigned Leads</span><strong>{Number(campaign.assigned_leads || 0)}</strong></div>
                        <div className="campaign-summary-card"><span>Unassigned Leads</span><strong>{Number(campaign.unassigned_leads || 0)}</strong></div>
                        <div className="campaign-summary-card campaign-description-summary"><span>Description</span><strong>{campaign.description || '—'}</strong></div>
                    </div>

                    <div className="card campaign-report-card">
                        <div className="campaign-report-title">
                            <div>
                                <h3>Campaign Leads</h3>
                                <p className="page-subtitle">{Number(campaign.total_leads || 0)} lead(s) attributed to this campaign</p>
                            </div>
                        </div>
                        <div className="table-container campaign-table-container">
                            <table className="campaign-leads-table">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Lead Name</th>
                                        <th>Mobile Number</th>
                                        <th>Email</th>
                                        <th>Location</th>
                                        <th>Lead Source</th>
                                        <th>Closure Reason</th>
                                        <th>Campaign</th>
                                        <th>Assigned Employee</th>
                                        <th>Lead Stage</th>
                                        <th>Lead Status</th>
                                        <th>Created Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {paginatedItems.length === 0 ? (
                                        <tr><td colSpan="12" className="campaign-table-empty">No leads have been captured for this campaign yet.</td></tr>
                                    ) : paginatedItems.map((lead, index) => (
                                        <tr key={lead.id}>
                                            <td>{(currentPage - 1) * currentPageSize + index + 1}</td>
                                            <td className="campaign-lead-name">{lead.lead_name || '—'}</td>
                                            <td>{lead.mobile_no || '—'}</td>
                                            <td>{lead.email || '—'}</td>
                                            <td>{lead.location || '—'}</td>
                                            <td>{lead.lead_source || '—'}</td>
                                            <td>{lead.closure_reason || '—'}</td>
                                            <td>{lead.campaign_name || campaign.campaign_name}</td>
                                            <td>{lead.assigned_employee || 'Unassigned'}</td>
                                            <td>{lead.lead_stage_name || '—'}</td>
                                            <td>{lead.lead_status || '—'}</td>
                                            <td>{formatDate(lead.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination
                            currentPage={currentPage}
                            pageSize={currentPageSize}
                            totalItems={totalItems}
                            onPageChange={setCurrentPage}
                            onPageSizeChange={setCurrentPageSize}
                            itemName="leads"
                        />
                    </div>
                </>
            )}
        </div>
    );
};

export default CampaignReportPage;
