import { useState, useEffect } from 'react';
import { PenTool, Eye, Edit, Trash2 } from 'lucide-react';
import api from '../../services/api';
import UpdateStockModal from './UpdateStockModal';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../components/TableFilterBar/TableFilterBar';
import Modal from '../../components/Modal/Modal';
import RecordViewModal from '../../components/RecordViewModal/RecordViewModal';
import StockAllocationEditModal from './StockAllocationEditModal';
import { formatDate } from '../../utils/date';

const StockManagement = () => {
    const [stockAllocations, setStockAllocations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [isUpdateModalOpen, setIsUpdateModalOpen] = useState(false);
    const [editingAllocationId, setEditingAllocationId] = useState(null);
    const [viewingAllocation, setViewingAllocation] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [platforms, setPlatforms] = useState([]);

    const fetchStockSummary = async () => {
        setLoading(true);
        try {
            const response = await api.get('/stock/list.php').catch(() => ({ data: { success: true, data: { allocations: [] } } }));
            if (response.data.success) {
                setStockAllocations(response.data.data.allocations || []);
            }
        } catch (error) {
            console.error('Failed to fetch stock summary', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchPlatforms = async () => {
        try {
            const res = await api.get('/platforms/list.php').catch(() => ({ data: { success: true, data: { platforms: [] } } }));
            const rawPlatforms = res.data?.data?.platforms || [];
            const active = rawPlatforms
                .filter((p) => !p.status || String(p.status).toLowerCase() === 'active')
                .map((p) => p.platform_name);
            setPlatforms(active);
        } catch (err) {
            console.error('Failed to fetch platforms', err);
        }
    };

    useEffect(() => {
        fetchStockSummary();
        fetchPlatforms();
    }, []);

    const deleteOwnerStock = async () => {
        if (!deleteTarget) return;
        setDeleting(true);
        try {
            const response = await api.post('/stock/delete.php', { owner_type: deleteTarget.owner_type, owner_id: deleteTarget.owner_id });
            if (!response.data.success) throw new Error(response.data.message);
            setDeleteTarget(null);
            fetchStockSummary();
        } catch (error) {
            window.alert(error.response?.data?.message || error.message || 'Unable to delete allocated stock.');
        } finally {
            setDeleting(false);
        }
    };

    // Filter out Not Willing Dealers on frontend as well
    const allowedAllocations = stockAllocations.filter((row) => {
        if (row.owner_type === 'dealer' && (row.dealer_installation_status === 'Not Willing' || row.installation_status === 'Not Willing')) {
            return false;
        }
        return true;
    });

    const filteredStock = filterTableRows(allowedAllocations, filters, {
        dateKeys: ['allocation_date'],
        searchKeys: ['owner_name', 'owner_type', 'imei_no', 'sim_no', 'software']
    });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredStock, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Stock Management</h2>
                <div className="header-actions">
                    <button className="btn btn-primary" onClick={() => setIsUpdateModalOpen(true)}>
                        <PenTool size={16} /> Update Stock
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={allowedAllocations}
                    dateKeys={['allocation_date']}
                    showOwnerType
                    showPlatform
                    platformOptions={platforms}
                    showPaymentStatus
                    searchPlaceholder="Search by owner name, IMEI, SIM, or software..."
                />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Dealer / Technician Name</th>
                                <th>Owner Type</th>
                                <th>Allocation Type</th>
                                <th>Allocation Date</th>
                                <th>Device</th>
                                <th>SIM</th>
                                <th>Status</th>
                                <th>Software</th>
                                <th>Total Amount</th>
                                <th>Pending Amount</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="12" className="text-center">Loading stock data...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="12" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((row) => {
                                    const isDeviceRow = row.device_id !== null || Boolean(row.imei_no);
                                    const deviceText = isDeviceRow ? (row.imei_no || '-') : '-';
                                    const simText = !isDeviceRow && (row.sim_id !== null || Boolean(row.sim_no)) ? (row.sim_no || '-') : '-';

                                    const rawStatus = isDeviceRow ? row.device_status : row.sim_status;
                                    const assetStatus = rawStatus ? (rawStatus.charAt(0).toUpperCase() + rawStatus.slice(1)) : (row.asset_status || 'Allocated');

                                    return (
                                        <tr key={row.allocation_id}>
                                            <td className="truncate-cell" title={row.owner_name} style={{ fontWeight: 500 }}>{row.owner_name || '-'}</td>
                                            <td>
                                                <span className={`badge ${row.owner_type === 'dealer' ? 'badge-primary' : 'badge-info'}`} style={{ backgroundColor: row.owner_type === 'dealer' ? '#e0e7ff' : '#dbeafe', color: row.owner_type === 'dealer' ? '#3730a3' : '#1e40af' }}>
                                                    {row.owner_type ? row.owner_type.charAt(0).toUpperCase() + row.owner_type.slice(1) : '-'}
                                                </span>
                                            </td>
                                            <td>{row.allocation_type || '-'}</td>
                                            <td>{formatDate(row.allocation_date)}</td>
                                            <td className="truncate-cell" title={deviceText}>{deviceText}</td>
                                            <td className="truncate-cell" title={simText}>{simText}</td>
                                            <td>
                                                <span className={`badge ${assetStatus.toLowerCase() === 'used' ? 'badge-info' : 'badge-warning'}`}>
                                                    {assetStatus}
                                                </span>
                                            </td>
                                            <td>{row.software || '-'}</td>
                                            <td>₹{Number(row.total_amount || 0).toFixed(2)}</td>
                                            <td>₹{Number(row.pending_amount || 0).toFixed(2)}</td>
                                            <td>{row.payment_status || 'No Payment Required'}</td>
                                            <td>
                                                <div className="action-buttons">
                                                    <button type="button" className="icon-btn view" aria-label="View stock allocation" title="View" onClick={() => setViewingAllocation(row)}><Eye size={16} /></button>
                                                    <button type="button" className="icon-btn edit" aria-label="Edit stock allocation" title="Edit" onClick={() => setEditingAllocationId(row.allocation_id)}><Edit size={16} /></button>
                                                    <button type="button" className="icon-btn delete" aria-label="Delete allocated stock" onClick={() => setDeleteTarget(row)}><Trash2 size={16} /></button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
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
                    itemName="allocations"
                />
            </div>

            {isUpdateModalOpen && <UpdateStockModal onClose={() => setIsUpdateModalOpen(false)} onSuccess={() => { setIsUpdateModalOpen(false); fetchStockSummary(); }} />}
            {editingAllocationId && <StockAllocationEditModal allocationId={editingAllocationId} onClose={() => setEditingAllocationId(null)} onSuccess={() => { setEditingAllocationId(null); fetchStockSummary(); }} />}
            {viewingAllocation && (
                <RecordViewModal
                    isOpen
                    onClose={() => setViewingAllocation(null)}
                    title="Stock Allocation Details"
                    record={viewingAllocation}
                    fetchRecord={async (row) => {
                        const allocation = (await api.get(`/stock/allocation.php?id=${row.allocation_id}`)).data.data.allocation;
                        return { ...allocation, owner_name: allocation.dealer_name || allocation.technician_name || row.owner_name };
                    }}
                    fields={[
                        { label: 'Owner', key: 'owner_name' },
                        { label: 'Owner Type', key: 'owner_type' },
                        { label: 'Allocation Type', key: 'allocation_type' },
                        { label: 'Allocation Date', key: 'allocation_date', format: (val) => formatDate(val) },
                        { label: 'Device IMEI', key: 'imei_no', format: (val, row) => (row.device_id || row.imei_no ? row.imei_no || '-' : '-') },
                        { label: 'SIM Number', key: 'sim_no', format: (val, row) => (!row.device_id && !row.imei_no && (row.sim_id || row.sim_no) ? row.sim_no || '-' : '-') },
                        { label: 'Status', key: 'asset_status', format: (val, row) => { const s = row.device_id || row.imei_no ? row.device_status : row.sim_status; return s ? s.charAt(0).toUpperCase() + s.slice(1) : val || 'Allocated'; } },
                        { label: 'Software', key: 'software' },
                        { label: 'Total Amount', key: 'total_amount' },
                        { label: 'Amount Paid', key: 'amount_paid' },
                        { label: 'Pending Amount', key: 'pending_amount' },
                        { label: 'Payment Status', key: 'payment_status' },
                        { label: 'Payment Mode', key: 'payment_mode' },
                        { label: 'Notes', key: 'notes' }
                    ]}
                />
            )}
            {deleteTarget && (
                <Modal isOpen onClose={() => setDeleteTarget(null)} title="Delete allocated stock" maxWidth="440px" footer={<><button className="btn btn-outline" onClick={() => setDeleteTarget(null)} disabled={deleting}>Cancel</button><button className="btn btn-danger" onClick={deleteOwnerStock} disabled={deleting}>{deleting ? 'Deleting...' : 'Delete Stock'}</button></>}>
                    <p>Remove all allocated stock for <strong>{deleteTarget.owner_name || deleteTarget.name}</strong>? Allocated devices and SIMs will be returned to available stock. The dealer/technician record will remain.</p>
                </Modal>
            )}
        </div>
    );
};

export default StockManagement;
