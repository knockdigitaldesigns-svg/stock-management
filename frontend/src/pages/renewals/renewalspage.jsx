import { useEffect, useMemo, useState } from 'react';
import { Eye, History, Pencil, RotateCcw, Trash2, Search } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import { showGlobalError } from '../../context/ErrorContext';
import { PAYMENT_MODES } from '../../constants/paymentModes';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const STATUSES = ['Active', 'Deactive', 'Expired', 'Safe Custody'];
const EMPTY_FILTERS = { search: '', date_from: '', date_to: '', validity: '', status: '' };
const EMPTY_FORM = { action: '', action_date: '', reactivation_date: '', validity_months: '', payment_amount: '', amount_paid: '0', payment_status: 'Not Paid', payment_mode: '', payment_date: '', transaction_id: '', notes: '', expired_to_safe_days: '', safe_to_deactive_days: '', installation_date: '' };

const display = (value) => value === null || value === undefined || value === '' ? '-' : value;
const formatDate = (value) => value ? String(value).slice(0, 10).split('-').reverse().join('-') : '-';
const formatDateTime = (value) => value ? `${formatDate(value)} ${String(value).slice(11, 19)}` : '-';
const formatValidity = (value) => `${value} ${Number(value) === 1 ? 'Month' : 'Months'}`;
const localDateToday = () => {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const addCalendarMonths = (value, months) => {
    if (!value || !months) return '-';
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    if (!year || !month || !day) return '-';
    const targetMonth = month - 1 + Number(months);
    const targetYear = year + Math.floor(targetMonth / 12);
    const normalizedMonth = (targetMonth % 12) + 1;
    const lastDay = new Date(Date.UTC(targetYear, normalizedMonth, 0)).getUTCDate();
    const normalizedDay = Math.min(day, lastDay);
    return `${targetYear}-${String(normalizedMonth).padStart(2, '0')}-${String(normalizedDay).padStart(2, '0')}`;
};
const Field = ({ label, children }) => <label className="form-group"><span className="form-label">{label}</span>{children}</label>;
const ModalSection = ({ title, children }) => (
    <section style={{ marginBottom: 16, padding: 16, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}>
        <h4 style={{ margin: '0 0 14px', paddingBottom: 9, borderBottom: '1px solid #e2e8f0', color: '#17243b', fontSize: 14, fontWeight: 700 }}>{title}</h4>
        {children}
    </section>
);
const ModalGrid = ({ children }) => (
    <div className="renewal-form-grid">
        {children}
    </div>
);
const InfoSection = ({ title, fields, record, inputStyle = false, onChange }) => <section style={{ marginBottom: 16, padding: 16, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}><h4 style={{ margin: '0 0 14px', borderBottom: '1px solid #e2e8f0', paddingBottom: 9, color: '#17243b', fontSize: 14, fontWeight: 700 }}>{title}</h4><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>{fields.map(([label, key, isDate]) => { const rawValue = isDate ? formatDate(record?.[key]) : key === 'validity_months' && record?.[key] ? formatValidity(record[key]) : display(record?.[key]); const inputValue = isDate ? String(record?.[key] || '').slice(0, 10) : String(record?.[key] ?? ''); const handleChange = (value) => onChange ? onChange(key, value) : (record[key] = value); return <div key={key}><strong>{label}</strong>{inputStyle ? isDate ? <input className="form-control" type="date" defaultValue={inputValue} readOnly={false} onChange={(event) => handleChange(event.target.value)} /> : <input className="form-control" type="text" defaultValue={inputValue} readOnly={false} onChange={(event) => handleChange(event.target.value)} /> : <div>{rawValue}</div>}</div>; })}</div></section>;

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
    const [closeOldRenewalPayment, setCloseOldRenewalPayment] = useState(false);
    const [oldRenewalPayment, setOldRenewalPayment] = useState({
        amount_paid: '',
        payment_mode: '',
        payment_date: '',
        transaction_id: ''
    });

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
    const oldRenewalPending = Math.max(0, Number(selected?.total_amount_pending || 0));
    const oldRenewalRemaining = Math.max(0, oldRenewalPending - Number(oldRenewalPayment.amount_paid || 0));
    const calculatedDate = useMemo(() => {
        if (!form.validity_months) return '-';
        const start = ['Renew SIM', 'Reactivate SIM'].includes(form.action)
            ? selected?.next_renewal_date
            : form.action === ''
                ? form.installation_date
                : '';
        return addCalendarMonths(start, Number(form.validity_months));
    }, [form.action, form.installation_date, form.validity_months, selected?.next_renewal_date]);

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
            setForm({ ...EMPTY_FORM, action_date: localDateToday(), payment_date: localDateToday(), validity_months: renewal.validity_months || '', action: '', expired_to_safe_days: renewal.expired_to_safe_days ?? '', safe_to_deactive_days: renewal.safe_to_deactive_days ?? '', installation_date: String(renewal.installation_date || '').slice(0, 10) });
            setCloseOldRenewalPayment(false);
            setOldRenewalPayment({ amount_paid: '', payment_mode: '', payment_date: localDateToday(), transaction_id: '' });
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
        if (form.action === 'Safe Custody' && !form.action_date) {
            showGlobalError('Please select a Safe Custody Date.');
            return;
        }
        if (form.action === 'Deactivate SIM' || form.action === 'Safe Custody') { setModal('confirm'); return; }
        await saveAction();
    };
    const closeActionModal = () => {
        setModal(null);
        setForm(EMPTY_FORM);
        setCloseOldRenewalPayment(false);
        setOldRenewalPayment({ amount_paid: '', payment_mode: '', payment_date: '', transaction_id: '' });
    };
    const updateDetail = (key, value) => setSelected((current) => ({ ...current, [key]: value }));
    const saveAction = async () => {
        if (['Renew SIM', 'Reactivate SIM'].includes(form.action) && !selected?.next_renewal_date) {
            showGlobalError('The saved Next Renewal Date is missing. Renewal cannot be completed.');
            return;
        }
        if (['Renew SIM', 'Reactivate SIM'].includes(form.action) && !form.validity_months) {
            showGlobalError('Please select a Validity.');
            return;
        }
        if (['Renew SIM', 'Safe Custody'].includes(form.action) && !form.action_date) {
            showGlobalError('Please select the actual action date.');
            return;
        }
        if (form.action === 'Reactivate SIM' && !form.reactivation_date) {
            showGlobalError('Please select an Actual Reactivation Date.');
            return;
        }
        if (['Renew SIM', 'Reactivate SIM'].includes(form.action)) {
            if (!Number.isFinite(Number(form.payment_amount)) || Number(form.payment_amount) <= 0) {
                showGlobalError(`Please enter a ${form.action === 'Renew SIM' ? 'Renewal' : 'Reactivation'} Total Amount greater than zero.`);
                return;
            }
            if (form.amount_paid === '' || form.amount_paid === null || form.amount_paid === undefined) {
                showGlobalError('Please enter Amount Paid.');
                return;
            }
            if (!Number.isFinite(Number(form.amount_paid)) || Number(form.amount_paid) < 0) {
                showGlobalError('Amount Paid cannot be negative.');
                return;
            }
            if (Number(form.amount_paid) > Number(form.payment_amount || 0)) {
                showGlobalError('Amount Paid cannot exceed Total Amount.');
                return;
            }
            if (Number(form.amount_paid) > 0 && !form.payment_mode) {
                showGlobalError('Please select a Payment Mode.');
                return;
            }
            if (Number(form.amount_paid) > 0 && !form.payment_date) {
                showGlobalError('Please select the Payment Date.');
                return;
            }
            if (Number(form.amount_paid) > 0 && form.payment_mode !== 'Cash' && !form.transaction_id) {
                showGlobalError('Please enter Transaction ID.');
                return;
            }
            if (form.transaction_id && !/^[0-9]{6}$/.test(form.transaction_id)) {
                showGlobalError('Transaction ID must contain exactly 6 digits.');
                return;
            }
        }
        try {
            setSaving(true);
            await api.post('/renewals/action.php', {
                ...form,
                action_type: form.action,
                renewal_id: selected.id,
                payment_amount: Number(form.payment_amount || 0),
                amount_paid: Number(form.amount_paid || 0),
                payment_status: paymentStatus,
                expired_to_safe_days: Number(form.expired_to_safe_days),
                safe_to_deactive_days: Number(form.safe_to_deactive_days),
                installation_date: form.installation_date
            });
            setModal(null);
            setForm(EMPTY_FORM);
            await load(pagination.page, filters);
        } catch (error) { showGlobalError(error.response?.data?.message || error.message || 'Renewal action failed.'); }
        finally { setSaving(false); }
    };
    const recordOldRenewalPayment = async () => {
        if (!selected) return;
        const outstanding = Number(selected.total_amount_pending || 0);
        const amount = Number(oldRenewalPayment.amount_paid);
        if (!Number.isFinite(amount) || amount <= 0) {
            showGlobalError('Enter an old renewal payment greater than zero.');
            return;
        }
        if (amount > outstanding) {
            showGlobalError(`Old renewal payment cannot exceed the outstanding balance of ₹${outstanding.toFixed(2)}.`);
            return;
        }
        if (!oldRenewalPayment.payment_mode) {
            showGlobalError('Please select a Payment Mode for the old renewal payment.');
            return;
        }
        if (!oldRenewalPayment.payment_date) {
            showGlobalError('Please select a Payment Date for the old renewal payment.');
            return;
        }
        if (oldRenewalPayment.payment_mode !== 'Cash' && !/^[0-9]{6}$/.test(oldRenewalPayment.transaction_id)) {
            showGlobalError('The old renewal Transaction ID must contain exactly 6 digits.');
            return;
        }
        try {
            setSaving(true);
            await api.post('/renewals/action.php', {
                renewal_id: selected.id,
                action_type: 'Renewal Payment',
                previous_pending_payment: oldRenewalPayment
            });
            setCloseOldRenewalPayment(false);
            setOldRenewalPayment({ amount_paid: '', payment_mode: '', payment_date: localDateToday(), transaction_id: '' });
            try {
                const response = await api.get(`/renewals/view.php?renewal_id=${selected.id}`);
                const updatedRenewal = response.data?.data?.renewal;
                if (!updatedRenewal) throw new Error('Renewal details were not returned.');
                setSelected(updatedRenewal);
            } catch (refreshError) {
                showGlobalError(`Old payment was saved, but renewal details could not be refreshed: ${refreshError.response?.data?.message || refreshError.message}`);
            }
            await load(pagination.page, filters);
        } catch (error) {
            showGlobalError(error.response?.data?.message || error.message || 'Failed to record old renewal payment.');
        } finally {
            setSaving(false);
        }
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
    const normalizedStatus = String(selected?.sim_status || '').trim().toLowerCase().replace(/[\s_-]/g, '');
    const availableActions = ['deactive', 'deactivated'].includes(normalizedStatus)
        ? ['Reactivate SIM']
        : normalizedStatus === 'safecustody'
            ? ['Renew SIM', 'Deactivate SIM']
            : ['active', 'expired'].includes(normalizedStatus)
                ? ['Renew SIM', 'Deactivate SIM', 'Safe Custody']
                : [];
    const renewalDetailFields = [
        ['Next Renewal Date', 'next_renewal_date', true],
        ['Validity', 'validity_months'],
        ['SIM Status', 'sim_status'],
        ...(selected?.safe_custody_date ? [['Safe Custody Date', 'safe_custody_date', true]] : []),
        ...(selected?.reactivation_date ? [['Reactivation Date', 'reactivation_date', true]] : []),
        ...(selected?.last_renewed_date ? [['Last Renewed Date', 'last_renewed_date', true]] : [])
    ];

    return <div className="page-container"><div className="card">
        <div className="page-header"><div><h2>Renewals</h2><p className="page-subtitle">Review upcoming SIM renewals and manage lifecycle actions.</p></div><button className="btn" type="button" onClick={() => load(pagination.page, filters)}><RotateCcw size={16} /> Refresh</button></div>
        <div className="table-filter-card card" style={{ padding: '20px 22px', marginBottom: '20px' }}>
            <div className="table-filter-grid">
                
                {/* SEARCH */}
                <div className="filter-group">
                    <label className="filter-label">Search</label>
                    <div className="search-input-wrapper">
                        <Search size={16} className="search-icon" />
                        <input className="form-control filter-input has-icon table-filter-search" placeholder="Search username, mobile, IMEI, SIM, model" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} />
                    </div>
                </div>

                {/* DATE FROM */}
                <div className="filter-group">
                    <label className="filter-label">From Date</label>
                    <input className="form-control filter-input" type="date" value={filters.date_from || ''} onChange={(event) => {
                        if (filters.date_to && event.target.value > filters.date_to) {
                            showGlobalError('From Date cannot be later than To Date.');
                            return;
                        }
                        updateFilter('date_from', event.target.value);
                    }} aria-label="From Date" />
                </div>

                {/* DATE TO */}
                <div className="filter-group">
                    <label className="filter-label">To Date</label>
                    <input className="form-control filter-input" type="date" value={filters.date_to || ''} onChange={(event) => {
                        if (filters.date_from && event.target.value && event.target.value < filters.date_from) {
                            showGlobalError('From Date cannot be later than To Date.');
                            return;
                        }
                        updateFilter('date_to', event.target.value);
                    }} aria-label="To Date" />
                </div>

                {/* VALIDITY */}
                <div className="filter-group">
                    <label className="filter-label">Validity</label>
                    <select className="form-control filter-input" value={filters.validity} onChange={(event) => updateFilter('validity', event.target.value)}>
                        <option value="">All Validity</option>
                        {validities.map((validity) => <option key={validity} value={validity}>{formatValidity(validity)}</option>)}
                    </select>
                </div>

                {/* STATUS */}
                <div className="filter-group">
                    <label className="filter-label">Status</label>
                    <select className="form-control filter-input" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}>
                        <option value="">All Status</option>
                        {STATUSES.map((status) => <option key={status}>{status}</option>)}
                    </select>
                </div>
                
            </div>
            
            {/* RESET & SEARCH */}
            <div className="table-filter-actions">
                <button type="button" className="btn btn-primary table-filter-btn" onClick={() => load(1, filters)}>
                    <Search size={16} /> Search
                </button>
                <button className="btn btn-secondary table-filter-btn" type="button" onClick={() => setFilters(EMPTY_FILTERS)}>
                    <RotateCcw size={16} /> Reset
                </button>
            </div>
        </div>
        <div className="table-container"><table><thead><tr>{['Installation Date', 'Username', 'Mobile No', 'IMEI No', 'SIM No', 'Device Model', 'Next Renewal Date', 'Platform', 'Validity', 'Total Amount', 'Amount Paid', 'Pending Amount', 'Payment Status', 'SIM Status', 'Actions'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{loading ? <tr><td colSpan="15">Loading renewals...</td></tr> : rows.length === 0 ? <tr><td colSpan="15">No renewal records found.</td></tr> : rows.map((row) => <tr key={row.id}><td>{formatDate(row.installation_date)}</td><td>{display(row.username)}</td><td>{display(row.primary_mobile_no)}</td><td>{display(row.imei_no)}</td><td>{display(row.sim_no_1)}</td><td>{display(row.device_model)}</td><td>{formatDate(row.next_renewal_date)}</td><td>{display(row.platform_name)}</td><td>{display(row.validity_months)} Months</td><td>{Number(row.total_payment_amount || 0) > 0 ? `₹${Number(row.total_payment_amount).toFixed(2)}` : '-'}</td><td>{Number(row.total_payment_amount || 0) > 0 ? `₹${Number(row.total_amount_paid || 0).toFixed(2)}` : '-'}</td><td>{Number(row.total_payment_amount || 0) > 0 ? `₹${Number(row.total_amount_pending || 0).toFixed(2)}` : '-'}</td><td>{row.payment_status ? <span className={`badge ${row.payment_status === 'Paid' ? 'badge-success' : row.payment_status === 'Partially Paid' ? 'badge-warning' : 'badge-danger'}`}>{row.payment_status}</span> : '-'}</td><td><span className="badge">{display(row.sim_status)}</span></td><td><div style={{ display: 'flex', gap: 6 }}><button className="btn" type="button" title="View" onClick={() => openView(row)}><Eye size={16} /></button><button className="btn" type="button" title="Edit" onClick={() => openEdit(row)}><Pencil size={16} /></button><button className="btn btn-danger" type="button" title="Delete" onClick={() => { setDeleteTarget(row); setModal('delete'); }}><Trash2 size={16} /></button></div></td></tr>)}</tbody></table></div>
        <Pagination currentPage={pagination.page} totalItems={pagination.total} pageSize={pagination.page_size} onPageChange={(page) => load(page, filters)} onPageSizeChange={(size) => load(1, filters, size)} itemName="renewals" />
    </div>

    <Modal isOpen={modal === 'view'} onClose={() => setModal(null)} title="Renewal Details" maxWidth="900px" footer={<><button className="btn" type="button" onClick={openHistory}><History size={16} /> View History</button><button className="btn btn-primary" type="button" onClick={() => openEdit(selected)}>Edit</button></>}>
        {selected && <><InfoSection title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} /><InfoSection title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} /><InfoSection title="Installation Details" record={selected} fields={[['Installation Person', 'installation_person'], ['Installation Person Type', 'installation_person_type'], ['Lead Closure', 'lead_closure'], ['Installation Date', 'installation_date', true]]} /><InfoSection title="Renewal Details" record={selected} fields={renewalDetailFields} /></>}
    </Modal>

    <Modal isOpen={modal === 'history'} onClose={() => setModal('view')} title="Renewal History" maxWidth="1150px"><div className="table-container"><table><thead><tr>{['Date', 'Action', 'Old Status', 'New Status', 'Old Validity', 'New Validity', 'Old Renewal Date', 'New Renewal Date', 'Amount', 'Paid', 'Pending', 'Payment Mode', 'Payment Date', 'Transaction ID', 'Changed By'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead><tbody>{history.length ? history.map((item) => <tr key={item.id}><td>{formatDateTime(item.action_date)}</td><td>{item.action_type}</td><td>{display(item.old_status)}</td><td>{display(item.new_status)}</td><td>{display(item.old_validity_months)}</td><td>{display(item.new_validity_months)}</td><td>{formatDate(item.old_renewal_date)}</td><td>{formatDate(item.new_renewal_date)}</td><td>{display(item.payment_amount)}</td><td>{display(item.amount_paid)}</td><td>{display(item.amount_pending)}</td><td>{display(item.payment_mode)}</td><td>{formatDate(item.payment_date)}</td><td>{display(item.transaction_id)}</td><td>{display(item.changed_by_name)}</td></tr>) : <tr><td colSpan="15">No history found.</td></tr>}</tbody></table></div></Modal>

    <Modal isOpen={modal === 'edit'} onClose={closeActionModal} title={`Renewal Action - ${selected?.username || ''}`} maxWidth="1100px" className="renewal-edit-modal" footer={<><button className="btn" type="button" onClick={closeActionModal}>Cancel</button><button className="btn btn-primary" type="button" disabled={saving} onClick={executeAction}>{saving ? 'Saving...' : 'Save Action'}</button></>}>
        {selected && <>
            <InfoSection title="Customer Details" record={selected} fields={[['Username', 'username'], ['Mobile No', 'primary_mobile_no'], ['Secondary Mobile No', 'secondary_mobile_no'], ['Email', 'email'], ['Location', 'location'], ['Pincode', 'pincode'], ['Platform', 'platform_name']]} />
            <InfoSection title="Vehicle / SIM Details" record={selected} fields={[['Vehicle No', 'vehicle_no'], ['Vehicle Type', 'vehicle_type'], ['IMEI No', 'imei_no'], ['SIM No', 'sim_no_1'], ['SIM No 2', 'sim_no_2'], ['Device Model', 'device_model'], ['Validity', 'validity_months']]} />
            <ModalSection title="Installation Details">
                <ModalGrid>
                    <Field label="Installation Person"><input className="form-control" value={selected.installation_person || ''} readOnly /></Field>
                    <Field label="Installation Person Type"><input className="form-control" value={selected.installation_person_type || ''} readOnly /></Field>
                    <Field label="Lead Closure"><input className="form-control" value={selected.lead_closure || ''} readOnly /></Field>
                    <Field label="Installation Date"><input className="form-control" type="date" value={form.installation_date} onChange={(event) => setForm({ ...form, installation_date: event.target.value })} /></Field>
                </ModalGrid>
                {!form.action && form.installation_date && form.validity_months && calculatedDate !== '-' && (
                    <div style={{ marginTop: 12, padding: '9px 12px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, fontSize: 13, color: '#15803d' }}>
                        New Renewal Date: <strong>{formatDate(calculatedDate)}</strong> (installation date + {form.validity_months} months)
                    </div>
                )}
            </ModalSection>
            <InfoSection title="Current Renewal Details" record={selected} fields={renewalDetailFields} />
        </>}
        <ModalSection title="Actions">
            <div className="renewal-action-options" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                {availableActions.map((action) => (
                    <label className="renewal-action-option" key={action} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                        <input type="checkbox" checked={form.action === action} onChange={() => {
                            const nextAction = form.action === action ? '' : action;
                            setForm((current) => ({ ...current, action: nextAction }));
                        }} />
                        <span>{action}</span>
                    </label>
                ))}
            </div>
        </ModalSection>
        {form.action === 'Reactivate SIM' ? <>
            <ModalSection title="Reactivation Details">
                <ModalGrid>
                    <Field label="Reactivation Date *"><input className="form-control" type="date" required value={form.reactivation_date} onChange={(event) => setForm({ ...form, reactivation_date: event.target.value })} /></Field>
                    <Field label="Previous Expiry Date"><input className="form-control" readOnly value={formatDate(selected?.next_renewal_date)} /></Field>
                    <Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{formatValidity(validity)}</option>)}</select></Field>
                    <Field label="Next Expiry / Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field>
                    <Field label="Remarks"><input className="form-control" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></Field>
                </ModalGrid>
            </ModalSection>
            <ModalSection title="New Reactivation Payment">
                <ModalGrid>
                    <Field label="Reactivation Total Amount *"><input className="form-control" type="number" min="0.01" step="0.01" value={form.payment_amount} onChange={(event) => setForm({ ...form, payment_amount: event.target.value })} /></Field>
                    <Field label="Amount Paid *"><input className="form-control" type="number" min="0" step="0.01" value={form.amount_paid} onChange={(event) => setForm({ ...form, amount_paid: event.target.value })} /></Field>
                    <Field label="Pending Amount"><input className="form-control" readOnly value={pending.toFixed(2)} /></Field>
                    <Field label="Payment Status"><input className="form-control" readOnly value={paymentStatus} /></Field>
                    <Field label="Payment Mode"><select className="form-control" value={form.payment_mode} onChange={(event) => setForm({ ...form, payment_mode: event.target.value })}><option value="">Select mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></Field>
                    <Field label="Payment Date"><input className="form-control" type="date" value={form.payment_date} onChange={(event) => setForm({ ...form, payment_date: event.target.value })} /></Field>
                    <Field label={`Transaction ID${Number(form.amount_paid) > 0 && form.payment_mode !== 'Cash' ? ' *' : ''}`}><input className="form-control" type="text" inputMode="numeric" maxLength={6} pattern="[0-9]{6}" value={form.transaction_id} onChange={(event) => setForm({ ...form, transaction_id: event.target.value.replace(/\D/g, '').slice(0, 6) })} /></Field>
                </ModalGrid>
            </ModalSection>
        </> : form.action === 'Renew SIM' ? <>
            <ModalSection title="Renewal Details">
                <ModalGrid>
                    <Field label="Previous Expiry Date / Renewal Due Date"><input className="form-control" readOnly value={formatDate(selected?.next_renewal_date)} /></Field>
                    <Field label="Actual Renewal Processed Date *"><input className="form-control" type="date" required value={form.action_date} onChange={(event) => setForm({ ...form, action_date: event.target.value })} /></Field>
                    <Field label="Validity *"><select className="form-control" value={form.validity_months} onChange={(event) => setForm({ ...form, validity_months: event.target.value })}><option value="">Select validity</option>{validities.map((validity) => <option key={validity} value={validity}>{formatValidity(validity)}</option>)}</select></Field>
                    <Field label="Calculated New Expiry / Next Renewal Date"><input className="form-control" readOnly value={formatDate(calculatedDate)} /></Field>
                </ModalGrid>
            </ModalSection>
            <ModalSection title="New Renewal Payment">
                <ModalGrid>
                    <Field label="Total Amount *"><input className="form-control" type="number" min="0.01" step="0.01" value={form.payment_amount} onChange={(event) => setForm({ ...form, payment_amount: event.target.value })} /></Field>
                    <Field label="Amount Paid *"><input className="form-control" type="number" min="0" step="0.01" value={form.amount_paid} onChange={(event) => setForm({ ...form, amount_paid: event.target.value })} /></Field>
                    <Field label="Pending Amount"><input className="form-control" readOnly value={pending.toFixed(2)} /></Field>
                    <Field label="Payment Status"><input className="form-control" readOnly value={paymentStatus} /></Field>
                    <Field label="Payment Mode"><select className="form-control" value={form.payment_mode} onChange={(event) => setForm({ ...form, payment_mode: event.target.value })}><option value="">Select mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></Field>
                    <Field label="Payment Date"><input className="form-control" type="date" value={form.payment_date} onChange={(event) => setForm({ ...form, payment_date: event.target.value })} /></Field>
                    <Field label={`Transaction ID${Number(form.amount_paid) > 0 && form.payment_mode !== 'Cash' ? ' *' : ''}`}><input className="form-control" type="text" inputMode="numeric" maxLength={6} pattern="[0-9]{6}" value={form.transaction_id} onChange={(event) => setForm({ ...form, transaction_id: event.target.value.replace(/\D/g, '').slice(0, 6) })} /></Field>
                </ModalGrid>
            </ModalSection>
        </> : form.action === 'Safe Custody' ? (
            <ModalSection title="Safe Custody Details">
                <ModalGrid>
                    <Field label="Previous Expiry Date"><input className="form-control" readOnly value={formatDate(selected?.next_renewal_date)} /></Field>
                    <Field label="Safe Custody Date *"><input className="form-control" type="date" required value={form.action_date} onChange={(event) => setForm({ ...form, action_date: event.target.value })} /></Field>
                    <Field label="Remarks"><input className="form-control" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></Field>
                </ModalGrid>
            </ModalSection>
        ) : form.action === 'Deactivate SIM' ? (
            <ModalSection title="Deactivate SIM">
                <p style={{ margin: 0 }}>Confirm the Deactivate SIM action to continue.</p>
            </ModalSection>
        ) : <p>Select an action to continue.</p>}
        {oldRenewalPending > 0 && (
            <ModalSection title="Close Old Renewal Pending Payment">
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: closeOldRenewalPayment ? 14 : 0, cursor: 'pointer' }}>
                    <input type="checkbox" checked={closeOldRenewalPayment} onChange={(event) => {
                        const checked = event.target.checked;
                        setCloseOldRenewalPayment(checked);
                        setOldRenewalPayment({
                            amount_paid: checked ? oldRenewalPending.toFixed(2) : '',
                            payment_mode: '',
                            payment_date: localDateToday(),
                            transaction_id: ''
                        });
                    }} />
                    <span>Close Old Renewal Payment</span>
                </label>
                {closeOldRenewalPayment && <ModalGrid>
                    <Field label="Old Renewal Outstanding"><input className="form-control" readOnly value={`₹${oldRenewalPending.toFixed(2)}`} /></Field>
                    <Field label="Amount to Pay *"><input className="form-control" type="number" min="0.01" max={oldRenewalPending} step="0.01" value={oldRenewalPayment.amount_paid} onChange={(event) => setOldRenewalPayment({ ...oldRenewalPayment, amount_paid: event.target.value })} /><small>Outstanding after payment: ₹{oldRenewalRemaining.toFixed(2)}</small></Field>
                    <Field label="Payment Mode *"><select className="form-control" value={oldRenewalPayment.payment_mode} onChange={(event) => setOldRenewalPayment({ ...oldRenewalPayment, payment_mode: event.target.value })}><option value="">Select mode</option>{PAYMENT_MODES.map((mode) => <option key={mode} value={mode}>{mode}</option>)}</select></Field>
                    <Field label="Payment Date *"><input className="form-control" type="date" value={oldRenewalPayment.payment_date} onChange={(event) => setOldRenewalPayment({ ...oldRenewalPayment, payment_date: event.target.value })} /></Field>
                    <Field label={`Transaction ID${oldRenewalPayment.payment_mode !== 'Cash' ? ' *' : ''}`}><input className="form-control" type="text" inputMode="numeric" maxLength={6} pattern="[0-9]{6}" value={oldRenewalPayment.transaction_id} onChange={(event) => setOldRenewalPayment({ ...oldRenewalPayment, transaction_id: event.target.value.replace(/\D/g, '').slice(0, 6) })} /></Field>
                    <div style={{ display: 'flex', alignItems: 'flex-end' }}><button className="btn btn-primary" type="button" disabled={saving} onClick={recordOldRenewalPayment}>{saving ? 'Recording...' : 'Record Old Payment'}</button></div>
                </ModalGrid>}
            </ModalSection>
        )}
    </Modal>
    <Modal isOpen={modal === 'delete'} onClose={() => setModal(null)} title="Delete Renewal" maxWidth="460px" footer={<><button className="btn" type="button" onClick={() => setModal(null)}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={deleteRenewal}>{saving ? 'Deleting...' : 'Delete'}</button></>}><p>Are you sure you want to delete this renewal record?</p></Modal>
    <Modal isOpen={modal === 'confirm'} onClose={() => setModal('edit')} title={form.action}><p>{form.action === 'Deactivate SIM' ? 'Are you sure you want to deactivate this SIM?' : 'Move this SIM to Safe Custody without changing its saved expiry date?'}</p><div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}><button className="btn" type="button" onClick={() => setModal('edit')}>Cancel</button><button className="btn btn-danger" type="button" disabled={saving} onClick={saveAction}>Confirm</button></div></Modal>
    </div>;
};

export default RenewalsPage;