import { useState, useEffect } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import { showGlobalError } from '../../../context/ErrorContext';
import { formatDate } from '../../../utils/date';

const DealerSimActivationEditModal = ({ allocation, onClose, onSuccess }) => {
    const status = allocation.sim_status || 'Available';
    
    const [action, setAction] = useState('');
    const [form, setForm] = useState({
        activation_date: allocation.activation_date || '',
        sim_validity_id: String(allocation.sim_validity_id || ''),
        renewal_validity_id: '',
        renewal_date: '',
        deactivation_date: '',
        reactivation_date: '',
        reactivation_validity_id: ''
    });
    
    const [simValidities, setSimValidities] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/sim_validities/list.php')
            .then(res => setSimValidities(res.data.data?.validities || []))
            .catch(err => showGlobalError('Failed to load SIM validities'))
            .finally(() => setLoading(false));
    }, []);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setForm(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async () => {
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
        
        const date = new Date(startDate);
        date.setMonth(date.getMonth() + parseInt(validity.months, 10));
        return formatDate(date.toISOString().split('T')[0]);
    };

    const getAvailableActions = () => {
        const actions = [{ value: '', label: 'Update Details (No lifecycle change)' }];
        if (status === 'Active' || status === 'Expired') {
            actions.push({ value: 'renew', label: 'Renew SIM' });
            actions.push({ value: 'deactivate', label: 'Deactivate SIM' });
            actions.push({ value: 'safe_custody', label: 'Move to Safe Custody' });
        } else if (status === 'Safe Custody') {
            actions.push({ value: 'renew', label: 'Renew SIM' });
            actions.push({ value: 'deactivate', label: 'Deactivate SIM' });
        } else if (status === 'Deactive') {
            actions.push({ value: 'reactivate', label: 'Reactivate SIM' });
        }
        return actions;
    };

    return (
        <Modal 
            isOpen 
            title="Edit Dealer SIM Lifecycle" 
            onClose={onClose} 
            maxWidth="650px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={saving}>Cancel</button>
                    <button type="button" className="btn btn-primary" onClick={handleSave} disabled={loading || saving}>
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
                    <div className="form-group">
                        <label className="form-label">Current Status</label>
                        <input className="form-control" value={status} readOnly />
                    </div>
                    <div className="form-group">
                        <label className="form-label">Current Expiry</label>
                        <input className="form-control" value={formatDate(allocation.expiry_date) || '-'} readOnly />
                    </div>
                    
                    <hr style={{ gridColumn: '1 / -1', margin: '0.5rem 0' }} />
                    
                    {status === 'Available' ? (
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
                                    {getAvailableActions().map(a => (
                                        <option key={a.value} value={a.value}>{a.label}</option>
                                    ))}
                                </select>
                            </div>

                            {action === 'renew' && (
                                <>
                                    <div className="form-group">
                                        <label className="form-label">Renewal Date</label>
                                        <input type="date" className="form-control" name="renewal_date" value={form.renewal_date} onChange={handleChange} />
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
                                        <input className="form-control" value={calculateExpiry(allocation.expiry_date, form.renewal_validity_id)} readOnly />
                                    </div>
                                </>
                            )}

                            {action === 'deactivate' && (
                                <div className="form-group">
                                    <label className="form-label">Deactivation Date</label>
                                    <input type="date" className="form-control" name="deactivation_date" value={form.deactivation_date} onChange={handleChange} />
                                </div>
                            )}

                            {action === 'safe_custody' && (
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                    <p className="text-warning">SIM will be moved to Safe Custody. Existing dates will be preserved.</p>
                                </div>
                            )}

                            {action === 'reactivate' && (
                                <>
                                    <div className="form-group">
                                        <label className="form-label">Reactivation Date</label>
                                        <input type="date" className="form-control" name="reactivation_date" value={form.reactivation_date} onChange={handleChange} />
                                    </div>
                                    <div className="form-group">
                                        <label className="form-label">New Validity</label>
                                        <select className="form-control" name="reactivation_validity_id" value={form.reactivation_validity_id} onChange={handleChange}>
                                            <option value="">Select Validity</option>
                                            {simValidities.map(v => (
                                                <option key={v.id} value={v.id}>{v.months} Months</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="form-group">
                                        <label className="form-label">Calculated Expiry Date</label>
                                        <input className="form-control" value={calculateExpiry(form.reactivation_date, form.reactivation_validity_id)} readOnly />
                                    </div>
                                </>
                            )}
                            
                            {action === '' && (
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                    <p className="text-muted">No lifecycle changes will be made. Only basic updates are allowed.</p>
                                    <div style={{ display: 'grid', gap: '1rem', gridTemplateColumns: '1fr 1fr' }}>
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
                                    </div>
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
