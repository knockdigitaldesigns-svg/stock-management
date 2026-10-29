import { useState, useEffect } from 'react';
import { Download } from 'lucide-react';
import api from '../../../services/api';
import { exportToExcel, exportToPDF } from '../../../utils/export';
import './InwardReports.css';
import { formatDate } from '../../../utils/date';
import Pagination from '../../../components/Pagination/Pagination';
import TableFilterBar, { emptyTableFilters } from '../../../components/TableFilterBar/TableFilterBar';

const InwardReports = () => {
    const [activeTab, setActiveTab] = useState('sim'); // 'sim' or 'device'
    
    const [simSummary, setSimSummary] = useState(null);
    const [deviceSummary, setDeviceSummary] = useState(null);
    const [simList, setSimList] = useState([]);
    const [deviceList, setDeviceList] = useState([]);
    const [deviceModels, setDeviceModels] = useState([]);
    const [simTypes, setSimTypes] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState('');

    const formatReportStatus = (row) => {
        const inventoryStatus = String(row.status || 'available');
        const lifecycleStatus = String(row.lifecycle_status || '');
        const inventoryLabel = inventoryStatus.charAt(0).toUpperCase() + inventoryStatus.slice(1).toLowerCase();
        if (lifecycleStatus && lifecycleStatus.toLowerCase() !== 'available') {
            const lifecycleLabel = lifecycleStatus.charAt(0).toUpperCase() + lifecycleStatus.slice(1).toLowerCase();
            return `${inventoryLabel} (${lifecycleLabel})`;
        }
        return inventoryLabel;
    };

    const getStatusCounts = (rows) => rows.reduce((counts, row) => {
        const status = String(row.status || 'available').toLowerCase();
        counts.total += 1;
        if (Object.prototype.hasOwnProperty.call(counts, status)) counts[status] += 1;
        return counts;
    }, { total: 0, available: 0, allocated: 0, used: 0, reserved: 0 });

    const getFilteredModelCounts = (devices) => devices.reduce((counts, device) => {
        const model = device.model_name || 'Unknown';
        counts[model] = (counts[model] || 0) + 1;
        return counts;
    }, {});

    useEffect(() => {
        Promise.all([api.get('/device_types/list.php'), api.get('/sim_types/list.php')])
            .then(([response, simTypesResponse]) => {
                const deviceTypes = response.data.data?.device_types || [];
                setDeviceModels(deviceTypes
                    .filter((type) => String(type.status || 'Active').trim().toLowerCase() === 'active')
                    .map((type) => type.device_type));
                setSimTypes((simTypesResponse.data.data?.sim_types || []).map((simType) => simType.sim_type));
            })
            .catch((error) => {
                console.error('Failed to load report filter options', error);
                setLoadError(error.response?.data?.message || 'Failed to load report filter options.');
            });
    }, []);

    useEffect(() => {
        const fetchReports = async () => {
            setLoading(true);
            setLoadError('');
            try {
                const params = {
                    page,
                    page_size: pageSize,
                    status: filters.status || '',
                    search: filters.search || '',
                    date_from: filters.date_from || '',
                    date_to: filters.date_to || ''
                };
                if (activeTab === 'sim') {
                    params.sim_type = filters.simType || '';
                    const listRes = await api.get('/reports/sim_available.php', { params });
                    if (listRes.data?.success) {
                        setSimList(listRes.data.data.sims || []);
                        const nextPagination = listRes.data.data.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
                        setPagination(nextPagination);
                        if (page > nextPagination.total_pages) setPage(nextPagination.total_pages);
                    }
                } else {
                    params.device_model = filters.deviceModel || '';
                    const listRes = await api.get('/reports/device_available.php', { params });
                    if (listRes.data?.success) {
                        setDeviceList(listRes.data.data.devices || []);
                        const nextPagination = listRes.data.data.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
                        setPagination(nextPagination);
                        if (page > nextPagination.total_pages) setPage(nextPagination.total_pages);
                    }
                }
            } catch (error) {
                console.error("Failed to fetch reports", error);
                setLoadError(error.response?.data?.message || 'Failed to fetch reports.');
            } finally {
                setLoading(false);
            }
        };
        fetchReports();
    }, [activeTab, filters, page, pageSize]);

    useEffect(() => {
        const fetchSummary = async () => {
            try {
                if (activeTab === 'sim') {
                    const response = await api.get('/reports/sim_summary.php');
                    if (response.data?.success) setSimSummary(response.data.data.summary);
                } else {
                    const response = await api.get('/reports/device_summary.php');
                    if (response.data?.success) setDeviceSummary(response.data.data);
                }
            } catch (error) {
                console.error('Failed to fetch inventory report summary', error);
                setLoadError(error.response?.data?.message || 'Failed to load report summary.');
            }
        };
        fetchSummary();
    }, [activeTab]);

    const updateFilters = (nextFilters) => {
        setFilters(nextFilters);
        setPage(1);
    };

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
        setPage(1);
    };

    const fetchAllReportRows = async () => {
        const params = {
            status: filters.status || '',
            search: filters.search || '',
            date_from: filters.date_from || '',
            date_to: filters.date_to || ''
        };
        if (activeTab === 'sim') {
            params.sim_type = filters.simType || '';
            const response = await api.get('/reports/sim_available.php', { params });
            return response.data.data?.sims || [];
        }
        params.device_model = filters.deviceModel || '';
        const response = await api.get('/reports/device_available.php', { params });
        return response.data.data?.devices || [];
    };

    const handleExportExcel = async () => {
        let exportRows;
        try {
            exportRows = await fetchAllReportRows();
        } catch (error) {
            setLoadError(error.response?.data?.message || 'Failed to load report export data.');
            return;
        }
        if (activeTab === 'sim') {
            const summary = getStatusCounts(exportRows);
            const summaryData = [
                { label: 'Total SIMs', value: summary.total },
                { label: 'Available', value: summary.available },
                { label: 'Allocated', value: summary.allocated },
                { label: 'Used', value: summary.used },
                { label: 'Reserved', value: summary.reserved }
            ];

            const simData = exportRows.map(s => ({
                "Purchase Date": formatDate(s.purchase_date),
                "SIM No": s.sim_no,
                "SIM Type": s.sim_type || '-',
                "Notes": s.notes || '-',
                "Status": formatReportStatus(s)
            }));

            const columns = [
                { header: "Purchase Date", key: "Purchase Date" },
                { header: "SIM No", key: "SIM No" },
                { header: "SIM Type", key: "SIM Type" },
                { header: "Notes", key: "Notes" },
                { header: "Status", key: "Status" }
            ];

            exportToExcel(simData, 'SIM_Report', 'SIMs', {
                summary: summaryData,
                summaryTitle: 'SIM Report Summary',
                tableTitle: 'Current SIM Report',
                columns: columns,
                combinedSheet: { title: 'SIM REPORT' }
            });
        } else {
            const summary = getStatusCounts(exportRows);
            const deviceSummaryData = [
                { label: 'Total Devices', value: summary.total },
                { label: 'Available', value: summary.available },
                { label: 'Allocated', value: summary.allocated },
                { label: 'Used', value: summary.used },
                { label: 'Reserved', value: summary.reserved }
            ];

            const modelBreakdown = Object.entries(getFilteredModelCounts(exportRows)).map(([model, count]) => ({
                "Model": model,
                "Count": count
            }));

            const deviceData = exportRows.map(d => ({
                "Purchase Date": formatDate(d.purchase_date),
                "Model": d.model_name,
                "IMEI No": d.imei_no,
                "Notes": d.notes || '-',
                "Status": formatReportStatus(d)
            }));

            const columns = [
                { header: "Purchase Date", key: "Purchase Date" },
                { header: "Model", key: "Model" },
                { header: "IMEI No", key: "IMEI No" },
                { header: "Notes", key: "Notes" },
                { header: "Status", key: "Status" }
            ];

            exportToExcel(deviceData, 'Device_Report', 'Devices', {
                summary: deviceSummaryData,
                summaryTitle: 'Device Report Summary',
                sections: [{
                    name: 'Model Breakdown',
                    title: 'Model Breakdown',
                    columns: [{ header: 'Model', key: 'Model' }, { header: 'Count', key: 'Count' }],
                    rows: modelBreakdown.length > 0 ? modelBreakdown : [{ Model: 'No models', Count: 0 }]
                }],
                tableTitle: 'Current Device Report',
                columns: columns,
                combinedSheet: { title: 'DEVICE REPORT' }
            });
        }
    };

    const handleExportPDF = async () => {
        let exportRows;
        try {
            exportRows = await fetchAllReportRows();
        } catch (error) {
            setLoadError(error.response?.data?.message || 'Failed to load report export data.');
            return;
        }
        if (activeTab === 'sim') {
            const cols = [
                { header: "Purchase Date", key: "purchase_date" },
                { header: "SIM No", key: "sim_no" },
                { header: "SIM Type", key: "sim_type" },
                { header: "Notes", key: "notes" },
                { header: "Status", key: "status" }
            ];

            const simData = exportRows.map(s => ({
                purchase_date: formatDate(s.purchase_date),
                sim_no: s.sim_no,
                sim_type: s.sim_type || '-',
                notes: s.notes || '-',
                status: formatReportStatus(s)
            }));

            const summary = getStatusCounts(exportRows);
            const summaryData = [
                { label: 'Total SIMs', value: summary.total },
                { label: 'Available', value: summary.available },
                { label: 'Allocated', value: summary.allocated },
                { label: 'Used', value: summary.used },
                { label: 'Reserved', value: summary.reserved }
            ];

            exportToPDF(simData, 'SIM_Report', 'SIM REPORT', cols, {
                summary: summaryData,
                summaryTitle: 'SIM Report Summary'
            });
        } else {
            const cols = [
                { header: "Purchase Date", key: "purchase_date" },
                { header: "Model", key: "model_name" },
                { header: "IMEI No", key: "imei_no" },
                { header: "Notes", key: "notes" },
                { header: "Status", key: "status" }
            ];

            const deviceData = exportRows.map(d => ({
                purchase_date: formatDate(d.purchase_date),
                model_name: d.model_name,
                imei_no: d.imei_no,
                notes: d.notes || '-',
                status: formatReportStatus(d)
            }));

            const summary = getStatusCounts(exportRows);
            const deviceSummaryData = [
                { label: 'Total Devices', value: summary.total },
                { label: 'Available', value: summary.available },
                { label: 'Allocated', value: summary.allocated },
                { label: 'Used', value: summary.used },
                { label: 'Reserved', value: summary.reserved }
            ];

            const modelBreakdown = Object.entries(getFilteredModelCounts(exportRows)).map(([model_name, count]) => ({
                model_name,
                count
            }));

            exportToPDF(deviceData, 'Device_Report', 'DEVICE REPORT', cols, {
                summary: deviceSummaryData,
                summaryTitle: 'Device Report Summary',
                sections: [{
                    title: 'MODEL BREAKDOWN',
                    columns: [{ header: 'Model', key: 'model_name' }, { header: 'Count', key: 'count' }],
                    rows: modelBreakdown.length > 0 ? modelBreakdown : [{ model_name: 'No models', count: 0 }]
                }]
            });
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Inward Reports</h2>
                    <p className="page-subtitle">Review incoming device and SIM stock with exportable reports.</p>
                </div>
                <div className="header-actions">
                    <button className="btn btn-outline" disabled={loading || pagination.total === 0} onClick={handleExportExcel}>
                        <Download size={16} /> Export Excel
                    </button>
                    <button className="btn btn-outline" disabled={loading || pagination.total === 0} onClick={handleExportPDF}>
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            <div className="tabs-container">
                <button 
                    className={`tab-btn ${activeTab === 'sim' ? 'active' : ''}`}
                    onClick={() => {
                        setFilters(emptyTableFilters());
                        setPage(1);
                        setActiveTab('sim');
                    }}
                >
                    SIM Report
                </button>
                <button 
                    className={`tab-btn ${activeTab === 'device' ? 'active' : ''}`}
                    onClick={() => {
                        setFilters(emptyTableFilters());
                        setPage(1);
                        setActiveTab('device');
                    }}
                >
                    Device Report
                </button>
            </div>

            {loading ? (
                <div className="card text-center" style={{ padding: '3rem' }}>Loading reports...</div>
            ) : (
                <>
            {loadError && <div className="alert alert-danger">{loadError}</div>}
                    {/* Summary Cards */}
                    <div className="summary-cards" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '1rem', marginBottom: '1.5rem' }}>
                        {activeTab === 'sim' && simSummary && (
                            <>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Total SIMs</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem' }}>{simSummary.total}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Available</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--success-color)' }}>{simSummary.available || 0}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Allocated</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--warning-color)' }}>{simSummary.allocated || 0}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Used</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--primary-color)' }}>{simSummary.used || 0}</h2>
                                </div>
                            </>
                        )}
                        {activeTab === 'device' && deviceSummary && (
                            <>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Total Devices</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem' }}>{deviceSummary.summary.total}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Available</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--success-color)' }}>{deviceSummary.summary.available || 0}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Allocated</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--warning-color)' }}>{deviceSummary.summary.allocated || 0}</h2>
                                </div>
                                <div className="card text-center">
                                    <h4 style={{ color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>Used</h4>
                                    <h2 style={{ margin: 0, fontSize: '2rem', color: 'var(--primary-color)' }}>{deviceSummary.summary.used || 0}</h2>
                                </div>
                            </>
                        )}
                    </div>

                    {activeTab === 'device' && deviceSummary?.model_counts && (
                        <div className="card" style={{ marginBottom: '1.5rem' }}>
                            <h3 style={{ marginTop: 0, marginBottom: '1rem' }}>Model Breakdown</h3>
                            <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
                                {Object.entries(deviceSummary.model_counts).map(([model, count]) => (
                                    <div key={model} style={{ padding: '0.5rem 1rem', backgroundColor: '#f1f5f9', borderRadius: '0.5rem' }}>
                                        <strong>{model}:</strong> {count}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '1rem', marginBottom: '1rem' }}>
                            <h3 style={{ margin: 0 }}>{activeTab === 'sim' ? 'SIM Report' : 'Device Report'}</h3>
                        </div>
                        <TableFilterBar
                            filters={filters}
                            onChange={updateFilters}
                            onReset={() => updateFilters(emptyTableFilters())}
                            items={activeTab === 'sim' ? simList : deviceList}
                            dateKeys={['purchase_date']}
                            deviceModelOptions={activeTab === 'device' ? deviceModels : undefined}
                            simTypeOptions={activeTab === 'sim' ? simTypes : undefined}
                            showSimType={activeTab === 'sim'}
                            showDeviceModel={activeTab === 'device'}
                            showStatus
                            statusLabel={activeTab === 'sim' ? 'SIM Status' : 'Device Status'}
                            statusPlaceholder="All Statuses"
                            statusOptions={activeTab === 'sim'
                                ? ['Available', 'Allocated', 'Used', 'Reserved', 'Active', 'Deactive', 'Expired', 'Safe Custody']
                                : ['Available', 'Allocated', 'Used', 'Reserved']}
                            searchPlaceholder={activeTab === 'sim' ? 'Search SIM number, type, or notes...' : 'Search IMEI, model, or notes...'}
                        />
                        <div className="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Purchase Date</th>
                                        {activeTab === 'sim' ? (
                                            <>
                                                <th>SIM No</th>
                                                <th>SIM Type</th>
                                            </>
                                        ) : (
                                            <>
                                                <th>Model</th>
                                                <th>IMEI No</th>
                                            </>
                                        )}
                                        <th>Notes</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {activeTab === 'sim' && simList.length === 0 && (
                                        <tr><td colSpan="5" className="text-center empty-state">No records found for the selected filters.</td></tr>
                                    )}
                                    {activeTab === 'sim' && simList.map(sim => (
                                        <tr key={sim.id}>
                                            <td>{formatDate(sim.purchase_date)}</td>
                                            <td className="truncate-cell" title={sim.sim_no}>{sim.sim_no}</td>
                                            <td>{sim.sim_type || '-'}</td>
                                            <td className="truncate-cell" title={sim.notes}>{sim.notes || '-'}</td>
                                            <td><span className={`badge ${String(sim.status).toLowerCase() === 'available' ? 'badge-success' : String(sim.status).toLowerCase() === 'allocated' ? 'badge-warning' : 'badge-info'}`}>{formatReportStatus(sim)}</span></td>
                                        </tr>
                                    ))}

                                    {activeTab === 'device' && deviceList.length === 0 && (
                                        <tr><td colSpan="5" className="text-center empty-state">No records found for the selected filters.</td></tr>
                                    )}
                                    {activeTab === 'device' && deviceList.map(dev => (
                                        <tr key={dev.id}>
                                            <td>{formatDate(dev.purchase_date)}</td>
                                            <td className="truncate-cell" title={dev.model_name}>{dev.model_name}</td>
                                            <td className="truncate-cell" title={dev.imei_no}>{dev.imei_no}</td>
                                            <td className="truncate-cell" title={dev.notes}>{dev.notes || '-'}</td>
                                            <td><span className={`badge ${String(dev.status).toLowerCase() === 'available' ? 'badge-success' : String(dev.status).toLowerCase() === 'allocated' ? 'badge-warning' : 'badge-info'}`}>{formatReportStatus(dev)}</span></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {activeTab === 'sim' ? (
                            <Pagination
                                currentPage={page}
                                totalItems={pagination.total}
                                pageSize={pageSize}
                                onPageChange={setPage}
                                onPageSizeChange={updatePageSize}
                                itemName="SIMs"
                            />
                        ) : (
                            <Pagination
                                currentPage={page}
                                totalItems={pagination.total}
                                pageSize={pageSize}
                                onPageChange={setPage}
                                onPageSizeChange={updatePageSize}
                                itemName="devices"
                            />
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default InwardReports;
