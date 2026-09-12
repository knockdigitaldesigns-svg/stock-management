import { useState, useCallback } from 'react';
import { ArrowLeftRight } from 'lucide-react';
import api from '../../services/api';
import Pagination from '../../components/Pagination/Pagination';
import usePagination from '../../hooks/usePagination';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';
import { formatDate } from '../../utils/date';
import { useEffect } from 'react';

const emptyFilters = () => ({
    search: '',
    fromOwner: '',
    fromOwnerType: '',
    toOwner: '',
    dateFrom: '',
    dateTo: ''
});

const StockTransferPage = () => {
    const { hasPermission } = useAuth();

    // --------------------------------------------------
    // TABLE STATE
    // --------------------------------------------------
    const [transfers, setTransfers]       = useState([]);
    const [loading, setLoading]           = useState(true);
    const [filters, setFilters]           = useState(emptyFilters());

    // --------------------------------------------------
    // MODAL STATE
    // --------------------------------------------------
    const [showModal, setShowModal]       = useState(false);
    const [saving, setSaving]             = useState(false);

    const [searchQuery, setSearchQuery]   = useState('');
    const [searching, setSearching]       = useState(false);
    const [foundAsset, setFoundAsset]     = useState(null);

    const [assetChecked, setAssetChecked] = useState(false);
    const [toOwnerType, setToOwnerType]   = useState('');
    const [toOwnerList, setToOwnerList]   = useState([]);
    const [toOwnerId, setToOwnerId]       = useState('');
    const [transferDate, setTransferDate] = useState(() => new Date().toISOString().slice(0, 10));
    const [loadingOwners, setLoadingOwners] = useState(false);

    // --------------------------------------------------
    // FETCH TRANSFERS
    // --------------------------------------------------
    const fetchTransfers = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (filters.search)        params.append('search',          filters.search);
            if (filters.fromOwner)    params.append('from_owner',      filters.fromOwner);
            if (filters.fromOwnerType) params.append('from_owner_type', filters.fromOwnerType);
            if (filters.toOwner)       params.append('to_owner',        filters.toOwner);
            if (filters.dateFrom)      params.append('date_from',       filters.dateFrom);
            if (filters.dateTo)        params.append('date_to',         filters.dateTo);

            const res = await api.get(`/stock_transfer/list.php?${params.toString()}`, { skipGlobalError: true });
            if (res.data?.success) {
                setTransfers(res.data.data?.transfers || []);
            }
        } catch (err) {
            console.error('Failed to fetch transfers', err);
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => { fetchTransfers(); }, [fetchTransfers]);

    // --------------------------------------------------
    // PAGINATION
    // --------------------------------------------------
    const { page, setPage, pageSize, setPageSize, totalItems, paginatedItems } =
        usePagination(transfers, 10, [filters]);

    // --------------------------------------------------
    // SEARCH ASSET
    // --------------------------------------------------
    const handleSearchAsset = async () => {
        if (!searchQuery.trim()) {
            showGlobalError('Please enter an IMEI or SIM number to search.');
            return;
        }
        setSearching(true);
        setFoundAsset(null);
        setAssetChecked(false);
        setToOwnerType('');
        setToOwnerId('');
        setToOwnerList([]);
        try {
            const res = await api.get(
                `/stock_transfer/search_asset.php?query=${encodeURIComponent(searchQuery.trim())}`,
                { skipGlobalError: true }
            );
            if (res.data?.success && res.data.data?.asset) {
                setFoundAsset(res.data.data.asset);
            } else {
                showGlobalError(res.data?.message || 'Device/SIM not found.');
            }
        } catch (err) {
            showGlobalError(
                err.response?.data?.message || err.message || 'Device/SIM not found.'
            );
        } finally {
            setSearching(false);
        }
    };

    // --------------------------------------------------
    // LOAD OWNER LIST
    // --------------------------------------------------
    const loadOwnerList = async (type) => {
        if (!type) { setToOwnerList([]); setToOwnerId(''); return; }
        setLoadingOwners(true);
        setToOwnerId('');
        try {
            if (type === 'technician') {
                const res = await api.get('/technicians/list.php', { skipGlobalError: true });
                const list = res.data?.data?.technicians || [];
                setToOwnerList(list.map(t => ({ id: t.id, name: t.technician_name })));
            } else {
                const res = await api.get('/dealers/list.php?installation_status=Onsite,Offsite', { skipGlobalError: true });
                const list = res.data?.data?.dealers || [];
                setToOwnerList(
                    list
                        .filter(d => d.installation_status !== 'Not Willing')
                        .map(d => ({ id: d.id, name: d.dealer_name }))
                );
            }
        } catch (err) {
            console.error('Failed to load owners', err);
        } finally {
            setLoadingOwners(false);
        }
    };

    const handleToOwnerTypeChange = (type) => {
        setToOwnerType(type);
        loadOwnerList(type);
    };

    // --------------------------------------------------
    // SAVE TRANSFER
    // --------------------------------------------------
    const handleSaveTransfer = async () => {
        if (!foundAsset) {
            showGlobalError('Please search and select an asset first.');
            return;
        }
        if (!assetChecked) {
            showGlobalError('Please select the asset checkbox to confirm the transfer.');
            return;
        }
        if (!toOwnerType) {
            showGlobalError('Please select the Transfer To type (Technician or Dealer).');
            return;
        }
        if (!toOwnerId) {
            showGlobalError('Please select the new owner.');
            return;
        }
        if (!transferDate) {
            showGlobalError('Please select a transfer date.');
            return;
        }
        if (foundAsset.owner_type === toOwnerType && String(foundAsset.owner_id) === String(toOwnerId)) {
            showGlobalError('New owner must be different from the current owner.');
            return;
        }

        setSaving(true);
        try {
            const payload = {
                allocation_id:   foundAsset.allocation_id,
                asset_type:      foundAsset.asset_type,
                device_id:       foundAsset.device_id,
                sim_id:          foundAsset.sim_id,
                from_owner_type: foundAsset.owner_type,
                from_owner_id:   foundAsset.owner_id,
                to_owner_type:   toOwnerType,
                to_owner_id:     parseInt(toOwnerId),
                transfer_date:   transferDate,
                asset_checked:   assetChecked
            };

            const res = await api.post('/stock_transfer/create.php', payload, { skipGlobalError: true });
            if (res.data?.success) {
                closeModal();
                fetchTransfers();
            } else {
                showGlobalError(res.data?.message || 'Failed to transfer asset.');
            }
        } catch (err) {
            showGlobalError(
                err.response?.data?.message || err.message || 'Failed to transfer asset.'
            );
        } finally {
            setSaving(false);
        }
    };

    // --------------------------------------------------
    // MODAL HELPERS
    // --------------------------------------------------
    const openModal = () => {
        setShowModal(true);
        setSearchQuery('');
        setFoundAsset(null);
        setAssetChecked(false);
        setToOwnerType('');
        setToOwnerId('');
        setToOwnerList([]);
        setTransferDate(new Date().toISOString().slice(0, 10));
    };

    const closeModal = () => { setShowModal(false); };

    const canDoTransfer = hasPermission('stock_transfer.add');

    // --------------------------------------------------
    // RENDER
    // --------------------------------------------------
    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Stock Transfer</h2>
                <div className="header-actions">
                    {canDoTransfer && (
                        <button className="btn btn-primary" onClick={openModal}>
                            <ArrowLeftRight size={16} /> Stock Transfer
                        </button>
                    )}
                </div>
            </div>

            {/* ---- FILTERS ---- */}
            <div className="card" style={{ marginBottom: '16px', padding: '16px' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'flex-end' }}>
                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Original Owner
                        </label>
                        <input
                            className="form-control"
                            placeholder="Original owner..."
                            value={filters.fromOwner}
                            onChange={e => setFilters(f => ({ ...f, fromOwner: e.target.value }))}
                            style={{ minWidth: '160px' }}
                        />
                    </div>

                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Search (Owner / IMEI / SIM)
                        </label>
                        <input
                            className="form-control"
                            placeholder="Search..."
                            value={filters.search}
                            onChange={e => setFilters(f => ({ ...f, search: e.target.value }))}
                            style={{ minWidth: '200px' }}
                        />
                    </div>

                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Original Owner Type
                        </label>
                        <select
                            className="form-control"
                            value={filters.fromOwnerType}
                            onChange={e => setFilters(f => ({ ...f, fromOwnerType: e.target.value }))}
                        >
                            <option value="">All</option>
                            <option value="technician">Technician</option>
                            <option value="dealer">Dealer</option>
                        </select>
                    </div>

                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Transfer To
                        </label>
                        <input
                            className="form-control"
                            placeholder="Transferred to..."
                            value={filters.toOwner}
                            onChange={e => setFilters(f => ({ ...f, toOwner: e.target.value }))}
                            style={{ minWidth: '160px' }}
                        />
                    </div>

                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Date From
                        </label>
                        <input
                            type="date"
                            className="form-control"
                            value={filters.dateFrom}
                            onChange={e => setFilters(f => ({ ...f, dateFrom: e.target.value }))}
                        />
                    </div>

                    <div>
                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: '#64748b' }}>
                            Date To
                        </label>
                        <input
                            type="date"
                            className="form-control"
                            value={filters.dateTo}
                            onChange={e => setFilters(f => ({ ...f, dateTo: e.target.value }))}
                        />
                    </div>

                    <button
                        className="btn btn-secondary"
                        onClick={() => setFilters(emptyFilters())}
                    >
                        Reset Filters
                    </button>
                </div>
            </div>

            {/* ---- TABLE ---- */}
            <div className="card">
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Original Allocated Person</th>
                                <th>Owner Type</th>
                                <th>Device / IMEI No</th>
                                <th>SIM No</th>
                                <th>Transfer To</th>
                                <th>Transfer Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="9" className="text-center">Loading transfer records...</td>
                                </tr>
                            ) : paginatedItems.length === 0 ? (
                                <tr>
                                    <td colSpan="9" className="text-center empty-state">
                                        No stock transfer records found.
                                    </td>
                                </tr>
                            ) : (
                                paginatedItems.map((row, idx) => (
                                    <tr key={row.id}>
                                        <td>{(page - 1) * pageSize + idx + 1}</td>
                                        <td>{row.from_owner_name || '-'}</td>
                                        <td style={{ textTransform: 'capitalize' }}>{row.from_owner_type || '-'}</td>
                                        <td>{row.imei_no && row.imei_no !== '-' ? row.imei_no : '-'}</td>
                                        <td>{row.sim_no && row.sim_no !== '-' ? row.sim_no : '-'}</td>
                                        <td>{row.to_owner_name || '-'}</td>
                                        <td>{row.transfer_date ? formatDate(row.transfer_date) : '-'}</td>
                                        <td>
                                            <span style={{
                                                display: 'inline-block',
                                                padding: '2px 10px',
                                                borderRadius: '12px',
                                                fontSize: '12px',
                                                fontWeight: 600,
                                                background: '#e0f2fe',
                                                color: '#0369a1'
                                            }}>
                                                {row.new_status || 'Allocated'}
                                            </span>
                                        </td>
                                        <td>-</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    page={page}
                    setPage={setPage}
                    pageSize={pageSize}
                    setPageSize={setPageSize}
                    totalItems={totalItems}
                />
            </div>

            {/* ================================================================
                STOCK TRANSFER MODAL
            ================================================================ */}
            {showModal && (
                <div style={{
                    position: 'fixed',
                    inset: 0,
                    backgroundColor: 'rgba(0,0,0,0.5)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 9999,
                    overflowY: 'auto',
                    padding: '24px'
                }}>
                    <div style={{
                        backgroundColor: '#fff',
                        borderRadius: '12px',
                        padding: '32px 28px',
                        width: '100%',
                        maxWidth: '560px',
                        boxShadow: '0 8px 32px rgba(0,0,0,0.18)'
                    }}>
                        {/* Header */}
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
                            <h3 style={{ margin: 0, fontSize: '18px', fontWeight: 600, color: '#1e293b' }}>
                                Stock Transfer
                            </h3>
                            <button
                                type="button"
                                style={{ background: 'none', border: 'none', fontSize: '22px', cursor: 'pointer', color: '#94a3b8', lineHeight: 1 }}
                                onClick={closeModal}
                                disabled={saving}
                            >
                                &times;
                            </button>
                        </div>

                        {/* ---- SEARCH ASSET ---- */}
                        <div style={{ marginBottom: '20px' }}>
                            <label style={{ display: 'block', fontWeight: 600, marginBottom: '8px', color: '#374151', fontSize: '14px' }}>
                                Search Asset
                            </label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input
                                    className="form-control"
                                    placeholder="Enter IMEI No or SIM No"
                                    value={searchQuery}
                                    onChange={e => setSearchQuery(e.target.value)}
                                    onKeyDown={e => { if (e.key === 'Enter') handleSearchAsset(); }}
                                    disabled={searching || saving}
                                    style={{ flex: 1 }}
                                />
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={handleSearchAsset}
                                    disabled={searching || saving}
                                    style={{ whiteSpace: 'nowrap' }}
                                >
                                    {searching ? 'Searching...' : 'Search'}
                                </button>
                            </div>
                        </div>

                        {/* ---- SEARCH RESULT ---- */}
                        {foundAsset && (
                            <div style={{
                                background: '#f8fafc',
                                border: '1px solid #e2e8f0',
                                borderRadius: '8px',
                                padding: '16px',
                                marginBottom: '20px'
                            }}>
                                <div style={{ fontWeight: 700, color: '#1e293b', marginBottom: '12px', fontSize: '14px' }}>
                                    {foundAsset.asset_type === 'device' ? 'Allocated Device' : 'Allocated SIM'}
                                </div>

                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px', fontSize: '13px' }}>
                                    {foundAsset.asset_type === 'device' ? (
                                        <>
                                            <div>
                                                <div style={{ color: '#64748b', marginBottom: '2px' }}>IMEI No</div>
                                                <div style={{ fontWeight: 600 }}>{foundAsset.imei_no}</div>
                                            </div>
                                            <div>
                                                <div style={{ color: '#64748b', marginBottom: '2px' }}>Device Model</div>
                                                <div style={{ fontWeight: 600 }}>{foundAsset.device_model}</div>
                                            </div>
                                        </>
                                    ) : (
                                        <div>
                                            <div style={{ color: '#64748b', marginBottom: '2px' }}>SIM No</div>
                                            <div style={{ fontWeight: 600 }}>{foundAsset.sim_no}</div>
                                        </div>
                                    )}
                                    <div>
                                        <div style={{ color: '#64748b', marginBottom: '2px' }}>Current Owner</div>
                                        <div style={{ fontWeight: 600 }}>{foundAsset.owner_name}</div>
                                    </div>
                                    <div>
                                        <div style={{ color: '#64748b', marginBottom: '2px' }}>Owner Type</div>
                                        <div style={{ fontWeight: 600, textTransform: 'capitalize' }}>{foundAsset.owner_type_display}</div>
                                    </div>
                                    <div>
                                        <div style={{ color: '#64748b', marginBottom: '2px' }}>Status</div>
                                        <div style={{ fontWeight: 600, color: '#16a34a' }}>{foundAsset.status}</div>
                                    </div>
                                </div>

                                {/* Asset checkbox */}
                                <div style={{ marginTop: '14px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                                    <input
                                        type="checkbox"
                                        id="assetCheck"
                                        checked={assetChecked}
                                        onChange={e => setAssetChecked(e.target.checked)}
                                        disabled={saving}
                                        style={{ width: '16px', height: '16px', cursor: 'pointer' }}
                                    />
                                    <label htmlFor="assetCheck" style={{ cursor: 'pointer', fontSize: '13px', fontWeight: 500 }}>
                                        {foundAsset.asset_type === 'device' ? foundAsset.imei_no : foundAsset.sim_no}
                                    </label>
                                </div>
                            </div>
                        )}

                        {/* ---- TRANSFER TO ---- */}
                        {foundAsset && (
                            <>
                                <div style={{ marginBottom: '16px' }}>
                                    <label style={{ display: 'block', fontWeight: 600, marginBottom: '6px', color: '#374151', fontSize: '13px' }}>
                                        Transfer To Type
                                    </label>
                                    <select
                                        className="form-control"
                                        value={toOwnerType}
                                        onChange={e => handleToOwnerTypeChange(e.target.value)}
                                        disabled={saving}
                                    >
                                        <option value="">— Select Type —</option>
                                        <option value="technician">Technician</option>
                                        <option value="dealer">Dealer</option>
                                    </select>
                                </div>

                                {toOwnerType && (
                                    <div style={{ marginBottom: '16px' }}>
                                        <label style={{ display: 'block', fontWeight: 600, marginBottom: '6px', color: '#374151', fontSize: '13px' }}>
                                            Transfer To
                                        </label>
                                        <select
                                            className="form-control"
                                            value={toOwnerId}
                                            onChange={e => setToOwnerId(e.target.value)}
                                            disabled={saving || loadingOwners}
                                        >
                                            <option value="">
                                                {loadingOwners ? 'Loading...' : `— Select ${toOwnerType === 'technician' ? 'Technician' : 'Dealer'} —`}
                                            </option>
                                            {toOwnerList
                                                .filter(o => !(String(o.id) === String(foundAsset.owner_id) && toOwnerType === foundAsset.owner_type))
                                                .map(o => (
                                                    <option key={o.id} value={o.id}>{o.name}</option>
                                                ))
                                            }
                                        </select>
                                    </div>
                                )}

                                <div style={{ marginBottom: '20px' }}>
                                    <label style={{ display: 'block', fontWeight: 600, marginBottom: '6px', color: '#374151', fontSize: '13px' }}>
                                        Transfer Date
                                    </label>
                                    <input
                                        type="date"
                                        className="form-control"
                                        value={transferDate}
                                        onChange={e => setTransferDate(e.target.value)}
                                        disabled={saving}
                                    />
                                </div>
                            </>
                        )}

                        {/* ---- BUTTONS ---- */}
                        <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end', marginTop: '8px' }}>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={closeModal}
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={handleSaveTransfer}
                                disabled={saving || !foundAsset || !assetChecked}
                            >
                                {saving ? 'Saving...' : 'Save Transfer'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default StockTransferPage;
