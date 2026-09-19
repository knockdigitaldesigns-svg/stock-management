import { useState } from 'react';
import api from '../../../services/api';
import DateInput from '../../../components/DateInput';
import { SOFTWARE_OPTIONS } from '../../../constants/software';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

const AddDealerModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [formData, setFormData] = useState({
        dealer_name: '',
        mobile_no: '',
        location: '',
        enrolled_date: '',
        installation_status: 'Onsite',
        threshold_amount: '',
        notes: '',
        software: []
    });
    const [softwareOpen, setSoftwareOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    const handleMobileChange = (e) => {
        const val = e.target.value.replace(/\D/g, '').slice(0, 10);
        setFormData(prev => ({ ...prev, mobile_no: val }));
    };

    const toggleSoftware = (opt) => {
        setFormData(prev => {
            const current = prev.software || [];
            const updated = current.includes(opt)
                ? current.filter(s => s !== opt)
                : [...current, opt];
            return { ...prev, software: updated };
        });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!formData.dealer_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            triggerError('All required fields must be filled.');
            return;
        }

        if (formData.mobile_no.length !== 10) {
            triggerError('Mobile Number must be exactly 10 digits.');
            return;
        }

        if (formData.threshold_amount !== '' && formData.threshold_amount !== null && formData.threshold_amount !== undefined) {
            const num = Number(formData.threshold_amount);
            if (isNaN(num) || num < 0) {
                triggerError('Threshold Amount must be a valid non-negative number.');
                return;
            }
        }

        setLoading(true);
        try {
            const payload = {
                ...formData,
                threshold_amount: formData.threshold_amount !== '' ? formData.threshold_amount : null
            };
            const response = await api.post('/dealers/create.php', payload);
            if (response.data.success) {
                onSuccess();
            } else {
                triggerError(response.data.message || 'Failed to save dealer');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Network error occurred');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '500px' }}>
                <div className="modal-header">
                    <h3>Add Dealer</h3>
                    <button className="close-btn" onClick={onClose}>&times;</button>
                </div>
                
                <form onSubmit={handleSubmit}>
                    <div className="modal-body">
                        {error && <div className="alert alert-danger" style={{ marginBottom: '1rem' }}>{error}</div>}

                        <div className="form-group">
                            <label className="form-label">Dealer Name *</label>
                            <input 
                                type="text" 
                                name="dealer_name"
                                className="form-control"
                                value={formData.dealer_name}
                                onChange={handleChange}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Notes</label>
                            <textarea name="notes" className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={handleChange} />
                        </div>

                        <div className="form-group">
                            <label className="form-label">Mobile No * (10 digits)</label>
                            <input 
                                type="text" 
                                name="mobile_no"
                                className="form-control"
                                value={formData.mobile_no}
                                onChange={handleMobileChange}
                                maxLength={10}
                                placeholder="Enter 10 digit mobile number"
                                required
                            />
                        </div>

                        <div className="form-group">
                            <label className="form-label">Location *</label>
                            <input 
                                type="text" 
                                name="location"
                                className="form-control"
                                value={formData.location}
                                onChange={handleChange}
                                required
                            />
                        </div>

                        <div className="form-group">
                            <label className="form-label">Enrolled Date *</label>
                            <DateInput 
                                name="enrolled_date"
                                className="form-control"
                                value={formData.enrolled_date}
                                onChange={(value) => setFormData((prev) => ({ ...prev, enrolled_date: value }))}
                                required
                            />
                        </div>

                        <div className="form-group" style={{ position: 'relative' }}>
                            <label className="form-label">Software</label>
                            <div 
                                className="form-control" 
                                onClick={() => setSoftwareOpen(!softwareOpen)}
                                style={{ cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', minHeight: '38px', userSelect: 'none' }}
                            >
                                <span style={{ color: (formData.software && formData.software.length > 0) ? 'inherit' : '#94a3b8' }}>
                                    {(formData.software && formData.software.length > 0) ? formData.software.join(', ') : 'Select software'}
                                </span>
                                <span>{softwareOpen ? '▲' : '▼'}</span>
                            </div>
                            {softwareOpen && (
                                <div style={{
                                    position: 'absolute',
                                    top: '100%',
                                    left: 0,
                                    right: 0,
                                    zIndex: 30,
                                    background: '#fff',
                                    border: '1px solid var(--border-color, #cbd5e1)',
                                    borderRadius: '0.375rem',
                                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                                    maxHeight: '180px',
                                    overflowY: 'auto',
                                    padding: '0.5rem'
                                }}>
                                    {SOFTWARE_OPTIONS.map((opt) => (
                                        <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', padding: '0.35rem 0.5rem', cursor: 'pointer', fontSize: '0.875rem' }}>
                                            <input
                                                type="checkbox"
                                                checked={(formData.software || []).includes(opt)}
                                                onChange={() => toggleSoftware(opt)}
                                            />
                                            {opt}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="form-group">
                            <label className="form-label">Installation Status *</label>
                            <select 
                                name="installation_status"
                                className="form-control"
                                value={formData.installation_status}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setFormData(prev => ({
                                        ...prev,
                                        installation_status: val
                                    }));
                                }}
                                required
                            >
                                <option value="Onsite">Onsite</option>
                                <option value="Offsite">Offsite</option>
                                <option value="Not Willing">Not Willing</option>
                            </select>
                        </div>

                        <div className="form-group">
                            <label className="form-label">Threshold Amount (₹)</label>
                            <input 
                                type="number" 
                                name="threshold_amount"
                                className="form-control"
                                placeholder="Enter threshold amount (e.g. 5000)"
                                value={formData.threshold_amount}
                                onChange={handleChange}
                                min="0"
                                step="any"
                            />
                        </div>
                    </div>
                    
                    <div className="modal-footer">
                        <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={loading}>
                            {loading ? 'Saving...' : 'Save Dealer'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

export default AddDealerModal;
