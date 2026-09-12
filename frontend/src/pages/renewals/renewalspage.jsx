import { useEffect, useMemo, useState } from 'react';
import { Eye, History, Pencil, RotateCcw, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import { showGlobalError } from '../../context/ErrorContext';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const STATUSES = ['Active', 'Deactive', 'Expired', 'Safe Custody'];
const EMPTY_FILTERS = { search: '', date: '', month: '', year: '', validity: '', status: '' };
const EMPTY_FORM = { action: 'Renew SIM', renewal_date: new Date().toISOString().slice(0, 10), reactivation_date: new Date().toISOString().slice(0, 10), validity_months: '', payment_amount: '', amount_paid: '', payment_status: 'Not Paid', payment_mode: '', transaction_id: '', notes: '', expired_to_safe_days: '', safe_to_deactive_days: '' };

const display = (value) => value === null || value === undefined || value === '' ? '-' : value;
const formatDate = (value) => value ? String(value).slice(0, 10).split('-').reverse().join('-') : '-';
const formatDateTime = (value) => value ? `${formatDate(value)} ${String(value).slice(11, 19)}` : '-';
const Field = ({ label, children }) => <label className="form-group"><span className="form-label">{label}</span>{children}</label>;
const InfoSection = ({ title, fields, record, inputStyle = false, onChange }) => <section style={{ marginBottom: 18 }}><h4 style={{ margin: '0 0 10px', borderBottom: '1px solid #e2e8f0', paddingBottom: 6 }}>{title}</h4><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>{fields.map(([label, key, isDate]) => { const rawValue = isDate ? formatDate(record?.[key]) : `${display(record?.[key])}${key === 'validity_months' ? ' Months' : ''}`; const inputValue = isDate ? String(record?.[key] || '').slice(0, 10) : String(record?.[key] ?? ''); return <div key={key}><strong>{label}</strong>{inputStyle ? <input className="form-control" type={isDate ? 'date' : 'text'} defaultValue={inputValue} readOnly={false} onChange={(event) => onChange ? onChange(key, event.target.value) : (record[key] = event.target.value)} /> : <div>{rawValue}</div>}</div>; })}</div></section>;

const RenewalsPage = () => {
    const [rows, setRows] = useState([]);
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [validities, setValidities] = useState([]);
    const [years, setYears] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, page_size: 10, total: 0 });
    const [loading, setLoading] = useState(true);
    const [modal, setModal] = useState(null);
    const [selected, setSelected] = useState(null);
    const [history, setHistory] = useState([]);
    const [form, setForm] = useState(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);

    const load = async (page = 1, nextFilters = filters, pageSize = pagination.page_size) => {
        try {
            setLoading(true);
            const params = { page, page_size: pageSize, ...Object.fromEntries(Object.entries(nextFilters).filter(([, value]) => value !== '')) };
            const response = await api.get('/renewals/list.php', { params });
            const data = response.data?.data || {};
            setRows(Array.isArray(data.renewals) ? data.renewals : []);
            setPagination(data.pagination || { page, page_size: pageSize, total: 0 });
            setValidities(Array.isArray(data.validities) ? data.validities : []);
            setYears(Array.isArray(data.years) ? data.years : []);
        } catch (error) {
            showGlobalError(error.response?.data?.message || error.message || 'Failed to load renewals.');
        } finally { setLoading(false); }
    };

    useEffect(() => { load(1, filters); }, [filters.search, filters.date, filters.month, filters.year, filters.validity, filters.status]);

    const localYears = useMemo(() => [...new Set([...years, ...rows.flatMap((row) => [row.installation_date, row.next_renewal_date]).filter(Boolean).map((date) => Number(String(date).slice(0, 4)))])].sort((a, b) => b - a), [years, rows]);
    const pending = Math.max(0, Number(form.payment_amount || 0) - Number(form.amount_paid || 0));
    const paymentStatus = Number(form.amount_paid || 0) <= 0 ? 'Not Paid' : Number(form.amount_paid || 0) < Number(form.payment_amount || 0) ? 'Partially Paid' : 'Paid';
    const calculatedDate = useMemo(() => {
        if (!form.validity_months) return '-';
        const start = form.action === 'Reactivate SIM' ? form.reactivation_date : form.renewal_date;
        if (!start) return '-';
        const date = new Date(`${start}T00:00:00`);
        date.setMonth(date.getMonth() + Number(form.validity_months));
        return date.toISOString().slice(0, 10);
    }, [form.action, form.reactivation_date, form.renewal_date, form.validity_months]);

    const updateFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
    const openView = async (row) => {
        try { const response = await api.get(`/renewals/view.php?renewal_id=${row.id}`); setSelected(response.data?.data?.renewal || row); setModal('view'); }
        catch (error) { showGlobalError(error.response?.data?.message || 'Failed to load renewal details.'); }
    };
    const openEdit = async (row) => {
        try {
            const response = await api.get(`/renewals/view.php?renewal_id=${row.id}`);
            const renewal = response.data?.data?.renewal || row;
            setSelected(renewal);
            setForm({ ...EMPTY_FORM, validity_months: renewal.validity_months || '', action: renewal.sim_status === 'Deactive' ? 'Reactivate SIM' : 'Renew SIM', expired_to_safe_days: renewal.expired_to_safe_days ?? '', safe_to_deactive_days: renewal.safe_to_deactive_days ?? '' });
            setModal('edit');
        } catch (error) { showGlobalError(error.response?.data?.message || 'Failed to load renewal for editing.'); }
    };
    const openHistory = async () => {
        try { const response = await api.get(`/renewals/history.php?renewal_id=${selected.id}`); setHistory(response.data?.data?.history || []); setModal('history'); }
        catch (error) { showGlobalError(error.response?.data?.message || 'Failed to load renewal history.'); }
    };
    const executeAction = async () => {
        if (!selected) return;
        if (form.action === 'Deactivate SIM' || form.action === 'Safe Custody') { setModal('confirm'); return; }
        await saveAction();
    };
    const updateDetail = (key, value) => setSelected((current) => ({ ...current, [key]: value }));
    const saveExistingDetails = async () => {
        const customerId = Number(selected.customer_record_id || selected.customer_id);
        await api.put('/customers/update.php', {
            id: customerId,
            platform_id: Number(selected.platform_id || 0),
            username: String(selected.username || '').trim(),
            primary_mobile_no: String(selected.primary_mobile_no || '').trim(),
            secondary_mobile_no: String(selected.secondary_mobile_no || '').trim(),
            email: String(selected.email || '').trim(),
            location: String(selected.location || '').trim(),
            pincode: String(selected.pincode || '').trim(),
            status: selected.customer_status || 'Active'
        });
        await api.put('/customers/vehicle_details/update.php', {
            id: Number(selected.vehicle_record_id || 0),
            customer_id: customerId,
            vehicle_no: String(selected.vehicle_no || '').trim(),
            vehicle_type_id: Number(selected.vehicle_type_id || 0),
            device_model_id: Number(selected.device_model_id || 0),
            imei_no: String(selected.imei_no || '').trim(),
            sim_no_1: String(selected.sim_no_1 || '').trim(),
            sim_no_2: String(selected.sim_no_2 || '').trim(),
            validity_id: Number(selected.validity_id || 0)
        });
        await api.post('/customers/installations/create.php', {
            customer_id: customerId,
            installation_person_type: selected.installation_person_type || 'Technician',
            installation_person_id: Number(selected.installation_person_id || 0),
            lead_closure_id: Number(selected.lead_closure_id || 0),
            installation_date: String(selected.installation_date || '').slice(0, 10)
        });
    };
    const saveAction = async () => {
        try {
            setSaving(true);
            await saveExistingDetails();
            await api.post('/renewals/action.php', { ...form, action_type: form.action, renewal_id: selected.id, payment_amount: Number(form.payment_amount || 0), amount_paid: Number(form.amount_paid || 0), payment_status: paymentStatus, expired_to_safe_days: Number(form.expired_to_safe_days), safe_to_deactive_days: Number(form.safe_to_deactive_days) });
            setModal(null);
            await load(pagination.page, filters);
        } catch (error) { showGlobalError(error.response?.data?.message || error.message || 'Renewal action failed.'); }
        finally { setSaving(false); }
    };
    const deleteRenewal = async () => {
        try {
            setSaving(true);
            await api.delete(`/renewals/delete.php?id=${deleteTarget.id}`);
            setDeleteTarget(null);
            setModal(null);
            await load(Math.min(pagination.page, Math.max(1, Math.ceil((pagination.total - 1) / pagination.page_size))), filters);
        } catch (error) { showGlobalError(error.response?.data?.message || error.message || 'Failed to delete renewal.'); }
        finally { setSaving(false); }
    };
    const availableActions = selected?.sim_status === 'Deactive'
        ? ['Reactivate SIM']
        : selected?.sim_status === 'Safe Custody'
            ? ['Renew SIM', 'Deactivate SIM']
            : ['Renew SIM', 'Deactivate SIM', 'Safe Custody'];

    return <div className="page-container"><div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}><h2>Renewals</h2><button className="btn" type="button" onClick={() => load(pagination.page, filters)}><RotateCcw size={16} /> Refresh</button></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10, margin: '18px 0' }}>
            <input className="form-control" placeholder="Search username, mobile, IMEI, SIM, model" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} />
            <input className="form-control" type="date" value={filters.date} onChange={(event) => updateFilter('date', event.target.value)} />
            <select className="form-control" value={filters.month} onChange={(event) => updateFilter('month', event.target.value)}><option value="">All Months</option>{MONTHS.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}</select>
            <select className="form-control" value={filters.year} onChange={(event) => updateFilter('year', event.target.value)}><option value="">All Years</option>{localYears.map((year) => <option key={year} value={year}>{year}</option>)}</select>
            <select className="form-control" value={filters.validity} onChange={(event) => updateFilter('validity', event.target.value)}><option value="">All Validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select>
            <select className="form-control" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">All Status</option>{STATUSES.map((status) => <option key={status}>{status}</option>)}</select>
            <button className="btn" type="button" onClick={() => setFilters(EMPTY_FILTERS)}><RotateCcw size={16} /> Reset</button>
        </div>
        <div className="table-container"><table><thead><tr>{['Installation Date', 'Username', 'Mobile No', 'IMEI No', 'SIM No', 'Device Model', 'Next Renewal Date', 'Platform', 'Validity', 'SIM Status', 'Actions'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{loading ? <tr><td colSpan="11">Loading renewals...</td></tr> : rows.length === 0 ? <tr><td colSpan="11">No renewal records found.</td></tr> : rows.map((row) => <tr key={row.id}><td>{formatDate(row.installation_date)}</td><td>{display(row.username)}</td><td>{display(row.primary_mobile_no)}</td><td>{display(row.imei_no)}</td><td>{display(row.sim_no_1)}</td><td>{display(row.device_model)}</td><td>{formatDate(row.next_renewal_date)}</td><td>{display(row.platform_name)}</td><td>{display(row.validity_months)} Months</td><td><span className="badge">{display(row.sim_status)}</span></td><td><div style={{ display: 'flex', gap: 6 }}><button className="btn" type="button" title="View" onClick={() => openView(row)}><Eye size={16} /></button><button className="btn" type="button" title="Edit" onClick={() => openEdit(row)}><Pencil size={16} /></button><button className="btn btn-danger" type="button" title="Delete" onClick={() => { setDeleteTarget(row); setModal('delete'); }}><Trash2 size={16} /></button></div></td></tr>)}</tbody></table></div>
        <Pagination currentPage={pagination.page} totalItems={pagination.total} pageSize={pagination.page_size} onPageChange={(page) => load(page, filters)} onPageSizeChange={(size) => load(1, filters, size)} itemName="renewals" />
    </div>

    <Modal isOpen={modal === 'view'} onClose={() => setModal(null)} title="Renewal Details" maxWidth="900px" footer={<><button className="btn" type="button" onClick={openHistory}><History size={16} /> View History</button><button className="btn btn-primary" type="button" onClick={() => openEdit(selected)}>Edit</button></>}>
        {selected && <><InfoSection title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} /><InfoSection title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} /><InfoSection title="Installation Details" record={selected} fields={[['Installation Person', 'installation_person'], ['Installation Person Type', 'installation_person_type'], ['Lead Closure', 'lead_closure'], ['Installation Date', 'installation_date', true]]} /><InfoSection title="Renewal Details" record={selected} fields={[['Next Renewal Date', 'next_renewal_date', true], ['Validity', 'validity_months'], ['SIM Status', 'sim_status'], ['Last Renewed Date', 'last_renewed_date', true]]} /><InfoSection title="SIM Lifecycle Settings" record={selected} fields={[['Expired -> Safe Custody After', 'expired_to_safe_days'], ['Safe Custody -> Deactive After', 'safe_to_deactive_days']]} /></>}
    </Modal>

    <Modal isOpen={modal === 'history'} onClose={() => setModal('view')} title="Renewal History" maxWidth="1150px"><div className="table-container"><table><thead><tr>{['Date', 'Action', 'Old Status', 'New Status', 'Old Validity', 'New Validity', 'Old Renewal Date', 'New Renewal Date', 'Amount', 'Paid', 'Pending', 'Payment Mode', 'Transaction ID', 'Changed By'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{history.length ? history.map((item) => <tr key={item.id}><td>{formatDateTime(item.action_date)}</td><td>{item.action_type}</td><td>{display(item.old_status)}</td><td>{display(item.new_status)}</td><td>{display(item.old_validity_months)}</td><td>{display(item.new_validity_months)}</td><td>{formatDate(item.old_renewal_date)}</td><td>{formatDate(item.new_renewal_date)}</td><td>{display(item.payment_amount)}</td><td>{display(item.amount_paid)}</td><td>{display(item.amount_pending)}</td><td>{display(item.payment_mode)}</td><td>{display(item.transaction_id)}</td><td>{display(item.changed_by_name)}</td></tr>) : <tr><td colSpan="14">No history found.</td></tr>}</tbody></table></div></Modal>

    <Modal isOpen={modal === 'edit'} onClose={() => setModal(null)} title={`Renewal Action - ${selected?.username || ''}`} maxWidth="900px" footer={<><button className="btn" type="button" onClick={() => setModal(null)}>Cancel</button><button className="btn btn-primary" type="button" disabled={saving} onClick={executeAction}>{saving ? 'Saving...' : 'Save Action'}</button></>}>
        {selected && <><InfoSection inputStyle title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} /><InfoSection inputStyle title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} /><InfoSection inputStyle title="Installation Details" record={selected} fields={[['Installation Person', 'installation_person'], ['Installation Person Type', 'installation_person_type'], ['Lead Closure', 'lead_closure'], ['Installation Date', 'installation_date', true]]} /><InfoSection inputStyle title="Current Renewal Details" record={selected} fields={[['Next Renewal Date', 'next_renewal_date', true], ['Validity', 'validity_months'], ['SIM Status', 'sim_status'], ['Last Renewed Date', 'last_renewed_date', true]]} /><section style={{ marginBottom: 18 }}><h4>SIM Lifecycle Settings</h4><div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 12 }}><Field label="Expired -> Safe Custody After (days)"><input className="form-control" type="number" min="0" step="1" value={form.expired_to_safe_days} onChange={(event) => setForm({ ...form, expired_to_safe_days: event.target.value })} /></Field><Field label="Safe Custody -> Deactive After (days)"><input className="form-control" type="number" min="0" step="1" value={form.safe_to_deactive_days} onChange={(event) => setForm({ ...form, safe_to_deactive_days: event.target.value })} /></Field></div></section></>}
        <div style={{ marginBottom: 18 }}><h4>Actions</h4><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10 }}>{availableActions.map((action) => <button key={action} type="button" className={`btn ${form.action === action ? 'btn-primary' : ''}`} onClick={() => setForm((current) => ({ ...current, action }))}>[ ] {action}</button>)}</div></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 12 }}>
            {form.action === 'Reactivate SIM' ? <><Field label="Actual Reactivation Date *"><input className="form-control" type="date" value={form.reactivation_date} onChange={(event) => setForm({ ...form, reactivation_date: event.target.value })} /></Field><Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select></Field><Field label="Next Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field></> : form.action === 'Renew SIM' ? <><Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select></Field><Field label="Renewal Date"><input className="form-control" type="date" value={form.renewal_date} onChange={(event) => setForm({ ...form, renewal_date: event.target.value })} /></Field><Field label="Next Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field><Field label="Total Amount *"><input className="form-control" type="number" min="0" value={form.payment_amount} onChange={(event) => setForm({ ...form, payment_amount: event.target.value })} /></Field><Field label="Amount Paid *"><input className="form-control" type="number" min="0" value={form.amount_paid} onChange={(event) => setForm({ ...form, amount_paid: event.target.value })} /></Field><Field label="Pending Amount"><input className="form-control" readOnly value={pending} /></Field><Field label="Payment Status *"><input className="form-control" readOnly value={paymentStatus} /></Field><Field label="Payment Mode *"><select className="form-control" value={form.payment_mode} onChange={(event) => setForm({ ...form, payment_mode: event.target.value })}><option value="">Select mode</option><option>Cash</option><option>UPI</option><option>Card</option><option>Bank Transfer</option></select></Field><Field label="Transaction ID *"><input className="form-control" value={form.transaction_id} onChange={(event) => setForm({ ...form, transaction_id: event.target.value })} /></Field></> : <div style={{ gridColumn: '1 / -1' }}>Select the action to continue.</div>}
        </div>
    </Modal>
    <Modal isOpen={modal === 'delete'} onClose={() => setModal(null)} title="Delete Renewal" maxWidth="460px" footer={<><button className="btn" type="button" onClick={() => setModal(null)}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={deleteRenewal}>{saving ? 'Deleting...' : 'Delete'}</button></>}><p>Are you sure you want to delete this renewal record?</p></Modal>
    <Modal isOpen={modal === 'confirm'} onClose={() => setModal('edit')} title={form.action}><p>Are you sure you want to {form.action === 'Deactivate SIM' ? 'deactivate this SIM' : 'move this SIM to Safe Custody'}?</p><div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}><button className="btn" type="button" onClick={() => setModal('edit')}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={saveAction}>Confirm</button></div></Modal>
    </div>;
};

export default RenewalsPage;