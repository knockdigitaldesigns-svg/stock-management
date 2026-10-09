import { useEffect, useState, useMemo } from 'react';
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
import { useAuth } from '../../context/AuthContext';

const PERMISSION_COLUMNS = ['view', 'add', 'edit', 'delete', 'export', 'update', 'approve'];

const PERMISSION_COLUMN_LABELS = {
    view: 'View',
    add: 'Add',
    edit: 'Edit',
    delete: 'Delete',
    export: 'Export',
    update: 'Update',
    approve: 'Approve'
};

const EXACT_MODULE_PERMISSIONS = [
    {
        name: 'Dashboard',
        actions: {
            view: ['dashboard.view']
        }
    },
    {
        name: 'SIM Maintenance',
        actions: {
            view: ['sims.view'],
            add: ['sims.add'],
            edit: ['sims.edit'],
            delete: ['sims.delete']
        }
    },
    {
        name: 'Device Maintenance',
        actions: {
            view: ['devices.view'],
            add: ['devices.add'],
            edit: ['devices.edit'],
            delete: ['devices.delete']
        }
    },
    {
        name: 'Inward Reports',
        actions: {
            view: ['inward_reports.view'],
            export: ['inward_reports.export']
        }
    },
    {
        name: 'Dealer',
        actions: {
            view: ['dealers.view'],
            add: ['dealers.add'],
            edit: ['dealers.edit'],
            delete: ['dealers.delete']
        }
    },
    {
        name: 'Technician',
        actions: {
            view: ['technicians.view'],
            add: ['technicians.add'],
            edit: ['technicians.edit'],
            delete: ['technicians.delete']
        }
    },
    {
        name: 'Dealer SIM Activation',
        actions: {
            view: ['dealer_sim_activation.view', 'dealers.view'],
            edit: ['dealer_sim_activation.edit', 'dealers.edit'],
            delete: ['dealer_sim_activation.delete', 'dealers.delete']
        }
    },
    {
        name: 'Outward Reports',
        actions: {
            view: ['outward_reports.view'],
            export: ['outward_reports.export']
        }
    },
    {
        name: 'Stock Management',
        actions: {
            view: ['stock_management.view', 'stock.view'],
            edit: ['stock_management.edit'],
            delete: ['stock_management.delete'],
            update: ['stock_management.update', 'stock.update']
        }
    },
    {
        name: 'Stock Transfer',
        actions: {
            view: ['stock_transfer.view'],
            add: ['stock_transfer.add'],
            edit: ['stock_transfer.edit'],
            delete: ['stock_transfer.delete']
        }
    },
    {
        name: 'Device Alert',
        actions: {
            view: ['device_alert.view'],
            add: ['device_alert.add'],
            edit: ['device_alert.edit'],
            delete: ['device_alert.delete']
        }
    },
    {
        name: 'Courier',
        actions: {
            view: ['courier.view'],
            add: ['courier.add'],
            edit: ['courier.edit'],
            delete: ['courier.delete'],
            approve: ['courier.approve']
        }
    },
    {
        name: 'Customer Details',
        actions: {
            view: ['customers.view', 'customer_details.view'],
            add: ['customers.add', 'customer_details.add'],
            edit: ['customers.edit', 'customer_details.edit'],
            delete: ['customers.delete', 'customer_details.delete'],
            export: ['customers.export', 'customer_details.export']
        }
    },
    {
        name: 'Reports',
        actions: {
            view: ['reports.view', 'customer_reports.view'],
            export: ['reports.export', 'customer_reports.export']
        }
    },
    {
        name: 'Renewals',
        actions: {
            view: ['renewals.view', 'customer_renewals.view'],
            edit: ['renewals.edit', 'customer_renewals.edit'],
            delete: ['renewals.delete', 'customer_renewals.delete']
        }
    },
    {
        name: 'History',
        actions: {
            view: ['history.view']
        }
    },
    {
        name: 'Support',
        actions: {
            view: ['support.view'],
            add: ['support.add'],
            edit: ['support.edit'],
            delete: ['support.delete']
        }
    },
    {
        name: 'Platform',
        actions: {
            view: ['platforms.view', 'platform.view'],
            add: ['platforms.add', 'platform.add'],
            edit: ['platforms.edit', 'platform.edit'],
            delete: ['platforms.delete', 'platform.delete']
        }
    },
    {
        name: 'Device Types',
        actions: {
            view: ['device_types.view'],
            add: ['device_types.add'],
            edit: ['device_types.edit'],
            delete: ['device_types.delete']
        }
    },
    {
        name: 'Vehicle Types',
        actions: {
            view: ['vehicle_types.view'],
            add: ['vehicle_types.add'],
            edit: ['vehicle_types.edit'],
            delete: ['vehicle_types.delete']
        }
    },
    {
        name: 'Lead Closure',
        actions: {
            view: ['lead_closures.view', 'lead_closure.view'],
            add: ['lead_closures.add', 'lead_closure.add'],
            edit: ['lead_closures.edit', 'lead_closure.edit'],
            delete: ['lead_closures.delete', 'lead_closure.delete']
        }
    },
    {
        name: 'Sale Amount',
        actions: {
            view: ['sale_amounts.view', 'sale_amount.view'],
            add: ['sale_amounts.add', 'sale_amount.add'],
            edit: ['sale_amounts.edit', 'sale_amount.edit'],
            delete: ['sale_amounts.delete', 'sale_amount.delete']
        }
    },
    {
        name: 'SIM Validity',
        actions: {
            view: ['sim_validity.view'],
            add: ['sim_validity.add'],
            edit: ['sim_validity.edit'],
            delete: ['sim_validity.delete']
        }
    },
    {
        name: 'Roles',
        actions: {
            view: ['roles.view'],
            add: ['roles.add'],
            edit: ['roles.edit'],
            delete: ['roles.delete']
        }
    },
    {
        name: 'Permissions',
        actions: {
            view: ['permissions.view'],
            update: ['permissions.update', 'permissions.assign']
        }
    },
    {
        name: 'Add User',
        actions: {
            view: ['users.view', 'add_user.view'],
            add: ['users.add', 'add_user.add'],
            edit: ['users.edit', 'add_user.edit'],
            delete: ['users.delete', 'add_user.delete']
        }
    },
    {
        name: 'Change Password',
        actions: {
            view: ['password.view', 'password.change'],
            update: ['password.update', 'password.change']
        }
    },
    {
        name: 'SIM Lifecycle',
        actions: {
            view: ['sim_lifecycle.view'],
            add: ['sim_lifecycle.add', 'sim_lifecycle.edit']
        }
    }
];

const buildPermissionMatrix = (definitions = [], assigned = []) => {
    const defKeys = new Set((definitions || []).map((d) => d.permission_key));

    return EXACT_MODULE_PERMISSIONS.map((modConfig) => {
        const supportedActions = modConfig.actions;
        const permissionKeys = {};

        Object.entries(supportedActions).forEach(([action, keys]) => {
            const dbKey = keys.find((k) => defKeys.has(k));
            permissionKeys[action] = dbKey || keys[0];
        });

        const columnStates = {};
        PERMISSION_COLUMNS.forEach((col) => {
            if (supportedActions[col]) {
                const isAssigned = supportedActions[col].some((k) => assigned.includes(k));
                columnStates[col] = Boolean(isAssigned);
            } else {
                columnStates[col] = false;
            }
        });

        return {
            module: modConfig.name,
            permissionKeys,
            supportedActions,
            ...columnStates
        };
    });
};

const RolesPermissionsPage = () => {
    const { currentUser, updatePermissions } = useAuth();
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
            const currentRoleId = currentUser?.role?.id ?? currentUser?.role_id;
            if (currentRoleId && String(roleId) === String(currentRoleId)) {
                updatePermissions(assigned);
            }
        } catch (error) {
            console.error('Failed to fetch permissions', error);
        }
    };

    const saveRolePermissions = async () => {
    if (!selectedRoleId) return;

    const selectedPermissions = [];

    matrix.forEach((row) => {
        PERMISSION_COLUMNS.forEach((column) => {
            if (row[column] && row.supportedActions && row.supportedActions[column]) {
                const keys = row.supportedActions[column];
                const keyToSave = row.permissionKeys[column] || keys[0];
                if (keyToSave) {
                    selectedPermissions.push(keyToSave);
                }
                if (keys[0] && keys[0] !== keyToSave) {
                    selectedPermissions.push(keys[0]);
                }
            }
        });
    });

    const allManagedPossibleKeys = new Set();
    EXACT_MODULE_PERMISSIONS.forEach((mod) => {
        Object.values(mod.actions).forEach((keys) => {
            keys.forEach((k) => allManagedPossibleKeys.add(k));
        });
    });

    const preservedExtraKeys = assignedPermissions.filter(
        (permKey) => !allManagedPossibleKeys.has(permKey)
    );

    const finalPermissions = Array.from(new Set([...selectedPermissions, ...preservedExtraKeys]));

    try {
        const response = await api.post('/permissions/assign.php', {
            role_id: Number(selectedRoleId),
            permissions: finalPermissions
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

    const togglePermission = (rowIndex, column) => {
        setMatrix((prev) =>
            prev.map((row, index) => {
                if (index !== rowIndex) return row;
                if (!row.supportedActions || !row.supportedActions[column]) return row;
                return { ...row, [column]: !row[column] };
            })
        );
    };

    const selectAllPermissions = () => {
        setMatrix((prev) =>
            prev.map((row) => {
                const updatedRow = { ...row };
                PERMISSION_COLUMNS.forEach((column) => {
                    updatedRow[column] = Boolean(row.supportedActions && row.supportedActions[column]);
                });
                return updatedRow;
            })
        );
    };

    const clearAllPermissions = () => {
        setMatrix((prev) =>
            prev.map((row) => {
                const updatedRow = { ...row };
                PERMISSION_COLUMNS.forEach((column) => {
                    updatedRow[column] = false;
                });
                return updatedRow;
            })
        );
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
                <div>
                    <h2>Roles & Permissions</h2>
                    <p className="page-subtitle">Manage user roles and control access to application features.</p>
                </div>
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
                                        <th style={{ width: '220px', minWidth: '180px' }}>Module</th>
                                        {PERMISSION_COLUMNS.map((column) => (
                                            <th key={column} style={{ textAlign: 'center', minWidth: '85px' }}>
                                                {PERMISSION_COLUMN_LABELS[column] || column.charAt(0).toUpperCase() + column.slice(1)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {matrix.length === 0 ? (
                                        <tr>
                                            <td colSpan={PERMISSION_COLUMNS.length + 1} className="text-center empty-state">
                                                No permissions found.
                                            </td>
                                        </tr>
                                    ) : (
                                        matrix.map((row, rowIndex) => (
                                            <tr key={row.module}>
                                                <td style={{ fontWeight: 500 }}>{row.module}</td>
                                                {PERMISSION_COLUMNS.map((column) => {
                                                    const isSupported = Boolean(row.supportedActions && row.supportedActions[column]);
                                                    return (
                                                        <td key={column} style={{ textAlign: 'center' }}>
                                                            {isSupported ? (
                                                                <input
                                                                    type="checkbox"
                                                                    checked={Boolean(row[column])}
                                                                    onChange={() => togglePermission(rowIndex, column)}
                                                                    aria-label={`${row.module} ${PERMISSION_COLUMN_LABELS[column] || column}`}
                                                                    style={{ cursor: 'pointer', width: '16px', height: '16px' }}
                                                                />
                                                            ) : (
                                                                <span style={{ color: '#cbd5e1', userSelect: 'none', fontWeight: 600 }}>—</span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))
                                    )}
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
