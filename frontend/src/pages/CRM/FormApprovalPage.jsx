import { useCallback, useEffect, useState } from 'react';
import { Check, RefreshCw, X } from 'lucide-react';
import api from '../../services/api';
import { formatDate } from '../../utils/date';
import {
    formatInstallerOption,
    sortInstallersByLocation
} from '../../utils/installationLocation';
import { useConfirmation } from '../../components/Modal/useConfirmation';
import Pagination from '../../components/Pagination/Pagination';
import SearchableDropdown from '../../components/SearchableDropdown/SearchableDropdown';
import usePagination from '../../hooks/usePagination';
import './ClosureWorkflowPages.css';
import { useAuth } from '../../context/AuthContext';

const FormApprovalPage = () => {
    const { hasPermission } = useAuth();
    const { confirm, confirmationDialog } = useConfirmation();
    const canAssignInstallations = hasPermission('crm_installations.assign')
        || hasPermission('crm_installations.manage');
    const [submissions, setSubmissions] = useState([]);
    const [locations, setLocations] = useState([]);
    const [rejectionReasons, setRejectionReasons] = useState({});
    const [installers, setInstallers] = useState([]);
    const [assignmentSelections, setAssignmentSelections] = useState({});
    const [assignments, setAssignments] = useState([]);
    const [requestLocationFilter, setRequestLocationFilter] = useState('');
    const [installerTypeFilter, setInstallerTypeFilter] = useState('');
    const [installerSearch, setInstallerSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [savingId, setSavingId] = useState(null);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [pendingTotal, setPendingTotal] = useState(0);
    const [assignmentTotal, setAssignmentTotal] = useState(0);
    const {
        page: pendingPage,
        setPage: setPendingPage,
        pageSize: pendingPageSize,
        setPageSize: setPendingPageSize
    } = usePagination(submissions, 10, [requestLocationFilter], true);
    const {
        page: assignmentPage,
        setPage: setAssignmentPage,
        pageSize: assignmentPageSize,
        setPageSize: setAssignmentPageSize
    } = usePagination(assignments, 10, [], true);

    const loadPending = useCallback(async () => {
        try {
            const response = await api.get('/crm_closures/pending.php', {
                params: {
                    page: pendingPage,
                    page_size: pendingPageSize,
                    location: requestLocationFilter
                }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load pending closure forms.');
                return;
            }
            setSubmissions(response.data.data?.submissions || []);
            setLocations(response.data.data?.locations || []);
            setPendingTotal(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load pending closure forms.');
        } finally {
            setLoading(false);
        }
    }, [pendingPage, pendingPageSize, requestLocationFilter]);

    const loadInstallers = useCallback(async () => {
        if (!canAssignInstallations) return;
        try {
            const response = await api.get('/crm_closures/installers.php');
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load installation personnel.');
                return;
            }
            setInstallers(response.data.data?.installers || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load installation personnel.');
        }
    }, [canAssignInstallations]);

    const filteredSubmissions = submissions;

    const getVisibleInstallers = (customerLocation) =>
        sortInstallersByLocation(
            installers
                .filter((installer) => installer.account_eligible)
                .filter((installer) =>
                    !installerTypeFilter ||
                    installer.person_type === installerTypeFilter
                )
                .filter((installer) => {
                    const query = String(installerSearch || '')
                        .trim()
                        .toLowerCase();

                    return (
                        !query ||
                        String(installer.person_name || '').toLowerCase().includes(query) ||
                        String(installer.mobile_no || '').toLowerCase().includes(query) ||
                        String(installer.alternate_mobile_no || '').toLowerCase().includes(query) ||
                        String(installer.location || '').toLowerCase().includes(query)
                    );
                }),
            customerLocation
        );

    const loadAssignments = useCallback(async () => {
        if (!canAssignInstallations) return;
        try {
            const response = await api.get('/crm_closures/assignments.php', {
                params: { page: assignmentPage, page_size: assignmentPageSize }
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to load installation assignments.');
                return;
            }
            setAssignments(response.data.data?.assignments || []);
            setAssignmentTotal(Number(response.data.data?.pagination?.total || 0));
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to load installation assignments.');
        }
    }, [canAssignInstallations, assignmentPage, assignmentPageSize]);

    useEffect(() => {
        loadPending();
    }, [loadPending]);

    useEffect(() => {
        if (canAssignInstallations) loadInstallers();
    }, [canAssignInstallations, loadInstallers]);

    useEffect(() => {
        if (canAssignInstallations) loadAssignments();
    }, [canAssignInstallations, loadAssignments]);

    const refreshPending = () => {
        setLoading(true);
        setError('');
        loadPending();
        if (canAssignInstallations) loadAssignments();
    };

    const decide = async (submission, decision) => {
        const reason = String(rejectionReasons[submission.id] || '').trim();
        if (decision === 'Reject' && !reason) {
            setError('Enter a rejection reason before rejecting the form.');
            return;
        }
        const assignment = assignmentSelections[submission.id] || {};
        const selectedInstaller = installers.find(
            (installer) => `${installer.person_type}:${installer.person_id}` === assignment.person
        );
        if (decision === 'Approve' && (!selectedInstaller || !selectedInstaller.account_eligible || !selectedInstaller.user_id)) {
            setError(selectedInstaller?.account_issue
                ? `${selectedInstaller.person_name}: ${selectedInstaller.account_issue}. Resolve the personnel-to-account link before approval.`
                : 'Select an installation person with a linked, authorized login account before approval.');
            return;
        }

        const confirmed = await confirm({
            title: decision === 'Approve' ? 'Approve & Assign Installation' : 'Reject Conversion Request',
            message: decision === 'Approve'
                ? `Approve ${submission.lead_name}'s conversion request and assign installation to ${selectedInstaller.person_name} (${selectedInstaller.account_name})? This does not create the final Customer Management records.`
                : `Reject ${submission.lead_name}'s conversion request?`,
            confirmLabel: decision === 'Approve' ? 'Approve & Assign' : 'Reject Request',
            confirmClassName: decision === 'Approve' ? 'btn-primary' : 'btn-danger'
        });
        if (!confirmed) return;
        setSavingId(submission.id);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_closures/decide.php', {
                id: Number(submission.id),
                decision,
                rejection_reason: decision === 'Reject' ? reason : '',
                ...(decision === 'Approve' ? {
                    installation_person_type: selectedInstaller.person_type,
                    installation_person_id: Number(selectedInstaller.person_id),
                    installation_person_user_id: Number(selectedInstaller.user_id)
                } : {})
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to save the review decision.');
                return;
            }
            setMessage(response.data.message);
            if (decision === 'Approve') {
                setAssignmentSelections((current) => {
                    const updated = { ...current };
                    delete updated[submission.id];
                    return updated;
                });
            }
            await loadPending();
            if (decision === 'Approve') await loadAssignments();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to save the review decision.');
        } finally {
            setSavingId(null);
        }
    };

    const reassign = async (assignment) => {
        const selection = assignmentSelections[`reassign-${assignment.closure_id}`] || {};
        const installer = installers.find(
            (item) => `${item.person_type}:${item.person_id}` === selection.person
        );
        if (!installer || !installer.account_eligible || !installer.user_id) {
            setError(installer?.account_issue
                ? `${installer.person_name}: ${installer.account_issue}. Resolve the personnel-to-account link before reassignment.`
                : 'Select an installation person with a linked, authorized login account.');
            return;
        }
        
        const confirmed = await confirm({
            title: 'Reassign Installation',
            message: `Reassign ${assignment.lead_name}'s installation to ${installer.person_name} (${installer.account_name})?`,
            confirmLabel: 'Reassign',
            confirmClassName: 'btn-primary'
        });
        if (!confirmed) return;
        setSavingId(assignment.closure_id);
        setError('');
        setMessage('');
        try {
            const response = await api.post('/crm_closures/assign.php', {
                closure_id: Number(assignment.closure_id),
                installation_person_type: installer.person_type,
                installation_person_id: Number(installer.person_id),
                installation_person_user_id: Number(installer.user_id)
            });
            if (!response.data?.success) {
                setError(response.data?.message || 'Unable to reassign installation.');
                return;
            }
            setMessage(response.data.message || 'Installation reassigned successfully.');
            await loadAssignments();
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to reassign installation.');
        } finally {
            setSavingId(null);
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h2>Form Approval</h2>
                    <p className="page-subtitle">Review lead conversion submissions before customer handoff.</p>
                </div>
                <button type="button" className="btn btn-outline" onClick={refreshPending} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {message && (
                <div className="alert alert-success" role="status">
                    {message}
                </div>
            )}
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            <section className="card closure-workflow-card">
                <div className="closure-approval-filters">
                    <label>
                        <span>Customer / Installation Location</span>
                        <input
                            className="form-control"
                            list="closure-location-options"
                            value={requestLocationFilter}
                            onChange={(event) => setRequestLocationFilter(event.target.value)}
                            placeholder="Filter pending requests"
                        />
                        <datalist id="closure-location-options">
                            {locations.map((location) => <option key={location} value={location} />)}
                        </datalist>
                    </label>
                    <label>
                        <span>Installation Personnel Type</span>
                        <select
                            className="form-control"
                            value={installerTypeFilter}
                            onChange={(event) => setInstallerTypeFilter(event.target.value)}
                        >
                            <option value="">All types</option>
                            <option value="Technician">Technician</option>
                            <option value="Onsite Dealer">Onsite Dealer</option>
                            <option value="Offsite Dealer">Offsite Dealer</option>
                        </select>
                    </label>
                    <label>
                        <span>Search Personnel</span>
                        <input
                            className="form-control"
                            value={installerSearch}
                            onChange={(event) => setInstallerSearch(event.target.value)}
                            placeholder="Name or contact number"
                        />
                    </label>
                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={() => {
                            setRequestLocationFilter('');
                            setInstallerTypeFilter('');
                            setInstallerSearch('');
                        }}
                    >
                        Clear filters
                    </button>
                </div>
                <div className="closure-location-note">
                    Showing {filteredSubmissions.length} of {pendingTotal} pending requests. Eligible personnel are ordered by exact customer-location match; personnel type and search narrow the available list.
                </div>
                <div className="table-container closure-approval-table-scroll">
                    <table className="table closure-workflow-table closure-approval-table">
                        <thead>
                            <tr>
                                <th>Lead</th><th>Contact</th><th>Campaign</th><th>Submitted By</th>
                                <th>Customer / Platform</th><th>Vehicle</th><th>Device / Amount</th>
                                <th>Submitted</th><th>Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="9">Loading approval queue...</td></tr>
                            ) : filteredSubmissions.length === 0 ? (
                            <tr><td colSpan="9">{pendingTotal === 0
                                    ? 'No closure submissions are awaiting approval.'
                                    : 'No pending requests match this location filter.'}</td></tr>
                            ) : filteredSubmissions.map((submission) => (
                                <tr key={submission.id}>
                                    <td data-label="Lead">{submission.lead_name}<small style={{ display: 'block' }}>{submission.lead_status}</small></td>
                                    <td data-label="Contact">{submission.mobile_no}</td>
                                    <td data-label="Campaign">{submission.campaign_name || '—'}</td>
                                    <td data-label="Submitted By">{submission.submitted_by}<small style={{ display: 'block' }}>Assigned: {submission.assigned_employee}</small></td>
                                    <td data-label="Customer / Platform">
                                        <strong>Username:</strong> {submission.username || '—'}
                                        <small style={{ display: 'block' }}>Customer Number: {submission.customer_number || '—'}</small>
                                        <small style={{ display: 'block' }}>{submission.platform_name}</small>
                                        <small style={{ display: 'block' }}>{submission.location}, {submission.pincode}</small>
                                        <small style={{ display: 'block' }}>Lead contact: {submission.mobile_no}</small>
                                        {submission.email && <small style={{ display: 'block' }}>{submission.email}</small>}
                                        <small style={{ display: 'block' }}>Commitment: {formatDate(submission.commitment_date)}</small>
                                    </td>
                                    <td data-label="Vehicle">{submission.vehicle_type}<small style={{ display: 'block' }}>{submission.vehicle_number}</small></td>
                                    <td data-label="Device / Amount">{submission.device_type} × {submission.device_count}<small style={{ display: 'block' }}>{submission.amount} ({submission.amount_status})</small></td>
                                    <td data-label="Submitted">{formatDate(submission.submitted_at)}</td>
                                    <td data-label="Decision">
                                        <div className="closure-approval-actions">
                                            {canAssignInstallations ? (() => {
                                                const assignment = assignmentSelections[submission.id] || {};
                                                const visibleInstallers = getVisibleInstallers(submission.location);
                                                const selectedInstaller = installers.find((installer) =>
                                                    `${installer.person_type}:${installer.person_id}` === assignment.person
                                                );
                                                return (
                                                    <>
                                                        <div className="closure-installer-picker">
                                                            <SearchableDropdown
                                                                options={visibleInstallers.map((installer) => ({
                                                                    value: `${installer.person_type}:${installer.person_id}`,
                                                                    label: formatInstallerOption(installer, submission.location)
                                                                }))}
                                                                value={assignment.person || ''}
                                                                placeholder="Search or select installer"
                                                                disabled={savingId !== null}
                                                                aria-label={`Installation person for ${submission.lead_name}`}
                                                                onChange={(person) => {
                                                                    setAssignmentSelections((current) => ({
                                                                        ...current,
                                                                        [submission.id]: { person }
                                                                    }));
                                                                }}
                                                            />
                                                        </div>
                                                        {selectedInstaller && (
                                                            <>
                                                                <small className="closure-selected-installer">{formatInstallerOption(selectedInstaller, submission.location)}</small>
                                                                <small>Linked authorized login: {selectedInstaller.account_name}</small>
                                                            </>
                                                        )}
                                                        {visibleInstallers.length === 0 && (
                                                            <small className="closure-filter-warning">
                                                                No eligible active installation personnel are available.
                                                            </small>
                                                        )}
                                                        <button
                                                            type="button"
                                                            className="btn btn-primary"
                                                            disabled={savingId !== null || !selectedInstaller}
                                                            onClick={() => decide(submission, 'Approve')}
                                                        >
                                                            <Check size={15} /> {savingId === submission.id ? 'Saving...' : 'Approve & Assign Installation'}
                                                        </button>
                                                    </>
                                                );
                                            })() : <small>You do not have permission to assign installation personnel.</small>}
                                            <textarea
                                                className="form-control"
                                                rows="2"
                                                maxLength="2000"
                                                placeholder="Required rejection reason"
                                                value={rejectionReasons[submission.id] || ''}
                                                onChange={(event) => setRejectionReasons((current) => ({ ...current, [submission.id]: event.target.value }))}
                                                aria-label={`Rejection reason for ${submission.lead_name}`}
                                            />
                                            <button type="button" className="btn btn-outline" disabled={savingId !== null} onClick={() => decide(submission, 'Reject')}>
                                                <X size={15} /> Reject
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination
                    currentPage={pendingPage}
                    pageSize={pendingPageSize}
                    totalItems={pendingTotal}
                    onPageChange={setPendingPage}
                    onPageSizeChange={setPendingPageSize}
                    itemName="pending requests"
                />
            </section>
            {canAssignInstallations && (
                <section className="card closure-workflow-card">
                    <h3>Assigned Installations — Reassign</h3>
                    <div className="table-container">
                        <table className="table closure-workflow-table closure-reassignment-table">
                            <thead>
                                <tr><th>Customer / Lead</th><th>Vehicle / Device</th><th>Location</th><th>Current Assignee</th><th>New Assignment</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                {assignments.length === 0 ? (
                                    <tr><td colSpan="6">No installation assignments are available.</td></tr>
                                ) : assignments.map((assignment) => {
                                    const selectionKey = `reassign-${assignment.closure_id}`;
                                    const selection = assignmentSelections[selectionKey] || {};
                                    const visibleInstallers = getVisibleInstallers(assignment.location);
                                    const selectedInstaller = installers.find((item) => `${item.person_type}:${item.person_id}` === selection.person);
                                    const currentInstaller = installers.find((item) =>
                                        item.person_type === assignment.installation_person_type &&
                                        Number(item.person_id) === Number(assignment.installation_person_id)
                                    );
                                    return (
                                        <tr key={assignment.closure_id}>
                                            <td data-label="Customer / Lead">{assignment.customer_name}<small style={{ display: 'block' }}>{assignment.lead_name} · {assignment.primary_mobile_no}</small></td>
                                            <td data-label="Vehicle / Device">{assignment.vehicle_number}<small style={{ display: 'block' }}>{assignment.vehicle_type} · {assignment.device_type} × {assignment.device_count}</small></td>
                                            <td data-label="Location">{assignment.location}, {assignment.pincode}</td>
                                            <td data-label="Current Assignee">{currentInstaller?.person_name || assignment.installation_person_type}<small style={{ display: 'block' }}>{assignment.installation_person_type} · {formatDate(assignment.installation_assigned_at)}</small></td>
                                            <td data-label="New Assignment">
                                                <div className="closure-reassignment-controls">
                                                    <div className="closure-installer-picker">
                                                        <SearchableDropdown
                                                            options={visibleInstallers.map((item) => ({
                                                                value: `${item.person_type}:${item.person_id}`,
                                                                label: formatInstallerOption(item, assignment.location)
                                                            }))}
                                                            value={selection.person || ''}
                                                            placeholder="Search or select installer"
                                                            aria-label={`New installer for ${assignment.lead_name}`}
                                                            onChange={(person) => {
                                                                setAssignmentSelections((current) => ({
                                                                    ...current,
                                                                    [selectionKey]: { person }
                                                                }));
                                                            }}
                                                        />
                                                    </div>
                                                    {selectedInstaller && (
                                                        <>
                                                            <small className="closure-selected-installer">{formatInstallerOption(selectedInstaller, assignment.location)}</small>
                                                            <small>Linked authorized login: {selectedInstaller.account_name}</small>
                                                        </>
                                                    )}
                                                    {visibleInstallers.length === 0 && <small className="closure-filter-warning">No eligible active installation personnel are available.</small>}
                                                </div>
                                            </td>
                                            <td data-label="Action">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline"
                                                    disabled={savingId !== null || !selectedInstaller}
                                                    onClick={() => reassign(assignment)}
                                                >
                                                    {savingId === assignment.closure_id ? 'Saving...' : 'Reassign'}
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <Pagination
                        currentPage={assignmentPage}
                        pageSize={assignmentPageSize}
                        totalItems={assignmentTotal}
                        onPageChange={setAssignmentPage}
                        onPageSizeChange={setAssignmentPageSize}
                        itemName="assignments"
                    />
                </section>
            )}
            {confirmationDialog}
        </div>
    );
};

export default FormApprovalPage;
