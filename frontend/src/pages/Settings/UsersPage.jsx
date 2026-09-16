import { useEffect, useState } from 'react';
import { Plus, Eye, Trash2, Pencil } from 'lucide-react';
import api from '../../services/api';
import Can from '../../components/Can/Can';
import { formatDate } from '../../utils/date';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import useModalScrollLock from '../../hooks/useModalScrollLock';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import { showGlobalError } from '../../context/ErrorContext';

const UsersPage = () => {
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
        if (!validateEmployeeName(form.employee_name)) return;
        if (!form.employee_name || !form.mobile_no || !form.role_id || (!editingUser && !form.password)) {
            triggerError('Employee name, mobile number, role and password are required');
            return;
        }
        if (form.password.length < 8) {
            triggerError('Password must be at least 8 characters');
            return;
        }
        if (form.password !== form.confirm_password) {
            triggerError('Passwords do not match');
            return;
        }

        try {
            const response = await api.post(editingUser ? '/users/update.php' : '/users/create.php', {
                ...(editingUser ? { id: editingUser.id } : {}),
                employee_name: form.employee_name,
                mobile_no: form.mobile_no,
                role_id: Number(form.role_id),
                ...(form.password ? { password: form.password } : {}),
                status: form.status
            });

            if (response.data.success) {
                setShowModal(false);
                setEditingUser(null);
                setForm({ employee_name: '', mobile_no: '', role_id: '', password: '', confirm_password: '', status: 'active' });
                fetchUsers();
            } else {
                triggerError(response.data.message || 'Unable to create user');
            }
        } catch (err) {
            const message = err.response?.data?.message || (editingUser ? 'Unable to update user' : 'Unable to create user');
            if (err.response?.status === 409 || message.toLowerCase().includes('username') || message.toLowerCase().includes('employee')) {
                setEmployeeNameError(message);
                showGlobalError(message);
            } else {
                triggerError(message);
            }
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Add User</h2>
                <div className="header-actions">
                    <Can permission="users.add">
                        <button className="btn btn-primary" type="button" onClick={() => { setEditingUser(null); setForm({ employee_name: '', mobile_no: '', role_id: '', password: '', confirm_password: '', status: 'active' }); setShowModal(true); }}>
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
                                <th>Mobile No</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="6" className="text-center">Loading users...</td></tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr><td colSpan="6" className="text-center empty-state">No records found for the selected filters.</td></tr>
                            ) : paginatedItems.map((user) => (
                                <tr key={user.id}>
                                    <td className="truncate-cell" title={user.employee_name || user.username} style={{ fontWeight: 500 }}>{user.employee_name || user.username}</td>
                                    <td>{user.mobile_no}</td>
                                    <td>{user.role_name || user.role}</td>
                                    <td><span className={`badge ${user.status === 'active' ? 'badge-success' : 'badge-danger'}`}>{user.status}</span></td>
                                    <td>{formatDate(user.created_at)}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button className="icon-btn view" type="button" aria-label="View user" title="View" onClick={() => setViewingUser(user)}><Eye size={16} /></button>
                                            <Can permission="users.edit"><button className="icon-btn edit" type="button" aria-label="Edit user" onClick={() => { setEditingUser(user); setForm({ employee_name: user.employee_name || '', mobile_no: user.mobile_no || '', role_id: String(user.role_id || ''), password: '', confirm_password: '', status: user.status || 'active' }); setEmployeeNameError(''); setError(''); setShowModal(true); }}><Pencil size={16} /></button></Can>
                                            <Can permission="users.delete"><button className="icon-btn delete" type="button" aria-label="Delete user"><Trash2 size={16} /></button></Can>
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
                                <input className="form-control" value={form.mobile_no} onChange={(e) => setForm({ ...form, mobile_no: e.target.value })} />
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
                            <div className="form-group">
                                <label className="form-label">Password{editingUser ? '' : ' *'}</label>
                                <input type="password" className="form-control" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required={!editingUser} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Confirm Password{editingUser ? '' : ' *'}</label>
                                <input type="password" className="form-control" value={form.confirm_password} onChange={(e) => setForm({ ...form, confirm_password: e.target.value })} required={!editingUser} />
                            </div>
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
            {viewingUser && <RecordViewModal isOpen onClose={() => setViewingUser(null)} title="User Details" record={viewingUser} fetchRecord={async (row) => (await api.get('/users/list.php')).data.data.users.find((user) => String(user.id) === String(row.id)) || row} fields={[{ label: 'Employee Name', key: 'employee_name' }, { label: 'Username', key: 'username' }, { label: 'Mobile No', key: 'mobile_no' }, { label: 'Role', key: 'role_name', format: (value, row) => value || row.role }, { label: 'Status', key: 'status' }, { label: 'Created Date', key: 'created_at' }]} />}
        </div>
    );
};

export default UsersPage;
