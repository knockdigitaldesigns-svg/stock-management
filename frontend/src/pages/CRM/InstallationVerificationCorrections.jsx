import { useCallback, useEffect, useState } from 'react';
import { Check, Edit2, RefreshCw, X } from 'lucide-react';
import api from '../../services/api';

const emptyForm = {
    lead_name: '',
    platform_id: '',
    username: '',
    primary_mobile_no: '',
    secondary_mobile_no: '',
    email: '',
    location: '',
    pincode: '',
    vehicle_no: '',
    vehicle_type: '',
    device_type: '',
    imei_no: '',
    sim_no_1: '',
    sim_no_2: '',
    validity_months: '',
    sale_amount: '',
    installation_datetime: '',
    installation_remarks: '',
    completion_evidence: ''
};

const InstallationVerificationCorrections = ({
    platforms,
    vehicleTypes,
    deviceTypes,
    onUpdated
}) => {
    const [corrections, setCorrections] = useState([]);
    const [selected, setSelected] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const loadCorrections = useCallback(async () => {
        try {
            setLoading(true);
            setError('');
            const response = await api.get('/crm_closures/verification_returns.php');
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Unable to load returned installations.');
            }
            const items = response.data.data?.corrections || [];
            setCorrections(items);
            setSelected((current) => items.find(
                (item) => Number(item.submission_id) === Number(current?.submission_id)
            ) || null);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Unable to load returned installations.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadCorrections();
    }, [loadCorrections]);

    const editCorrection = (item) => {
        const customer = item.customer || {};
        const vehicle = item.vehicle || {};
        const installation = vehicle.installation || {};
        const installedAt = String(installation.installation_datetime || '').replace(' ', 'T').slice(0, 16);
        setSelected(item);
        setForm({
            lead_name: item.lead_name || '',
            platform_id: String(customer.platform_id || ''),
            username: customer.username || '',
            primary_mobile_no: customer.primary_mobile_no || '',
            secondary_mobile_no: customer.secondary_mobile_no || '',
            email: customer.email || '',
            location: customer.location || '',
            pincode: customer.pincode || '',
            vehicle_no: vehicle.vehicle_no || '',
            vehicle_type: vehicle.vehicle_type || '',
            device_type: vehicle.device_type || vehicle.device_model || '',
            imei_no: vehicle.imei_no || '',
            sim_no_1: vehicle.sim_no_1 || '',
            sim_no_2: vehicle.sim_no_2 || '',
            validity_months: String(vehicle.validity_months || ''),
            sale_amount: String(item.payment_part1?.total_sale_amount || ''),
            installation_datetime: installedAt,
            installation_remarks: installation.installation_remarks || '',
            completion_evidence: installation.completion_evidence || ''
        });
        setNotice('');
        setError('');
    };

    const updateField = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    const submitCorrection = async (event) => {
        event.preventDefault();
        if (!selected || saving) return;
        try {
            setSaving(true);
            setError('');
            setNotice('');
            const response = await api.post('/crm_closures/resubmit_verification.php', {
                submission_id: Number(selected.submission_id),
                lead: { lead_name: form.lead_name },
                customer: {
                    platform_id: Number(form.platform_id),
                    username: form.username,
                    primary_mobile_no: form.primary_mobile_no,
                    secondary_mobile_no: form.secondary_mobile_no,
                    email: form.email,
                    location: form.location,
                    pincode: form.pincode
                },
                vehicle: {
                    vehicle_no: form.vehicle_no,
                    vehicle_type: form.vehicle_type,
                    device_type: form.device_type,
                    imei_no: form.imei_no,
                    sim_no_1: form.sim_no_1,
                    sim_no_2: form.sim_no_2,
                    validity_months: Number(form.validity_months)
                },
                installation: {
                    installation_datetime: form.installation_datetime,
                    installation_remarks: form.installation_remarks,
                    completion_evidence: form.completion_evidence
                },
                payment: { total_sale_amount: Number(form.sale_amount) }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Unable to resubmit corrected installation.');
            }
            setNotice('Corrected device returned to Customer Verification.');
            setSelected(null);
            await loadCorrections();
            await onUpdated?.();
        } catch (submitError) {
            setError(submitError.response?.data?.message || submitError.message || 'Unable to resubmit corrected installation.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <section id="returned-installation-verifications" className="card closure-workflow-card" aria-label="Returned installation verifications">
            <div className="page-header">
                <div>
                    <h3>Returned Installation Verifications</h3>
                    <p className="page-subtitle">Correct and resubmit device details rejected by Customer Verification.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={loadCorrections} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            {notice && <div className="alert alert-success" role="status">{notice}</div>}

            {selected ? (
                <form className="closure-workflow-form" onSubmit={submitCorrection}>
                    <div className="alert alert-warning">
                        <strong>{selected.lead_name} — Device {selected.device_sequence}</strong>
                        <span> Rejection reason: {selected.rejection_reason || 'No reason provided.'}</span>
                    </div>
                    <label className="form-group">
                        <span>Lead Name <b>*</b></span>
                        <input className="form-control" required maxLength="150" value={form.lead_name} onChange={(event) => updateField('lead_name', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Platform <b>*</b></span>
                        <select className="form-control" required value={form.platform_id} onChange={(event) => updateField('platform_id', event.target.value)}>
                            <option value="">Select platform</option>
                            {platforms.map((platform) => <option key={platform.id} value={platform.id}>{platform.platform_name}</option>)}
                        </select>
                    </label>
                    <label className="form-group">
                        <span>Username <b>*</b></span>
                        <input className="form-control" required maxLength="100" value={form.username} onChange={(event) => updateField('username', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Primary Mobile <b>*</b></span>
                        <input className="form-control" required inputMode="numeric" pattern="[0-9]{10}" maxLength="10" value={form.primary_mobile_no} onChange={(event) => updateField('primary_mobile_no', event.target.value.replace(/\D/g, '').slice(0, 10))} />
                    </label>
                    <label className="form-group">
                        <span>Alternate Mobile</span>
                        <input className="form-control" inputMode="numeric" pattern="[0-9]{10}" maxLength="10" value={form.secondary_mobile_no} onChange={(event) => updateField('secondary_mobile_no', event.target.value.replace(/\D/g, '').slice(0, 10))} />
                    </label>
                    <label className="form-group">
                        <span>Email</span>
                        <input className="form-control" type="email" maxLength="150" value={form.email} onChange={(event) => updateField('email', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Location <b>*</b></span>
                        <input className="form-control" required maxLength="150" value={form.location} onChange={(event) => updateField('location', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Pincode <b>*</b></span>
                        <input className="form-control" required inputMode="numeric" pattern="[0-9]{6}" maxLength="6" value={form.pincode} onChange={(event) => updateField('pincode', event.target.value.replace(/\D/g, '').slice(0, 6))} />
                    </label>
                    <label className="form-group">
                        <span>Vehicle Number <b>*</b></span>
                        <input className="form-control" required maxLength="30" value={form.vehicle_no} onChange={(event) => updateField('vehicle_no', event.target.value.toUpperCase())} />
                    </label>
                    <label className="form-group">
                        <span>Vehicle Type <b>*</b></span>
                        <select className="form-control" required value={form.vehicle_type} onChange={(event) => updateField('vehicle_type', event.target.value)}>
                            <option value="">Select vehicle type</option>
                            {vehicleTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </label>
                    <label className="form-group">
                        <span>Device Type</span>
                        <select className="form-control" value={form.device_type} disabled aria-describedby="locked-installation-assets">
                            <option value="">Select device type</option>
                            {deviceTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </label>
                    <label className="form-group">
                        <span>Device IMEI</span>
                        <input className="form-control" value={form.imei_no} readOnly aria-describedby="locked-installation-assets" />
                    </label>
                    <label className="form-group">
                        <span>Primary SIM</span>
                        <input className="form-control" value={form.sim_no_1} readOnly aria-describedby="locked-installation-assets" />
                    </label>
                    <label className="form-group">
                        <span>Secondary SIM</span>
                        <input className="form-control" value={form.sim_no_2} readOnly aria-describedby="locked-installation-assets" />
                    </label>
                    <p id="locked-installation-assets" className="form-hint">
                        Installed device type, IMEI, and SIM identifiers are locked to preserve inventory history. Correct the linked stock records first if these details are wrong.
                    </p>
                    <label className="form-group">
                        <span>SIM Validity (months) <b>*</b></span>
                        <input className="form-control" type="number" min="1" step="1" required value={form.validity_months} onChange={(event) => updateField('validity_months', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Sale Amount <b>*</b></span>
                        <input className="form-control" type="number" min="0.01" step="0.01" required value={form.sale_amount} onChange={(event) => updateField('sale_amount', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Installation Date and Time <b>*</b></span>
                        <input className="form-control" type="datetime-local" required value={form.installation_datetime} onChange={(event) => updateField('installation_datetime', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Installation Remarks (optional)</span>
                        <textarea className="form-control" maxLength="2000" value={form.installation_remarks} onChange={(event) => updateField('installation_remarks', event.target.value)} />
                    </label>
                    <label className="form-group">
                        <span>Installation Evidence (optional)</span>
                        <textarea className="form-control" maxLength="2000" value={form.completion_evidence} onChange={(event) => updateField('completion_evidence', event.target.value)} />
                    </label>
                    <div className="form-actions">
                        <button type="button" className="btn btn-outline" onClick={() => setSelected(null)} disabled={saving}>
                            <X size={16} /> Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={saving}>
                            <Check size={16} /> {saving ? 'Resubmitting...' : 'Save and Resubmit'}
                        </button>
                    </div>
                </form>
            ) : loading ? (
                <p>Loading returned installations...</p>
            ) : error ? null : corrections.length === 0 ? (
                <p>No installation verification corrections are waiting for you.</p>
            ) : (
                <div className="installer-device-list">
                    {corrections.map((item) => (
                        <article className="installer-device-card" key={item.submission_id}>
                            <header className="installer-device-header">
                                <h4>{item.lead_name} — Device {item.device_sequence}</h4>
                                <button type="button" className="btn btn-outline" onClick={() => editCorrection(item)}>
                                    <Edit2 size={16} /> Correct Details
                                </button>
                            </header>
                            <p><strong>Customer:</strong> {item.customer?.username || '—'} · {item.vehicle?.vehicle_no || '—'}</p>
                            <p className="alert alert-warning"><strong>Rejection reason:</strong> {item.rejection_reason || 'No reason provided.'}</p>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
};

export default InstallationVerificationCorrections;
