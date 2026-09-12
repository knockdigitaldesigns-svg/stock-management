import { useState, useEffect } from 'react';
import { Plus, Eye, Trash2, Edit, PackagePlus, Upload } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import AddDealerModal from './AddDealerModal';
import EditDealerModal from './EditDealerModal';
import DealerExcelUploadModal from './DealerExcelUploadModal';
import AddStockAllocationModal from '../../../components/AddStockAllocationModal/AddStockAllocationModal';
import Modal from '../../../components/Modal/Modal';
import Can from '../../../components/Can/Can';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import PaymentModal from '../../../components/PaymentModal/PaymentModal';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../../components/RecordViewModal/RecordViewModal';

const Dealers = () => {
    const [dealers, setDealers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [isAddDealerModalOpen, setIsAddDealerModalOpen] = useState(false);
    const [isDealerUploadModalOpen, setIsDealerUploadModalOpen] = useState(false);
    const [isAddStockModalOpen, setIsAddStockModalOpen] = useState(false);
    const [editingDealer, setEditingDealer] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [paymentDealer, setPaymentDealer] = useState(null);
    const [viewingDealer, setViewingDealer] = useState(null);
    const [masterData, setMasterData] = useState({
        platforms: [],
        deviceModels: [],
        simTypes: [],
        simValidities: []
    });

    const fetchDealers = async () => {
        setLoading(true);
        try {
            const response = await api.get('/dealers/list.php').catch(() => ({ data: { success: true, data: { dealers: [] } } }));
            if (response.data.success) {
                setDealers(response.data.data.dealers || []);
            }
        } catch (error) {
            console.error('Failed to fetch dealers', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchFilterMasters = async () => {
        try {
            const [platformsRes, deviceTypesRes, validitiesRes, simsRes] = await Promise.all([
                api.get('/platforms/list.php').catch(() => ({ data: { success: true, data: { platforms: [] } } })),
                api.get('/device_types/list.php').catch(() => ({ data: { success: true, data: { device_types: [] } } })),
                api.get('/sim_validities/list.php').catch(() => ({ data: { success: true, data: { validities: [] } } })),
                api.get('/sims/list.php').catch(() => ({ data: { success: true, data: { sims: [] } } }))
            ]);

            const rawPlatforms = platformsRes.data?.data?.platforms || [];
            const activePlatforms = rawPlatforms
                .filter((p) => !p.status || String(p.status).toLowerCase() === 'active')
                .map((p) => p.platform_name);

            const rawDeviceTypes = deviceTypesRes.data?.data?.device_types || [];
            const deviceModels = rawDeviceTypes.map((dt) => dt.device_type);

            const rawValidities = validitiesRes.data?.data?.validities || [];
            const simValidities = rawValidities
                .filter((v) => !v.status || String(v.status).toLowerCase() === 'active')
                .map((v) => `${v.months} Months`);

            const rawSims = simsRes.data?.data?.sims || [];
            const simTypes = [...new Set(rawSims.map((s) => s.sim_type).filter(Boolean))];

            setMasterData({
                platforms: activePlatforms,
                deviceModels,
                simValidities,
                simTypes
            });
        } catch (err) {
            console.error('Failed to load filter masters', err);
        }
    };

    const handleDeleteDealer = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post('/dealers/delete.php', { id: deleteTarget.id });
            if (response.data.success) {
                setDeleteTarget(null);
                fetchDealers();
            } else {
                window.alert(response.data.message || 'Failed to delete dealer');
            }
        } catch (error) {
            window.alert(error.response?.data?.message || 'Failed to delete dealer');
        }
    };

    useEffect(() => {
        fetchDealers();
        fetchFilterMasters();
    }, []);

    const filteredDealers = filterTableRows(dealers, filters, {
        dateKeys: ['enrolled_date'],
        searchKeys: ['dealer_name', 'mobile_no', 'location', 'software', 'notes']
    });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredDealers, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Dealer Management</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={() => setIsAddStockModalOpen(true)}>
                        <PackagePlus size={16} /> Add Device / SIM
                    </button>
                    <Can permission="dealers.import">
                        <button className="btn btn-outline" onClick={() => setIsDealerUploadModalOpen(true)}>
                            <Upload size={16} /> Upload Excel
                        </button>
                    </Can>
                    <button className="btn btn-primary" onClick={() => setIsAddDealerModalOpen(true)}>
                        <Plus size={16} /> Add Dealer
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={dealers}
                    dateKeys={['enrolled_date']}
                    searchPlaceholder="Search by name, mobile, location, or notes..."
                    platformOptions={masterData.platforms}
                    deviceModelOptions={masterData.deviceModels}
                    simTypeOptions={masterData.simTypes}
                    simValidityOptions={masterData.simValidities}
                    showPlatform
                    showDeviceModel
                    showSimType
                    showSimValidity
                    showInstallationStatus
                    showPaymentStatus
                />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Dealer Name</th>
                                <th>Mobile No</th>
                                <th>Location</th>
                                <th>Enrolled Date</th>
                                <th>Installation Status</th>
                                <th>Software</th>
                                <th>Device Count</th>
                                <th>SIM Count</th>
                                <th>Payment Status</th>
                                <th>Total Price</th>
                                <th>Amount Paid</th>
                                <th>Pending Payment</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="14" className="text-center">Loading dealers...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="14" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map(dealer => (
                                    <tr key={dealer.id}>
                                        <td className="truncate-cell" title={dealer.dealer_name}>{dealer.dealer_name}</td>
                                        <td>{dealer.mobile_no}</td>
                                        <td className="truncate-cell" title={dealer.location}>{dealer.location}</td>
                                        <td>{formatDate(dealer.enrolled_date)}</td>
                                        <td>
                                            <span className={`badge ${dealer.installation_status === 'Not Willing' ? 'badge-danger' : 'badge-info'}`}>
                                                {dealer.installation_status}
                                            </span>
                                        </td>
                                        <td>{dealer.software || '-'}</td>
                                        <td>{dealer.available_device_count ?? dealer.device_count ?? 0}</td>
                                        <td>{dealer.available_sim_count ?? dealer.sim_count ?? 0}</td>
                                        <td>{dealer.total_amount > 0 ? <span className={`badge ${dealer.payment_status === 'Paid' ? 'badge-success' : dealer.payment_status === 'Partially Paid' ? 'badge-warning' : 'badge-danger'}`}>{dealer.payment_status}</span> : '-'}</td>
                                        <td>₹{Number(dealer.total_amount || 0).toFixed(2)}</td>
                                        <td>₹{Number(dealer.amount_paid || 0).toFixed(2)}</td>
                                        <td>₹{Number(dealer.pending_amount || 0).toFixed(2)}</td>
                                        <td><button type="button" className="btn btn-outline" onClick={() => setPaymentDealer(dealer)}>Payment</button></td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View dealer" title="View" onClick={() => setViewingDealer(dealer)}><Eye size={16} /></button>
                                                <button className="icon-btn edit" type="button" aria-label="Edit dealer" onClick={() => setEditingDealer(dealer)}><Edit size={16} /></button>
                                                <button className="icon-btn delete" type="button" aria-label="Delete dealer" onClick={() => setDeleteTarget(dealer)}><Trash2 size={16} /></button>
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
                    itemName="dealers"
                />
            </div>

            {isAddDealerModalOpen && (
                <AddDealerModal 
                    onClose={() => setIsAddDealerModalOpen(false)} 
                    onSuccess={() => {
                        setIsAddDealerModalOpen(false);
                        fetchDealers();
                    }}
                />
            )}

            {isDealerUploadModalOpen && (
                <DealerExcelUploadModal
                    onClose={() => setIsDealerUploadModalOpen(false)}
                    onSuccess={() => {
                        setIsDealerUploadModalOpen(false);
                        fetchDealers();
                    }}
                />
            )}

            {isAddStockModalOpen && (
                <AddStockAllocationModal 
                    onClose={() => setIsAddStockModalOpen(false)}
                    onSuccess={() => {
                        setIsAddStockModalOpen(false);
                        fetchDealers();
                    }}
                    ownerType="dealer"
                    ownersList={dealers}
                />
            )}

            {editingDealer && (
                <EditDealerModal
                    dealer={editingDealer}
                    onClose={() => setEditingDealer(null)}
                    onSuccess={() => {
                        setEditingDealer(null);
                        fetchDealers();
                    }}
                />
            )}

            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete dealer"
                    maxWidth="420px"
                    footer={
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={handleDeleteDealer}>Delete</button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this dealer?</p>
                </Modal>
            )}
            {paymentDealer && <PaymentModal dealer={paymentDealer} onClose={() => setPaymentDealer(null)} onSuccess={() => { setPaymentDealer(null); fetchDealers(); }} />}
            {viewingDealer && <RecordViewModal isOpen onClose={() => setViewingDealer(null)} title="Dealer Details" record={viewingDealer} fetchRecord={async (dealer) => { const details = (await api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer.id}`)).data.data; return { ...dealer, ...details }; }} fields={[{ label: 'Dealer Name', key: 'dealer_name' }, { label: 'Mobile No', key: 'mobile_no' }, { label: 'Location', key: 'location' }, { label: 'Enrolled Date', key: 'enrolled_date' }, { label: 'Installation Status', key: 'installation_status' }, { label: 'Software', key: 'software' }, { label: 'Total Device', key: 'summary', format: (value) => value?.total_device ?? 0 }, { label: 'Used Device', key: 'summary', format: (value) => value?.used_device ?? 0 }, { label: 'Available Device', key: 'summary', format: (value) => value?.available_device ?? 0 }, { label: 'Total SIM', key: 'summary', format: (value) => value?.total_sim ?? 0 }, { label: 'Used SIM', key: 'summary', format: (value) => value?.used_sim ?? 0 }, { label: 'Available SIM', key: 'summary', format: (value) => value?.available_sim ?? 0 }, { label: 'Notes', key: 'notes' }]} renderDetails={(details) => <><h4>Allocated Devices</h4><div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI No</th><th>Status</th></tr></thead><tbody>{(details.devices || []).map((device) => <tr key={device.allocation_id}><td>{device.model_name}</td><td>{device.imei_no}</td><td>{device.status}</td></tr>)}</tbody></table></div><h4>Allocated SIMs</h4><div className="table-container"><table><thead><tr><th>SIM No</th><th>SIM Type</th><th>Validity</th><th>Status</th></tr></thead><tbody>{(details.sims || []).map((sim) => <tr key={sim.allocation_id}><td>{sim.sim_no}</td><td>{sim.sim_type || '-'}</td><td>{sim.sim_validity_months ? `${sim.sim_validity_months} Months` : '-'}</td><td>{sim.status}</td></tr>)}</tbody></table></div></>} />}
        </div>
    );
};

export default Dealers;
