import { useState, useEffect } from 'react';
import { Plus, Eye, Trash2, Edit, PackagePlus, Upload } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import AddTechnicianModal from './AddTechnicianModal';
import EditTechnicianModal from './EditTechnicianModal';
import TechnicianExcelUploadModal from './TechnicianExcelUploadModal';
import AddStockAllocationModal from '../../../components/AddStockAllocationModal/AddStockAllocationModal';
import Modal from '../../../components/Modal/Modal';
import Pagination from '../../../components/Pagination/Pagination';
import TableFilterBar, { emptyTableFilters } from '../../../components/TableFilterBar/TableFilterBar';
import RecordViewModal from '../../../components/RecordViewModal/RecordViewModal';
import CustomerCashCollections from '../../../components/CustomerCashCollections/CustomerCashCollections';
import { useAuth } from '../../../context/AuthContext';
import { showGlobalError } from '../../../context/ErrorContext';

const Technicians = () => {
    const { hasPermission, refreshPermissions } = useAuth();
    const [technicians, setTechnicians] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [filters, setFilters] = useState(emptyTableFilters);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isTechnicianUploadModalOpen, setIsTechnicianUploadModalOpen] = useState(false);
    const [isAddStockModalOpen, setIsAddStockModalOpen] = useState(false);
    const [editingTechnician, setEditingTechnician] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [viewingTechnician, setViewingTechnician] = useState(null);
    const [cashTechnician, setCashTechnician] = useState(null);
    const [technicianDropdownResetKey, setTechnicianDropdownResetKey] = useState(0);
    const [masterData, setMasterData] = useState({
        platforms: [],
        deviceModels: [],
        simTypes: [],
        simValidities: [],
        technicians: []
    });

    const fetchTechnicians = async (targetPage = page, targetPageSize = pageSize, targetFilters = filters) => {
        setLoading(true);
        setLoadError('');
        try {
            const response = await api.get('/technicians/list.php', {
                params: {
                    page: targetPage,
                    page_size: targetPageSize,
                    search: targetFilters.search || '',
                    date_from: targetFilters.date_from || '',
                    date_to: targetFilters.date_to || '',
                    technician_id: targetFilters.technician_id || '',
                    platform: targetFilters.platform || '',
                    device_model: targetFilters.deviceModel || '',
                    sim_type: targetFilters.simType || '',
                    sim_validity: targetFilters.simValidity || '',
                    payment_status: targetFilters.paymentStatus || ''
                }
            });
            if (response.data.success) {
                setTechnicians(response.data.data.technicians || []);
                const nextPagination = response.data.data.pagination || { total: 0, page: targetPage, page_size: targetPageSize, total_pages: 1 };
                setPagination(nextPagination);
                if (targetPage > nextPagination.total_pages) setPage(nextPagination.total_pages);
            } else {
                setLoadError(response.data.message || 'Failed to load technicians.');
            }
        } catch (error) {
            console.error('Failed to fetch technicians', error);
            setLoadError(error.response?.data?.message || 'Failed to load technicians.');
        } finally {
            setLoading(false);
        }
    };

    const fetchFilterMasters = async () => {
        try {
            const [platformsRes, deviceTypesRes, validitiesRes, simTypesRes, techniciansRes] = await Promise.all([
                api.get('/platforms/list.php'),
                api.get('/device_types/list.php'),
                api.get('/sim_validities/list.php'),
                api.get('/sim_types/list.php'),
                api.get('/technicians/list.php?for_lookup=1')
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

            const rawSimTypes = simTypesRes.data?.data?.sim_types || [];
            const simTypes = [...new Set(rawSimTypes.map((simType) => simType.sim_type).filter(Boolean))];

            setMasterData({
                platforms: activePlatforms,
                deviceModels,
                simValidities,
                simTypes,
                technicians: techniciansRes.data?.data?.technicians || []
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
        fetchFilterMasters();
    }, []);

    useEffect(() => {
        fetchTechnicians(page, pageSize, filters);
    }, [page, pageSize, filters]);

    useEffect(() => {
        refreshPermissions().catch((error) => {
            showGlobalError(error.response?.data?.message || error.message || 'Unable to refresh permissions.');
        });
    }, [refreshPermissions]);

    const updateFilters = (nextFilters) => {
        setFilters(nextFilters);
        setPage(1);
    };

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
        setPage(1);
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Technician Management</h2>
                    <p className="page-subtitle">Manage technician profiles and their assigned inventory.</p>
                </div>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={() => setIsAddStockModalOpen(true)}>
                        <PackagePlus size={16} /> Add Device / SIM
                    </button>
                        {hasPermission('technicians.import') && <button className="btn btn-outline" onClick={() => setIsTechnicianUploadModalOpen(true)}>
                            <Upload size={16} /> Upload Excel
                        </button>}
                    <button className="btn btn-primary" onClick={() => setIsAddModalOpen(true)}>
                        <Plus size={16} /> Add Technician
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={updateFilters}
                    onReset={() => {
                        updateFilters(emptyTableFilters());
                        setTechnicianDropdownResetKey((key) => key + 1);
                    }}
                    items={technicians}
                    dateKeys={['enrolled_date']}
                    searchPlaceholder="Search by name, mobile, location, or notes..."
                    platformOptions={masterData.platforms}
                    deviceModelOptions={masterData.deviceModels}
                    simTypeOptions={masterData.simTypes}
                    simValidityOptions={masterData.simValidities}
                    technicianOptions={masterData.technicians.map((technician) => ({
                        value: String(technician.id),
                        label: technician.technician_name
                    }))}
                    technicianDropdownResetKey={technicianDropdownResetKey}
                    showTechnician
                    showPlatform
                    showDeviceModel
                    showSimType
                    showSimValidity
                    showPaymentStatus
                    gridCols={5}
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
                            ) : loadError ? (
                                <tr>
                                    <td colSpan="8" className="text-center empty-state">{loadError}</td>
                                </tr>
                            ) : technicians.length === 0 ? (
                                <tr>
                                    <td colSpan="8" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                technicians.map(tech => (
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
                                                <button className="btn btn-outline" type="button" onClick={() => setCashTechnician(tech)}>Cash</button>
                                                <button className="icon-btn view" type="button" aria-label="View technician" title="View" onClick={() => setViewingTechnician(tech)}><Eye size={16} /></button>
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
                    totalItems={pagination.total}
                    pageSize={pageSize}
                    onPageChange={setPage}
                    onPageSizeChange={updatePageSize}
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
