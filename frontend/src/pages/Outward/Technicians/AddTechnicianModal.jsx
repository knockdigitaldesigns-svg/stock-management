import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import api from '../../../services/api';
import DateInput from '../../../components/DateInput';
import PersonnelCredentialNotice from '../../../components/PersonnelCredentialNotice';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

const AddTechnicianModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [formData, setFormData] = useState({
        technician_name: '',
        mobile_no: '',
        alternate_mobile_no: '',
        location: '',
        installation_service_areas: '',
        enrolled_date: '',
        status: 'active',
        notes: ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [credentials, setCredentials] = useState(null);
    const generatedUsername = formData.technician_name.trim().replace(/\s+/g, '').toLowerCase();

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
        if (!/^[6-9][0-9]{9}$/.test(formData.mobile_no)) {
            triggerError('Enter a valid 10-digit mobile number.');
            return;
        }

        if (formData.alternate_mobile_no && (formData.alternate_mobile_no.length !== 10 || !/^\d{10}$/.test(formData.alternate_mobile_no))) {
            triggerError('Alternate mobile number must contain exactly 10 digits.');
            return;
        }

        if (password.length < 8) {
            triggerError('Password must be at least 8 characters long.');
            return;
        }
        if (password !== confirmPassword) {
            triggerError('Passwords do not match.');
            return;
        }

        setLoading(true);
        try {
            const response = await api.post('/technicians/create.php', {
                ...formData,
                password,
                confirm_password: confirmPassword
            });
            if (response.data.success) {
                setCredentials({
                    username: response.data.data?.user?.username || generatedUsername,
                    role: response.data.data?.user?.role || 'Technician',
                    password
                });
            } else {
                triggerError(response.data.message || 'Failed to save technician');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Network error occurred');
        } finally {
            setLoading(false);
        }
    };

    if (credentials) {
        return (
            <div className="modal-overlay">
                <div className="modal-content" style={{ maxWidth: '500px' }}>
                    <div className="modal-header">
                        <h3>Technician Login Created</h3>
                        <button className="close-btn" onClick={onSuccess}>&times;</button>
                    </div>
                    <div className="modal-body">
                        <PersonnelCredentialNotice credentials={credentials} onDone={onSuccess} />
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '500px' }}>
                <div className="modal-header">
                    <h3>Add Technician</h3>
                    <button className="close-btn" onClick={onClose}>&times;</button>
                </div>
                
                <form onSubmit={handleSubmit}>
                    <div className="modal-body">
                        {error && <div className="alert alert-danger" role="alert">{error}</div>}
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
                            <label className="form-label">Installation Service Areas</label>
                            <textarea
                                name="installation_service_areas"
                                className="form-control"
                                rows="2"
                                value={formData.installation_service_areas}
                                onChange={handleChange}
                                placeholder="Coimbatore, Pollachi, Mettupalayam"
                            />
                            <small>Enter only cities/areas this technician explicitly serves, separated by commas or new lines.</small>
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
                            <label className="form-label">Status *</label>
                            <select
                                name="status"
                                className="form-control"
                                value={formData.status}
                                onChange={handleChange}
                                required
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <hr />
                        <h4>Login Account</h4>
                        <p>Employee name and mobile number are used to create this account. The Technician role is assigned automatically.</p>
                        <div className="form-group">
                            <label className="form-label">Employee Name</label>
                            <input className="form-control" value={formData.technician_name} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Mobile Number</label>
                            <input className="form-control" value={formData.mobile_no} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Generated Username</label>
                            <input className="form-control" value={generatedUsername} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Password *</label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    className="form-control"
                                    autoComplete="new-password"
                                    value={password}
                                    onChange={(event) => setPassword(event.target.value)}
                                    required
                                />
                                <button type="button" className="btn btn-outline" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((visible) => !visible)}>
                                    {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Confirm Password *</label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input
                                    type={showConfirmPassword ? 'text' : 'password'}
                                    className="form-control"
                                    autoComplete="new-password"
                                    value={confirmPassword}
                                    onChange={(event) => setConfirmPassword(event.target.value)}
                                    required
                                />
                                <button type="button" className="btn btn-outline" aria-label={showConfirmPassword ? 'Hide confirmation password' : 'Show confirmation password'} onClick={() => setShowConfirmPassword((visible) => !visible)}>
                                    {showConfirmPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
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
