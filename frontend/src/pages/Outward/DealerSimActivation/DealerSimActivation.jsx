import { useState, useEffect, useRef } from 'react';
import api from '../../../services/api';
import { formatDate } from '../../../utils/date';
import { exportToExcel, exportToPDF } from '../../../utils/export';
import { showGlobalError } from '../../../context/ErrorContext';

import TableFilterBar from '../../../components/TableFilterBar/TableFilterBar';
import Pagination from '../../../components/Pagination/Pagination';
import Modal from '../../../components/Modal/Modal';
import { useAuth } from '../../../context/AuthContext';
import { Ban, Download, Edit2, Eye, Play, RotateCw, ShieldCheck, Trash2, Upload } from 'lucide-react';
import DealerSimActivationEditModal from './DealerSimActivationEditModal';
import DealerSimActivationBulkUploadModal from './DealerSimActivationBulkUploadModal';
import './DealerSimActivation.css';

const simStatusBadgeClass = (status) => ({
    available: 'badge-info',
    active: 'badge-success',
    expired: 'badge-warning',
    'safe custody': 'badge-warning',
    deactive: 'badge-danger'
}[String(status || '').trim().toLowerCase()] || 'badge-info');

const paymentStatusBadgeClass = (status) => ({
    paid: 'badge-success',
    'partially paid': 'badge-warning',
    'not paid': 'badge-danger'
}[String(status || '').trim().toLowerCase()] || 'badge-info');

const DealerSimActivation = () => {
    const { currentUser, hasPermission } = useAuth();
    const roleName = typeof currentUser?.role === 'string'
        ? currentUser.role
        : currentUser?.role?.name || currentUser?.role_name || '';
    const isSuperAdmin = String(roleName).trim().toLowerCase().replace(/[\s_-]/g, '') === 'superadmin';
    const canViewActivation = hasPermission('dealer_sim_activation.view') || hasPermission('dealers.view');
    const canEditActivation = hasPermission('dealer_sim_activation.edit') || hasPermission('dealers.edit');
    const canDeleteActivation = hasPermission('dealer_sim_activation.delete') || hasPermission('dealers.delete');
    const initialFilters = {
        search: '',
        dealer_id: '',
        simType: '',
        status: '',
        paymentStatus: '',
        given_date: '',
        given_date_operator: 'exact',
        simValidity: '',
        activation_date_from: '',
        activation_date_to: ''
    };

    const [sims, setSims] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const [masters, setMasters] = useState({ dealers: [], simTypes: [], simValidities: [] });
    const [dateYears, setDateYears] = useState({ given_date: [], activation_date: [] });
    const [filters, setFilters] = useState(initialFilters);
    const [appliedFilters, setAppliedFilters] = useState(initialFilters);
    const [dealerDropdownResetKey, setDealerDropdownResetKey] = useState(0);

    const [activeSimModal, setActiveSimModal] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [isBulkUploadOpen, setIsBulkUploadOpen] = useState(false);
    const [uploadSuccess, setUploadSuccess] = useState('');

    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [totalRecords, setTotalRecords] = useState(0);
    const latestFetchId = useRef(0);

    const buildFilterParams = (selectedFilters, exportAll = false) => {
        let validity_id = '';
        if (selectedFilters.simValidity) {
            const months = String(selectedFilters.simValidity).replace(/[^0-9]/g, '');
            const validity = masters.simValidities.find(v => String(v.months) === months);
            if (validity) validity_id = validity.id;
        }

        return new URLSearchParams({
            page: exportAll ? '1' : String(page),
            limit: exportAll ? '1' : String(pageSize),
            export_all: exportAll ? '1' : '0',
            search: selectedFilters.search || '',
            dealer_id: selectedFilters.dealer_id || '',
            sim_type: selectedFilters.simType || '',
            status: selectedFilters.status || '',
            payment_status: selectedFilters.paymentStatus || '',
            given_date: selectedFilters.given_date || '',
            given_date_operator: selectedFilters.given_date_operator || 'exact',
            activation_date_from: selectedFilters.activation_date_from || '',
            activation_date_to: selectedFilters.activation_date_to || '',
            validity_id
        });
    };

    const fetchMasters = async () => {
        try {
            const [dealersRes, simTypesRes, simValiditiesRes] = await Promise.all([
                api.get('/dealers/list.php'),
                api.get('/sim_types/list.php'),
                api.get('/sim_validities/list.php')
            ]);
            setMasters({
                dealers: dealersRes.data.data?.dealers || [],
                simTypes: simTypesRes.data.data?.sim_types || [],
                simValidities: simValiditiesRes.data.data?.validities || []
            });
        } catch (err) {
            console.error('Failed to fetch filter masters', err);
        }
    };

    const fetchSims = async () => {
        const fetchId = ++latestFetchId.current;
        setLoading(true);
        setError(null);
        try {
            const params = buildFilterParams(appliedFilters);
            const response = await api.get(`/dealers/sim_activation_list.php?${params.toString()}`);
            if (fetchId !== latestFetchId.current) return;
            if (response.data.success) {
                setSims(response.data.data?.sims || []);
                setDateYears(response.data.data?.date_years || { given_date: [], activation_date: [] });
                setTotalRecords(response.data.data?.pagination?.total_records || 0);
            } else {
                setError(response.data.message || 'Failed to load SIMs');
            }
        } catch (err) {
            if (fetchId !== latestFetchId.current) return;
            setError(err.response?.data?.message || 'Error fetching SIMs');
        } finally {
            if (fetchId === latestFetchId.current) setLoading(false);
        }
    };

    useEffect(() => {
        fetchMasters();
    }, []);

    useEffect(() => {
        fetchSims();
    }, [page, pageSize, appliedFilters, masters.simValidities]);

    const handleFilterChange = (newFilters) => {
        setFilters(newFilters);
    };

    const handleSearch = () => {
        setAppliedFilters({ ...filters });
        setPage(1);
    };

    const getExportRows = async () => {
        const params = buildFilterParams(appliedFilters, true);
        const response = await api.get(`/dealers/sim_activation_list.php?${params.toString()}`);
        if (!response.data.success) throw new Error(response.data.message || 'Failed to load SIM activations for export.');
        return response.data.data?.sims || [];
    };

    const exportColumns = [
        { header: 'S.No', key: 's_no' },
        { header: 'Dealer Name', key: 'dealer_name' },
        { header: 'SIM Number', key: 'sim_no' },
        { header: 'Given Date', key: 'given_date' },
        { header: 'Activation Date', key: 'activation_date' },
        { header: 'Next Renewal Date', key: 'renewal_date' },
        { header: 'SIM Type', key: 'sim_type' },
        { header: 'Validity', key: 'validity' },
        { header: 'Expiry Date', key: 'expiry_date' },
        { header: 'Deactivation Date', key: 'deactivation_date' },
        { header: 'Amount (₹)', key: 'total_payment_amount' },
        { header: 'Pending Amount (₹)', key: 'total_pending_amount' },
        { header: 'SIM Status', key: 'sim_status' },
        { header: 'Payment Status', key: 'payment_status' }
    ];

    const getFormattedExportRows = async () => {
        const records = await getExportRows();
        return records.map((sim, index) => ({
            s_no: index + 1,
            dealer_name: sim.dealer_name || '-',
            sim_no: sim.sim_no || '-',
            given_date: sim.given_date || '',
            activation_date: sim.activation_date || '',
            renewal_date: sim.renewal_date || '',
            sim_type: sim.sim_type || '-',
            validity: sim.validity_months ? `${sim.validity_months} Months` : '-',
            expiry_date: sim.expiry_date || '',
            deactivation_date: sim.deactivation_date || '',
            total_payment_amount: sim.total_payment_amount == null ? '-' : Number(sim.total_payment_amount).toFixed(2),
            total_pending_amount: sim.total_pending_amount == null ? '-' : Number(sim.total_pending_amount).toFixed(2),
            sim_status: sim.sim_status || 'Available',
            payment_status: sim.payment_status || '-'
        }));
    };

    const handleExportExcel = async () => {
        try {
            const exportRows = await getFormattedExportRows();
            if (!exportRows.length) {
                showGlobalError('No SIM activation records match the applied filters.');
                return;
            }
            exportToExcel(exportRows, 'Dealer_SIM_Activation', 'SIM Activations', { columns: exportColumns });
        } catch (exportError) {
            showGlobalError(exportError.response?.data?.message || exportError.message || 'Failed to export SIM activations.');
        }
    };

    const handleExportPdf = async () => {
        try {
            const exportRows = await getFormattedExportRows();
            if (!exportRows.length) {
                showGlobalError('No SIM activation records match the applied filters.');
                return;
            }
            exportToPDF(exportRows, 'Dealer_SIM_Activation', 'DEALER SIM ACTIVATION', exportColumns, {
                orientation: 'landscape',
                margin: { top: 30, right: 8, bottom: 12, left: 8 },
                styles: {
                    fontSize: 7,
                    cellPadding: 1.5,
                    overflow: 'linebreak',
                    valign: 'middle'
                },
                headStyles: {
                    fontSize: 7,
                    halign: 'center',
                    valign: 'middle'
                },
                columnStyles: {
                    0: { cellWidth: 9, halign: 'center' },
                    1: { cellWidth: 25 },
                    2: { cellWidth: 24 },
                    3: { cellWidth: 17, halign: 'center' },
                    4: { cellWidth: 18, halign: 'center' },
                    5: { cellWidth: 20, halign: 'center' },
                    6: { cellWidth: 14 },
                    7: { cellWidth: 13, halign: 'center' },
                    8: { cellWidth: 17, halign: 'center' },
                    9: { cellWidth: 19, halign: 'center' },
                    10: { cellWidth: 19, halign: 'right' },
                    11: { cellWidth: 19, halign: 'right' },
                    12: { cellWidth: 17 },
                    13: { cellWidth: 21 }
                }
            });
        } catch (exportError) {
            showGlobalError(exportError.response?.data?.message || exportError.message || 'Failed to export SIM activations.');
        }
    };

    const deleteSimActivation = async () => {
        if (!deleteTarget) return;
        setDeleting(true);
        setError(null);
        try {
            const response = await api.post('/dealers/sim_activation_delete.php', {
                allocation_id: deleteTarget.allocation_id
            });
            if (!response.data.success) throw new Error(response.data.message || 'Unable to delete SIM activation.');
            setDeleteTarget(null);
            setUploadSuccess('Dealer SIM activation deleted and SIM returned to available stock.');
            await fetchSims();
        } catch (deleteError) {
            setError(deleteError.response?.data?.message || deleteError.message || 'Unable to delete SIM activation.');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h1>Dealer SIM Activation</h1>
                    <p className="page-subtitle">Review and manage SIM activation records for dealers.</p>
                </div>
                {isSuperAdmin && (
                    <button type="button" className="btn btn-primary" onClick={() => setIsBulkUploadOpen(true)}>
                        <Upload size={16} /> Upload Excel
                    </button>
                )}
            </div>

            {uploadSuccess && <div className="alert alert-success">{uploadSuccess}</div>}

            <div className="card">
                <div className="header-actions" style={{ justifyContent: 'flex-end', marginBottom: 16 }}>
                    <button type="button" className="btn btn-outline" onClick={handleExportExcel} disabled={loading || totalRecords === 0}>
                        <Download size={16} /> Export Excel
                    </button>
                    <button type="button" className="btn btn-outline" onClick={handleExportPdf} disabled={loading || totalRecords === 0}>
                        <Download size={16} /> Export PDF
                    </button>
                </div>
                <TableFilterBar
                    filters={filters}
                    onChange={handleFilterChange}
                    onSearch={handleSearch}
                    dealerDropdownResetKey={dealerDropdownResetKey}
                    onReset={() => {
                        setFilters({ ...initialFilters });
                        setAppliedFilters({ ...initialFilters });
                        setDealerDropdownResetKey((key) => key + 1);
                        setPage(1);
                    }}
                    searchPlaceholder="Search SIM Number..."
                    dealerOptions={masters.dealers.map(d => ({ value: d.id, label: d.dealer_name }))}
                    simTypeOptions={masters.simTypes.map(t => t.sim_type)}
                    statusOptions={['Available', 'Active', 'Expired', 'Safe Custody', 'Deactive']}
                    statusPlaceholder="All Statuses"
                    showPaymentStatus
                    paymentStatusOptions={['Paid', 'Partially Paid', 'Not Paid']}
                    simValidityOptions={masters.simValidities.map(v => `${v.months} Months`)}
                    dateRangeFilters={[{ key: 'activation_date', label: 'Activation Date' }]}
                    customDateFilters={[
                        { key: 'given_date', label: 'Given Date', years: dateYears.given_date }
                    ]}
                />

                {error && <div className="alert alert-danger">{error}</div>}

                <div className="table-container">
                    <table className="dealer-sim-activation-table">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Dealer Name</th>
                                <th>SIM Number</th>
                                <th>Given Date</th>
                                <th>Activation Date</th>
                                <th>Next Renewal Date</th>
                                <th>SIM Type</th>
                                <th>Validity</th>
                                <th>Expiry Date</th>
                                <th>Deactivation Date</th>
                                <th>Amount (₹)</th>
                                <th>Pending Amount (₹)</th>
                                <th>SIM Status</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan="15" className="text-center">Loading SIMs...</td>
                                </tr>
                            ) : sims.length === 0 ? (
                                <tr>
                                    <td colSpan="15" className="text-center empty-state">No SIMs found</td>
                                </tr>
                            ) : (
                                sims.map((sim, index) => (
                                    <tr key={sim.allocation_id}>
                                        <td>{(page - 1) * pageSize + index + 1}</td>
                                        <td className="truncate-cell" title={sim.dealer_name}>{sim.dealer_name}</td>
                                        <td className="truncate-cell" title={sim.sim_no}>{sim.sim_no}</td>
                                        <td>{formatDate(sim.given_date)}</td>
                                        <td>{formatDate(sim.activation_date)}</td>
                                        <td>{formatDate(sim.renewal_date)}</td>
                                        <td>{sim.sim_type || '-'}</td>
                                        <td>{sim.validity_months ? `${sim.validity_months} Months` : '-'}</td>
                                        <td>{formatDate(sim.expiry_date)}</td>
                                        <td>{formatDate(sim.deactivation_date)}</td>
                                        <td>{sim.total_payment_amount === null || sim.total_payment_amount === undefined ? '-' : `₹${Number(sim.total_payment_amount).toFixed(2)}`}</td>
                                        <td>{sim.total_pending_amount === null || sim.total_pending_amount === undefined ? '-' : `₹${Number(sim.total_pending_amount).toFixed(2)}`}</td>
                                        <td>
                                            <span className={`badge ${simStatusBadgeClass(sim.sim_status || 'Available')}`}>
                                                {sim.sim_status || 'Available'}
                                            </span>
                                        </td>
                                        <td>
                                            {sim.payment_status
                                                ? <span className={`badge ${paymentStatusBadgeClass(sim.payment_status)}`}>{sim.payment_status}</span>
                                                : '-'}
                                        </td>
                                        <td>
                                            <div className="action-buttons">
                                                {canViewActivation && (
                                                    <button
                                                        type="button"
                                                        className="icon-btn view"
                                                        onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'view' })}
                                                        title="View SIM Activation"
                                                        aria-label={`View SIM Activation for ${sim.sim_no}`}
                                                    >
                                                        <Eye size={16} />
                                                    </button>
                                                )}
                                                {isSuperAdmin && (
                                                    <button
                                                        type="button"
                                                        className="icon-btn edit"
                                                        onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'edit' })}
                                                        title="Edit SIM and Payment Details"
                                                        aria-label={`Edit SIM and Payment Details for ${sim.sim_no}`}
                                                    >
                                                        <Edit2 size={16} />
                                                    </button>
                                                )}
                                                {canEditActivation && (
                                                    <>
                                                        {['active', 'expired'].includes(String(sim.sim_status || '').toLowerCase()) && (
                                                            <>
                                                                <button type="button" className="icon-btn" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'renew' })} title="Renew SIM" aria-label={`Renew SIM ${sim.sim_no}`}><RotateCw size={16} /></button>
                                                                <button type="button" className="icon-btn delete" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'deactivate' })} title="Deactivate SIM" aria-label={`Deactivate SIM ${sim.sim_no}`}><Ban size={16} /></button>
                                                                <button type="button" className="icon-btn" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'safe_custody' })} title="Move to Safe Custody" aria-label={`Move SIM ${sim.sim_no} to Safe Custody`}><ShieldCheck size={16} /></button>
                                                            </>
                                                        )}
                                                        {String(sim.sim_status || '').toLowerCase() === 'safe custody' && (
                                                            <>
                                                                <button type="button" className="icon-btn" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'renew' })} title="Renew SIM" aria-label={`Renew SIM ${sim.sim_no}`}><RotateCw size={16} /></button>
                                                                <button type="button" className="icon-btn delete" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'deactivate' })} title="Deactivate SIM" aria-label={`Deactivate SIM ${sim.sim_no}`}><Ban size={16} /></button>
                                                            </>
                                                        )}
                                                        {String(sim.sim_status || '').toLowerCase() === 'deactive' && (
                                                            <button type="button" className="icon-btn" onClick={() => setActiveSimModal({ allocationId: sim.allocation_id, mode: 'lifecycle', action: 'reactivate' })} title="Reactivate SIM" aria-label={`Reactivate SIM ${sim.sim_no}`}><Play size={16} /></button>
                                                        )}
                                                    </>
                                                )}
                                                {canDeleteActivation && (
                                                    <button
                                                        type="button"
                                                        className="icon-btn delete"
                                                        onClick={() => setDeleteTarget(sim)}
                                                        title="Delete SIM Activation"
                                                        aria-label={`Delete SIM Activation for ${sim.sim_no}`}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                )}
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
                    pageSize={pageSize}
                    totalItems={totalRecords}
                    onPageChange={setPage}
                    onPageSizeChange={setPageSize}
                    itemName="SIMs"
                />
            </div>

            {activeSimModal && (
                <DealerSimActivationEditModal
                    allocationId={activeSimModal.allocationId}
                    mode={activeSimModal.mode}
                    lifecycleAction={activeSimModal.action}
                    canEditDetails={isSuperAdmin}
                    onClose={() => setActiveSimModal(null)}
                    onSuccess={async () => {
                        setActiveSimModal(null);
                        setUploadSuccess(activeSimModal.mode === 'lifecycle'
                            ? `${activeSimModal.action === 'safe_custody' ? 'Safe Custody' : activeSimModal.action.charAt(0).toUpperCase() + activeSimModal.action.slice(1)} action saved successfully.`
                            : 'Dealer SIM activation details updated successfully.');
                        await fetchSims();
                    }}
                    onPaymentSuccess={async () => {
                        setUploadSuccess('SIM payment recorded successfully.');
                        await fetchSims();
                    }}
                />
            )}
            {deleteTarget && (
                <Modal
                    isOpen
                    onClose={() => !deleting && setDeleteTarget(null)}
                    title="Delete Dealer SIM Activation"
                    maxWidth="440px"
                    footer={(
                        <>
                            <button type="button" className="btn btn-outline" onClick={() => setDeleteTarget(null)} disabled={deleting}>Cancel</button>
                            <button type="button" className="btn btn-danger" onClick={deleteSimActivation} disabled={deleting}>
                                {deleting ? 'Deleting...' : 'Delete SIM Activation'}
                            </button>
                        </>
                    )}
                >
                    <p>Delete the activation for SIM <strong>{deleteTarget.sim_no}</strong>? The SIM will be returned to available stock.</p>
                </Modal>
            )}
            {isBulkUploadOpen && (
                <DealerSimActivationBulkUploadModal
                    onClose={() => setIsBulkUploadOpen(false)}
                    onSuccess={(count) => {
                        setIsBulkUploadOpen(false);
                        setUploadSuccess(`${count} SIMs activated successfully.`);
                        fetchSims();
                    }}
                />
            )}
        </div>
    );
};

export default DealerSimActivation;