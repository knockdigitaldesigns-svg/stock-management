import { useState } from 'react';
import api from '../../../services/api';
import DateInput from '../../../components/DateInput';
import { SOFTWARE_OPTIONS } from '../../../constants/software';
import useModalScrollLock from '../../../hooks/useModalScrollLock';

const AddDealerModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [formData, setFormData] = useState({
        dealer_name: '',
        mobile_no: '',
        location: '',
        enrolled_date: '',
        installation_status: 'Onsite',
        notes: '', software: ''
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

        if (!formData.dealer_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            setError('All fields are required');
            return;
        }

        setLoading(true);
        try {
            const response = await api.post('/dealers/create.php', formData);
            if (response.data.success) {
                onSuccess();
            } else {
                setError(response.data.message || 'Failed to save dealer');
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
                    <h3>Add Dealer</h3>
                    <button className="close-btn" onClick={onClose}>&times;</button>
                </div>
                
                <form onSubmit={handleSubmit}>
                    <div className="modal-body">
                        {error && <div className="alert alert-danger">{error}</div>}

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

                        <div className="form-group">
                            <label className="form-label">Software</label><select name="software" className="form-control" value={formData.software} onChange={handleChange}><option value="">Select software</option>{SOFTWARE_OPTIONS.map((option) => <option key={option}>{option}</option>)}</select>
                        </div><div className="form-group">
                            <label className="form-label">Installation Status *</label>
                            <select 
                                name="installation_status"
                                className="form-control"
                                value={formData.installation_status}
                                onChange={handleChange}
                                required
                            >
                                <option value="Onsite">Onsite</option>
                                <option value="Offsite">Offsite</option>
                                <option value="Not Willing">Not Willing</option>
                            </select>
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
