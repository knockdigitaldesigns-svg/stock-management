import { useState, useEffect } from 'react';
import { Download, Plus, Upload, Eye, Trash2, Edit } from 'lucide-react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import './DeviceMaintenance.css';
import AddDeviceModal from './AddDeviceModal';
import EditDeviceModal from './EditDeviceModal';
import DeviceExcelUploadModal from './DeviceExcelUploadModal';
import Modal from '../../../components/Modal/Modal';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';
import useDeviceModels from '../../../hooks/useDeviceModels';
import RecordViewModal from '../../../components/RecordViewModal/RecordViewModal';

const DeviceMaintenance = () => {
    const [devices, setDevices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isExcelModalOpen, setIsExcelModalOpen] = useState(false);
    const [editingDevice, setEditingDevice] = useState(null);
    const [viewingDevice, setViewingDevice] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const { models } = useDeviceModels();

    const fetchDevices = async () => {
        setLoading(true);
        try {
            const response = await api.get('/devices/list.php').catch(() => ({ data: { success: true, data: { devices: [] } } }));
            if (response.data.success) {
                setDevices(response.data.data.devices || []);
            }
        } catch (error) {
            console.error('Failed to fetch devices', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDeleteDevice = async () => {
        if (!deleteTarget) return;

        try {
            const response = await api.post('/devices/delete.php', { id: deleteTarget.id });
            if (response.data.success) {
                setDeleteTarget(null);
                fetchDevices();
            } else {
                window.alert(response.data.message || 'Failed to delete device');
            }
        } catch (error) {
            window.alert(error.response?.data?.message || 'Failed to delete device');
        }
    };

    useEffect(() => {
        fetchDevices();
    }, []);

    const filteredDevices = filterTableRows(devices, filters, { dateKeys: ['purchase_date'], deviceKey: 'model_name', searchKeys: ['imei_no', 'model_name', 'notes'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredDevices, 10, [filters]);

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
                <h2>Device Maintenance</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" onClick={() => setIsExcelModalOpen(true)}>
                        <Upload size={16} /> Upload Excel
                    </button>
                    <button className="btn btn-primary" onClick={() => setIsAddModalOpen(true)}>
                        <Plus size={16} /> Add Device
                    </button>
                </div>
            </div>

            <div className="card">
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={devices}
                    dateKeys={['purchase_date']}
                    showDeviceModel
                    deviceModelOptions={models.map((model) => model.label)}
                    showStatus
                    statusOptions={['Available', 'Allocated', 'Used']}
                    searchPlaceholder="Search by IMEI, model, or notes..."
                />

                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Purchase Date</th>
                                <th>Device Model</th>
                                <th>IMEI No</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="5" className="text-center">Loading devices...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="5" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map(device => (
                                    <tr key={device.id}>
                                        <td>{formatDate(device.purchase_date)}</td>
                                        <td className="truncate-cell" title={device.model_name}>{device.model_name}</td>
                                        <td className="truncate-cell" title={device.imei_no}>{device.imei_no}</td>
                                        <td>{getStatusBadge(device.status)}</td>
                                        <td>
                                            <div className="action-buttons">
                                                <button className="icon-btn view" type="button" aria-label="View device" title="View" onClick={() => setViewingDevice(device)}><Eye size={16} /></button>
                                                <button className="icon-btn edit" type="button" aria-label="Edit device" onClick={() => setEditingDevice(device)}><Edit size={16} /></button>
                                                <button className="icon-btn delete" type="button" aria-label="Delete device" onClick={() => setDeleteTarget(device)}><Trash2 size={16} /></button>
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
                    itemName="devices"
                />
            </div>

            {isAddModalOpen && (
                <AddDeviceModal 
                    onClose={() => setIsAddModalOpen(false)} 
                    onSuccess={() => {
                        setIsAddModalOpen(false);
                        fetchDevices();
                    }}
                />
            )}

            {isExcelModalOpen && (
                <DeviceExcelUploadModal 
                    onClose={() => setIsExcelModalOpen(false)} 
                    onSuccess={() => {
                        setIsExcelModalOpen(false);
                        fetchDevices();
                    }}
                />
            )}

            {editingDevice && (
                <EditDeviceModal
                    device={editingDevice}
                    onClose={() => setEditingDevice(null)}
                    onSuccess={() => {
                        setEditingDevice(null);
                        fetchDevices();
                    }}
                />
            )}

            {viewingDevice && <RecordViewModal isOpen onClose={() => setViewingDevice(null)} title="Device Details" record={viewingDevice} fetchRecord={async (row) => (await api.get('/devices/list.php')).data.data.devices.find((device) => String(device.id) === String(row.id)) || row} fields={[{ label: 'Device Model', key: 'model_name' }, { label: 'IMEI No', key: 'imei_no' }, { label: 'Purchase Date', key: 'purchase_date' }, { label: 'Status', key: 'status' }, { label: 'Owner', key: 'owner_name' }, { label: 'Allocation Date', key: 'allocation_date' }, { label: 'Software', key: 'software' }, { label: 'Payment Status', key: 'payment_status' }, { label: 'Notes', key: 'notes' }]} />}

            {deleteTarget && (
                <Modal
                    isOpen={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    title="Delete device"
                    maxWidth="420px"
                    footer={
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={handleDeleteDevice}>Delete</button>
                        </>
                    }
                >
                    <p>Are you sure you want to delete this device?</p>
                </Modal>
            )}
        </div>
    );
};

export default DeviceMaintenance;
