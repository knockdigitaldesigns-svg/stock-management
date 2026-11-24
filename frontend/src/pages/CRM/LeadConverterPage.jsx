import { useCallback, useEffect, useState } from 'react';
import { Check, RefreshCw } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { formatDate } from '../../utils/date';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import InstallationVerificationCorrections from './InstallationVerificationCorrections';
import './ClosureWorkflowPages.css';

const emptyForm = {
    platform_id: '',
    customer_number: '',
    username: '',
    vehicle_type: '',
    vehicle_number: '',
    commitment_date: '',
    location: '',
    pincode: '',
    device_type: '',
    device_count: '1',
    amount: '',
    amount_status: ''
};

const LeadConverterPage = () => {
    const location = useLocation();
    const navigate = useNavigate();
    const { confirm, confirmationDialog } = useConfirmation();
    const [leads, setLeads] = useState([]);
    const [platforms, setPlatforms] = useState([]);
    const [vehicleTypes, setVehicleTypes] = useState([]);
    const [deviceTypes, setDeviceTypes] = useState([]);
    const [selectedLead, setSelectedLead] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [notifications, setNotifications] = useState([]);
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
    } = usePagination(leads, 10, [], true);

    const loadData = useCallback(async () => {
        try {
            const [leadResponse, optionResponse, notificationResponse] = await Promise.all([
                api.get('/crm_closures/leads.php', { params: { page, page_size: pageSize } }),
                api.get('/crm_closures/options.php'),
                api.get('/crm_closures/notifications.php')
            ]);
            if (!leadResponse.data?.success || !optionResponse.data?.success || !notificationResponse.data?.success) {
                throw new Error(
                    leadResponse.data?.message ||
                    optionResponse.data?.message ||
                    notificationResponse.data?.message ||
                    'Unable to load lead converter data.'
                );
            }
            setLeads(leadResponse.data.data?.leads || []);
            setTotalItems(Number(leadResponse.data.data?.pagination?.total || 0));
            setPlatforms(optionResponse.data.data?.platforms || []);
            setVehicleTypes(optionResponse.data.data?.vehicle_types || []);
            setDeviceTypes(optionResponse.data.data?.device_types || []);
            setNotifications(notificationResponse.data.data?.notifications || []);
        } catch (err) {
            setError(err.response?.data?.message || err.message || 'Unable to load lead converter data.');
        } finally {
            setLoading(false);
        }
    }, [page, pageSize, setTotalItems]);

    const chooseLead = useCallback((lead) => {
        setSelectedLead(lead);
        setForm({
            ...emptyForm,
            platform_id: lead.platform_id ? String(lead.platform_id) : '',
            customer_number: lead.customer_number || '',
            username: lead.username || '',
            vehicle_type: lead.vehicle_type || '',
            vehicle_number: lead.vehicle_number || '',
            commitment_date: lead.commitment_date || '',
            location: lead.closure_location || lead.location || '',
            pincode: lead.closure_pincode || lead.pincode || '',
            device_type: lead.device_type || '',
            device_count: lead.device_count ? String(lead.device_count) : '1',
            amount: lead.amount || '',
            amount_status: lead.amount_status || ''
        });
        setMessage('');
        setError('');
    }, []);

    useEffect(() => {
        loadData();
    }, [loadData]);

    useEffect(() => {
        const refresh = () => loadData();
        window.addEventListener('crm:lead-updated', refresh);
        return () => window.removeEventListener('crm:lead-updated', refresh);
    }, [loadData]);

    useEffect(() => {
        const requestedLeadId = Number(location.state?.leadId || 0);
        if (!requestedLeadId || leads.length === 0) return;
        const lead = leads.find((item) => Number(item.id) === requestedLeadId);
        if (lead) {
            chooseLead(lead);
            navigate('/crm/lead-converter', { replace: true, state: null });
            return;
        }
        let active = true;
        const loadRequestedLead = async () => {
            try {
                const response = await api.get('/crm_closures/leads.php', {
                    params: { lead_id: requestedLeadId, page: 1, page_size: 1 }
                });
                if (!active) return;
                if (!response.data?.success) {
                    setError(response.data?.message || 'Unable to load the requested conversion-ready lead.');
                    return;
                }
                const requestedLead = response.data?.data?.leads?.[0];
                if (requestedLead) {
                    chooseLead(requestedLead);
                    navigate('/crm/lead-converter', { replace: true, state: null });
                }
            } catch (err) {
                if (active) setError(err.response?.data?.message || 'Unable to load the requested conversion-ready lead.');
            }
        };
        loadRequestedLead();
        return () => {
            active = false;
        };
    }, [leads, location.state, navigate, chooseLead]);

    const refreshData = () => {
        setLoading(true);
        setError('');
        loadData();
    };

    const submit = async (event) => {
        event.preventDefault();
        if (!selectedLead || saving) return;
        const confirmed = await confirm({
            title: 'Submit for Approval',
            message: `Submit the conversion form for ${selectedLead.lead_name} for approval?`,
            confirmLabel: 'Submit for Approval',
            confirmClassName: 'btn-primary'
        });
        if (!confirmed) return;
        setSaving(true);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_closures/submit.php', {
                ...form,
                lead_id: Number(selectedLead.id),
                platform_id: Number(form.platform_id),
                device_count: Number(form.device_count)
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to submit closure form.');
                return;
            }
            setMessage(response.data.message || 'Closure submitted for approval.');
            setSelectedLead(null);
            await loadData();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to submit closure form.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Lead Converter</h2>
                    <p className="page-subtitle">Submit conversion-ready leads for authorized approval.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={refreshData} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {message && <div className="alert alert-success" role="status">{message}</div>}
            {error && <div className="alert alert-danger" role="alert">{error}</div>}

            {notifications.length > 0 && (
                <section className="card" aria-label="Closure decisions">
                    <h3>Notifications</h3>
                    {notifications.map((notification) => (
                        <div key={notification.id} className="alert alert-info">
                            <strong>{notification.title}</strong> — {notification.message}
                            <small style={{ display: 'block' }}>{formatDate(notification.created_at)}</small>
                            {notification.title === 'Installation verification rejected' && (
                                <a className="btn btn-outline" style={{ marginTop: '0.5rem' }} href="#returned-installation-verifications">
                                    Review returned installation
                                </a>
                            )}
                        </div>
                    ))}
                </section>
            )}

            <InstallationVerificationCorrections
                platforms={platforms}
                vehicleTypes={vehicleTypes}
                deviceTypes={deviceTypes}
                onUpdated={loadData}
            />

            {selectedLead ? (
                <section className="card closure-workflow-card" aria-label="Lead conversion form">
                    <div className="page-header">
                        <div>
                            <h3>Convert {selectedLead.lead_name}</h3>
                            <p className="page-subtitle">{selectedLead.mobile_no} · {selectedLead.lead_status}</p>
                        </div>
                        <button type="button" className="btn btn-outline" onClick={() => setSelectedLead(null)}>Back to leads</button>
                    </div>
                    {selectedLead.rejection_reason && (
                        <div className="alert alert-warning">
                            Previous submission was rejected: {selectedLead.rejection_reason}
                        </div>
                    )}
                    <form className="closure-workflow-form" onSubmit={submit}>
                        <label className="form-group">
                            <span>Platform <b>*</b></span>
                            <select className="form-control" required value={form.platform_id} onChange={(event) => setForm((old) => ({ ...old, platform_id: event.target.value }))}>
                                <option value="">Select active platform</option>
                                {platforms.map((platform) => <option key={platform.id} value={platform.id}>{platform.platform_name}</option>)}
                            </select>
                        </label>
                        <label className="form-group">
                            <span>Username <b>*</b></span>
                            <input className="form-control" required maxLength="100" pattern="[A-Za-z0-9._-]+" value={form.username} onChange={(event) => setForm((old) => ({ ...old, username: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Customer Number</span>
                            <input className="form-control" maxLength="50" value={form.customer_number} onChange={(event) => setForm((old) => ({ ...old, customer_number: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Vehicle Type <b>*</b></span>
                            <select className="form-control" required value={form.vehicle_type} onChange={(event) => setForm((old) => ({ ...old, vehicle_type: event.target.value }))}>
                                <option value="">Select vehicle type</option>
                                {vehicleTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                            </select>
                        </label>
                        <label className="form-group">
                            <span>Vehicle Number <b>*</b></span>
                            <input className="form-control" required maxLength="30" value={form.vehicle_number} onChange={(event) => setForm((old) => ({ ...old, vehicle_number: event.target.value.toUpperCase() }))} />
                        </label>
                        <label className="form-group">
                            <span>Commitment Date <b>*</b></span>
                            <input className="form-control" type="date" required value={form.commitment_date} onChange={(event) => setForm((old) => ({ ...old, commitment_date: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Location <b>*</b></span>
                            <input className="form-control" required maxLength="150" value={form.location} onChange={(event) => setForm((old) => ({ ...old, location: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Pincode <b>*</b></span>
                            <input className="form-control" required inputMode="numeric" pattern="[0-9]{6}" maxLength="6" value={form.pincode} onChange={(event) => setForm((old) => ({ ...old, pincode: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Device Type <b>*</b></span>
                            <select className="form-control" required value={form.device_type} onChange={(event) => setForm((old) => ({ ...old, device_type: event.target.value }))}>
                                <option value="">Select device type</option>
                                {deviceTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                            </select>
                        </label>
                        <label className="form-group">
                            <span>Device Count <b>*</b></span>
                            <input className="form-control" type="number" min="1" step="1" required value={form.device_count} onChange={(event) => setForm((old) => ({ ...old, device_count: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Amount <b>*</b></span>
                            <input className="form-control" type="number" min="0.01" step="0.01" required value={form.amount} onChange={(event) => setForm((old) => ({ ...old, amount: event.target.value }))} />
                        </label>
                        <label className="form-group">
                            <span>Amount Status <b>*</b></span>
                            <input className="form-control" required maxLength="50" value={form.amount_status} onChange={(event) => setForm((old) => ({ ...old, amount_status: event.target.value }))} />
                        </label>
                        <div className="form-actions">
                            <button type="button" className="btn btn-outline" onClick={() => setSelectedLead(null)}>Cancel</button>
                            <button type="submit" className="btn btn-primary" disabled={saving || platforms.length === 0}>
                                <Check size={16} /> {saving ? 'Submitting...' : 'Submit for Approval'}
                            </button>
                        </div>
                    </form>
                </section>
            ) : (
                <section className="card closure-workflow-card">
                    <h3>Conversion-Ready Leads</h3>
                    <div className="table-container">
                        <table className="table closure-workflow-table">
                            <thead>
                                <tr><th>Lead</th><th>Mobile</th><th>Campaign</th><th>Status</th><th>Assigned Employee</th><th>Created</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                {loading ? (
                                    <tr><td colSpan="7">Loading leads...</td></tr>
                                ) : paginatedItems.length === 0 ? (
                                    <tr><td colSpan="7">No conversion-ready leads are available in your authorized scope.</td></tr>
                                ) : paginatedItems.map((lead) => (
                                    <tr key={lead.id}>
                                        <td>{lead.lead_name}</td>
                                        <td>{lead.mobile_no}</td>
                                        <td>{lead.campaign_name || '—'}</td>
                                        <td>{lead.lead_status}</td>
                                        <td>{lead.assigned_employee || 'Unassigned'}</td>
                                        <td>{formatDate(lead.created_at)}</td>
                                        <td><button type="button" className="btn btn-outline" onClick={() => chooseLead(lead)}>Convert Lead</button></td>
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
                </section>
            )}
            {confirmationDialog}
        </div>
    );
};

export default LeadConverterPage;
