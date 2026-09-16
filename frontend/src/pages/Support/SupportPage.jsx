import { useEffect, useState } from 'react';
import { CheckCircle2, ChevronDown, ChevronRight, LifeBuoy, Plus, RefreshCw, Save, ShieldQuestion } from 'lucide-react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const initialVerification = { mobile_no: '', vehicle_no: '', imei_no: '', username: '' };
const statuses = ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed'];

const SupportPage = () => {
    const { hasPermission } = useAuth();
    const [step, setStep] = useState(1);
    const [verification, setVerification] = useState(initialVerification);
    const [customer, setCustomer] = useState(null);
    const [verifying, setVerifying] = useState(false);
    const [questions, setQuestions] = useState([]);
    const [expandedQuestion, setExpandedQuestion] = useState(null);
    const [tickets, setTickets] = useState([]);
    const [users, setUsers] = useState([]);
    const [issue, setIssue] = useState('');
    const [priority, setPriority] = useState('Medium');
    const [assignedTo, setAssignedTo] = useState('');
    const [selectedTicket, setSelectedTicket] = useState(null);
    const [resolution, setResolution] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [questionForm, setQuestionForm] = useState({ id: null, question: '', answer: '', display_order: 0, is_active: true });

    const fail = (message) => { setError(message); showGlobalError(message); };
    const loadQuestions = async () => {
        try { const response = await api.get('/support/questions.php'); setQuestions(response.data?.data?.questions || []); } catch (requestError) { fail(requestError.response?.data?.message || 'Unable to load support questions.'); }
    };
    const loadQueue = async () => {
        try { const [ticketResponse, userResponse] = await Promise.all([api.get('/support/tickets.php'), api.get('/support/users.php')]); setTickets(ticketResponse.data?.data?.tickets || []); setUsers(userResponse.data?.data?.users || []); } catch (requestError) { fail(requestError.response?.data?.message || 'Unable to load support task queue.'); }
    };
    useEffect(() => { if (hasPermission('support.qa.view')) loadQuestions(); loadQueue(); }, [hasPermission]);

    const verifyCustomer = async (event) => {
        event.preventDefault(); setError(''); setVerifying(true); setCustomer(null);
        try { const response = await api.post('/support/verify.php', verification); setCustomer(response.data?.data?.customer); setStep(1); } catch (requestError) { fail(requestError.response?.data?.message || 'Customer verification failed.'); } finally { setVerifying(false); }
    };
    const continueToQuestions = () => { if (!customer) return; setStep(2); };
    const continueToTicket = () => { if (customer) setStep(3); };
    const createTicket = async (event) => {
        event.preventDefault(); if (!customer || !issue.trim()) return fail('Issue / Problem is required.');
        setSaving(true); setError('');
        try { await api.post('/support/tickets.php', { customer_id: customer.customer_id, vehicle_id: customer.vehicle_id, issue, priority, assigned_to_user_id: assignedTo ? Number(assignedTo) : 0 }); setIssue(''); setAssignedTo(''); setStep(1); await loadQueue(); } catch (requestError) { fail(requestError.response?.data?.message || 'Ticket creation failed.'); } finally { setSaving(false); }
    };
    const updateTicket = async (event) => {
        event.preventDefault(); if (!selectedTicket || saving) return;
        if (selectedTicket.status === 'Closed' && !resolution.trim()) return fail('Please enter Resolution / Closing Notes before closing the ticket.');
        setSaving(true); setError('');
        const payload = { id: selectedTicket.id, status: selectedTicket.status, resolution_notes: resolution };
        if (Number(selectedTicket.assigned_to_user_id || 0) !== Number(selectedTicket.original_assigned_to_user_id || 0)) payload.assigned_to_user_id = Number(selectedTicket.assigned_to_user_id || 0);
        try { await api.put('/support/tickets.php', payload); setSelectedTicket(null); setResolution(''); await loadQueue(); } catch (requestError) { fail(requestError.response?.data?.message || 'Unable to update ticket.'); } finally { setSaving(false); }
    };
    const saveQuestion = async (event) => {
        event.preventDefault(); setSaving(true); setError('');
        try { await api({ method: questionForm.id ? 'put' : 'post', url: '/support/questions.php', data: questionForm }); setQuestionForm({ id: null, question: '', answer: '', display_order: 0, is_active: true }); await loadQuestions(); } catch (requestError) { fail(requestError.response?.data?.message || 'Unable to save question.'); } finally { setSaving(false); }
    };
    const deleteQuestion = async (id) => { try { await api.delete(`/support/questions.php?id=${id}`); await loadQuestions(); } catch (requestError) { fail(requestError.response?.data?.message || 'Unable to delete question.'); } };
    const openTicket = (ticket) => {
        const assignedId = Number(ticket.assigned_to_user_id || 0) || Number(users.find((user) =>
            String(user.employee_name || '').toLowerCase() === String(ticket.assigned_to || '').toLowerCase() ||
            String(user.username || '').toLowerCase() === String(ticket.assigned_to || '').toLowerCase()
        )?.user_id || 0);
        setSelectedTicket({ ...ticket, assigned_to_user_id: assignedId, original_assigned_to_user_id: assignedId, original_status: ticket.status });
        setResolution(ticket.resolution_notes || '');
    };

    return <div className="page-container">
        <div className="page-header"><div><h2><LifeBuoy size={22} style={{ verticalAlign: 'middle', marginRight: 8 }} />Support</h2><p className="page-subtitle">Verify customer, review guidance, and manage support tickets</p></div><button className="btn btn-outline" type="button" onClick={loadQueue}><RefreshCw size={16} /> Refresh Queue</button></div>
        {error && <div className="alert alert-danger">{error}</div>}
        <div className="customer-step-card card"><div className="customer-stepper"><div className={`customer-step ${step >= 1 ? 'active' : ''}`}><div className="step-circle">1</div><span>Customer Verification</span></div><div className={`step-line ${step >= 2 ? 'active-line' : ''}`} /><div className={`customer-step ${step >= 2 ? 'active' : ''}`}><div className="step-circle">2</div><span>Questions &amp; Answers</span></div><div className={`step-line ${step >= 3 ? 'active-line' : ''}`} /><div className={`customer-step ${step >= 3 ? 'active' : ''}`}><div className="step-circle">3</div><span>Raise Ticket</span></div></div></div>

        {step === 1 && <div className="card"><div className="customer-section-title"><ShieldQuestion size={20} /><h3>Customer Verification</h3></div><form onSubmit={verifyCustomer}><div className="customer-vehicle-grid">{[['mobile_no', 'Mobile No *'], ['vehicle_no', 'Vehicle No'], ['imei_no', 'IMEI No'], ['username', 'Username']].map(([name, label]) => <div className="form-group" key={name}><label className="form-label">{label}</label><input className="form-control" value={verification[name]} onChange={(event) => setVerification({ ...verification, [name]: event.target.value })} required={name === 'mobile_no'} /></div>)}</div><div className="customer-form-actions"><button className="btn btn-primary" disabled={verifying}>{verifying ? 'Verifying...' : 'Verify'}</button></div></form>{customer && <div className="card" style={{ marginTop: 20, background: '#f8fafc' }}><div className="alert alert-success"><CheckCircle2 size={16} /> Customer verified successfully.</div><div className="details-grid">{[['Username', customer.username], ['Mobile No', customer.mobile_no], ['IMEI No', customer.imei_no], ['Vehicle No', customer.vehicle_no], ['Expiry / Renewal Date', customer.expiry_date || '-'], ['Platform', customer.platform || '-']].map(([label, value]) => <div key={label}><strong>{label}:</strong> {value || '-'}</div>)}</div><div className="customer-form-actions"><button className="btn btn-primary" type="button" onClick={continueToQuestions}>Next</button></div></div>}</div>}

        {step === 2 && <div className="card"><div className="customer-section-title"><ShieldQuestion size={20} /><h3>Questions &amp; Answers</h3></div>{hasPermission('support.qa.view') ? questions.map((question) => <div key={question.id} className="card" style={{ marginBottom: 10, padding: 14 }}><button type="button" className="btn btn-outline" style={{ width: '100%', justifyContent: 'space-between' }} onClick={() => setExpandedQuestion(expandedQuestion === question.id ? null : question.id)}>{question.question}{expandedQuestion === question.id ? <ChevronDown size={16} /> : <ChevronRight size={16} />}</button>{expandedQuestion === question.id && <p style={{ margin: '12px 4px 0' }}>{question.answer}</p>}</div>) : <div className="alert alert-danger">You do not have permission to view Support Questions &amp; Answers.</div>}<p style={{ marginTop: 24, fontWeight: 600 }}>Still issue not clear?</p><button className="btn btn-primary" type="button" onClick={continueToTicket}>Next</button></div>}

        {step === 3 && <div className="card"><div className="customer-section-title"><LifeBuoy size={20} /><h3>Raise Ticket</h3></div><div className="details-grid" style={{ marginBottom: 20 }}><div><strong>Customer:</strong> {customer?.username}</div><div><strong>Mobile No:</strong> {customer?.mobile_no}</div><div><strong>IMEI No:</strong> {customer?.imei_no}</div><div><strong>Vehicle No:</strong> {customer?.vehicle_no}</div></div><form onSubmit={createTicket}><div className="form-group"><label className="form-label">Issue / Problem *</label><textarea className="form-control" rows="4" value={issue} onChange={(event) => setIssue(event.target.value)} required /></div><div className="customer-vehicle-grid"><div className="form-group"><label className="form-label">Priority</label><select className="form-control" value={priority} onChange={(event) => setPriority(event.target.value)}>{['Low', 'Medium', 'High', 'Urgent'].map((item) => <option key={item}>{item}</option>)}</select></div><div className="form-group"><label className="form-label">Assign Employee</label><select className="form-control" value={assignedTo} onChange={(event) => setAssignedTo(event.target.value)}><option value="">Unassigned</option>{users.map((user) => <option value={user.user_id} key={user.user_id}>{user.employee_name} ({user.username})</option>)}</select></div></div><button className="btn btn-primary" disabled={saving}>Create Ticket</button></form></div>}

        <div className="card" style={{ marginTop: 20 }}><div className="customer-section-title"><LifeBuoy size={20} /><h3>Support Task Queue</h3></div><div className="table-container"><table><thead><tr><th>Ticket ID</th><th>Customer</th><th>Mobile No</th><th>Issue</th><th>Priority</th><th>Assigned To</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>{tickets.length === 0 ? <tr><td colSpan="9" className="text-center">No support tickets found.</td></tr> : tickets.map((ticket) => <tr key={ticket.id}><td>{ticket.ticket_id}</td><td>{ticket.username}</td><td>{ticket.mobile_no}</td><td className="truncate-cell" title={ticket.issue}>{ticket.issue}</td><td>{ticket.priority}</td><td>{ticket.assigned_to || '-'}</td><td><span className={`badge ${ticket.status === 'Closed' ? 'badge-success' : ticket.status === 'In Progress' ? 'badge-warning' : 'badge-info'}`}>{ticket.status}</span></td><td>{ticket.created_at}</td><td><button className="icon-btn view" type="button" title="Update ticket" onClick={() => openTicket(ticket)}><Save size={16} /></button></td></tr>)}</tbody></table></div></div>

        {hasPermission('support.qa.manage') && <div className="card" style={{ marginTop: 20 }}><div className="customer-section-title"><ShieldQuestion size={20} /><h3>Manage Support Questions</h3></div><form onSubmit={saveQuestion}><div className="form-group"><label className="form-label">Question</label><input className="form-control" value={questionForm.question} onChange={(event) => setQuestionForm({ ...questionForm, question: event.target.value })} required /></div><div className="form-group"><label className="form-label">Answer</label><textarea className="form-control" rows="3" value={questionForm.answer} onChange={(event) => setQuestionForm({ ...questionForm, answer: event.target.value })} required /></div><button className="btn btn-primary" disabled={saving}>{questionForm.id ? 'Update Question' : 'Add Question'}</button></form><div className="table-container" style={{ marginTop: 16 }}><table><thead><tr><th>Question</th><th>Answer</th><th>Actions</th></tr></thead><tbody>{questions.map((question) => <tr key={question.id}><td>{question.question}</td><td>{question.answer}</td><td><button className="btn btn-outline" type="button" onClick={() => setQuestionForm({ ...question, is_active: Boolean(Number(question.is_active)) })}>Edit</button> <button className="btn btn-danger" type="button" onClick={() => deleteQuestion(question.id)}>Delete</button></td></tr>)}</tbody></table></div></div>}

        {selectedTicket && <div className="modal-overlay" onClick={() => setSelectedTicket(null)}><div className="modal-shell" onClick={(event) => event.stopPropagation()}><div className="modal-header"><h3>{selectedTicket.ticket_id}</h3><button className="close-btn" type="button" onClick={() => setSelectedTicket(null)}>&times;</button></div><form className="modal-body" onSubmit={updateTicket}><div className="details-grid" style={{ marginBottom: 16 }}><div><strong>Customer:</strong> {selectedTicket.username || '-'}</div><div><strong>Mobile No:</strong> {selectedTicket.mobile_no || '-'}</div><div><strong>IMEI No:</strong> {selectedTicket.imei_no || '-'}</div><div><strong>Vehicle No:</strong> {selectedTicket.vehicle_no || '-'}</div><div><strong>Platform:</strong> {selectedTicket.platform_name || '-'}</div><div><strong>Priority:</strong> {selectedTicket.priority || '-'}</div><div><strong>Assigned Employee:</strong> {selectedTicket.assigned_to || '-'}</div><div><strong>Created By:</strong> {selectedTicket.created_by || '-'}</div><div><strong>Created At:</strong> {selectedTicket.created_at || '-'}</div><div><strong>Updated At:</strong> {selectedTicket.updated_at || '-'}</div><div><strong>Closed By:</strong> {selectedTicket.closed_by || '-'}</div><div><strong>Closed At:</strong> {selectedTicket.closed_at || '-'}</div></div><div className="form-group"><label className="form-label">Issue</label><div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 180, overflowY: 'auto', padding: 12, border: '1px solid var(--border-color)', borderRadius: 6 }}>{selectedTicket.issue || '-'}</div></div><div className="form-group"><label className="form-label">Assigned Employee</label><select className="form-control" value={selectedTicket.assigned_to_user_id || ''} onChange={(event) => setSelectedTicket({ ...selectedTicket, assigned_to_user_id: event.target.value ? Number(event.target.value) : 0 })}><option value="">Unassigned</option>{users.map((user) => <option key={user.user_id} value={user.user_id}>{user.employee_name} ({user.username})</option>)}</select></div><div className="form-group"><label className="form-label">Status</label><select className="form-control" value={selectedTicket.status} onChange={(event) => setSelectedTicket({ ...selectedTicket, status: event.target.value })}>{statuses.map((item) => <option key={item}>{item}</option>)}</select></div><div className="form-group"><label className="form-label">Resolution / Closing Notes {selectedTicket.status === 'Closed' ? '*' : ''}</label><textarea className="form-control" rows="4" value={resolution} onChange={(event) => setResolution(event.target.value)} /></div><div className="modal-footer"><button className="btn btn-outline" type="button" onClick={() => setSelectedTicket(null)}>Close</button><button className="btn btn-primary" disabled={saving}>{saving ? 'Saving...' : 'Save Changes'}</button></div></form></div></div>}
    </div>;
};

export default SupportPage;
