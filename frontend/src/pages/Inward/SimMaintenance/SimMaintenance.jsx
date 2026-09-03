import { useState, useEffect } from 'react';
import { useAuth } from '../../../context/AuthContext';
import { Plus, Upload, Eye, Trash2, Edit } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import '../DeviceMaintenance/DeviceMaintenance.css';
import AddSimModal from './AddSimModal';
import EditSimModal from './EditSimModal';
import SimExcelUploadModal from './SimExcelUploadModal';
import Modal from '../../../components/Modal/Modal';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../../components/RecordViewModal/RecordViewModal';

const SimMaintenance = () => {
    const { hasPermission } = useAuth();
    const [sims, setSims] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isExcelModalOpen, setIsExcelModalOpen] = useState(false);
    const [editingSim, setEditingSim] = useState(null);
    const [viewingSim, setViewingSim] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);

    const fetchSims = async () => {
        setLoading(true);
        try {
            const response = await api.get('/sims/list.php').catch(() => ({ data: { success: true, data: { sims: [] } } }));
            if (response.data.success) {
                setSims(response.data.data.sims || []);
            }
        } catch (error) {
            console.error('Failed to fetch sims', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDeleteSim = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post('/sims/delete.php', { id: deleteTarget.id });
            if (response.data.success) {
                setDeleteTarget(null);
                fetchSims();
            } else {
                window.alert(response.data.message || 'Failed to delete SIM');
            }
        } catch (error) {
            window.alert(error.response?.data?.message || 'Failed to delete SIM');
        }
    };

    useEffect(() => {
        fetchSims();
    }, []);

    const filteredSims = filterTableRows(sims, filters, { dateKeys: ['purchase_date'], simKey: 'sim_type', searchKeys: ['sim_no', 'sim_type', 'notes'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredSims, 10, [filters]);

    const getStatusBadge = (status) => {
        switch(status) {
            case 'available': return <span className="badge badge-success">Available</span>;
            case 'allocated': return <span className="badge badge-warning">Allocated</span>;
            case 'used': return <span className="badge badge-info">Used</span>;
            default: return <span className="badge">{status}</span>;
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>SIM Maintenance</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={() => setIsExcelModalOpen(true)}>
                        <Upload size={16} /> Upload Excel
                    </button>
                    <button className="btn btn-primary" onClick={() => setIsAddModalOpen(true)}>
                        <Plus size={16} /> Add SIM
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={sims} dateKeys={['purchase_date']} simKey="sim_type" searchPlaceholder="Search by SIM number or type..." />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Purchase Date</th>
                                <th>SIM No</th>
                                <th>SIM Type</th>
                                <th>SIM Validity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="6" className="text-center">Loading SIMs...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="6" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map(sim => (
                                    <tr key={sim.id}>
                                        <td>{formatDate(sim.purchase_date)}</td>
                                        <td className="truncate-cell" title={sim.sim_no}>{sim.sim_no}</td>
                                        <td>{sim.sim_type || '-'}</td>
                                        <td>{sim.sim_validity_months ? `${sim.sim_validity_months} Months` : '-'}</td>
                                        <td>{getStatusBadge(sim.status)}</td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View SIM" title="View" onClick={() => setViewingSim(sim)}><Eye size={16} /></button>
                                                {hasPermission('sims.edit') && <button className="icon-btn edit" type="button" aria-label="Edit SIM" onClick={() => setEditingSim(sim)}><Edit size={16} /></button>}
                                                {hasPermission('sims.delete') && <button className="icon-btn delete" type="button" aria-label="Delete SIM" onClick={() => setDeleteTarget(sim)}><Trash2 size={16} /></button>}
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
                    itemName="SIMs"
                />
            </div>

            {isAddModalOpen && (
                <AddSimModal 
                    onClose={() => setIsAddModalOpen(false)} 
                    onSuccess={() => {
                        setIsAddModalOpen(false);
                        fetchSims();
                    }}
                />
            )}

            {isExcelModalOpen && (
                <SimExcelUploadModal 
                    onClose={() => setIsExcelModalOpen(false)} 
                    onSuccess={() => {
                        setIsExcelModalOpen(false);
                        fetchSims();
                    }}
                />
            )}

            {editingSim && (
                <EditSimModal
                    sim={editingSim}
                    onClose={() => setEditingSim(null)}
                    onSuccess={() => {
                        setEditingSim(null);
                        fetchSims();
                    }}
                />
            )}

            {viewingSim && <RecordViewModal isOpen onClose={() => setViewingSim(null)} title="SIM Details" record={viewingSim} fetchRecord={async (row) => (await api.get('/sims/list.php')).data.data.sims.find((sim) => String(sim.id) === String(row.id)) || row} fields={[{ label: 'SIM No', key: 'sim_no' }, { label: 'SIM Type', key: 'sim_type' }, { label: 'SIM Validity', key: 'sim_validity_months', format: (value) => value ? `${value} Months` : '-' }, { label: 'Purchase Date', key: 'purchase_date' }, { label: 'Status', key: 'status' }, { label: 'Owner', key: 'owner_name' }, { label: 'Allocation Date', key: 'allocation_date' }, { label: 'Software', key: 'software' }, { label: 'Payment Status', key: 'payment_status' }, { label: 'Notes', key: 'notes' }]} />}

            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete SIM"
                    maxWidth="420px"
                    footer={
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={handleDeleteSim}>Delete</button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this SIM?</p>
                </Modal>
            )}
        </div>
    );
};

export default SimMaintenance;
