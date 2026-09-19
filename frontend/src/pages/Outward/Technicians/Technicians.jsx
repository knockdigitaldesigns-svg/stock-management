import { useState, useEffect } from 'react';
import { Plus, Eye, Trash2, Edit, PackagePlus, Upload } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import AddTechnicianModal from './AddTechnicianModal';
import EditTechnicianModal from './EditTechnicianModal';
import TechnicianExcelUploadModal from './TechnicianExcelUploadModal';
import AddStockAllocationModal from '../../../components/AddStockAllocationModal/AddStockAllocationModal';
import Modal from '../../../components/Modal/Modal';
import Can from '../../../components/Can/Can';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../../components/RecordViewModal/RecordViewModal';
import CustomerCashCollections from '../../../components/CustomerCashCollections/CustomerCashCollections';

const Technicians = () => {
    const [technicians, setTechnicians] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isTechnicianUploadModalOpen, setIsTechnicianUploadModalOpen] = useState(false);
    const [isAddStockModalOpen, setIsAddStockModalOpen] = useState(false);
    const [editingTechnician, setEditingTechnician] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [viewingTechnician, setViewingTechnician] = useState(null);
    const [cashTechnician, setCashTechnician] = useState(null);
    const [masterData, setMasterData] = useState({
        platforms: [],
        deviceModels: [],
        simTypes: [],
        simValidities: []
    });

    const fetchTechnicians = async () => {
        setLoading(true);
        try {
            const response = await api.get('/technicians/list.php').catch(() => ({ data: { success: true, data: { technicians: [] } } }));
            if (response.data.success) {
                setTechnicians(response.data.data.technicians || []);
            }
        } catch (error) {
            console.error('Failed to fetch technicians', error);
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

    const handleDeleteTechnician = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post('/technicians/delete.php', { id: deleteTarget.id });
            if (response.data.success) {
                setDeleteTarget(null);
                fetchTechnicians();
            } else {
                window.alert(response.data.message || 'Failed to delete technician');
            }
        } catch (error) {
            window.alert(error.response?.data?.message || 'Failed to delete technician');
        }
    };

    useEffect(() => {
        fetchTechnicians();
        fetchFilterMasters();
    }, []);

    const filteredTechnicians = filterTableRows(technicians, filters, {
        dateKeys: ['enrolled_date'],
        searchKeys: ['technician_name', 'mobile_no', 'location', 'notes']
    });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredTechnicians, 10, [filters]);

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Technician Management</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={() => setIsAddStockModalOpen(true)}>
                        <PackagePlus size={16} /> Add Device / SIM
                    </button>
                    <Can permission="technicians.import">
                        <button className="btn btn-outline" onClick={() => setIsTechnicianUploadModalOpen(true)}>
                            <Upload size={16} /> Upload Excel
                        </button>
                    </Can>
                    <button className="btn btn-primary" onClick={() => setIsAddModalOpen(true)}>
                        <Plus size={16} /> Add Technician
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={technicians}
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
                    showPaymentStatus
                />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Technician Name</th>
                                <th>Mobile No</th>
                                <th>Location</th>
                                <th>Enrolled Date</th>
                                <th>Device Count</th>
                                <th>SIM Count</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="8" className="text-center">Loading technicians...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="8" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map(tech => (
                                    <tr key={tech.id}>
                                        <td className="truncate-cell" title={tech.technician_name}>{tech.technician_name}</td>
                                        <td>{tech.mobile_no}</td>
                                        <td className="truncate-cell" title={tech.location}>{tech.location}</td>
                                        <td>{formatDate(tech.enrolled_date)}</td>
                                        <td>{tech.available_device_count ?? tech.device_count ?? 0}</td>
                                        <td>{tech.available_sim_count ?? tech.sim_count ?? 0}</td>
                                        <td>
                                            <span className={`badge ${tech.payment_status === 'Paid' ? 'badge-success' : tech.payment_status === 'Partially Paid' ? 'badge-warning' : 'badge-danger'}`}>
                                                {tech.payment_status || 'No Payment Required'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View technician" title="View" onClick={() => setViewingTechnician(tech)}><Eye size={16} /></button>
                                                <button className="btn btn-outline" type="button" onClick={() => setCashTechnician(tech)}>Cash</button>
                                                <button className="icon-btn edit" type="button" aria-label="Edit technician" onClick={() => setEditingTechnician(tech)}><Edit size={16} /></button>
                                                <button className="icon-btn delete" type="button" aria-label="Delete technician" onClick={() => setDeleteTarget(tech)}><Trash2 size={16} /></button>
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
                    itemName="technicians"
                />
            </div>

            {isAddModalOpen && (
                <AddTechnicianModal 
                    onClose={() => setIsAddModalOpen(false)} 
                    onSuccess={() => {
                        setIsAddModalOpen(false);
                        fetchTechnicians();
                    }}
                />
            )}

            {isTechnicianUploadModalOpen && (
                <TechnicianExcelUploadModal
                    onClose={() => setIsTechnicianUploadModalOpen(false)}
                    onSuccess={() => {
                        setIsTechnicianUploadModalOpen(false);
                        fetchTechnicians();
                    }}
                />
            )}

            {isAddStockModalOpen && (
                <AddStockAllocationModal 
                    onClose={() => setIsAddStockModalOpen(false)}
                    onSuccess={() => {
                        setIsAddStockModalOpen(false);
                        fetchTechnicians();
                    }}
                    ownerType="technician"
                    ownersList={technicians}
                />
            )}

            {editingTechnician && (
                <EditTechnicianModal
                    technician={editingTechnician}
                    onClose={() => setEditingTechnician(null)}
                    onSuccess={() => {
                        setEditingTechnician(null);
                        fetchTechnicians();
                    }}
                />
            )}

            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete technician"
                    maxWidth="420px"
                    footer={
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={handleDeleteTechnician}>Delete</button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this technician?</p>
                </Modal>
            )}
            {viewingTechnician && <RecordViewModal isOpen onClose={() => setViewingTechnician(null)} title="Technician Details" record={viewingTechnician} fetchRecord={async (technician) => { const details = (await api.get(`/stock/owner_details.php?owner_type=technician&owner_id=${technician.id}`)).data.data; return { ...technician, ...details }; }} fields={[{ label: 'Technician Name', key: 'technician_name' }, { label: 'Mobile No', key: 'mobile_no' }, { label: 'Location', key: 'location' }, { label: 'Enrolled Date', key: 'enrolled_date' }, { label: 'Total Device', key: 'summary', format: (value) => value?.total_device ?? 0 }, { label: 'Used Device', key: 'summary', format: (value) => value?.used_device ?? 0 }, { label: 'Available Device', key: 'summary', format: (value) => value?.available_device ?? 0 }, { label: 'Total SIM', key: 'summary', format: (value) => value?.total_sim ?? 0 }, { label: 'Used SIM', key: 'summary', format: (value) => value?.used_sim ?? 0 }, { label: 'Available SIM', key: 'summary', format: (value) => value?.available_sim ?? 0 }, { label: 'Notes', key: 'notes' }]} renderDetails={(details) => <><h4>Allocated Devices</h4><div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI No</th><th>Status</th></tr></thead><tbody>{(details.devices || []).map((device) => <tr key={device.allocation_id}><td>{device.model_name}</td><td>{device.imei_no}</td><td>{device.status}</td></tr>)}</tbody></table></div><h4>Allocated SIMs</h4><div className="table-container"><table><thead><tr><th>SIM No</th><th>SIM Type</th><th>Validity</th><th>Status</th></tr></thead><tbody>{(details.sims || []).map((sim) => <tr key={sim.allocation_id}><td>{sim.sim_no}</td><td>{sim.sim_type || '-'}</td><td>{sim.sim_validity_months ? `${sim.sim_validity_months} Months` : '-'}</td><td>{sim.status}</td></tr>)}</tbody></table></div></>} />}
            {cashTechnician && <Modal isOpen onClose={() => setCashTechnician(null)} title={`Cash Collections - ${cashTechnician.technician_name}`} maxWidth="1250px" footer={<button type="button" className="btn btn-outline" onClick={() => setCashTechnician(null)}>Close</button>}><CustomerCashCollections ownerId={cashTechnician.id} recipientType="Technician" onSaved={fetchTechnicians} /></Modal>}
        </div>
    );
};

export default Technicians;
