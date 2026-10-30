import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import api from '../../../services/api';
import { exportToExcel, exportToPDF } from '../../../utils/export';
import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';
import { formatDate } from '../../../utils/date';
import Pagination from '../../../components/Pagination/Pagination';
import TableFilterBar, { emptyTableFilters } from '../../../components/TableFilterBar/TableFilterBar';

const tabs = [
    { key: 'dealer-sim', label: 'Dealer SIM Report', owner: 'Dealer', type: 'SIM' },
    { key: 'dealer-device', label: 'Dealer Device Report', owner: 'Dealer', type: 'Device' },
    { key: 'technician-sim', label: 'Technician SIM Report', owner: 'Technician', type: 'SIM' },
    { key: 'technician-device', label: 'Technician Device Report', owner: 'Technician', type: 'Device' }
];

const DealerReports = () => {
    const [activeTab, setActiveTab] = useState('dealer-sim');
    const [owners, setOwners] = useState({ dealers: [], technicians: [] });
    const [deviceModels, setDeviceModels] = useState([]);
    const [simValidities, setSimValidities] = useState([]);
    const [simTypes, setSimTypes] = useState([]);
    const [selectedOwner, setSelectedOwner] = useState('');
    const [filters, setFilters] = useState(emptyTableFilters);
    const [reportData, setReportData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [reportError, setReportError] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [totalItems, setTotalItems] = useState(0);
    const currentTab = tabs.find((tab) => tab.key === activeTab) || tabs[0];
    const isDealerSim = activeTab === 'dealer-sim';
    const ownerKey = currentTab.owner === 'Dealer' ? 'dealers' : 'technicians';
    const nameKey = currentTab.owner === 'Dealer' ? 'dealer_name' : 'technician_name';

    useEffect(() => {
        Promise.all([api.get('/dealers/list.php?for_lookup=1'), api.get('/technicians/list.php?for_lookup=1')])
            .then(([dealers, technicians]) =>
                setOwners({
                    dealers: dealers.data.data?.dealers || [],
                    technicians: technicians.data.data?.technicians || []
                })
            )
            .catch((error) => console.error('Failed to load report owners', error));
    }, []);

    useEffect(() => {
        Promise.all([
            api.get('/device_types/list.php').catch(() => null),
            api.get('/sim_validities/list.php').catch(() => null),
            api.get('/sim_types/list.php').catch(() => null)
        ]).then(([deviceTypesResponse, validitiesResponse, simTypesResponse]) => {
            const deviceTypes = deviceTypesResponse?.data.data?.device_types || [];
            const validities = validitiesResponse?.data.data?.validities || [];
            const masterSimTypes = simTypesResponse?.data.data?.sim_types || [];
            setDeviceModels(deviceTypes
                .filter((type) => String(type.status || 'Active').trim().toLowerCase() === 'active')
                .map((type) => type.device_type));
            setSimValidities(validities
                .filter((validity) => String(validity.status || 'active').trim().toLowerCase() === 'active')
                .map((validity) => `${validity.months} Months`));
            setSimTypes(masterSimTypes.map((simType) => simType.sim_type));
        });
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
        const params = {
            [parameter]: selectedOwner,
            search: filters.search || '',
            date_from: filters.date_from || '',
            date_to: filters.date_to || '',
            sim_type: isSim ? filters.simType || '' : '',
            sim_validity: isSim ? (filters.simValidity || '').replace(/\D/g, '') : '',
            device_model: isSim ? '' : filters.deviceModel || '',
            page,
            page_size: pageSize
        };
        setReportError('');
        setLoading(true);
        api.get(endpoint, { params })
            .then((response) => {
                setReportData(response.data.data?.report || []);
                setTotalItems(response.data.data?.pagination?.total || 0);
            })
            .catch((error) => {
                console.error('Failed to load allocation report', error);
                setReportError(error.response?.data?.message || 'Failed to load allocation report.');
                setReportData([]);
                setTotalItems(0);
            })
            .finally(() => setLoading(false));
    }, [activeTab, selectedOwner, currentTab, filters, page, pageSize]);

    const getReportParams = () => ({
        [currentTab.owner === 'Technician' ? 'technician_id' : 'dealer_id']: selectedOwner,
        search: filters.search || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        sim_type: currentTab.type === 'SIM' ? filters.simType || '' : '',
        sim_validity: currentTab.type === 'SIM' ? (filters.simValidity || '').replace(/\D/g, '') : '',
        device_model: currentTab.type === 'SIM' ? '' : filters.deviceModel || ''
    });

    const fetchAllReportRows = async () => {
        const isTechnician = currentTab.owner === 'Technician';
        const endpoint = isTechnician
            ? currentTab.type === 'SIM' ? '/reports/sim_technician.php' : '/reports/device_technician.php'
            : currentTab.type === 'SIM' ? '/reports/sim_dealer.php' : '/reports/device_dealer.php';
        const response = await api.get(endpoint, { params: getReportParams() });
        return response.data.data?.report || [];
    };

    const exportRows = (rows) =>
        rows.map((row) =>
            currentTab.type === 'SIM'
                ? {
                      [`${currentTab.owner} Name`]: row[nameKey],
                      'SIM No': row.sim_no,
                      'SIM Type': row.sim_type || '-',
                      'SIM Validity': row.sim_validity_months ? `${row.sim_validity_months} Months` : '-',
                      'SIM Status': row.sim_status || '-',
                      'SIM Purchase Date': row.sim_purchase_date,
                      ...(isDealerSim ? {
                          'SIM Given Date': row.sim_given_date,
                          'SIM Activation Date': row.sim_activation_date,
                          'SIM Expiry Date': row.sim_expiry_date
                      } : {}),
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

    const handleExportExcel = async () => {
        try {
            exportToExcel(exportRows(await fetchAllReportRows()), `${currentTab.owner}_${currentTab.type}_Report`);
        } catch (error) {
            console.error('Failed to export allocation report', error);
            setReportError(error.response?.data?.message || 'Failed to export allocation report.');
        }
    };
    const handleExportPDF = async () => {
        let rows;
        try {
            rows = await fetchAllReportRows();
        } catch (error) {
            console.error('Failed to export allocation report', error);
            setReportError(error.response?.data?.message || 'Failed to export allocation report.');
            return;
        }
        const columns =
            currentTab.type === 'SIM'
                ? [
                      { header: `${currentTab.owner} Name`, key: nameKey },
                      { header: 'SIM No', key: 'sim_no' },
                      { header: 'SIM Type', key: 'sim_type' },
                      { header: 'SIM Validity', key: 'sim_validity_months' },
                      { header: 'SIM Status', key: 'sim_status' },
                      { header: 'Purchase Date', key: 'sim_purchase_date' },
                      ...(isDealerSim ? [
                          { header: 'Given Date', key: 'sim_given_date' },
                          { header: 'Activation Date', key: 'sim_activation_date' },
                          { header: 'Expiry Date', key: 'sim_expiry_date' }
                      ] : []),
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
        exportToPDF(rows, `${currentTab.owner}_${currentTab.type}_Report`, currentTab.label, columns);
    };

    const tableColSpan = currentTab.type === 'SIM' ? (isDealerSim ? 11 : 8) : 6;

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Outward Reports</h2>
                    <p className="page-subtitle">Review dealer and technician stock allocations and export reports.</p>
                </div>
                <div className="header-actions">
                    <button
                        className="btn btn-outline"
                        disabled={loading || totalItems === 0}
                        onClick={handleExportExcel}
                    >
                        <Download size={16} /> Export Excel
                    </button>
                    <button
                        className="btn btn-outline"
                        disabled={loading || totalItems === 0}
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
                            setPage(1);
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
                            onChange={(value) => {
                                setSelectedOwner(value);
                                setPage(1);
                            }}
                            placeholder={`Search ${currentTab.owner}...`}
                        />
                    </div>
                </div>
                <div style={{ marginTop: '10px' }}>
                    <TableFilterBar
                        filters={filters}
                        onChange={(nextFilters) => {
                            setFilters(nextFilters);
                            setPage(1);
                        }}
                        onReset={() => {
                            setFilters(emptyTableFilters());
                            setPage(1);
                        }}
                        items={reportData}
                        dateKeys={['allocation_date']}
                        deviceModelOptions={currentTab.type !== 'SIM' ? deviceModels : undefined}
                        simTypeOptions={currentTab.type === 'SIM' ? simTypes : undefined}
                        simValidityOptions={currentTab.type === 'SIM' ? simValidities : undefined}
                        showSimType={currentTab.type === 'SIM'}
                        showSimValidity={currentTab.type === 'SIM'}
                        showDeviceModel={currentTab.type !== 'SIM'}
                        searchPlaceholder="Search report results..."
                        gridCols={5}
                    />
                </div>
            </div>

            <div className="card">
                {reportError && <div className="alert alert-danger">{reportError}</div>}
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
                                        <th>SIM Status</th>
                                        <th>SIM Purchase Date</th>
                                        {isDealerSim && <>
                                            <th>SIM Given Date</th>
                                            <th>SIM Activation Date</th>
                                            <th>SIM Expiry Date</th>
                                        </>}
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
                                    <td colSpan={tableColSpan} className="text-center">
                                        Loading report...
                                    </td>
                                </tr>
                            ) : reportData.length === 0 ? (
                                <tr>
                                    <td colSpan={tableColSpan} className="text-center empty-state">
                                        No records found for the selected filters.
                                    </td>
                                </tr>
                            ) : (
                                reportData.map((row, index) => (
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
                                                <td>{row.sim_status || '-'}</td>
                                                <td>{formatDate(row.sim_purchase_date)}</td>
                                                {isDealerSim && <>
                                                    <td>{formatDate(row.sim_given_date)}</td>
                                                    <td>{formatDate(row.sim_activation_date)}</td>
                                                    <td>{formatDate(row.sim_expiry_date)}</td>
                                                </>}
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
                    onPageSizeChange={(size) => {
                        setPageSize(size);
                        setPage(1);
                    }}
                    itemName="allocated records"
                />
            </div>
        </div>
    );
};

export default DealerReports;
