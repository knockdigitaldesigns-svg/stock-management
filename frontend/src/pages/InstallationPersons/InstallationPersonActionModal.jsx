import { useState } from 'react';
import Modal from '../../components/Modal/Modal';
import api from '../../services/api';
import { showToast } from '../../utils/toast';

const InstallationPersonActionModal = ({ person, action, onClose, onSuccess }) => {
    const [form, setForm] = useState({
        username: person.username || '',
        mobile_no: person.mobile_no || '',
        status: person.status || 'active',
        password: '',
        confirm_password: ''
    });
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const isEdit = action === 'edit';
    const isReset = action === 'reset';
    const title = isEdit
        ? 'Edit Installation Person Account'
        : isReset
            ? 'Reset Password'
            : 'Deactivate Login Account';

    const submit = async (event) => {
        event.preventDefault();
        setError('');

        if (isReset) {
            if (form.password.length < 8) {
                setError('Password must be at least 8 characters long.');
                return;
            }
            if (form.password !== form.confirm_password) {
                setError('Passwords do not match.');
                return;
            }
        }

        setSubmitting(true);
        try {
            const endpoint = isEdit
                ? '/installation_personnel/update.php'
                : isReset
                    ? '/installation_personnel/reset_password.php'
                    : '/installation_personnel/deactivate.php';
            const payload = isEdit
                ? {
                    type: person.personnel_type,
                    id: person.id,
                    username: form.username,
                    mobile_no: form.mobile_no,
                    status: form.status
                }
                : isReset
                    ? {
                        type: person.personnel_type,
                        id: person.id,
                        password: form.password,
                        confirm_password: form.confirm_password
                    }
                    : { type: person.personnel_type, id: person.id };
            const response = await api.post(endpoint, payload);
            if (!response.data?.success) {
                setError(response.data?.message || 'The requested action failed.');
                return;
            }
            showToast(response.data.message || 'Installation person account updated.');
            onSuccess();
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to complete the requested action.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            isOpen
            onClose={onClose}
            title={title}
            maxWidth="560px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={submitting}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="installation-personnel-action-form"
                        className={`btn ${action === 'deactivate' ? 'btn-danger' : 'btn-primary'}`}
                        disabled={submitting}
                    >
                        {submitting ? 'Saving...' : isEdit ? 'Save Changes' : isReset ? 'Reset Password' : 'Deactivate Login'}
                    </button>
                </>
            }
        >
            <form id="installation-personnel-action-form" onSubmit={submit}>
                <p className="installation-personnel-modal-person">
                    {person.name} <span>({person.role_name})</span>
                </p>
                {isEdit && (
                    <>
                        <div className="form-group">
                            <label className="form-label" htmlFor="installation-personnel-username">Username</label>
                            <input
                                id="installation-personnel-username"
                                className="form-control"
                                value={form.username}
                                maxLength={50}
                                onChange={(event) => setForm({ ...form, username: event.target.value })}
                                autoComplete="username"
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label" htmlFor="installation-personnel-mobile">Contact No</label>
                            <input
                                id="installation-personnel-mobile"
                                className="form-control"
                                value={form.mobile_no}
                                maxLength={10}
                                inputMode="numeric"
                                onChange={(event) => setForm({ ...form, mobile_no: event.target.value.replace(/\D/g, '') })}
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label" htmlFor="installation-personnel-status">Status</label>
                            <select
                                id="installation-personnel-status"
                                className="form-control"
                                value={form.status}
                                onChange={(event) => setForm({ ...form, status: event.target.value })}
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </>
                )}
                {isReset && (
                    <>
                        <div className="form-group">
                            <label className="form-label" htmlFor="installation-personnel-password">New Password</label>
                            <input
                                id="installation-personnel-password"
                                className="form-control"
                                type="password"
                                value={form.password}
                                onChange={(event) => setForm({ ...form, password: event.target.value })}
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <div className="form-group">
                            <label className="form-label" htmlFor="installation-personnel-confirm-password">Confirm Password</label>
                            <input
                                id="installation-personnel-confirm-password"
                                className="form-control"
                                type="password"
                                value={form.confirm_password}
                                onChange={(event) => setForm({ ...form, confirm_password: event.target.value })}
                                autoComplete="new-password"
                                required
                            />
                        </div>
                    </>
                )}
                {action === 'deactivate' && (
                    <p className="installation-personnel-deactivate-note">
                        This disables the login account. The Dealer/Technician profile, assignments, and transaction history will be preserved.
                    </p>
                )}
                {error && <div className="alert alert-danger" role="alert">{error}</div>}
            </form>
        </Modal>
    );
};

export default InstallationPersonActionModal;
