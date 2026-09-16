import { useState } from 'react';
import api from '../../services/api';
import { showGlobalError } from '../../context/ErrorContext';

const ChangePasswordPage = () => {
    const [form, setForm] = useState({ current_password: '', new_password: '', confirm_password: '' });
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const handleChange = async () => {
        setError('');
        setSuccess('');

        if (!form.current_password || !form.new_password || !form.confirm_password) {
            triggerError('All fields are required');
            return;
        }
        if (form.new_password.length < 8) {
            triggerError('New password must be at least 8 characters long');
            return;
        }
        if (form.new_password !== form.confirm_password) {
            triggerError('New password and confirm password do not match');
            return;
        }

        try {
            const response = await api.post('/auth/change_password.php', form);
            if (response.data.success) {
                setSuccess('Password changed successfully');
                setForm({ current_password: '', new_password: '', confirm_password: '' });
            } else {
                triggerError(response.data.message || 'Failed to change password');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Failed to change password');
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Change Password</h2>
            </div>
            <div className="card" style={{ maxWidth: '520px' }}>
                {error && <div className="alert alert-danger">{error}</div>}
                {success && <div className="alert alert-success">{success}</div>}
                <div className="form-group">
                    <label className="form-label">Current Password *</label>
                    <input type="password" className="form-control" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} />
                </div>
                <div className="form-group">
                    <label className="form-label">New Password *</label>
                    <input type="password" className="form-control" value={form.new_password} onChange={(e) => setForm({ ...form, new_password: e.target.value })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Confirm New Password *</label>
                    <input type="password" className="form-control" value={form.confirm_password} onChange={(e) => setForm({ ...form, confirm_password: e.target.value })} />
                </div>
                <button className="btn btn-primary" type="button" onClick={handleChange}>Change Password</button>
            </div>
        </div>
    );
};

export default ChangePasswordPage;
