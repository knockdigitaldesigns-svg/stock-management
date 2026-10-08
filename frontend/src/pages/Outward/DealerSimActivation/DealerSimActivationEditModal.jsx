import { useState, useEffect } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import { showGlobalError } from '../../../context/ErrorContext';
import { formatDate } from '../../../utils/date';

const DealerSimActivationEditModal = ({ allocation, onClose, onSuccess }) => {
    const status = allocation.sim_status || 'Available';
    const normalizedStatus = String(status).trim().toLowerCase().replace(/[\s_-]/g, '');
    const displayedStatus = {
        active: 'Active',
        expired: 'Expired',
        deactive: 'Deactive',
        deactivated: 'Deactive',
        safecustody: 'Safe Custody',
        available: 'Available'
    }[normalizedStatus] || status;
    
    const [action, setAction] = useState('');
    const [form, setForm] = useState({
        activation_date: allocation.activation_date || '',
        sim_validity_id: String(allocation.sim_validity_id || ''),
        renewal_validity_id: '',
        renewal_date: '',
        deactivation_date: '',
        reactivation_date: ''
    });
    
    const [simValidities, setSimValidities] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/sim_validities/list.php')
            .then(res => setSimValidities(res.data.data?.validities || []))
            .catch(() => showGlobalError('Failed to load SIM validities'))
            .finally(() => setLoading(false));
    }, []);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setForm(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async () => {
        if (normalizedStatus !== 'available' && !action) {
            showGlobalError('Please select a lifecycle action.');
            return;
        }
        const requiredDate = {
            renew: form.renewal_date,
            deactivate: form.deactivation_date,
            reactivate: form.reactivation_date
        }[action];
        if (action && !requiredDate) {
            showGlobalError('Please select the action date.');
            return;
        }
        if (action === 'renew' && !form.renewal_validity_id) {
            showGlobalError('Please select the renewal validity.');
            return;
        }

        setSaving(true);
        try {
            const res = await api.post('/dealers/sim_activation_update.php', {
                allocation_id: allocation.allocation_id,
                action: action || 'update',
                ...form
            });
            if (res.data.success) {
                onSuccess();
            } else {
                showGlobalError(res.data.message || 'Failed to update SIM lifecycle');
            }
        } catch (err) {
            showGlobalError(err.response?.data?.message || 'Error updating SIM lifecycle');
        } finally {
            setSaving(false);
        }
    };

    const calculateExpiry = (startDate, validityId) => {
        if (!startDate || !validityId) return '-';
        const validity = simValidities.find(v => String(v.id) === String(validityId));
        if (!validity) return '-';
        
        const date = new Date(`${startDate}T00:00:00.000Z`);
        date.setUTCMonth(date.getUTCMonth() + parseInt(validity.months, 10));
        return formatDate(date.toISOString().split('T')[0]);
    };

    const getAvailableActions = () => {
        if (['active', 'expired'].includes(normalizedStatus)) {
            return [
                { value: 'renew', label: 'Renew SIM' },
                { value: 'deactivate', label: 'Deactivate SIM' },
                { value: 'safe_custody', label: 'Safe Custody' }
            ];
        }
        if (normalizedStatus === 'safecustody') {
            return [
                { value: 'renew', label: 'Renew SIM' },
                { value: 'deactivate', label: 'Deactivate SIM' }
            ];
        }
        if (['deactive', 'deactivated'].includes(normalizedStatus)) {
            return [{ value: 'reactivate', label: 'Reactivate SIM' }];
        }
        return [];
    };

    const lifecycleActions = getAvailableActions();

    return (
        <Modal 
            isOpen 
            title="Edit Dealer SIM Lifecycle" 
            onClose={onClose} 
            maxWidth="650px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={saving}>Cancel</button>
                    <button type="button" className="btn btn-primary" onClick={handleSave} disabled={loading || saving || (normalizedStatus !== 'available' && !action)}>
                        {saving ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            }
        >
            {loading ? <p>Loading...</p> : (
                <div style={{ display: 'grid', gap: '1rem', gridTemplateColumns: '1fr 1fr' }}>
                    <div className="form-group">
                        <label className="form-label">Dealer Name</label>
                        <input className="form-control" value={allocation.dealer_name || '-'} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">SIM Number</label>
                        <input className="form-control" value={allocation.sim_no || '-'} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Given Date</label>
                        <input className="form-control" value={formatDate(allocation.given_date) || '-'} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">SIM Type</label>
                        <input className="form-control" value={allocation.sim_type || '-'} readOnly />
                    </div>
                    <h4 style={{ gridColumn: '1 / -1', margin: '0.5rem 0 0' }}>Current Renewal Details</h4>
                    <div className="form-group">
                        <label className="form-label">SIM Status</label>
                        <input className="form-control" value={displayedStatus} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Next Renewal Date</label>
                        <input className="form-control" value={formatDate(allocation.expiry_date) || '-'} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Validity</label>
                        <input className="form-control" value={allocation.validity_months ? `${allocation.validity_months} Months` : '-'} readOnly />
                    </div>
                    
                    <hr style={{ gridColumn: '1 / -1', margin: '0.5rem 0' }} />
                    
                    {normalizedStatus === 'available' ? (
                        <>
                            <div className="form-group">
                                <label className="form-label">Activation Date</label>
                                <input type="date" className="form-control" name="activation_date" value={form.activation_date} onChange={handleChange} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">SIM Validity</label>
                                <select className="form-control" name="sim_validity_id" value={form.sim_validity_id} onChange={handleChange}>
                                    <option value="">Select Validity</option>
                                    {simValidities.map(v => (
                                        <option key={v.id} value={v.id}>{v.months} Months</option>
                                    ))}
                                </select>
                            </div>
                            <div className="form-group">
                                <label className="form-label">Calculated Expiry Date</label>
                                <input className="form-control" value={calculateExpiry(form.activation_date, form.sim_validity_id)} readOnly />
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                <label className="form-label">Lifecycle Action</label>
                                <select className="form-control" value={action} onChange={(e) => setAction(e.target.value)}>
                                    <option value="">Select an action</option>
                                    {lifecycleActions.map(a => (
                                        <option key={a.value} value={a.value}>{a.label}</option>
                                    ))}
                                </select>
                            </div>

                            {action === 'renew' && (
                                <>
                                    <div className="form-group">
                                        <label className="form-label">Renewal Date</label>
                                        <input type="date" className="form-control" name="renewal_date" value={form.renewal_date} onChange={handleChange} required />
                                    </div>
                                    <div className="form-group">
                                        <label className="form-label">Renewal Validity</label>
                                        <select className="form-control" name="renewal_validity_id" value={form.renewal_validity_id} onChange={handleChange}>
                                            <option value="">Select Validity</option>
                                            {simValidities.map(v => (
                                                <option key={v.id} value={v.id}>{v.months} Months</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="form-group">
                                        <label className="form-label">Previous Expiry</label>
                                        <input className="form-control" value={formatDate(allocation.expiry_date) || '-'} readOnly />
                                    </div>
                                    <div className="form-group">
                                        <label className="form-label">New Expiry Date (Calc)</label>
                                        <input className="form-control" value={calculateExpiry(form.renewal_date, form.renewal_validity_id)} readOnly />
                                    </div>
                                </>
                            )}

                            {action === 'deactivate' && (
                                <div className="form-group">
                                    <label className="form-label">Deactivation Date</label>
                                    <input type="date" className="form-control" name="deactivation_date" value={form.deactivation_date} onChange={handleChange} required />
                                </div>
                            )}

                            {action === 'safe_custody' && (
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                    <p className="text-warning">SIM will be moved to Safe Custody. Existing dates will be preserved.</p>
                                </div>
                            )}

                            {action === 'reactivate' && (
                                <div className="form-group">
                                    <label className="form-label">Reactivation Date</label>
                                    <input type="date" className="form-control" name="reactivation_date" value={form.reactivation_date} onChange={handleChange} required />
                                </div>
                            )}
                            
                            {action === '' && (
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                    <p className="text-muted">Select a lifecycle action to continue.</p>
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}
        </Modal>
    );
};

export default DealerSimActivationEditModal;
