import { useEffect, useState } from 'react';
import { Plus, Eye, Edit, Trash2 } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';

const SimValidityPage = () => {
    const { hasPermission } = useAuth();
    const [validities, setValidities] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null);
    const [showModal, setShowModal] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [months, setMonths] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [viewingValidity, setViewingValidity] = useState(null);

    const fetchValidities = async () => {
        setLoading(true);
        try {
            const response = await api.get('/sim_validities/list.php');
            if (response.data.success) setValidities(response.data.data?.validities || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load SIM validities.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchValidities();
    }, []);

    const openModal = (validity = null) => {
        setEditing(validity);
        setMonths(validity ? String(validity.months) : '');
        setError('');
        setShowModal(true);
    };

    const save = async () => {
        if (!/^[1-9][0-9]*$/.test(months.trim())) {
            setError('Number of months must be a positive integer.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            const response = editing
                ? await api.post('/sim_validities/update.php', { id: editing.id, months: months.trim() })
                : await api.post('/sim_validities/create.php', { months: months.trim() });
            if (!response.data.success) {
                setError(response.data.message || 'Unable to save SIM validity.');
                return;
            }
            setShowModal(false);
            setMessage(response.data.message);
            await fetchValidities();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save SIM validity.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        try {
            const response = await api.post('/sim_validities/delete.php', { id: deleteTarget.id });
            if (!response.data.success) {
                setError(response.data.message);
                return;
            }
            setDeleteTarget(null);
            setMessage(response.data.message);
            await fetchValidities();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to delete SIM validity.');
        }
    };

    const filtered = filterTableRows(validities, filters, { dateKeys: ['created_at'], searchKeys: ['months', 'status'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filtered, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>SIM Validity</h2>
                <div className="header-actions">
                    {hasPermission('sim_validity.add') && (
                        <button className="btn btn-primary" onClick={() => openModal()}>
                            <Plus size={16} /> Add Validity
                        </button>
                    )}
                </div>
            </div>

            {message && <div className="alert alert-success">{message}</div>}
            {error && !showModal && <div className="alert alert-danger">{error}</div>}

            <div className="card">
                <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={validities} dateKeys={['created_at']} searchPlaceholder="Search SIM validity..." />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Validity</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="4" className="text-center">
                                        Loading SIM validities...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="4" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((validity) => (
                                    <tr key={validity.id}>
                                        <td style={{ fontWeight: 500 }}>{validity.months} Months</td>
                                        <td>
                                            <span className="badge badge-success">{validity.status || 'active'}</span>
                                        </td>
                                        <td>{formatDate(validity.created_at)}</td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View SIM validity" title="View" onClick={() => setViewingValidity(validity)}><Eye size={16} /></button>
                                                {hasPermission('sim_validity.edit') && (
                                                    <button
                                                        className="icon-btn edit"
                                                        type="button"
                                                        aria-label="Edit SIM validity"
                                                        onClick={() => openModal(validity)}
                                                    >
                                                        <Edit size={16} />
                                                    </button>
                                                )}
                                                {hasPermission('sim_validity.delete') && (
                                                    <button
                                                        className="icon-btn delete"
                                                        type="button"
                                                        aria-label="Delete SIM validity"
                                                        onClick={() => setDeleteTarget(validity)}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                )}
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
                    itemName="validities"
                />
            </div>

            {showModal && (
                <Modal
                    isOpen
                    title={editing ? 'Edit SIM Validity' : 'Add SIM Validity'}
                    onClose={() => setShowModal(false)}
                    maxWidth="460px"
                    footer={
                        <>
                            <button className="btn btn-outline" onClick={() => setShowModal(false)} disabled={saving}>
                                Cancel
                            </button>
                            <button className="btn btn-primary" onClick={save} disabled={saving}>
                                {saving ? 'Saving...' : editing ? 'Update Validity' : 'Save Validity'}
                            </button>
                        </>
                    }
                >
                    <div className="form-group">
                        <label className="form-label">Validity / Number of Months *</label>
                        <input
                            type="text"
                            inputMode="numeric"
                            className="form-control"
                            value={months}
                            onChange={(event) => setMonths(event.target.value)}
                            autoFocus
                        />
                        {error && <div className="text-danger">{error}</div>}
                    </div>
                </Modal>
            )}

            {deleteTarget && (
                <Modal
                    isOpen
                    title="Delete SIM Validity"
                    onClose={() => setDeleteTarget(null)}
                    maxWidth="420px"
                    footer={
                        <>
                            <button className="btn btn-outline" onClick={() => setDeleteTarget(null)}>
                                Cancel
                            </button>
                            <button className="btn btn-danger" onClick={remove}>
                                Delete
                            </button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this SIM validity?</p>
                </Modal>
            )}
            {viewingValidity && <RecordViewModal isOpen onClose={() => setViewingValidity(null)} title="SIM Validity Details" record={viewingValidity} fetchRecord={async (row) => (await api.get('/sim_validities/list.php')).data.data.validities.find((item) => String(item.id) === String(row.id)) || row} fields={[{ label: 'Validity', key: 'months', format: (value) => `${value} Months` }, { label: 'Status', key: 'status' }, { label: 'Created Date', key: 'created_at' }]} />}
        </div>
    );
};

export default SimValidityPage;

