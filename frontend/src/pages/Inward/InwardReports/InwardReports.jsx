import { useState, useEffect } from 'react';
import { Download } from 'lucide-react';
import api from '../../../services/api';
import { exportToExcel, exportToPDF } from '../../../utils/export';
import './InwardReports.css';
import { formatDate } from '../../../utils/date';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';

const InwardReports = () => {
    const [activeTab, setActiveTab] = useState('sim'); // 'sim' or 'device'
    
    const [simSummary, setSimSummary] = useState(null);
    const [deviceSummary, setDeviceSummary] = useState(null);
    const [simList, setSimList] = useState([]);
    const [deviceList, setDeviceList] = useState([]);
    const [filters, setFilters] = useState(emptyTableFilters);
    
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        setFilters(emptyTableFilters());
    }, [activeTab]);

    useEffect(() => {
        const fetchReports = async () => {
            setLoading(true);
            try {
                if (activeTab === 'sim') {
                    const sumRes = await api.get('/reports/sim_summary.php').catch(()=>null);
                    const listRes = await api.get('/reports/sim_available.php').catch(()=>null);
                    if (sumRes?.data?.success) setSimSummary(sumRes.data.data.summary);
                    if (listRes?.data?.success) setSimList(listRes.data.data.sims || []);
                } else {
                    const sumRes = await api.get('/reports/device_summary.php').catch(()=>null);
                    const listRes = await api.get('/reports/device_available.php').catch(()=>null);
                    if (sumRes?.data?.success) setDeviceSummary(sumRes.data.data);
                    if (listRes?.data?.success) setDeviceList(listRes.data.data.devices || []);
                }
            } catch (error) {
                console.error("Failed to fetch reports", error);
            } finally {
                setLoading(false);
            }
        };
        fetchReports();
    }, [activeTab]);

    const filteredSims = filterTableRows(simList, filters, { dateKeys: ['purchase_date'], simKey: 'sim_type', searchKeys: ['sim_no', 'sim_type', 'notes'] });
    const filteredDevices = filterTableRows(deviceList, filters, { dateKeys: ['purchase_date'], deviceKey: 'model_name', searchKeys: ['imei_no', 'model_name', 'notes'] });

    const simPagination = usePagination(filteredSims, 10, [filters, activeTab]);
    const devicePagination = usePagination(filteredDevices, 10, [filters, activeTab]);

    const handleExportExcel = () => {
        if (activeTab === 'sim') {
            const data = simList.map(s => ({ "Purchase Date": s.purchase_date, "SIM No": s.sim_no, "SIM Type": s.sim_type || '-', "SIM Validity": s.sim_validity_months ? `${s.sim_validity_months} Months` : '-', "Notes": s.notes || '-', "Status": "Available" }));
            exportToExcel(data, 'Available_SIM_Report');
        } else {
            const data = deviceList.map(d => ({ "Purchase Date": d.purchase_date, "Model": d.model_name, "IMEI No": d.imei_no, "Notes": d.notes || '-', "Status": "Available" }));
            const summary = deviceSummary?.summary || {};
            const modelBreakdown = Object.entries(deviceSummary?.model_counts || {}).map(([model, count]) => ({ Model: model, Count: count }));
            exportToExcel(data, 'Available_Device_Report', 'Available Devices', {
                summary: [
                    { label: 'Total Devices', value: summary.total },
                    { label: 'Available', value: summary.available || 0 },
                    { label: 'Allocated', value: summary.allocated || 0 },
                    { label: 'Used', value: summary.used || 0 }
                ],
                sections: [{ name: 'Model Breakdown', rows: modelBreakdown }]
                , combinedSheet: { title: 'DEVICE REPORT' }
            });
        }
    };

    const handleExportPDF = () => {
        if (activeTab === 'sim') {
            const cols = [{header: "Purchase Date", key: "purchase_date"}, {header: "SIM No", key: "sim_no"}, {header: "SIM Type", key: "sim_type"}, {header: "SIM Validity", key: "sim_validity_months"}, {header: "Notes", key: "notes"}, {header: "Status", key: "status"}];
            const data = simList.map(s => ({ ...s, status: 'Available' }));
            exportToPDF(data, 'Available_SIM_Report', 'Available SIM Inventory Report', cols);
        } else {
            const cols = [{header: "Purchase Date", key: "purchase_date"}, {header: "Model", key: "model_name"}, {header: "IMEI No", key: "imei_no"}, {header: "Notes", key: "notes"}, {header: "Status", key: "status"}];
            const data = deviceList.map(d => ({ ...d, status: 'Available' }));
            const summary = deviceSummary?.summary || {};
            const modelBreakdown = Object.entries(deviceSummary?.model_counts || {}).map(([model_name, count]) => ({ model_name, count }));
            exportToPDF(data, 'Available_Device_Report', 'DEVICE REPORT', cols, {
                summary: [
                    { label: 'Total Devices', value: summary.total },
                    { label: 'Available', value: summary.available || 0 },
                    { label: 'Allocated', value: summary.allocated || 0 },
                    { label: 'Used', value: summary.used || 0 }
                ],
                sections: [{
                    title: 'MODEL BREAKDOWN',
                    columns: [{ header: 'Model', key: 'model_name' }, { header: 'Count', key: 'count' }],
                    rows: modelBreakdown
                }]
            });
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Inward Reports</h2>
                <div className="header-actions">
                    <button className="btn btn-outline" disabled={loading || (activeTab === 'sim' ? simList.length === 0 : deviceList.length === 0)} onClick={handleExportExcel}>
                        <Download size={16} /> Export Excel
                    </button>
                    <button className="btn btn-outline" disabled={loading || (activeTab === 'sim' ? simList.length === 0 : deviceList.length === 0)} onClick={handleExportPDF}>
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            <div className="tabs-container">
                <button 
                    className={`tab-btn ${activeTab === 'sim' ? 'active' : ''}`}
                    onClick={() => setActiveTab('sim')}
                >
                    SIM Report
                </button>
                <button 
                    className={`tab-btn ${activeTab === 'device' ? 'active' : ''}`}
                    onClick={() => setActiveTab('device')}
                >
                    Device Report
                </button>
            </div>

            {loading ? (
                <div className="card text-center" style={{ padding: '3rem' }}>Loading reports...</div>
            ) : (
                <>
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
                            <h3 style={{ margin: 0 }}>Available {activeTab === 'sim' ? 'SIMs' : 'Devices'}</h3>
                        </div>
                        <TableFilterBar filters={filters} onChange={setFilters} onReset={() => setFilters(emptyTableFilters())} items={activeTab === 'sim' ? simList : deviceList} dateKeys={['purchase_date']} {...(activeTab === 'sim' ? { simKey: 'sim_type' } : { deviceKey: 'model_name' })} searchPlaceholder={activeTab === 'sim' ? 'Search SIM number or type...' : 'Search IMEI or model...'} />
                        <div className="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Purchase Date</th>
                                        {activeTab === 'sim' ? (
                                            <>
                                                <th>SIM No</th>
                                                <th>SIM Type</th>
                                                <th>SIM Validity</th>
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
                                    {activeTab === 'sim' && simPagination.paginatedItems.length === 0 && (
                                        <tr><td colSpan="6" className="text-center empty-state">No records found for the selected filters.</td></tr>
                                    )}
                                    {activeTab === 'sim' && simPagination.paginatedItems.map(sim => (
                                        <tr key={sim.id}>
                                            <td>{formatDate(sim.purchase_date)}</td>
                                            <td className="truncate-cell" title={sim.sim_no}>{sim.sim_no}</td>
                                            <td>{sim.sim_type || '-'}</td>
                                            <td>{sim.sim_validity_months ? `${sim.sim_validity_months} Months` : '-'}</td>
                                            <td className="truncate-cell" title={sim.notes}>{sim.notes || '-'}</td>
                                            <td><span className="badge badge-success">Available</span></td>
                                        </tr>
                                    ))}

                                    {activeTab === 'device' && devicePagination.paginatedItems.length === 0 && (
                                        <tr><td colSpan="5" className="text-center empty-state">No available Devices found</td></tr>
                                    )}
                                    {activeTab === 'device' && devicePagination.paginatedItems.map(dev => (
                                        <tr key={dev.id}>
                                            <td>{formatDate(dev.purchase_date)}</td>
                                            <td className="truncate-cell" title={dev.model_name}>{dev.model_name}</td>
                                            <td className="truncate-cell" title={dev.imei_no}>{dev.imei_no}</td>
                                            <td className="truncate-cell" title={dev.notes}>{dev.notes || '-'}</td>
                                            <td><span className="badge badge-success">Available</span></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {activeTab === 'sim' ? (
                            <Pagination
                                currentPage={simPagination.page}
                                totalItems={simPagination.totalItems}
                                pageSize={simPagination.pageSize}
                                onPageChange={simPagination.setPage}
                                onPageSizeChange={simPagination.setPageSize}
                                itemName="available SIMs"
                            />
                        ) : (
                            <Pagination
                                currentPage={devicePagination.page}
                                totalItems={devicePagination.totalItems}
                                pageSize={devicePagination.pageSize}
                                onPageChange={devicePagination.setPage}
                                onPageSizeChange={devicePagination.setPageSize}
                                itemName="available devices"
                            />
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default InwardReports;
