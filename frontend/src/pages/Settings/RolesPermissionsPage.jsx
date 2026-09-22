import { useEffect, useState } from 'react';
import { Plus, Eye, Save, Edit, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Can from '../../components/Can/Can';
import { formatDate } from '../../utils/date';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import useModalScrollLock from '../../hooks/useModalScrollLock';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import Modal from '../../components/Modal/Modal';

const MODULE_LABELS = {
    dashboard: 'Dashboard', devices: 'Device Maintenance', sims: 'SIM Maintenance', inward_reports: 'Inward Reports',
    dealers: 'Dealer', technicians: 'Technician', outward_reports: 'Outward Reports', stock: 'Stock Management',
    stock_transfer: 'Stock Transfer', device_alert: 'Device Alert', customers: 'Customer Details',
    customer_reports: 'Customer Reports', customer_renewals: 'Renewals', roles: 'Roles', permissions: 'Permissions',
    users: 'Users', history: 'History', password: 'Change Password', device_types: 'Device Types', sim_validity: 'SIM Validity',
    platforms: 'Platform', vehicle_types: 'Vehicle Types', lead_closures: 'Lead Closure', sale_amounts: 'Sale Amount', support: 'Support'
};
const MODULE_ORDER = ['dashboard', 'history', 'support', 'devices', 'sims', 'inward_reports', 'dealers', 'technicians', 'outward_reports', 'stock', 'stock_transfer', 'device_alert', 'customers', 'customer_reports', 'customer_renewals', 'roles', 'permissions', 'users', 'password', 'device_types', 'sim_validity', 'platforms', 'vehicle_types', 'lead_closures', 'sale_amounts'];
const ACTION_COLUMNS = ['view', 'add', 'edit', 'delete', 'export', 'update'];
const buildPermissionMatrix = (permissions, assigned = []) => {
    const groups = permissions.reduce((result, permission) => {
        const module = permission.module || permission.permission_key.split('.')[0];
        if (!result[module]) result[module] = [];
        result[module].push(permission);
        return result;
    }, {});
    return Object.keys(groups).sort((left, right) => {
        const leftIndex = MODULE_ORDER.indexOf(left); const rightIndex = MODULE_ORDER.indexOf(right);
        return (leftIndex < 0 ? 999 : leftIndex) - (rightIndex < 0 ? 999 : rightIndex);
    }).map((module) => {
        const permissionKeys = Object.fromEntries(groups[module].map((permission) => {
            const action = String(permission.action || '').toLowerCase();
            const column = action === 'change' ? 'update' : action;
            return [column, permission.permission_key];
        }));
        return {
            module: MODULE_LABELS[module] || groups[module][0].module || module,
            key: permissionKeys.view || groups[module][0].permission_key,
            permissionKeys,
            extraKeys: groups[module].filter((permission) => !Object.values(permissionKeys).includes(permission.permission_key)).map((permission) => permission.permission_key),
            ...Object.fromEntries(ACTION_COLUMNS.map((column) => [column, Boolean(permissionKeys[column] && assigned.includes(permissionKeys[column]))]))
        };
    });
};

const RolesPermissionsPage = () => {
    const [tab, setTab] = useState('roles');
    const [roles, setRoles] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [selectedRoleId, setSelectedRoleId] = useState('');
    const [matrix, setMatrix] = useState([]);
    const [assignedPermissions, setAssignedPermissions] = useState([]);
    const [showRoleModal, setShowRoleModal] = useState(false);
const [editingRole, setEditingRole] = useState(null);

const [newRole, setNewRole] = useState({
    role_name: '',
    description: '',
    status: 'active'
});

const [viewingRole, setViewingRole] = useState(null);
const [feedback, setFeedback] = useState({
    open: false,
    type: 'success',
    title: '',
    message: ''
});

const [deleteRoleTarget, setDeleteRoleTarget] = useState(null);
const showFeedback = (type, title, message) => {
    setFeedback({
        open: true,
        type,
        title,
        message
    });
};

const closeFeedback = () => {
    setFeedback({
        open: false,
        type: 'success',
        title: '',
        message: ''
    });
};
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
        try {
            const definitionsResponse = await api.get('/permissions/list.php');
            const definitions = definitionsResponse.data.data?.permissions || [];
            if (!roleId) {
                setMatrix(buildPermissionMatrix(definitions));
                return;
            }
            const response = await api.get(`/permissions/role_permissions.php?role_id=${roleId}`);
            const assigned = response.data.data?.permissions || [];
            setAssignedPermissions(assigned);
            setMatrix(buildPermissionMatrix(definitions, assigned));
        } catch (error) {
            console.error('Failed to fetch permissions', error);
        }
    };

    const saveRolePermissions = async () => {
    if (!selectedRoleId) return;

    const selectedPermissions = [];

    matrix.forEach((row) => {
        ACTION_COLUMNS.forEach((column) => {
            if (row[column] && row.permissionKeys[column]) {
                selectedPermissions.push(row.permissionKeys[column]);
            }
        });

        row.extraKeys.forEach((permissionKey) => {
            if (assignedPermissions.includes(permissionKey)) {
                selectedPermissions.push(permissionKey);
            }
        });
    });

    try {
        const response = await api.post('/permissions/assign.php', {
            role_id: Number(selectedRoleId),
            permissions: selectedPermissions
        });

        if (response.data.success) {
            showFeedback(
                'success',
                'Permissions Saved',
                'Permissions saved successfully.'
            );

            await fetchPermissions(selectedRoleId);
        } else {
            showFeedback(
                'error',
                'Unable to Save',
                response.data.message || 'Unable to save permissions.'
            );
        }
    } catch (error) {
        showFeedback(
            'error',
            'Unable to Save',
            error.response?.data?.message ||
                'Unable to save permissions.'
        );
    }
};

    const createRole = async () => {
    try {
        const response = await api.post('/roles/create.php', newRole);

        if (response.data.success) {
            setShowRoleModal(false);
            setEditingRole(null);

            setNewRole({
                role_name: '',
                description: '',
                status: 'active'
            });

            await fetchRoles();

            showFeedback(
                'success',
                'Role Created',
                'Role created successfully.'
            );
        } else {
            showFeedback(
                'error',
                'Unable to Create',
                response.data.message || 'Unable to create role.'
            );
        }
    } catch (error) {
        showFeedback(
            'error',
            'Unable to Create',
            error.response?.data?.message ||
                'Unable to create role.'
        );
    }
};

    const openEditRole = (role) => {
    setEditingRole(role);

    setNewRole({
        role_name: role.role_name || '',
        description: role.description || '',
        status: role.status || 'active'
    });

    setShowRoleModal(true);
};
const updateRole = async () => {
    if (!newRole.role_name.trim()) {
        showFeedback(
            'error',
            'Validation Error',
            'Role name is required.'
        );
        return;
    }

    try {
        const response = await api.post('/roles/update.php', {
            id: editingRole.id,
            role_name: newRole.role_name.trim(),
            description: newRole.description.trim(),
            status: newRole.status
        });

        if (!response.data.success) {
            showFeedback(
                'error',
                'Unable to Update',
                response.data.message || 'Unable to update role.'
            );
            return;
        }

        setShowRoleModal(false);
        setEditingRole(null);

        setNewRole({
            role_name: '',
            description: '',
            status: 'active'
        });

        await fetchRoles();

        showFeedback(
            'success',
            'Role Updated',
            'Role updated successfully.'
        );
    } catch (error) {
        showFeedback(
            'error',
            'Unable to Update',
            error.response?.data?.message ||
                'Unable to update role.'
        );
    }
};
const requestDeleteRole = (role) => {
    setDeleteRoleTarget(role);
};
const deleteRole = async () => {
    if (!deleteRoleTarget) return;

    const role = deleteRoleTarget;

    try {
        const response = await api.post('/roles/delete.php', {
            id: role.id
        });

        if (!response.data.success) {
            showFeedback(
                'error',
                'Unable to Delete',
                response.data.message || 'Unable to delete role.'
            );
            return;
        }

        setDeleteRoleTarget(null);

        if (String(selectedRoleId) === String(role.id)) {
            setSelectedRoleId('');
            setAssignedPermissions([]);
            setMatrix([]);
        }

        await fetchRoles();

        showFeedback(
            'success',
            'Role Deleted',
            'Role deleted successfully.'
        );
    } catch (error) {
        showFeedback(
            'error',
            'Unable to Delete',
            error.response?.data?.message ||
                'Unable to delete role.'
        );
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
    const selectAllPermissions = () => setMatrix((prev) => prev.map((row) => ({ ...row, ...Object.fromEntries(ACTION_COLUMNS.map((column) => [column, Boolean(row.permissionKeys[column])])) })));
    const clearAllPermissions = () => setMatrix((prev) => prev.map((row) => ({ ...row, ...Object.fromEntries(ACTION_COLUMNS.map((column) => [column, false])) })));

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
                       <button
    className="btn btn-primary"
    onClick={() => {
        setEditingRole(null);
        setNewRole({
            role_name: '',
            description: '',
            status: 'active'
        });
        setShowRoleModal(true);
    }}
>
    <Plus size={16} /> Add Role
</button>
                    </Can>
                </div>
            </div>

            <div className="card settings-tabs-card">
                <div className="tab-row settings-tab-row">
                    <button className={`btn ${tab === 'roles' ? 'btn-primary' : 'btn-outline'}`} onClick={() => setTab('roles')}>Roles</button>
                    <button className={`btn ${tab === 'permissions' ? 'btn-primary' : 'btn-outline'}`} onClick={() => setTab('permissions')}>Permissions</button>
                </div>

                {tab === 'roles' && (
                    <div className="settings-tab-panel">
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
                                                        <Can permission="roles.edit">
    <button
        className="icon-btn edit"
        type="button"
        aria-label="Edit role"
        title="Edit"
        onClick={() => openEditRole(role)}
    >
        <Edit size={16} />
    </button>
</Can>

<Can permission="roles.delete">
    <button
        className="icon-btn delete"
        type="button"
        aria-label="Delete role"
        title="Delete"
        onClick={() => requestDeleteRole(role)}
    >
        <Trash2 size={16} />
    </button>
</Can>
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
                    <div className="settings-tab-panel">
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
                            <button className="btn btn-outline" type="button" onClick={selectAllPermissions}>Select All</button>
                            <button className="btn btn-outline" type="button" onClick={clearAllPermissions}>Clear All</button>
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
                <div className="modal-overlay" onClick={() => {
    setShowRoleModal(false);
    setEditingRole(null);
}}>
                    <div className="modal-shell" style={{ maxWidth: '480px' }} onClick={(event) => event.stopPropagation()}>
                        <div className="modal-header">
                            <h3>{editingRole ? 'Edit Role' : 'Add Role'}</h3>
                            <button type="button" className="close-btn" onClick={() => {
    setShowRoleModal(false);
    setEditingRole(null);
}}>&times;</button>
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
                            <button className="btn btn-outline" onClick={() => {
    setShowRoleModal(false);
    setEditingRole(null);
}}>Cancel</button>
                            <button
    className="btn btn-primary"
    onClick={editingRole ? updateRole : createRole}
>
    {editingRole ? 'Update Role' : 'Save Role'}
</button>
                        </div>
                    </div>
                </div>
            )}
            <Modal
    isOpen={feedback.open}
    onClose={closeFeedback}
    title={feedback.title}
    maxWidth="420px"
>
    <div style={{ padding: '0.25rem 0' }}>
        <div
            style={{
                padding: '12px 14px',
                borderRadius: '8px',
                background:
                    feedback.type === 'error'
                        ? '#fef2f2'
                        : '#f0fdf4',
                color:
                    feedback.type === 'error'
                        ? '#991b1b'
                        : '#166534',
                lineHeight: 1.5
            }}
        >
            {feedback.message}
        </div>
    </div>

    <div
        style={{
            display: 'flex',
            justifyContent: 'flex-end',
            marginTop: '1.25rem'
        }}
    >
        <button
            type="button"
            className="btn btn-primary"
            onClick={closeFeedback}
        >
            OK
        </button>
    </div>
</Modal>
<Modal
    isOpen={Boolean(deleteRoleTarget)}
    onClose={() => setDeleteRoleTarget(null)}
    title="Delete Role"
    maxWidth="420px"
>
    <div style={{ padding: '0.25rem 0' }}>
        <p style={{ margin: 0, lineHeight: 1.6 }}>
            Are you sure you want to delete{' '}
            <strong>
                {deleteRoleTarget?.role_name}
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
            onClick={() => setDeleteRoleTarget(null)}
        >
            Cancel
        </button>

        <button
            type="button"
            className="btn btn-danger"
            onClick={deleteRole}
        >
            Delete
        </button>
    </div>
</Modal>
            {viewingRole && <RecordViewModal isOpen onClose={() => setViewingRole(null)} title="Role Details" record={viewingRole} fetchRecord={async (row) => (await api.get('/roles/list.php')).data.data.roles.find((role) => String(role.id) === String(row.id)) || row} fields={[{ label: 'Role Name', key: 'role_name' }, { label: 'Description', key: 'description' }, { label: 'Status', key: 'status' }, { label: 'Created Date', key: 'created_at' }]} />}
        </div>
    );
};

export default RolesPermissionsPage;
