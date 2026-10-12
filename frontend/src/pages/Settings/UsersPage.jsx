import { useEffect, useState } from 'react';
import { Plus, Eye, EyeOff, Trash2, Pencil, KeyRound, Copy } from 'lucide-react';
import api from '../../services/api';
import Can from '../../components/Can/Can';
import { formatDate } from '../../utils/date';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import useModalScrollLock from '../../hooks/useModalScrollLock';
import Modal from '../../components/Modal/Modal';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { showGlobalError } from '../../context/ErrorContext';
import { useAuth } from '../../context/AuthContext';

const UsersPage = () => {
    const { user: currentUser } = useAuth();
    const isSuperAdmin = ['super admin', 'super_admin'].includes(
        String(currentUser?.role?.name ?? currentUser?.role ?? '').trim().toLowerCase()
    );
    const [users, setUsers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    useModalScrollLock(showModal);
    const [form, setForm] = useState({ employee_name: '', mobile_no: '', role_id: '', password: '', confirm_password: '', status: 'active' });
    const [viewingUser, setViewingUser] = useState(null);
    const [editingUser, setEditingUser] = useState(null);
    const [error, setError] = useState('');
    const [employeeNameError, setEmployeeNameError] = useState('');
    const [deleteUserTarget, setDeleteUserTarget] = useState(null);
    const [mobileError, setMobileError] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [credentialNotice, setCredentialNotice] = useState(null);
    const [copyError, setCopyError] = useState('');
    const [resetPasswordTarget, setResetPasswordTarget] = useState(null);
    const [resettingPassword, setResettingPassword] = useState(false);
    const [resetPasswordForm, setResetPasswordForm] = useState({ password: '', confirm_password: '' });
    const [resetPasswordError, setResetPasswordError] = useState('');
    const [showResetPassword, setShowResetPassword] = useState(false);
    const [showResetConfirmPassword, setShowResetConfirmPassword] = useState(false);
    const [copiedPassword, setCopiedPassword] = useState(false);
    const [viewCredentialPassword, setViewCredentialPassword] = useState(null);
    const [viewCredentialUnavailable, setViewCredentialUnavailable] = useState('');
    const [viewCredentialLoading, setViewCredentialLoading] = useState(false);
    const [viewCredentialError, setViewCredentialError] = useState('');
    const [showViewCredentialPassword, setShowViewCredentialPassword] = useState(false);
    const [viewCredentialCopied, setViewCredentialCopied] = useState(false);
    const validateMobile = (value) => {
    const mobile = String(value || '').trim();

    if (!mobile) {
        setMobileError('Mobile number is required.');
        return false;
    }

    if (!/^[6-9][0-9]{9}$/.test(mobile)) {
        setMobileError('Enter a valid 10-digit mobile number.');
        return false;
    }

    setMobileError('');
    return true;
};
    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const normalizeEmployeeName = (value) => value.trim().replace(/\s+/g, '').toLowerCase();
    const validateEmployeeName = (value) => {
        const normalized = normalizeEmployeeName(value);
        const duplicate = normalized && users.some((user) => String(user.id) !== String(editingUser?.id) && normalizeEmployeeName(user.employee_name || user.username || '') === normalized);
        const message = duplicate ? 'Employee name already exists. Please enter a different name.' : '';
        setEmployeeNameError(message);
        return !message;
    };

    const fetchUsers = async () => {
        try {
            const response = await api.get('/users/list.php');
            if (response.data.success) setUsers(response.data.data?.users || []);
        } catch (err) {
            console.error('Failed to fetch users', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchRoles = async () => {
        try {
            const response = await api.get('/roles/list.php');
            if (response.data.success) {
                setRoles((response.data.data?.roles || []).filter((role) => role.status === 'active'));
            }
        } catch (err) {
            console.error('Failed to fetch roles', err);
        }
    };

    useEffect(() => {
        fetchRoles();
        fetchUsers();
    }, []);

    const filteredUsers = filterTableRows(users, filters, { dateKeys: ['created_at'], searchKeys: ['employee_name', 'username', 'mobile_no', 'role_name', 'role'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredUsers, 10, [filters]);

    const handleSubmit = async () => {
    setError('');
    setMobileError('');

    if (!validateEmployeeName(form.employee_name)) return;

    if (!validateMobile(form.mobile_no)) return;

    if (!form.employee_name.trim() || !form.role_id) {
        triggerError(
            'Employee name, mobile number and role are required.'
        );
        return;
    }

    // Password validation ONLY for Add User
    if (!editingUser) {
        if (!form.password) {
            triggerError('Password is required.');
            return;
        }

        if (form.password.length < 8) {
            triggerError('Password must be at least 8 characters.');
            return;
        }

        if (form.password !== form.confirm_password) {
            triggerError('Passwords do not match.');
            return;
        }
    }

    try {
        const payload = {
            ...(editingUser ? { id: editingUser.id } : {}),
            employee_name: form.employee_name.trim(),
            mobile_no: form.mobile_no.trim(),
            role_id: Number(form.role_id),
            status: form.status
        };

        // Send password ONLY when creating a user
        if (!editingUser) {
            payload.password = form.password;
        }

        const response = await api.post(
            editingUser
                ? '/users/update.php'
                : '/users/create.php',
            payload
        );

        if (response.data.success) {
            if (!editingUser) {
                const createdUser = response.data.data?.user || {};
                setCredentialNotice({
                    title: 'User Created Successfully',
                    username: createdUser.username,
                    role: createdUser.role,
                    password: form.password
                });
                setCopiedPassword(false);
                setCopyError('');
            }
            setShowModal(false);
            setEditingUser(null);

            setForm({
                employee_name: '',
                mobile_no: '',
                role_id: '',
                password: '',
                confirm_password: '',
                status: 'active'
            });

            setEmployeeNameError('');
            setMobileError('');
            setError('');

            await fetchUsers();
        } else {
            triggerError(
                response.data.message ||
                (editingUser
                    ? 'Unable to update user.'
                    : 'Unable to create user.')
            );
        }
    } catch (err) {
        const message =
            err.response?.data?.message ||
            (editingUser
                ? 'Unable to update user.'
                : 'Unable to create user.');

        if (
            err.response?.status === 409 ||
            message.toLowerCase().includes('username') ||
            message.toLowerCase().includes('employee')
        ) {
            setEmployeeNameError(message);
            showGlobalError(message);
        } else if (
            message.toLowerCase().includes('mobile')
        ) {
            setMobileError(message);
            showGlobalError(message);
        } else {
            triggerError(message);
        }
    }
};
const requestDeleteUser = (user) => {
    setDeleteUserTarget(user);
};

const deleteUser = async () => {
    if (!deleteUserTarget) return;

    const user = deleteUserTarget;

    try {
        const response = await api.post('/users/delete.php', {
            id: user.id
        });

        if (!response.data.success) {
            showGlobalError(
                response.data.message ||
                'Unable to delete user.'
            );
            return;
        }

        setDeleteUserTarget(null);

        await fetchUsers();
    } catch (err) {
        showGlobalError(
            err.response?.data?.message ||
            'Unable to delete user.'
        );
    }
};

const resetUserPassword = async () => {
    if (!resetPasswordTarget) return;
    setResetPasswordError('');
    if (!resetPasswordForm.password || resetPasswordForm.password.length < 8) {
        setResetPasswordError('Password must be at least 8 characters.');
        return;
    }
    if (resetPasswordForm.password !== resetPasswordForm.confirm_password) {
        setResetPasswordError('Passwords do not match.');
        return;
    }

    const submittedPassword = resetPasswordForm.password;
    setResettingPassword(true);
    try {
        const response = await api.post('/users/reset_password.php', {
            id: resetPasswordTarget.id,
            password: submittedPassword
        });
        if (!response.data.success) {
            showGlobalError(response.data.message || 'Unable to reset user password.');
            return;
        }
        const resetUser = response.data.data?.user || resetPasswordTarget;
        setCredentialNotice({
            title: 'Password Reset Successfully',
            username: resetUser.username,
            role: resetUser.role || resetUser.role_name,
            password: submittedPassword
        });
        setCopiedPassword(false);
        setCopyError('');
        setResetPasswordTarget(null);
        setResetPasswordForm({ password: '', confirm_password: '' });
        setShowResetPassword(false);
        setShowResetConfirmPassword(false);
    } catch (err) {
        showGlobalError(err.response?.data?.message || 'Unable to reset user password.');
    } finally {
        setResettingPassword(false);
    }
};

const copyCredentialPassword = async () => {
    if (!credentialNotice?.password) return;
    try {
        await navigator.clipboard.writeText(credentialNotice.password);
        setCopiedPassword(true);
        setCopyError('');
    } catch {
        setCopyError('Unable to copy automatically. Select and copy the password above.');
    }
};

const clearViewedCredential = () => {
    setViewCredentialPassword(null);
    setViewCredentialUnavailable('');
    setViewCredentialLoading(false);
    setViewCredentialError('');
    setShowViewCredentialPassword(false);
    setViewCredentialCopied(false);
};

const revealUserCredential = async () => {
    if (!viewingUser || !isSuperAdmin) return;
    setViewCredentialError('');
    if (viewCredentialPassword !== null) {
        setShowViewCredentialPassword((visible) => !visible);
        return;
    }

    setViewCredentialLoading(true);
    try {
        const response = await api.post('/users/reveal_credential.php', { id: viewingUser.id });
        if (!response.data.success) {
            throw new Error(response.data.message || 'Unable to retrieve user credential.');
        }
        if (!response.data.data?.available) {
            setViewCredentialUnavailable(
                response.data.data?.message || 'Not available — reset password to set a new password.'
            );
            return;
        }
        setViewCredentialPassword(response.data.data.password);
        setShowViewCredentialPassword(true);
    } catch (requestError) {
        setViewCredentialError(
            requestError.response?.data?.message ||
            requestError.message ||
            'Unable to retrieve user credential.'
        );
    } finally {
        setViewCredentialLoading(false);
    }
};

const copyViewedCredential = async () => {
    if (viewCredentialPassword === null) return;
    try {
        await navigator.clipboard.writeText(viewCredentialPassword);
        setViewCredentialCopied(true);
        setViewCredentialError('');
    } catch {
        setViewCredentialError('Unable to copy automatically. Select and copy the password above.');
    }
};
    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Add User</h2>
                    <p className="page-subtitle">Create and manage user accounts and their assigned roles.</p>
                </div>
                <div className="header-actions">
                    <Can permission="users.add">
                        <button className="btn btn-primary" type="button" onClick={() => { setEditingUser(null); setShowPassword(false); setShowConfirmPassword(false); setForm({ employee_name: '', mobile_no: '', role_id: '', password: '', confirm_password: '', status: 'active' }); setShowModal(true); }}>
                            <Plus size={16} /> Add User
                        </button>
                    </Can>
                </div>
            </div>

            <div className="card">
                <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={users} dateKeys={['created_at']} searchPlaceholder="Search by employee name, mobile, or role..." />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th>Username</th>
                                <th>Mobile No</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="7" className="text-center">Loading users...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr><td colSpan="7" className="text-center empty-state">No records found for the selected filters.</td></tr>
                            ) : paginatedItems.map((user) => (
                                <tr key={user.id}>
                                    <td className="truncate-cell" title={user.employee_name || user.username} style={{ fontWeight: 500 }}>{user.employee_name || user.username}</td>
                                    <td>{user.username}</td>
                                    <td>{user.mobile_no}</td>
                                    <td>{user.role_name || user.role}</td>
                                    <td><span className={`badge ${user.status === 'active' ? 'badge-success' : 'badge-danger'}`}>{user.status}</span></td>
                                    <td>{formatDate(user.created_at)}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button className="icon-btn view" type="button" aria-label="View user" title="View" onClick={() => { clearViewedCredential(); setViewingUser(user); }}><Eye size={16} /></button>
                                            <Can permission="users.edit"><button className="icon-btn edit" type="button" aria-label="Edit user" onClick={() => { setEditingUser(user); setShowPassword(false); setShowConfirmPassword(false); setForm({ employee_name: user.employee_name || '', mobile_no: user.mobile_no || '', role_id: String(user.role_id || ''), password: '', confirm_password: '', status: user.status || 'active' }); setEmployeeNameError(''); setError(''); setShowModal(true); }}><Pencil size={16} /></button></Can>
                                            {isSuperAdmin && <Can permission="users.edit"><button className="icon-btn edit" type="button" aria-label={`Reset password for ${user.username}`} title="Reset Password" onClick={() => { setResetPasswordTarget(user); setResetPasswordForm({ password: '', confirm_password: '' }); setResetPasswordError(''); setShowResetPassword(false); setShowResetConfirmPassword(false); }}><KeyRound size={16} /></button></Can>}
                                            <Can permission="users.delete">
    <button
        className="icon-btn delete"
        type="button"
        aria-label="Delete user"
        title="Delete"
        onClick={() => requestDeleteUser(user)}
    >
        <Trash2 size={16} />
    </button>
</Can>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={page}
                    totalItems={totalItems}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="users"
                />
            </div>

            {showModal && (
                <div className="modal-overlay" onClick={() => setShowModal(false)}>
                    <div className="modal-shell" style={{ maxWidth: '520px' }} onClick={(event) => event.stopPropagation()}>
                        <div className="modal-header">
                                <h3>{editingUser ? 'Edit User' : 'Add User'}</h3>
                            <button type="button" className="close-btn" onClick={() => setShowModal(false)}>&times;</button>
                        </div>
                        <div className="modal-body">
                            {error && <div className="alert alert-danger">{error}</div>}
                            <div className="form-group">
                                <label className="form-label">Employee Name *</label>
                                <input className="form-control" value={form.employee_name} onChange={(e) => { setForm({ ...form, employee_name: e.target.value }); setEmployeeNameError(''); }} onBlur={(e) => validateEmployeeName(e.target.value)} />
                                {employeeNameError && <div className="text-danger">{employeeNameError}</div>}
                            </div>
                            <div className="form-group">
                                <label className="form-label">Mobile No *</label>
                                <input
    type="tel"
    inputMode="numeric"
    maxLength={10}
    className="form-control"
    value={form.mobile_no}
    onChange={(e) => {
        const value = e.target.value.replace(/\D/g, '').slice(0, 10);

        setForm({
            ...form,
            mobile_no: value
        });

        setMobileError('');
    }}
    onBlur={(e) => validateMobile(e.target.value)}
/>

{mobileError && (
    <div className="text-danger" style={{ marginTop: '4px' }}>
        {mobileError}
    </div>
)}
                            </div>
                            <div className="form-group">
                                <label className="form-label">Role *</label>
                                <SearchableDropdown
                                    options={roles.map((role) => ({ value: String(role.id), label: role.role_name }))}
                                    value={form.role_id}
                                    onChange={(val) => setForm({ ...form, role_id: val })}
                                    placeholder="Select Role"
                                />
                            </div>
                            {!editingUser && (
    <>
        <div className="form-group">
            <label className="form-label">Password *</label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    className="form-control"
                                    autoComplete="new-password"
                                    value={form.password}
                                    onChange={(e) => setForm({ ...form, password: e.target.value })}
                                />
                                <button className="btn btn-outline" type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword((visible) => !visible)}>
                                    {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
        </div>

        <div className="form-group">
            <label className="form-label">
                Confirm Password *
            </label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input
                                    type={showConfirmPassword ? 'text' : 'password'}
                                    className="form-control"
                                    autoComplete="new-password"
                                    value={form.confirm_password}
                                    onChange={(e) => setForm({ ...form, confirm_password: e.target.value })}
                                />
                                <button className="btn btn-outline" type="button" aria-label={showConfirmPassword ? 'Hide confirmation password' : 'Show confirmation password'} onClick={() => setShowConfirmPassword((visible) => !visible)}>
                                    {showConfirmPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
        </div>
    </>
)}
                            <div className="form-group">
                                <label className="form-label">Status</label>
                                <select className="form-control" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div className="modal-footer">
                            <button className="btn btn-outline" onClick={() => setShowModal(false)}>Cancel</button>
                            <button className="btn btn-primary" onClick={handleSubmit}>{editingUser ? 'Update User' : 'Save User'}</button>
                        </div>
                    </div>
                </div>
            )}
            <Modal
    isOpen={Boolean(deleteUserTarget)}
    onClose={() => setDeleteUserTarget(null)}
    title="Delete User"
    maxWidth="420px"
>
    <div style={{ padding: '0.25rem 0' }}>
        <p style={{ margin: 0, lineHeight: 1.6 }}>
            Are you sure you want to delete{' '}
            <strong>
                {deleteUserTarget?.employee_name ||
                    deleteUserTarget?.username}
            </strong>
            ?
        </p>
    </div>

    <div
        style={{
            display: 'flex',
            justifyContent: 'flex-end',
            gap: '10px',
            marginTop: '1.25rem'
        }}
    >
        <button
            type="button"
            className="btn btn-outline"
            onClick={() => setDeleteUserTarget(null)}
        >
            Cancel
        </button>

        <button
            type="button"
            className="btn btn-danger"
            onClick={deleteUser}
        >
            Delete
        </button>
    </div>
</Modal>
            <Modal
                isOpen={Boolean(resetPasswordTarget)}
                onClose={() => {
                    if (resettingPassword) return;
                    setResetPasswordTarget(null);
                    setResetPasswordForm({ password: '', confirm_password: '' });
                    setResetPasswordError('');
                }}
                title="Reset User Password"
                maxWidth="440px"
                footer={(
                    <>
                        <button type="button" className="btn btn-outline" disabled={resettingPassword} onClick={() => { setResetPasswordTarget(null); setResetPasswordForm({ password: '', confirm_password: '' }); setResetPasswordError(''); }}>Cancel</button>
                        <button type="button" className="btn btn-primary" disabled={resettingPassword} onClick={resetUserPassword}>
                            {resettingPassword ? 'Resetting...' : 'Reset Password'}
                        </button>
                    </>
                )}
            >
                <p>
                    Set a new password for <strong>{resetPasswordTarget?.username}</strong>
                    {' '}({resetPasswordTarget?.role_name || resetPasswordTarget?.role}).
                </p>
                {resetPasswordError && <div className="alert alert-danger">{resetPasswordError}</div>}
                <div className="form-group">
                    <label className="form-label">New Password *</label>
                    <div style={{ display: 'flex', gap: '8px' }}>
                        <input
                            type={showResetPassword ? 'text' : 'password'}
                            className="form-control"
                            autoComplete="new-password"
                            value={resetPasswordForm.password}
                            onChange={(event) => setResetPasswordForm({ ...resetPasswordForm, password: event.target.value })}
                        />
                        <button className="btn btn-outline" type="button" aria-label={showResetPassword ? 'Hide new password' : 'Show new password'} onClick={() => setShowResetPassword((visible) => !visible)}>
                            {showResetPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>
                </div>
                <div className="form-group">
                    <label className="form-label">Confirm New Password *</label>
                    <div style={{ display: 'flex', gap: '8px' }}>
                        <input
                            type={showResetConfirmPassword ? 'text' : 'password'}
                            className="form-control"
                            autoComplete="new-password"
                            value={resetPasswordForm.confirm_password}
                            onChange={(event) => setResetPasswordForm({ ...resetPasswordForm, confirm_password: event.target.value })}
                        />
                        <button className="btn btn-outline" type="button" aria-label={showResetConfirmPassword ? 'Hide confirmation password' : 'Show confirmation password'} onClick={() => setShowResetConfirmPassword((visible) => !visible)}>
                            {showResetConfirmPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>
                </div>
            </Modal>
            <Modal
                isOpen={Boolean(credentialNotice)}
                onClose={() => setCredentialNotice(null)}
                title={credentialNotice?.title || 'User Credentials'}
                maxWidth="460px"
            >
                <p>Save these credentials securely. A Super Admin can also view this password later in User Details.</p>
                <div className="form-group">
                    <label className="form-label">Username</label>
                    <input className="form-control" readOnly value={credentialNotice?.username || ''} />
                </div>
                <div className="form-group">
                    <label className="form-label">Role</label>
                    <input className="form-control" readOnly value={credentialNotice?.role || ''} />
                </div>
                <div className="form-group">
                    <label className="form-label">Password</label>
                    <input className="form-control" readOnly value={credentialNotice?.password || ''} />
                </div>
                {copyError && <div className="text-danger" role="alert">{copyError}</div>}
                <div className="modal-footer" style={{ padding: 0, borderTop: 0 }}>
                    <button type="button" className="btn btn-outline" onClick={copyCredentialPassword}>
                        <Copy size={16} /> {copiedPassword ? 'Copied' : 'Copy Password'}
                    </button>
                    <button type="button" className="btn btn-primary" onClick={() => setCredentialNotice(null)}>Done</button>
                </div>
            </Modal>
            {viewingUser && (
                <RecordViewModal
                    isOpen
                    onClose={() => {
                        clearViewedCredential();
                        setViewingUser(null);
                    }}
                    title="User Details"
                    record={viewingUser}
                    fetchRecord={async (row) => (await api.get('/users/list.php')).data.data.users.find((user) => String(user.id) === String(row.id)) || row}
                    fields={[
                        { label: 'Employee Name', key: 'employee_name' },
                        { label: 'Username', key: 'username' },
                        { label: 'Mobile No', key: 'mobile_no' },
                        { label: 'Role', key: 'role_name', format: (value, row) => value || row.role },
                        { label: 'Status', key: 'status' },
                        { label: 'Created Date', key: 'created_at' }
                    ]}
                    renderDetails={() => (
                        <div className="details-grid">
                            <div>
                                <strong>Password:</strong>{' '}
                                {viewCredentialUnavailable
                                    ? <span>{viewCredentialUnavailable}</span>
                                    : viewCredentialPassword === null
                                        ? (
                                            isSuperAdmin
                                                ? (
                                                    <button
                                                        type="button"
                                                        className="btn btn-outline"
                                                        disabled={viewCredentialLoading}
                                                        onClick={revealUserCredential}
                                                    >
                                                        {viewCredentialLoading
                                                            ? 'Loading...'
                                                            : <><Eye size={16} /> Show Password</>}
                                                    </button>
                                                )
                                                : <span>Only a Super Admin can reveal this password.</span>
                                        )
                                        : (
                                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                <span>{showViewCredentialPassword ? viewCredentialPassword : '••••••••'}</span>
                                                <button
                                                    type="button"
                                                    className="icon-btn view"
                                                    aria-label={showViewCredentialPassword ? 'Hide password' : 'Show password'}
                                                    title={showViewCredentialPassword ? 'Hide password' : 'Show password'}
                                                    onClick={revealUserCredential}
                                                >
                                                    {showViewCredentialPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                                </button>
                                                {showViewCredentialPassword && (
                                                    <button type="button" className="icon-btn view" aria-label="Copy password" title={viewCredentialCopied ? 'Copied' : 'Copy password'} onClick={copyViewedCredential}>
                                                        <Copy size={16} />
                                                    </button>
                                                )}
                                            </span>
                                        )}
                                {viewCredentialError && <div className="text-danger" role="alert">{viewCredentialError}</div>}
                            </div>
                        </div>
                    )}
                />
            )}
        </div>
    );
};

export default UsersPage;
