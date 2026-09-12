import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import api from '../../../services/api';
import { exportToExcel, exportToPDF } from '../../../utils/export';
import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';
import { formatDate } from '../../../utils/date';
import Pagination from '../../../components/Pagination/Pagination';
import usePagination from '../../../hooks/usePagination';
import TableFilterBar, { emptyTableFilters, filterTableRows } from '../../../components/TableFilterBar/TableFilterBar';

const tabs = [
    { key: 'dealer-sim', label: 'Dealer SIM Report', owner: 'Dealer', type: 'SIM' },
    { key: 'dealer-device', label: 'Dealer Device Report', owner: 'Dealer', type: 'Device' },
    { key: 'technician-sim', label: 'Technician SIM Report', owner: 'Technician', type: 'SIM' },
    { key: 'technician-device', label: 'Technician Device Report', owner: 'Technician', type: 'Device' }
];

const DealerReports = () => {
    const [activeTab, setActiveTab] = useState('dealer-sim');
    const [owners, setOwners] = useState({ dealers: [], technicians: [] });
    const [selectedOwner, setSelectedOwner] = useState('');
    const [filters, setFilters] = useState(emptyTableFilters);
    const [reportData, setReportData] = useState([]);
    const [loading, setLoading] = useState(false);
    const currentTab = tabs.find((tab) => tab.key === activeTab) || tabs[0];
    const ownerKey = currentTab.owner === 'Dealer' ? 'dealers' : 'technicians';
    const nameKey = currentTab.owner === 'Dealer' ? 'dealer_name' : 'technician_name';

    useEffect(() => {
        Promise.all([api.get('/dealers/list.php'), api.get('/technicians/list.php')])
            .then(([dealers, technicians]) =>
                setOwners({
                    dealers: dealers.data.data?.dealers || [],
                    technicians: technicians.data.data?.technicians || []
                })
            )
            .catch((error) => console.error('Failed to load report owners', error));
    }, []);

    useEffect(() => {
        const isSim = currentTab.type === 'SIM';
        const isTechnician = currentTab.owner === 'Technician';
        const endpoint = isTechnician
            ? isSim
                ? '/reports/sim_technician.php'
                : '/reports/device_technician.php'
            : isSim
            ? '/reports/sim_dealer.php'
            : '/reports/device_dealer.php';
        const parameter = isTechnician ? 'technician_id' : 'dealer_id';
        const url = selectedOwner ? `${endpoint}?${parameter}=${selectedOwner}` : endpoint;
        setLoading(true);
        api.get(url)
            .then((response) => setReportData(response.data.data?.report || []))
            .catch(() => setReportData([]))
            .finally(() => setLoading(false));
    }, [activeTab, selectedOwner, currentTab]);

    const filteredReportData = filterTableRows(reportData, filters, currentTab.type === 'SIM'
        ? { dateKeys: ['allocation_date'], simKey: 'sim_type', searchKeys: [nameKey, 'sim_no', 'sim_type', 'notes'] }
        : { dateKeys: ['allocation_date'], deviceKey: 'model_name', searchKeys: [nameKey, 'imei_no', 'model_name', 'notes'] });

    const {
        page,
        setPage,
        pageSize,
        setPageSize,
        totalItems,
        paginatedItems
    } = usePagination(filteredReportData, 10, [filters, selectedOwner, activeTab]);

    const exportRows = () =>
        filteredReportData.map((row) =>
            currentTab.type === 'SIM'
                ? {
                      [`${currentTab.owner} Name`]: row[nameKey],
                      'SIM No': row.sim_no,
                      'SIM Type': row.sim_type || '-',
                      'SIM Validity': row.sim_validity_months ? `${row.sim_validity_months} Months` : '-',
                      'SIM Purchase Date': row.sim_purchase_date,
                      'Allocation Date': row.allocation_date,
                      Notes: row.notes || '-'
                  }
                : {
                      [`${currentTab.owner} Name`]: row[nameKey],
                      Model: row.model_name,
                      'IMEI No': row.imei_no,
                      'Device Purchase Date': row.device_purchase_date,
                      'Allocation Date': row.allocation_date,
                      Notes: row.notes || '-'
                  }
        );

    const handleExportExcel = () => exportToExcel(exportRows(), `${currentTab.owner}_${currentTab.type}_Report`);
    const handleExportPDF = () => {
        const columns =
            currentTab.type === 'SIM'
                ? [
                      { header: `${currentTab.owner} Name`, key: nameKey },
                      { header: 'SIM No', key: 'sim_no' },
                      { header: 'SIM Type', key: 'sim_type' },
                      { header: 'SIM Validity', key: 'sim_validity_months' },
                      { header: 'Purchase Date', key: 'sim_purchase_date' },
                      { header: 'Allocation Date', key: 'allocation_date' },
                      { header: 'Notes', key: 'notes' }
                  ]
                : [
                      { header: `${currentTab.owner} Name`, key: nameKey },
                      { header: 'Model', key: 'model_name' },
                      { header: 'IMEI No', key: 'imei_no' },
                      { header: 'Purchase Date', key: 'device_purchase_date' },
                      { header: 'Allocation Date', key: 'allocation_date' },
                      { header: 'Notes', key: 'notes' }
                  ];
        exportToPDF(filteredReportData, `${currentTab.owner}_${currentTab.type}_Report`, currentTab.label, columns);
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Outward Reports</h2>
                <div className="header-actions">
                    <button
                        className="btn btn-outline"
                        disabled={loading || !filteredReportData.length}
                        onClick={handleExportExcel}
                    >
                        <Download size={16} /> Export Excel
                    </button>
                    <button
                        className="btn btn-outline"
                        disabled={loading || !filteredReportData.length}
                        onClick={handleExportPDF}
                    >
                        <Download size={16} /> Export PDF
                    </button>
                </div>
            </div>

            <div
                className="tabs-container"
                style={{
                    display: 'flex',
                    gap: '1rem',
                    marginBottom: '1.5rem',
                    borderBottom: '1px solid var(--border-color)',
                    flexWrap: 'wrap'
                }}
            >
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        className={`tab-btn ${activeTab === tab.key ? 'active' : ''}`}
                        onClick={() => {
                            setActiveTab(tab.key);
                            setSelectedOwner('');
                            setFilters(emptyTableFilters());
                        }}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <div className="card" style={{ marginBottom: '1.5rem' }}>
                <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'flex-end' }}>
                    <div className="form-group" style={{ maxWidth: '350px', margin: 0, flex: 1, minWidth: '240px' }}>
                        <label className="form-label">Filter by {currentTab.owner}</label>
                        <SearchableDropdown
                            options={[
                                { value: '', label: `All ${currentTab.owner}s` },
                                ...owners[ownerKey].map((owner) => ({ value: owner.id, label: owner[nameKey] }))
                            ]}
                            value={selectedOwner}
                            onChange={setSelectedOwner}
                            placeholder={`Search ${currentTab.owner}...`}
                        />
                    </div>
                </div>
                <TableFilterBar
                    filters={filters}
                    onChange={setFilters}
                    onReset={() => setFilters(emptyTableFilters())}
                    items={reportData}
                    dateKeys={['allocation_date']}
                    showSimType={currentTab.type === 'SIM'}
                    showSimValidity={currentTab.type === 'SIM'}
                    showDeviceModel={currentTab.type !== 'SIM'}
                    searchPlaceholder="Search report results..."
                />
            </div>

            <div className="card">
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>{currentTab.owner} Name</th>
                                {currentTab.type === 'SIM' ? (
                                    <>
                                        <th>SIM No</th>
                                        <th>SIM Type</th>
                                        <th>SIM Validity</th>
                                        <th>SIM Purchase Date</th>
                                    </>
                                ) : (
                                    <>
                                        <th>Model</th>
                                        <th>IMEI No</th>
                                        <th>Device Purchase Date</th>
                                    </>
                                )}
                                <th>Allocation Date</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="8" className="text-center">
                                        Loading report...
                                    </td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="8" className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((row, index) => (
                                    <tr key={index}>
                                        <td className="truncate-cell" title={row[nameKey]}>{row[nameKey]}</td>
                                        {currentTab.type === 'SIM' ? (
                                            <>
                                                <td className="truncate-cell" title={row.sim_no}>{row.sim_no}</td>
                                                <td>{row.sim_type || '-'}</td>
                                                <td>
                                                    {row.sim_validity_months
                                                        ? `${row.sim_validity_months} Months`
                                                        : '-'}
                                                </td>
                                                <td>{formatDate(row.sim_purchase_date)}</td>
                                            </>
                                        ) : (
                                            <>
                                                <td className="truncate-cell" title={row.model_name}>{row.model_name}</td>
                                                <td className="truncate-cell" title={row.imei_no}>{row.imei_no}</td>
                                                <td>{formatDate(row.device_purchase_date)}</td>
                                            </>
                                        )}
                                        <td>{formatDate(row.allocation_date)}</td>
                                        <td className="truncate-cell" title={row.notes}>{row.notes || '-'}</td>
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
                    itemName="allocated records"
                />
            </div>
        </div>
    );
};

export default DealerReports;
