import { useCallback, useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { formatDate } from '../../utils/date';
import './ClosureWorkflowPages.css';

const isPrimarySimOptional = (deviceType) =>
    String(deviceType ?? '').trim().toLowerCase() === 'bharat101 - ais 140';

const getPaymentStatus = (amountPaid, totalAmount) => {
    const paid = Number(amountPaid) || 0;
    const total = Number(totalAmount) || 0;

    if (paid <= 0) return 'Not Paid';
    if (total > 0 && paid >= total) return 'Paid';
    return 'Partially Paid';
};

const InstallerJobsPage = () => {
    const [jobs, setJobs] = useState([]);
    const [vehicleTypes, setVehicleTypes] = useState([]);
    const [deviceTypes, setDeviceTypes] = useState([]);
    const [paymentModes, setPaymentModes] = useState([]);
    const [completionForms, setCompletionForms] = useState({});
    const [sameUsernameByClosure, setSameUsernameByClosure] = useState({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [savingClosureId, setSavingClosureId] = useState(null);
    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        setTotalItems,
        totalItems,
        paginatedItems
    } = usePagination(jobs, 10, [], true);

    const loadJobs = useCallback(async () => {
        try {
            const response = await api.get('/crm_closures/jobs.php', {
                params: { page, page_size: pageSize }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load assigned installations.');
                return;
            }
            const data = response.data.data || {};
            setJobs(data.jobs || []);
            setVehicleTypes(data.vehicle_types || []);
            setDeviceTypes(data.device_types || []);
            setPaymentModes(data.payment_modes || []);
            setTotalItems(Number(data.pagination?.total || 0));
            setError('');
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load assigned installations.');
        } finally {
            setLoading(false);
        }
    }, [page, pageSize, setTotalItems]);

    useEffect(() => {
        loadJobs();
    }, [loadJobs]);

    const startInstallation = async (job) => {
        setSavingClosureId(job.closure_id);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_closures/complete.php', {
                closure_id: Number(job.closure_id),
                action: 'start'
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to start installation.');
                return;
            }
            setMessage(response.data.message);
            await loadJobs();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to start installation.');
        } finally {
            setSavingClosureId(null);
        }
    };

    const updateCompletionField = (formKey, field, value) => {
        setCompletionForms((current) => ({
            ...current,
            [formKey]: {
                ...(current[formKey] || {}),
                [field]: value
            }
        }));
    };

    const defaultSameUsername = (job) => {
        const usernames = new Set((job.devices || [])
            .map((device) => device.customer_username)
            .filter(Boolean));
        return usernames.size <= 1;
    };

    const renderPaymentFields = (formKey, saleAmount) => {
        const form = completionForms[formKey] || {};
        const totalAmount = form.total_amount ?? saleAmount ?? '';
        const amountPaid = form.amount_paid ?? '0';
        const paymentStatus = getPaymentStatus(amountPaid, totalAmount);
        const paymentMode = form.payment_mode ?? '';
        return (
            <>
                <label>Total Sale Amount
                    <input className="form-control" type="number" readOnly value={saleAmount ?? ''} />
                </label>
                <label>Total Amount
                    <input className="form-control" type="number" min="0.01" step="0.01" required readOnly value={totalAmount} />
                </label>
                <label>Amount Paid
                    <input className="form-control" type="number" min="0" max={totalAmount || undefined} step="0.01" required value={amountPaid} onChange={(event) => updateCompletionField(formKey, 'amount_paid', event.target.value)} />
                </label>
                <label>Payment Status
                    <input className="form-control" value={paymentStatus} readOnly />
                </label>
                <label>Payment Method
                    <select className="form-control" required={Number(amountPaid) > 0} value={paymentMode} onChange={(event) => {
                        updateCompletionField(formKey, 'payment_mode', event.target.value);
                        if (event.target.value === 'Cash') {
                            updateCompletionField(formKey, 'transaction_id', '');
                        }
                    }}>
                        <option value="">Select payment method</option>
                        {paymentModes.map((mode) => <option key={mode} value={mode}>{mode}</option>)}
                    </select>
                </label>
                <label>Transaction ID
                    <input className="form-control" inputMode="numeric" maxLength="6" pattern="[0-9]{6}" required={Number(amountPaid) > 0 && paymentMode !== 'Cash'} disabled={paymentMode === 'Cash'} value={form.transaction_id || ''} onChange={(event) => updateCompletionField(formKey, 'transaction_id', event.target.value.replace(/\D/g, '').slice(0, 6))} />
                </label>
            </>
        );
    };

    const completeInstallation = async (event, job, device = null) => {
        event.preventDefault();
        const sequence = Number(device?.device_sequence || 1);
        const formKey = `${job.closure_id}:${sequence}`;
        const form = completionForms[formKey] || {};
        const isMultiDevice = Boolean(device && Number(job.device_count) > 1);
        const shareUsername = sameUsernameByClosure[job.closure_id] ?? defaultSameUsername(job);
        const sharedUsername = completionForms[`${job.closure_id}:shared`]?.username ||
            job.devices?.find((item) => item.customer_username)?.customer_username ||
            job.customer_name ||
            job.username ||
            '';
        const sharedMobile = completionForms[`${job.closure_id}:shared`]?.mobile_no ??
            job.devices?.find((item) => item.customer_primary_mobile_no)?.customer_primary_mobile_no ??
            job.primary_mobile_no ??
            '';
        const username = form.username ??
            device?.customer_username ??
            job.customer_name ??
            job.username ??
            '';
        const mobileNo = isMultiDevice && shareUsername
            ? sharedMobile
            : form.mobile_no ?? device?.customer_primary_mobile_no ?? job.primary_mobile_no ?? '';
        if (isMultiDevice && shareUsername && !/^[A-Za-z0-9._-]{1,100}$/.test(sharedUsername)) {
            setError('Enter a valid username for all vehicles.');
            return;
        }
        if (!/^\d{10}$/.test(mobileNo)) {
            setError('Enter a valid 10-digit mobile number.');
            return;
        }
        const deviceDefaults = isMultiDevice
            ? {
                vehicle_number: device.vehicle_number || (sequence === 1 ? job.vehicle_number : ''),
                vehicle_type: device.vehicle_type || job.vehicle_type || '',
                device_type: device.device_type || job.device_type || '',
                sale_amount: device.sale_amount ?? ''
            }
            : {
                vehicle_number: device?.vehicle_number || job.vehicle_number || ''
            };
        const saleAmount = form.sale_amount ?? deviceDefaults.sale_amount ?? job.amount ?? '';
        const totalAmount = form.total_amount ?? saleAmount;
        setSavingClosureId(job.closure_id);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_closures/complete.php', {
                closure_id: Number(job.closure_id),
                action: 'complete',
                ...(isMultiDevice
                    ? { device_sequence: sequence }
                    : {}),
                ...deviceDefaults,
                ...form,
                username,
                mobile_no: mobileNo,
                sale_amount: saleAmount,
                total_amount: totalAmount,
                amount_paid: form.amount_paid ?? '0',
                payment_status: getPaymentStatus(form.amount_paid ?? '0', totalAmount),
                payment_mode: form.payment_mode ?? '',
                transaction_id: form.transaction_id ?? '',
                ...(isMultiDevice && shareUsername ? { username: sharedUsername } : {})
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to complete installation.');
                return;
            }
            const customerName = job.customer_name || job.username || job.lead_name || 'Customer';
            const vehicleNumber = form.vehicle_number ||
                device?.vehicle_number ||
                (sequence === 1 ? job.vehicle_number : '');
            setMessage(
                `${response.data.message} Customer: ${customerName}.` +
                (vehicleNumber ? ` Vehicle: ${vehicleNumber}.` : '')
            );
            await loadJobs();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to complete installation.');
        } finally {
            setSavingClosureId(null);
        }
    };

    const renderSingleDeviceForm = (job, device) => {
        const formKey = `${job.closure_id}:${device.device_sequence}`;
        const form = completionForms[formKey] || {};
        const primarySimOptional = isPrimarySimOptional(device.device_type ?? job.device_type);
        return (
            <form className="closure-completion-form" onSubmit={(event) => completeInstallation(event, job, device)}>
                <label>Username
                    <input className="form-control" required maxLength="100" pattern="[A-Za-z0-9._-]+" value={form.username ?? device.customer_username ?? job.customer_name ?? job.username ?? ''} onChange={(event) => updateCompletionField(formKey, 'username', event.target.value)} />
                </label>
                <label>Mobile Number
                    <input className="form-control" type="tel" required inputMode="numeric" maxLength="10" pattern="[0-9]{10}" value={form.mobile_no ?? device.customer_primary_mobile_no ?? job.primary_mobile_no ?? ''} onChange={(event) => updateCompletionField(formKey, 'mobile_no', event.target.value.replace(/\D/g, '').slice(0, 10))} />
                </label>
                <label>Installed Vehicle Number
                    <input className="form-control" required maxLength="30" value={form.vehicle_number ?? device.vehicle_number ?? job.vehicle_number ?? ''} onChange={(event) => updateCompletionField(formKey, 'vehicle_number', event.target.value.toUpperCase())} />
                </label>
                <label>Device IMEI
                    <input className="form-control" required inputMode="numeric" maxLength="15" value={form.imei_no || ''} onChange={(event) => updateCompletionField(formKey, 'imei_no', event.target.value.replace(/\D/g, '').slice(0, 15))} />
                </label>
                <label>{primarySimOptional ? 'Primary SIM (optional)' : 'Primary SIM'}
                    <input className="form-control" required={!primarySimOptional} inputMode="numeric" maxLength="13" value={form.sim_no_1 || ''} onChange={(event) => updateCompletionField(formKey, 'sim_no_1', event.target.value.replace(/\D/g, '').slice(0, 13))} />
                </label>
                <label>Secondary SIM (optional)
                    <input className="form-control" inputMode="numeric" maxLength="13" value={form.sim_no_2 || ''} onChange={(event) => updateCompletionField(formKey, 'sim_no_2', event.target.value.replace(/\D/g, '').slice(0, 13))} />
                </label>
                <label>Validity (months)
                    <input className="form-control" type="number" min="1" step="1" required value={form.validity_months || ''} onChange={(event) => updateCompletionField(formKey, 'validity_months', Number(event.target.value))} />
                </label>
                <label>Installation Date &amp; Time
                    <input className="form-control" type="datetime-local" required value={form.installation_datetime || ''} onChange={(event) => updateCompletionField(formKey, 'installation_datetime', event.target.value)} />
                </label>
                <label>Completion Remarks (optional)
                    <textarea className="form-control" maxLength="2000" value={form.installation_remarks || ''} onChange={(event) => updateCompletionField(formKey, 'installation_remarks', event.target.value)} />
                </label>
                <label>Verification / Evidence Note (optional)
                    <textarea className="form-control" maxLength="2000" value={form.completion_evidence || ''} onChange={(event) => updateCompletionField(formKey, 'completion_evidence', event.target.value)} placeholder="Verification result, evidence reference, or URL" />
                </label>
                {renderPaymentFields(formKey, form.sale_amount ?? device.sale_amount ?? job.amount)}
                <label className="closure-completion-verified">
                    <input type="checkbox" required checked={Boolean(form.completion_verified)} onChange={(event) => updateCompletionField(formKey, 'completion_verified', event.target.checked)} />
                    Vehicle and device details verified
                </label>
                <button type="submit" className="btn btn-primary" disabled={savingClosureId !== null}>
                    {savingClosureId === job.closure_id ? 'Completing...' : 'Complete & Hand Off to Customer Management'}
                </button>
            </form>
        );
    };

    const renderMultiDeviceForm = (job, device) => {
        const formKey = `${job.closure_id}:${device.device_sequence}`;
        const form = completionForms[formKey] || {};
        const initialAmount = device.sale_amount ?? '';
        const shareUsername = sameUsernameByClosure[job.closure_id] ?? defaultSameUsername(job);
        const primarySimOptional = isPrimarySimOptional(form.device_type ?? device.device_type ?? job.device_type);
        return (
            <form className="closure-completion-form closure-device-form" onSubmit={(event) => completeInstallation(event, job, device)}>
                {!shareUsername && (
                    <>
                        <label>Username
                            <input className="form-control" required maxLength="100" pattern="[A-Za-z0-9._-]+" value={form.username ?? device.customer_username ?? job.customer_name ?? job.username ?? ''} onChange={(event) => updateCompletionField(formKey, 'username', event.target.value)} />
                        </label>
                        <label>Mobile Number
                            <input className="form-control" type="tel" required inputMode="numeric" maxLength="10" pattern="[0-9]{10}" value={form.mobile_no ?? device.customer_primary_mobile_no ?? job.primary_mobile_no ?? ''} onChange={(event) => updateCompletionField(formKey, 'mobile_no', event.target.value.replace(/\D/g, '').slice(0, 10))} />
                        </label>
                    </>
                )}
                <label>Vehicle Registration Number
                    <input className="form-control" required maxLength="30" value={form.vehicle_number ?? device.vehicle_number ?? (device.device_sequence === 1 ? job.vehicle_number : '')} onChange={(event) => updateCompletionField(formKey, 'vehicle_number', event.target.value.toUpperCase())} />
                </label>
                <label>Vehicle Type
                    <select className="form-control" required value={form.vehicle_type ?? device.vehicle_type ?? job.vehicle_type ?? ''} onChange={(event) => updateCompletionField(formKey, 'vehicle_type', event.target.value)}>
                        <option value="">Select vehicle type</option>
                        {vehicleTypes.map((type) => <option key={type.id} value={type.vehicle_type}>{type.vehicle_type}</option>)}
                    </select>
                </label>
                <label>Device Type
                    <select className="form-control" required value={form.device_type ?? device.device_type ?? job.device_type ?? ''} onChange={(event) => updateCompletionField(formKey, 'device_type', event.target.value)}>
                        <option value="">Select device type</option>
                        {deviceTypes.map((type) => <option key={type.id} value={type.device_type}>{type.device_type}</option>)}
                    </select>
                </label>
                <label>Device Sale Amount
                    <input className="form-control" type="number" min="0.01" max="9999999999.99" step="0.01" required value={form.sale_amount ?? initialAmount} onChange={(event) => {
                        updateCompletionField(formKey, 'sale_amount', event.target.value);
                        updateCompletionField(formKey, 'total_amount', event.target.value);
                    }} />
                </label>
                <label>Device IMEI
                    <input className="form-control" required inputMode="numeric" maxLength="15" value={form.imei_no || ''} onChange={(event) => updateCompletionField(formKey, 'imei_no', event.target.value.replace(/\D/g, '').slice(0, 15))} />
                </label>
                <label>{primarySimOptional ? 'Primary SIM (optional)' : 'Primary SIM'}
                    <input className="form-control" required={!primarySimOptional} inputMode="numeric" maxLength="13" value={form.sim_no_1 || ''} onChange={(event) => updateCompletionField(formKey, 'sim_no_1', event.target.value.replace(/\D/g, '').slice(0, 13))} />
                </label>
                <label>Secondary SIM (optional)
                    <input className="form-control" inputMode="numeric" maxLength="13" value={form.sim_no_2 || ''} onChange={(event) => updateCompletionField(formKey, 'sim_no_2', event.target.value.replace(/\D/g, '').slice(0, 13))} />
                </label>
                <label>Validity (months)
                    <input className="form-control" type="number" min="1" step="1" required value={form.validity_months || ''} onChange={(event) => updateCompletionField(formKey, 'validity_months', Number(event.target.value))} />
                </label>
                <label>Installation Date &amp; Time
                    <input className="form-control" type="datetime-local" required value={form.installation_datetime || ''} onChange={(event) => updateCompletionField(formKey, 'installation_datetime', event.target.value)} />
                </label>
                <label>Installation Remarks (optional)
                    <textarea className="form-control" maxLength="2000" value={form.installation_remarks || ''} onChange={(event) => updateCompletionField(formKey, 'installation_remarks', event.target.value)} />
                </label>
                <label>Verification / Evidence Note (optional)
                    <textarea className="form-control" maxLength="2000" value={form.completion_evidence || ''} onChange={(event) => updateCompletionField(formKey, 'completion_evidence', event.target.value)} placeholder="Verification result, evidence reference, or URL" />
                </label>
                {renderPaymentFields(formKey, form.sale_amount ?? initialAmount)}
                <label className="closure-completion-verified">
                    <input type="checkbox" required checked={Boolean(form.completion_verified)} onChange={(event) => updateCompletionField(formKey, 'completion_verified', event.target.checked)} />
                    Vehicle and device details verified
                </label>
                <button type="submit" className="btn btn-primary" disabled={savingClosureId !== null}>
                    {savingClosureId === job.closure_id ? 'Completing...' : `Complete Device ${device.device_sequence}`}
                </button>
            </form>
        );
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>My Installations</h2>
                    <p className="page-subtitle">Approved installation work assigned to your linked personnel account.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={() => {
                    setLoading(true);
                    loadJobs();
                }} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            {message && <div className="alert alert-success" role="status">{message}</div>}
            <section className="card closure-workflow-card">
                {loading ? (
                    <p>Loading assigned installations...</p>
                ) : paginatedItems.length === 0 ? (
                    <p>No installation jobs are assigned to this account.</p>
                ) : (
                    <div className="installer-job-list">
                        {paginatedItems.map((job) => {
                            const devices = job.devices || [];
                            const multiDeviceOrder = Number(job.device_count) > 1;
                            const orderTotal = devices.reduce((total, device) => {
                                const deviceForm = completionForms[`${job.closure_id}:${device.device_sequence}`] || {};
                                const fallbackAmount = Number(job.device_count) === 1 && Number(device.device_sequence) === 1
                                    ? job.amount
                                    : 0;
                                const amount = Number(deviceForm.sale_amount ?? device.sale_amount ?? fallbackAmount);
                                return total + (Number.isFinite(amount) ? amount : 0);
                            }, 0);
                            const orderSharedKey = `${job.closure_id}:shared`;
                            const shareUsername = sameUsernameByClosure[job.closure_id] ?? defaultSameUsername(job);
                            const sharedUsername = completionForms[orderSharedKey]?.username ??
                                devices.find((device) => device.customer_username)?.customer_username ??
                                job.customer_name ??
                                job.username ??
                                '';
                            return (
                                <article className="installer-job-card" key={job.closure_id}>
                                    <header className="installer-job-header">
                                        <div>
                                            <h3>{job.customer_name || job.username || job.lead_name}</h3>
                                            <p>Lead: {job.lead_name} · {job.primary_mobile_no || job.lead_mobile}</p>
                                        </div>
                                        <span className="installer-job-status">{job.submission_status}</span>
                                    </header>
                                    <div className="installer-job-summary">
                                        <span><strong>Location:</strong> {job.customer_location || job.location || '—'}</span>
                                        <span><strong>Customer Number:</strong> {job.customer_number || '—'}</span>
                                        <span><strong>Commitment:</strong> {formatDate(job.commitment_date)}</span>
                                        <span><strong>Assigned:</strong> {formatDate(job.installation_assigned_at)}</span>
                                        <span><strong>Total devices:</strong> {job.total_devices ?? job.device_count}</span>
                                        <span><strong>Installed:</strong> {job.installed_devices ?? 0}</span>
                                        <span><strong>Pending:</strong> {job.pending_devices ?? job.device_count}</span>
                                        <span><strong>Awaiting verification:</strong> {job.pending_verification_devices ?? 0}</span>
                                        <span><strong>Returned:</strong> {job.rejected_verification_devices ?? 0}</span>
                                        <span><strong>Overall vehicle total:</strong> {new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(orderTotal)}</span>
                                    </div>
                                    {job.submission_status === 'Approved' && (
                                        <button type="button" className="btn btn-primary installer-start-button" disabled={savingClosureId !== null} onClick={() => startInstallation(job)}>
                                            {savingClosureId === job.closure_id ? 'Starting...' : 'Start Installation'}
                                        </button>
                                    )}
                                    {multiDeviceOrder && job.submission_status === 'Installation In Progress' && (
                                        <div className="installer-shared-username">
                                            <label className="closure-completion-verified">
                                                <input
                                                    type="checkbox"
                                                    checked={shareUsername}
                                                    onChange={(event) => setSameUsernameByClosure((current) => ({
                                                        ...current,
                                                        [job.closure_id]: event.target.checked
                                                    }))}
                                                />
                                                Use the same username and mobile number for all vehicles
                                            </label>
                                            {shareUsername && (
                                                <>
                                                    <label>Username for all vehicles
                                                        <input
                                                            className="form-control"
                                                            required
                                                            maxLength="100"
                                                            pattern="[A-Za-z0-9._-]+"
                                                            value={sharedUsername}
                                                            onChange={(event) => updateCompletionField(orderSharedKey, 'username', event.target.value)}
                                                        />
                                                    </label>
                                                    <label>Mobile Number for all vehicles
                                                        <input
                                                            className="form-control"
                                                            type="tel"
                                                            required
                                                            inputMode="numeric"
                                                            maxLength="10"
                                                            pattern="[0-9]{10}"
                                                            value={completionForms[orderSharedKey]?.mobile_no ?? devices.find((device) => device.customer_primary_mobile_no)?.customer_primary_mobile_no ?? job.primary_mobile_no ?? ''}
                                                            onChange={(event) => updateCompletionField(orderSharedKey, 'mobile_no', event.target.value.replace(/\D/g, '').slice(0, 10))}
                                                        />
                                                    </label>
                                                </>
                                            )}
                                        </div>
                                    )}
                                    <div className="installer-device-list">
                                        {devices.map((device) => {
                                            const completed = device.installation_status === 'Completed';
                                            return (
                                                <section className="installer-device-card" key={`${job.closure_id}-${device.device_sequence}`}>
                                                    <header className="installer-device-header">
                                                        <h4>Device {device.device_sequence} <span>— {completed ? 'Installed' : 'Pending'}</span></h4>
                                                        {device.sale_amount && <strong>Sale amount: {device.sale_amount}</strong>}
                                                    </header>
                                                    {completed ? (
                                                        <div className="installer-device-details">
                                                            <span><strong>Username:</strong> {device.customer_username || job.customer_name || job.username || '—'}</span>
                                                            <span><strong>Mobile:</strong> {device.customer_primary_mobile_no || job.primary_mobile_no || '—'}</span>
                                                            <span><strong>Vehicle:</strong> {device.vehicle_number || '—'}</span>
                                                            <span><strong>Vehicle type:</strong> {device.vehicle_type || '—'}</span>
                                                            <span><strong>Device type:</strong> {device.device_type || '—'}</span>
                                                            <span><strong>IMEI:</strong> {device.imei_no || '—'}</span>
                                                            <span><strong>SIM:</strong> {device.sim_no_1 || '—'}{device.sim_no_2 ? ` / ${device.sim_no_2}` : ''}</span>
                                                            <span><strong>Installed:</strong> {formatDate(device.installation_datetime)}</span>
                                                            <span><strong>Customer verification:</strong> {device.verification_status || 'Not Submitted'}</span>
                                                            <span><strong>Payment:</strong> {device.payment_status || '—'} · {device.payment_mode || 'No method'} · Paid {device.amount_paid ?? '—'} / {device.total_amount ?? device.sale_amount ?? '—'}{device.transaction_id ? ` · Transaction ${device.transaction_id}` : ''}</span>
                                                            {device.installation_remarks && <span className="installer-device-remarks"><strong>Remarks:</strong> {device.installation_remarks}</span>}
                                                            {device.verification_status === 'Rejected' && (
                                                                <p className="alert alert-warning">
                                                                    Returned to the assigned lead owner for correction.
                                                                    {device.verification_rejection_reason ? ` Reason: ${device.verification_rejection_reason}` : ''}
                                                                </p>
                                                            )}
                                                        </div>
                                                    ) : job.submission_status === 'Installation In Progress' ? (
                                                        multiDeviceOrder
                                                            ? renderMultiDeviceForm(job, device)
                                                            : renderSingleDeviceForm(job, device)
                                                    ) : (
                                                        <p className="installer-device-pending">Device details can be entered after the installation is started.</p>
                                                    )}
                                                </section>
                                            );
                                        })}
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}
                <Pagination
                    currentPage={page}
                    pageSize={pageSize}
                    totalItems={totalItems}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="installations"
                />
            </section>
        </div>
    );
};

export default InstallerJobsPage;
