import { useState } from 'react';
import api from '../../../services/api';
import DateInput from '../../../components/DateInput';
import useModalScrollLock from '../../../hooks/useModalScrollLock';

const AddTechnicianModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [formData, setFormData] = useState({
        technician_name: '',
        mobile_no: '',
        location: '',
        enrolled_date: '',
        notes: ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!formData.technician_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            setError('All fields are required');
            return;
        }

        setLoading(true);
        try {
            const response = await api.post('/technicians/create.php', formData);
            if (response.data.success) {
                onSuccess();
            } else {
                setError(response.data.message || 'Failed to save technician');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Network error occurred');
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
                        {error && <div className="alert alert-danger">{error}</div>}

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
                                onChange={handleChange}
                                maxLength={15}
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
