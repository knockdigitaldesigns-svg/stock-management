import { useEffect, useMemo, useState } from 'react';
import { Eye, History, Pencil, RotateCcw, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import { showGlobalError } from '../../context/ErrorContext';
import { PAYMENT_MODES } from '../../constants/paymentModes';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const STATUSES = ['Active', 'Deactive', 'Expired', 'Safe Custody'];
const EMPTY_FILTERS = { search: '', date_from: '', date_to: '', validity: '', status: '' };
const EMPTY_FORM = { action: '', renewal_date: new Date().toISOString().slice(0, 10), reactivation_date: new Date().toISOString().slice(0, 10), validity_months: '', payment_amount: '', amount_paid: '0', payment_status: 'Not Paid', payment_mode: '', transaction_id: '', notes: '', expired_to_safe_days: '', safe_to_deactive_days: '', installation_date: '' };

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

    useEffect(() => { load(1, filters); }, [filters.search, filters.date_from, filters.date_to, filters.validity, filters.status]);

    const localYears = useMemo(() => [...new Set([...years, ...rows.flatMap((row) => [row.installation_date, row.next_renewal_date]).filter(Boolean).map((date) => Number(String(date).slice(0, 4)))])].sort((a, b) => b - a), [years, rows]);
    const pending = Math.max(0, Number(form.payment_amount || 0) - Number(form.amount_paid || 0));
    const paymentStatus = Number(form.amount_paid || 0) <= 0 ? 'Not Paid' : Number(form.amount_paid || 0) < Number(form.payment_amount || 0) ? 'Partially Paid' : 'Paid';
    const calculatedDate = useMemo(() => {
        if (!form.validity_months) return '-';
        const start = form.action === 'Reactivate SIM' ? form.reactivation_date : form.action === 'Renew SIM' ? form.renewal_date : form.installation_date;
        if (!start) return '-';
        const date = new Date(`${start}T00:00:00`);
        date.setMonth(date.getMonth() + Number(form.validity_months));
        return date.toISOString().slice(0, 10);
    }, [form.action, form.reactivation_date, form.renewal_date, form.installation_date, form.validity_months]);

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
            setForm({ ...EMPTY_FORM, validity_months: renewal.validity_months || '', action: '', expired_to_safe_days: renewal.expired_to_safe_days ?? '', safe_to_deactive_days: renewal.safe_to_deactive_days ?? '', installation_date: String(renewal.installation_date || '').slice(0, 10) });
            setModal('edit');
        } catch (error) { showGlobalError(error.response?.data?.message || 'Failed to load renewal for editing.'); }
    };
    const openHistory = async () => {
        try { const response = await api.get(`/renewals/history.php?renewal_id=${selected.id}`); setHistory(response.data?.data?.history || []); setModal('history'); }
        catch (error) { showGlobalError(error.response?.data?.message || 'Failed to load renewal history.'); }
    };
    const executeAction = async () => {
        if (!selected) return;
        if (form.expired_to_safe_days !== '' && (Number(form.expired_to_safe_days) < 0 || !Number.isInteger(Number(form.expired_to_safe_days)))) {
            showGlobalError('Expired → Safe Custody Days must be a non-negative integer.');
            return;
        }
        if (form.safe_to_deactive_days !== '' && (Number(form.safe_to_deactive_days) < 0 || !Number.isInteger(Number(form.safe_to_deactive_days)))) {
            showGlobalError('Safe Custody → Deactive Days must be a non-negative integer.');
            return;
        }
        if (form.action === 'Deactivate SIM' || form.action === 'Safe Custody') { setModal('confirm'); return; }
        await saveAction();
    };
    const closeActionModal = () => {
        setModal(null);
        setForm(EMPTY_FORM);
    };
    const updateDetail = (key, value) => setSelected((current) => ({ ...current, [key]: value }));
    const saveAction = async () => {
        if (form.action === 'Renew SIM') {
            if (form.payment_mode && (form.amount_paid === '' || form.amount_paid === null || form.amount_paid === undefined)) {
                showGlobalError('Please enter Amount Paid.');
                return;
            }
            if (form.amount_paid === '' || form.amount_paid === null || form.amount_paid === undefined) {
                showGlobalError('Please enter Amount Paid.');
                return;
            }
            if (Number(form.amount_paid) < 0) {
                showGlobalError('Amount Paid cannot be negative.');
                return;
            }
            if (Number(form.amount_paid) > Number(form.payment_amount || 0)) {
                showGlobalError('Amount Paid cannot exceed Total Amount.');
                return;
            }
            if (form.payment_mode && form.payment_mode !== 'Cash' && !form.transaction_id) {
                showGlobalError('Please enter Transaction ID.');
                return;
            }
        }
        try {
            setSaving(true);
            await api.post('/renewals/action.php', { ...form, action_type: form.action, renewal_id: selected.id, payment_amount: Number(form.payment_amount || 0), amount_paid: Number(form.amount_paid || 0), payment_status: paymentStatus, expired_to_safe_days: Number(form.expired_to_safe_days), safe_to_deactive_days: Number(form.safe_to_deactive_days), installation_date: form.installation_date });
            setModal(null);
            setForm(EMPTY_FORM);
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
    const renewalDetailFields = [
        ['Next Renewal Date', 'next_renewal_date', true],
        ['Validity', 'validity_months'],
        ['SIM Status', 'sim_status'],
        ...(selected?.last_renewed_date ? [['Last Renewed Date', 'last_renewed_date', true]] : [])
    ];

    return <div className="page-container"><div className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}><h2>Renewals</h2><button className="btn" type="button" onClick={() => load(pagination.page, filters)}><RotateCcw size={16} /> Refresh</button></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10, margin: '18px 0' }}>
            <input className="form-control" placeholder="Search username, mobile, IMEI, SIM, model" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} />
            <input className="form-control" type="date" value={filters.date_from || ''} onChange={(event) => {
                if (filters.date_to && event.target.value > filters.date_to) {
                    showGlobalError('From Date cannot be later than To Date.');
                    return;
                }
                updateFilter('date_from', event.target.value);
            }} aria-label="From Date" />
            <input className="form-control" type="date" value={filters.date_to || ''} onChange={(event) => {
                if (filters.date_from && event.target.value && event.target.value < filters.date_from) {
                    showGlobalError('From Date cannot be later than To Date.');
                    return;
                }
                updateFilter('date_to', event.target.value);
            }} aria-label="To Date" />
            <select className="form-control" value={filters.validity} onChange={(event) => updateFilter('validity', event.target.value)}><option value="">All Validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select>
            <select className="form-control" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}><option value="">All Status</option>{STATUSES.map((status) => <option key={status}>{status}</option>)}</select>
            <button className="btn" type="button" onClick={() => setFilters(EMPTY_FILTERS)}><RotateCcw size={16} /> Reset</button>
        </div>
        <div className="table-container"><table><thead><tr>{['Installation Date', 'Username', 'Mobile No', 'IMEI No', 'SIM No', 'Device Model', 'Next Renewal Date', 'Platform', 'Validity', 'SIM Status', 'Actions'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{loading ? <tr><td colSpan="11">Loading renewals...</td></tr> : rows.length === 0 ? <tr><td colSpan="11">No renewal records found.</td></tr> : rows.map((row) => <tr key={row.id}><td>{formatDate(row.installation_date)}</td><td>{display(row.username)}</td><td>{display(row.primary_mobile_no)}</td><td>{display(row.imei_no)}</td><td>{display(row.sim_no_1)}</td><td>{display(row.device_model)}</td><td>{formatDate(row.next_renewal_date)}</td><td>{display(row.platform_name)}</td><td>{display(row.validity_months)} Months</td><td><span className="badge">{display(row.sim_status)}</span></td><td><div style={{ display: 'flex', gap: 6 }}><button className="btn" type="button" title="View" onClick={() => openView(row)}><Eye size={16} /></button><button className="btn" type="button" title="Edit" onClick={() => openEdit(row)}><Pencil size={16} /></button><button className="btn btn-danger" type="button" title="Delete" onClick={() => { setDeleteTarget(row); setModal('delete'); }}><Trash2 size={16} /></button></div></td></tr>)}</tbody></table></div>
        <Pagination currentPage={pagination.page} totalItems={pagination.total} pageSize={pagination.page_size} onPageChange={(page) => load(page, filters)} onPageSizeChange={(size) => load(1, filters, size)} itemName="renewals" />
    </div>

    <Modal isOpen={modal === 'view'} onClose={() => setModal(null)} title="Renewal Details" maxWidth="900px" footer={<><button className="btn" type="button" onClick={openHistory}><History size={16} /> View History</button><button className="btn btn-primary" type="button" onClick={() => openEdit(selected)}>Edit</button></>}>
        {selected && <><InfoSection title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} /><InfoSection title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} /><InfoSection title="Installation Details" record={selected} fields={[['Installation Person', 'installation_person'], ['Installation Person Type', 'installation_person_type'], ['Lead Closure', 'lead_closure'], ['Installation Date', 'installation_date', true]]} /><InfoSection title="Renewal Details" record={selected} fields={renewalDetailFields} /></>}
    </Modal>

    <Modal isOpen={modal === 'history'} onClose={() => setModal('view')} title="Renewal History" maxWidth="1150px"><div className="table-container"><table><thead><tr>{['Date', 'Action', 'Old Status', 'New Status', 'Old Validity', 'New Validity', 'Old Renewal Date', 'New Renewal Date', 'Amount', 'Paid', 'Pending', 'Payment Mode', 'Transaction ID', 'Changed By'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{history.length ? history.map((item) => <tr key={item.id}><td>{formatDateTime(item.action_date)}</td><td>{item.action_type}</td><td>{display(item.old_status)}</td><td>{display(item.new_status)}</td><td>{display(item.old_validity_months)}</td><td>{display(item.new_validity_months)}</td><td>{formatDate(item.old_renewal_date)}</td><td>{formatDate(item.new_renewal_date)}</td><td>{display(item.payment_amount)}</td><td>{display(item.amount_paid)}</td><td>{display(item.amount_pending)}</td><td>{display(item.payment_mode)}</td><td>{display(item.transaction_id)}</td><td>{display(item.changed_by_name)}</td></tr>) : <tr><td colSpan="14">No history found.</td></tr>}</tbody></table></div></Modal>

    <Modal isOpen={modal === 'edit'} onClose={closeActionModal} title={`Renewal Action - ${selected?.username || ''}`} maxWidth="900px" footer={<><button className="btn" type="button" onClick={closeActionModal}>Cancel</button><button className="btn btn-primary" type="button" disabled={saving} onClick={executeAction}>{saving ? 'Saving...' : 'Save Action'}</button></>}>
        {selected && <><InfoSection inputStyle title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} /><InfoSection inputStyle title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} /><section style={{ marginBottom: 18 }}><h4 style={{ margin: '0 0 10px', borderBottom: '1px solid #e2e8f0', paddingBottom: 6 }}>Installation Details</h4><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}><div><strong>Installation Person</strong><input className="form-control" type="text" defaultValue={selected.installation_person || ''} readOnly /></div><div><strong>Installation Person Type</strong><input className="form-control" type="text" defaultValue={selected.installation_person_type || ''} readOnly /></div><div><strong>Lead Closure</strong><input className="form-control" type="text" defaultValue={selected.lead_closure || ''} readOnly /></div><div><strong>Installation Date</strong><input className="form-control" type="date" value={form.installation_date} onChange={(e) => setForm({ ...form, installation_date: e.target.value })} /></div></div>{!form.action && form.installation_date && form.validity_months && calculatedDate !== '-' && (<div style={{ marginTop: 10, padding: '8px 12px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, fontSize: 13, color: '#15803d' }}>📅 New Renewal Date: <strong>{formatDate(calculatedDate)}</strong> (installation date + {form.validity_months} months)</div>)}</section><InfoSection inputStyle title="Current Renewal Details" record={selected} fields={renewalDetailFields} /></>}
        <div className="renewal-actions" role="group" aria-label="Renewal actions" style={{ marginBottom: 18 }}><h4>Actions</h4><div className="renewal-action-options" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>{availableActions.map((action) => <label className="renewal-action-option" key={action} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}><input type="checkbox" checked={form.action === action} onChange={() => setForm((current) => ({ ...current, action: current.action === action ? '' : action }))} /><span>{action}</span></label>)}</div></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 12 }}>
            {form.action === 'Reactivate SIM' ? <><Field label="Actual Reactivation Date *"><input className="form-control" type="date" value={form.reactivation_date} onChange={(event) => setForm({ ...form, reactivation_date: event.target.value })} /></Field><Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select></Field><Field label="Next Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field></> : form.action === 'Renew SIM' ? <><Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{validity} Months</option>)}</select></Field><Field label="Renewal Date"><input className="form-control" type="date" value={form.renewal_date} onChange={(event) => setForm({ ...form, renewal_date: event.target.value })} /></Field><Field label="Next Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field><Field label="Total Amount *"><input className="form-control" type="number" min="0" value={form.payment_amount} onChange={(event) => setForm({ ...form, payment_amount: event.target.value })} /></Field><Field label="Amount Paid *"><input className="form-control" type="number" min="0" value={form.amount_paid} onChange={(event) => setForm({ ...form, amount_paid: event.target.value })} /></Field><Field label="Pending Amount"><input className="form-control" readOnly value={pending} /></Field><Field label="Payment Status *"><input className="form-control" readOnly value={paymentStatus} /></Field><Field label="Payment Mode *"><select className="form-control" value={form.payment_mode} onChange={(event) => setForm({ ...form, payment_mode: event.target.value })}><option value="">Select mode</option><option>Cash</option><option>UPI</option><option>Card</option><option>Bank Transfer</option></select></Field><Field label="Transaction ID *"><input className="form-control" value={form.transaction_id} onChange={(event) => setForm({ ...form, transaction_id: event.target.value })} /></Field></> : <div style={{ gridColumn: '1 / -1' }}>Select the action to continue.</div>}
        </div>
    </Modal>
    <Modal isOpen={modal === 'delete'} onClose={() => setModal(null)} title="Delete Renewal" maxWidth="460px" footer={<><button className="btn" type="button" onClick={() => setModal(null)}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={deleteRenewal}>{saving ? 'Deleting...' : 'Delete'}</button></>}><p>Are you sure you want to delete this renewal record?</p></Modal>
    <Modal isOpen={modal === 'confirm'} onClose={() => setModal('edit')} title={form.action}><p>Are you sure you want to {form.action === 'Deactivate SIM' ? 'deactivate this SIM' : 'move this SIM to Safe Custody'}?</p><div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}><button className="btn" type="button" onClick={() => setModal('edit')}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={saveAction}>Confirm</button></div></Modal>
    </div>;
};

export default RenewalsPage;