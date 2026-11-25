import { useEffect, useState } from 'react';
import { Eye, KeyRound, Pencil, Trash2 } from 'lucide-react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import Pagination from '../../components/Pagination/Pagination';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import TableFilterBar, { emptyTableFilters } from '../../components/TableFilterBar/TableFilterBar';
import InstallationPersonActionModal from './InstallationPersonActionModal';
import './InstallationPersonsPage.css';

const detailFields = [
    { label: 'Name', key: 'name' },
    { label: 'Username', key: 'username' },
    { label: 'Contact No', key: 'mobile_no' },
    { label: 'Role', key: 'role_name' },
    { label: 'Account Status', key: 'status' },
    { label: 'Profile Status', key: 'profile_status' },
    { label: 'Created Date', key: 'created_at' },
    { label: 'Alternate Contact', key: 'alternate_mobile_no' },
    { label: 'Location', key: 'location' },
    { label: 'Enrolled Date', key: 'enrolled_date' },
    { label: 'Installation Status', key: 'installation_status' },
    { label: 'Software', key: 'software' },
    { label: 'Threshold Amount', key: 'threshold_amount' },
    { label: 'Service Areas', key: 'installation_service_areas' },
    { label: 'Notes', key: 'notes' }
];

const InstallationPersonsPage = () => {
    const { hasPermission } = useAuth();
    const canViewDealers = hasPermission('dealers.view');
    const canViewTechnicians = hasPermission('technicians.view');
    const [personnelType, setPersonnelType] = useState(canViewDealers ? 'dealer' : 'technician');
    const [persons, setPersons] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [appliedFilters, setAppliedFilters] = useState({ search: '', status: '' });
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [refreshVersion, setRefreshVersion] = useState(0);
    const [viewingPerson, setViewingPerson] = useState(null);
    const [actionTarget, setActionTarget] = useState(null);

    useEffect(() => {
        let active = true;
        const loadPersons = async () => {
            setLoading(true);
            setError('');
            try {
                const response = await api.get('/installation_personnel/list.php', {
                    params: {
                        type: personnelType,
                        search: appliedFilters.search,
                        status: appliedFilters.status || 'all',
                        page,
                        page_size: pageSize
                    }
                });
                if (!active) return;
                if (!response.data?.success) {
                    setPersons([]);
                    setError(response.data?.message || 'Unable to load installation persons.');
                    return;
                }
                const data = response.data.data || {};
                const nextPagination = data.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
                setPersons(data.persons || []);
                setPagination(nextPagination);
                if (page > nextPagination.total_pages) setPage(nextPagination.total_pages);
            } catch (requestError) {
                if (!active) return;
                setPersons([]);
                setError(requestError.response?.data?.message || 'Unable to load installation persons.');
            } finally {
                if (active) setLoading(false);
            }
        };
        loadPersons();
        return () => { active = false; };
    }, [personnelType, appliedFilters.search, appliedFilters.status, page, pageSize, refreshVersion]);

    const openAction = (person, action) => setActionTarget({ person, action });
    const closeAction = () => setActionTarget(null);
    const applyFilters = () => {
        setAppliedFilters({
            search: filters.search.trim(),
            status: filters.status
        });
        setPage(1);
    };
    const resetFilters = () => {
        setFilters(emptyTableFilters());
        setAppliedFilters({ search: '', status: '' });
        setPage(1);
    };
    const completeAction = () => {
        setActionTarget(null);
        setRefreshVersion((version) => version + 1);
    };

    const fetchDetails = async (person) => {
        const response = await api.get('/installation_personnel/details.php', {
            params: { type: person.personnel_type, id: person.id }
        });
        if (!response.data?.success) {
            throw new Error(response.data?.message || 'Unable to load installation person details.');
        }
        return response.data.data.person;
    };

    return (
        <div className="page-container installation-personnel-page">
            <div className="page-header">
                <div>
                    <h2>Installation Persons</h2>
                    <p className="page-subtitle">Manage linked Dealer and Technician login accounts.</p>
                </div>
                <div className="header-actions installation-personnel-tabs" role="tablist" aria-label="Installation person type">
                    {canViewDealers && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={personnelType === 'dealer'}
                            className={`btn ${personnelType === 'dealer' ? 'btn-primary' : 'btn-outline'}`}
                            onClick={() => { setPersonnelType('dealer'); setPage(1); }}
                        >
                            Dealers
                        </button>
                    )}
                    {canViewTechnicians && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={personnelType === 'technician'}
                            className={`btn ${personnelType === 'technician' ? 'btn-primary' : 'btn-outline'}`}
                            onClick={() => { setPersonnelType('technician'); setPage(1); }}
                        >
                            Technicians
                        </button>
                    )}
                </div>
            </div>

            {error && <div className="alert alert-danger" role="alert">{error}</div>}

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={resetFilters}
                    onSearch={applyFilters}
                    items={persons}
                    searchPlaceholder="Search by name, username, or contact..."
                    showStatus
                    statusOptions={['active', 'inactive']}
                    statusPlaceholder="All statuses"
                    statusLabel="Account Status"
                    className="installation-personnel-filter"
                />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Contact No</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="7" className="text-center">Loading installation persons...</td></tr>
                            ) : persons.length === 0 ? (
                                <tr><td colSpan="7" className="text-center empty-state">No linked login accounts found.</td></tr>
                            ) : persons.map((person) => (
                                <tr key={`${person.personnel_type}-${person.id}`}>
                                    <td className="truncate-cell" title={person.name || person.username} style={{ fontWeight: 500 }}>{person.name || '-'}</td>
                                    <td>{person.username || '-'}</td>
                                    <td>{person.mobile_no || '-'}</td>
                                    <td>{person.role_name}</td>
                                    <td><span className={`badge ${person.status === 'active' ? 'badge-success' : 'badge-danger'}`}>{person.status}</span></td>
                                    <td>{formatDate(person.created_at)}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button className="icon-btn view" type="button" aria-label={`View ${person.name}`} title="View" onClick={() => setViewingPerson(person)}><Eye size={16} /></button>
                                            {hasPermission(person.personnel_type === 'dealer' ? 'dealers.edit' : 'technicians.edit') && (
                                                <button className="icon-btn edit" type="button" aria-label={`Edit ${person.name}`} title="Edit" onClick={() => openAction(person, 'edit')}><Pencil size={16} /></button>
                                            )}
                                            {hasPermission(person.personnel_type === 'dealer' ? 'dealers.edit' : 'technicians.edit') && hasPermission('users.edit') && (
                                                <button className="icon-btn edit" type="button" aria-label={`Reset password for ${person.name}`} title="Reset Password" onClick={() => openAction(person, 'reset')}><KeyRound size={16} /></button>
                                            )}
                                            {hasPermission(person.personnel_type === 'dealer' ? 'dealers.delete' : 'technicians.delete') && hasPermission('users.delete') && (
                                                <button className="icon-btn delete" type="button" aria-label={`Delete ${person.name}`} title="Delete" onClick={() => openAction(person, 'deactivate')}><Trash2 size={16} /></button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    currentPage={page}
                    totalItems={pagination.total}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={(nextSize) => { setPageSize(nextSize); setPage(1); }}
                    itemName="installation persons"
                />
            </div>

            {viewingPerson && (
                <RecordViewModal
                    isOpen
                    onClose={() => setViewingPerson(null)}
                    title={`${viewingPerson.role_name} Details`}
                    record={viewingPerson}
                    fetchRecord={fetchDetails}
                    fields={detailFields}
                />
            )}

            {actionTarget && (
                <InstallationPersonActionModal
                    key={`${actionTarget.action}-${actionTarget.person.personnel_type}-${actionTarget.person.id}`}
                    person={actionTarget.person}
                    action={actionTarget.action}
                    onClose={closeAction}
                    onSuccess={completeAction}
                />
            )}
        </div>
    );
};

export default InstallationPersonsPage;
