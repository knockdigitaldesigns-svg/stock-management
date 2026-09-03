import { useEffect, useState } from 'react';
import { Plus, Eye, Save, ShieldCheck } from 'lucide-react';
import api from '../../services/api';
import Can from '../../components/Can/Can';
import { formatDate } from '../../utils/date';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import useModalScrollLock from '../../hooks/useModalScrollLock';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';

const defaultPermissionMatrix = [
    { module: 'Dashboard', key: 'dashboard.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Device Maintenance', key: 'devices.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'SIM Maintenance', key: 'sims.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Inward Reports', key: 'inward_reports.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Dealer', key: 'dealers.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Technician', key: 'technicians.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Outward Reports', key: 'outward_reports.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Stock Management', key: 'stock.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Users', key: 'users.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Roles', key: 'roles.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Permissions', key: 'permissions.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Device Alert', key: 'device_alert.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Device Types', key: 'device_types.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'SIM Validity', key: 'sim_validity.view', view: false, add: false, edit: false, delete: false, export: false, update: false },
    { module: 'Change Password', key: 'password.change', view: false, add: false, edit: false, delete: false, export: false, update: false }
];

const RolesPermissionsPage = () => {
    const [tab, setTab] = useState('roles');
    const [roles, setRoles] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [selectedRoleId, setSelectedRoleId] = useState('');
    const [matrix, setMatrix] = useState(defaultPermissionMatrix);
    const [showRoleModal, setShowRoleModal] = useState(false);
    useModalScrollLock(showRoleModal);
    const [newRole, setNewRole] = useState({ role_name: '', description: '', status: 'active' });
    const [viewingRole, setViewingRole] = useState(null);

    const fetchRoles = async () => {
        try {
            const response = await api.get('/roles/list.php');
            if (response.data.success) {
                const list = response.data.data?.roles || [];
                setRoles(list);
                if (!selectedRoleId && list.length) {
                    setSelectedRoleId(String(list[0].id));
                }
            }
        } catch (error) {
            console.error('Failed to fetch roles', error);
        }
    };

    const fetchPermissions = async (roleId = selectedRoleId) => {
        if (!roleId) return;
        try {
            const response = await api.get(`/permissions/role_permissions.php?role_id=${roleId}`);
            if (response.data.success) {
                const assigned = response.data.data?.permissions || [];
                setMatrix(defaultPermissionMatrix.map((item) => ({
                    ...item,
                    view: assigned.includes(item.key),
                    add: assigned.includes(item.key.replace('.view', '.add')),
                    edit: assigned.includes(item.key.replace('.view', '.edit')),
                    delete: assigned.includes(item.key.replace('.view', '.delete')),
                    export: assigned.includes(item.key.replace('.view', '.export')),
                    update: assigned.includes(item.key.replace('.view', '.update'))
                })));
            }
        } catch (error) {
            console.error('Failed to fetch permissions', error);
        }
    };

    const saveRolePermissions = async () => {
        if (!selectedRoleId) return;
        const selectedPermissions = [];
        matrix.forEach((row) => {
            if (row.view) selectedPermissions.push(row.key);
            if (row.add) selectedPermissions.push(row.key.replace('.view', '.add'));
            if (row.edit) selectedPermissions.push(row.key.replace('.view', '.edit'));
            if (row.delete) selectedPermissions.push(row.key.replace('.view', '.delete'));
            if (row.export) selectedPermissions.push(row.key.replace('.view', '.export'));
            if (row.update) selectedPermissions.push(row.key.replace('.view', '.update'));
        });

        try {
            const response = await api.post('/permissions/assign.php', {
                role_id: Number(selectedRoleId),
                permissions: selectedPermissions
            });
            if (response.data.success) {
                alert('Permissions saved');
            } else {
                alert(response.data.message || 'Unable to save permissions');
            }
        } catch (error) {
            alert(error.response?.data?.message || 'Unable to save permissions');
        }
    };

    const createRole = async () => {
        try {
            const response = await api.post('/roles/create.php', newRole);
            if (response.data.success) {
                setShowRoleModal(false);
                setNewRole({ role_name: '', description: '', status: 'active' });
                fetchRoles();
            } else {
                alert(response.data.message || 'Unable to create role');
            }
        } catch (error) {
            alert(error.response?.data?.message || 'Unable to create role');
        }
    };

    useEffect(() => {
        fetchRoles();
    }, []);

    useEffect(() => {
        fetchPermissions();
    }, [selectedRoleId]);

    const togglePermission = (rowIndex, field) => {
        setMatrix((prev) => prev.map((row, index) => index === rowIndex ? { ...row, [field]: !row[field] } : row));
    };

    const filteredRoles = filterTableRows(roles, filters, { dateKeys: ['created_at'], searchKeys: ['role_name', 'description', 'status'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredRoles, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Roles & Permissions</h2>
                <div className="header-actions">
                    <Can permission="roles.add">
                        <button className="btn btn-primary" onClick={() => setShowRoleModal(true)}>
                            <Plus size={16} /> Add Role
                        </button>
                    </Can>
                </div>
            </div>

            <div className="card" style={{ padding: 0 }}>
                <div className="tab-row" style={{ display: 'flex', gap: '1rem', padding: '1rem 1.5rem', borderBottom: '1px solid #e2e8f0' }}>
                    <button className={`btn ${tab === 'roles' ? 'btn-primary' : 'btn-outline'}`} onClick={() => setTab('roles')}>Roles</button>
                    <button className={`btn ${tab === 'permissions' ? 'btn-primary' : 'btn-outline'}`} onClick={() => setTab('permissions')}>Permissions</button>
                </div>

                {tab === 'roles' && (
                    <div style={{ padding: '1.5rem' }}>
                        <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={roles} dateKeys={['created_at']} searchPlaceholder="Search roles..." />

                        <div className="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Role Name</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Created Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {paginatedItems.length === 0 ? (
                                        <tr>
                                            <td colSpan="5" className="text-center empty-state">
                                                No records found for the selected filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        paginatedItems.map((role) => (
                                            <tr key={role.id}>
                                                <td className="truncate-cell" title={role.role_name} style={{ fontWeight: 500 }}>{role.role_name}</td>
                                                <td className="truncate-cell-lg" title={role.description}>{role.description || '-'}</td>
                                                <td><span className={`badge ${role.status === 'active' ? 'badge-success' : 'badge-danger'}`}>{role.status}</span></td>
                                                <td>{formatDate(role.created_at)}</td>
                                                <td>
                                                    <div className="action-buttons">
                                                        <button className="icon-btn view" type="button" aria-label="View role" title="View" onClick={() => setViewingRole(role)}><Eye size={16} /></button>
                                                        <Can permission="roles.edit"><button className="icon-btn edit" type="button" aria-label="Edit role">✎</button></Can>
                                                        <Can permission="roles.delete"><button className="icon-btn delete" type="button" aria-label="Delete role">🗑</button></Can>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <Pagination
                            currentPage={page}
                            totalItems={totalItems}
                            pageSize={pageSize}
                            onPageChange={setPage}
                            onPageSizeChange={setPageSize}
                            itemName="roles"
                        />
                    </div>
                )}

                {tab === 'permissions' && (
                    <div style={{ padding: '1.5rem' }}>
                        <div className="form-group" style={{ maxWidth: '320px' }}>
                            <label className="form-label">Select Role</label>
                            <SearchableDropdown
                                options={roles.map((role) => ({ value: String(role.id), label: role.role_name }))}
                                value={selectedRoleId}
                                onChange={(val) => setSelectedRoleId(val)}
                                placeholder="Select Role"
                            />
                        </div>

                        <div className="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Module</th>
                                        <th>View</th>
                                        <th>Add</th>
                                        <th>Edit</th>
                                        <th>Delete</th>
                                        <th>Export</th>
                                        <th>Update</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {matrix.map((row, rowIndex) => (
                                        <tr key={row.module}>
                                            <td>{row.module}</td>
                                            <td><input type="checkbox" checked={row.view} onChange={() => togglePermission(rowIndex, 'view')} /></td>
                                            <td><input type="checkbox" checked={row.add} onChange={() => togglePermission(rowIndex, 'add')} /></td>
                                            <td><input type="checkbox" checked={row.edit} onChange={() => togglePermission(rowIndex, 'edit')} /></td>
                                            <td><input type="checkbox" checked={row.delete} onChange={() => togglePermission(rowIndex, 'delete')} /></td>
                                            <td><input type="checkbox" checked={row.export} onChange={() => togglePermission(rowIndex, 'export')} /></td>
                                            <td><input type="checkbox" checked={row.update} onChange={() => togglePermission(rowIndex, 'update')} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div style={{ display: 'flex', gap: '1rem', marginTop: '1rem' }}>
                            <button className="btn btn-outline" type="button">Select All</button>
                            <button className="btn btn-outline" type="button">Clear All</button>
                            <Can permission="permissions.assign">
                                <button className="btn btn-primary" type="button" onClick={saveRolePermissions}>
                                    <Save size={16} /> Save Permissions
                                </button>
                            </Can>
                        </div>
                    </div>
                )}
            </div>

            {showRoleModal && (
                <div className="modal-overlay" onClick={() => setShowRoleModal(false)}>
                    <div className="modal-shell" style={{ maxWidth: '480px' }} onClick={(event) => event.stopPropagation()}>
                        <div className="modal-header">
                            <h3>Add Role</h3>
                            <button type="button" className="close-btn" onClick={() => setShowRoleModal(false)}>&times;</button>
                        </div>
                        <div className="modal-body">
                            <div className="form-group">
                                <label className="form-label">Role Name *</label>
                                <input className="form-control" value={newRole.role_name} onChange={(e) => setNewRole({ ...newRole, role_name: e.target.value })} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Description</label>
                                <textarea className="form-control" rows="3" value={newRole.description} onChange={(e) => setNewRole({ ...newRole, description: e.target.value })} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Status</label>
                                <select className="form-control" value={newRole.status} onChange={(e) => setNewRole({ ...newRole, status: e.target.value })}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div className="modal-footer">
                            <button className="btn btn-outline" onClick={() => setShowRoleModal(false)}>Cancel</button>
                            <button className="btn btn-primary" onClick={createRole}>Save Role</button>
                        </div>
                    </div>
                </div>
            )}
            {viewingRole && <RecordViewModal isOpen onClose={() => setViewingRole(null)} title="Role Details" record={viewingRole} fetchRecord={async (row) => (await api.get('/roles/list.php')).data.data.roles.find((role) => String(role.id) === String(row.id)) || row} fields={[{ label: 'Role Name', key: 'role_name' }, { label: 'Description', key: 'description' }, { label: 'Status', key: 'status' }, { label: 'Created Date', key: 'created_at' }]} />}
        </div>
    );
};

export default RolesPermissionsPage;
