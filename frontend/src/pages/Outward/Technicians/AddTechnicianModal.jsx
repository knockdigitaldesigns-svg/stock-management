import { useState } from 'react';
import api from '../../../services/api';
import DateInput from '../../../components/DateInput';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

const AddTechnicianModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [formData, setFormData] = useState({
        technician_name: '',
        mobile_no: '',
        alternate_mobile_no: '',
        location: '',
        enrolled_date: '',
        notes: ''
    });
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

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!formData.technician_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            triggerError('All fields are required');
            return;
        }

        const digitsOnly = formData.mobile_no.replace(/\D/g, '');
        if (digitsOnly.length !== 10 || digitsOnly !== formData.mobile_no) {
            triggerError('Mobile number must contain exactly 10 digits.');
            return;
        }

        if (formData.alternate_mobile_no && (formData.alternate_mobile_no.length !== 10 || !/^\d{10}$/.test(formData.alternate_mobile_no))) {
            triggerError('Alternate mobile number must contain exactly 10 digits.');
            return;
        }

        setLoading(true);
        try {
            const response = await api.post('/technicians/create.php', formData);
            if (response.data.success) {
                onSuccess();
            } else {
                triggerError(response.data.message || 'Failed to save technician');
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
                    <h3>Add Technician</h3>
                    <button className="close-btn" onClick={onClose}>&times;</button>
                </div>
                
                <form onSubmit={handleSubmit}>
                    <div className="modal-body">
                        <div className="form-group">
                            <label className="form-label">Technician Name *</label>
                            <input 
                                type="text" 
                                name="technician_name"
                                className="form-control"
                                value={formData.technician_name}
                                onChange={handleChange}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Notes</label>
                            <textarea name="notes" className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={handleChange} />
                        </div>

                        <div className="form-group">
                            <label className="form-label">Mobile No *</label>
                            <input 
                                type="text" 
                                name="mobile_no"
                                className="form-control"
                                value={formData.mobile_no}
                                onChange={(e) => {
                                    const val = e.target.value.replace(/\D/g, '');
                                    setFormData(prev => ({ ...prev, mobile_no: val }));
                                }}
                                maxLength={10}
                                placeholder="Enter 10 digit mobile no"
                                required
                            />
                        </div>

                        <div className="form-group">
                            <label className="form-label">Alternate Mobile No</label>
                            <input
                                type="text"
                                name="alternate_mobile_no"
                                className="form-control"
                                value={formData.alternate_mobile_no}
                                onChange={(e) => {
                                    const val = e.target.value.replace(/\D/g, '').slice(0, 10);
                                    setFormData(prev => ({ ...prev, alternate_mobile_no: val }));
                                }}
                                maxLength={10}
                                placeholder="Enter 10 digit mobile no"
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
                    </div>
                    
                    <div className="modal-footer">
                        <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={loading}>
                            {loading ? 'Saving...' : 'Save Technician'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

export default AddTechnicianModal;
