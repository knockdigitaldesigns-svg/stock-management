import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, RefreshCw, RotateCcw } from 'lucide-react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import Pagination from '../../components/Pagination/Pagination';
import './CustomerPaymentVerificationPage.css';

const detailSections = [
    ['customer', 'Customer Details'],
    ['courier', 'Courier Request'],
    ['vehicle', 'Vehicle Details'],
    ['installation', 'Installation Details'],
    ['payment_part1', 'Payment Part 1'],
    ['payment_part2', 'Payment Part 2']
];

const getSectionData = (data, key) => {
    if (key === 'customer') return data.customer || data.step1;
    if (key === 'payment_part1') return data.payment_part1 || data.step4;
    if (key === 'vehicle') return data.vehicle || data.vehicles;
    return data[key];
};

const formatValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
};

const getCorrectionStep = (reason) => {
    const text = String(reason || '').toLowerCase();
    if (/\b(payment\s*(part\s*)?2|second payment|amount paid|amount pending|cash collection|charge|payment mode|payment status)\b/.test(text)) return 5;
    if (/\b(payment\s*(part\s*)?1|first payment|sale amount|transaction id)\b/.test(text)) return 4;
    if (/\b(installation|technician|dealer|closure|installer)\b/.test(text)) return 3;
    if (/\b(vehicle|registration|imei|sim|device|validity)\b/.test(text)) return 2;
    if (/\b(customer|username|mobile|phone|email|platform|location|pincode|pin code)\b/.test(text)) return 1;
    return 5;
};

const MyCustomerVerificationsPage = () => {
    const navigate = useNavigate();
    const [submissions, setSubmissions] = useState([]);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [pagination, setPagination] = useState({ total: 0, page: 1, page_size: 10, total_pages: 1 });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(null);

    const loadSubmissions = useCallback(async () => {
        try {
            setLoading(true);
            setError('');
            const response = await api.get('/customers/verification/mine.php', {
                params: { page, page_size: pageSize }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load your submissions.');
            }
            setSubmissions(response.data.data?.submissions || []);
            const nextPagination = response.data.data?.pagination || { total: 0, page, page_size: pageSize, total_pages: 1 };
            setPagination(nextPagination);
            if (page > nextPagination.total_pages) setPage(nextPagination.total_pages);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load your submissions.');
        } finally {
            setLoading(false);
        }
    }, [page, pageSize]);

    const updatePageSize = (nextPageSize) => {
        setPageSize(nextPageSize);
        setPage(1);
    };

    useEffect(() => {
        loadSubmissions();
    }, [loadSubmissions]);

    const resumeDraft = async (id) => {
        try {
            setError('');
            const response = await api.get('/customers/verification/own_draft.php', { params: { id } });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load rejected submission.');
            }
            const item = response.data.data?.submission;
            const data = item?.submission_data || {};
            const correctionStep = getCorrectionStep(item?.rejection_reason);
            if (Array.isArray(data.vehicles) && Number(item.existing_customer_id || 0) > 0) {
                const customerRouteId = Number(item.existing_customer_id);
                sessionStorage.setItem(`customer_edit_verification_${customerRouteId}`, JSON.stringify({
                    id: Number(item.id),
                    submission_data: data
                }));
                navigate(`/customer-management/details/${customerRouteId}/edit?step=${correctionStep}`);
                return;
            }
            const normalized = Boolean(data.customer);
            const sessionData = normalized
                ? {
                    draft_id: Number(item.id),
                    existing_customer_id: item.existing_customer_id || null,
                    customer_id: item.existing_customer_id || -Number(item.id),
                    step1: data.customer || {},
                    step2: data.vehicle ? {
                        vehicle_no: data.vehicle.vehicle_no || '',
                        vehicle_type_id: data.vehicle.vehicle_type_id || '',
                        imei_no: data.vehicle.imei_no || '',
                        sim_no_1: data.vehicle.sim_no_1 || '',
                        sim_no_2: data.vehicle.sim_no_2 || '',
                        device_model_id: data.vehicle.device_model_id || '',
                        validity_id: data.vehicle.validity_id || ''
                    } : undefined,
                    vehicle_record_id: data.vehicle?.vehicle_record_id || null,
                    append_vehicle: Boolean(data.vehicle?.append_vehicle),
                    new_vehicle_flow: true,
                    step3: data.installation || {},
                    step4: data.payment_part1 ? {
                        totalSaleAmount: data.payment_part1.totalSaleAmount ?? data.payment_part1.total_sale_amount,
                        transactionId: data.payment_part1.transactionId || data.payment_part1.transaction_id || '',
                        paymentMode: data.payment_part1.paymentMode || data.payment_part1.payment_mode || ''
                    } : {},
                    payment_part2: data.payment_part2 || null
                }
                : {
                    ...data,
                    draft_id: Number(item.id),
                    existing_customer_id: item.existing_customer_id || null,
                    customer_id: item.existing_customer_id || -Number(item.id),
                    new_vehicle_flow: true
                };
            const customerRouteId = Number(item.existing_customer_id || 0) || -Number(item.id);
            sessionStorage.setItem(`customer_creation_${customerRouteId}`, JSON.stringify(sessionData));
            const correctionRoutes = {
                1: `/customer-management/details/${customerRouteId}`,
                2: `/customer-management/details/vehicle/${customerRouteId}`,
                3: `/customer-management/details/installation/${customerRouteId}`,
                4: `/customer-management/details/payment/${customerRouteId}`,
                5: `/customer-management/details/payment-details/${customerRouteId}`
            };
            navigate(correctionRoutes[correctionStep]);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to resume submission.');
        }
    };

    const viewDetails = async (id) => {
        try {
            setError('');
            const response = await api.get('/customers/verification/own_details.php', { params: { id } });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Failed to load submission details.');
            }
            setSelected(response.data.data?.submission || null);
        } catch (loadError) {
            setError(loadError.response?.data?.message || loadError.message || 'Failed to load submission details.');
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h1>My Verification Requests</h1>
                    <p>Review your customer and courier verification requests.</p>
                </div>
                <button type="button" className="btn btn-secondary" onClick={loadSubmissions} disabled={loading}>
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>
            {error && <div className="alert alert-danger">{error}</div>}
            <div className="table-responsive">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>Request Type</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th>Location</th>
                            <th>Payment Status</th>
                            <th>Submitted At</th>
                            <th>Verification Status</th>
                            <th>Rejection Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {submissions.map((item) => (
                            <tr key={item.id}>
                                <td>{item.submission_type || 'Customer Payment'}</td>
                                <td>{item.customer_name}</td>
                                <td>{item.primary_mobile_no || '—'}</td>
                                <td>{item.location || '—'}</td>
                                <td>{item.payment_status || '—'}</td>
                                <td>{formatDate(item.submitted_at)}</td>
                                <td>{item.status}</td>
                                <td>{item.rejection_reason || '—'}</td>
                                <td>
                                    <div className="my-verification-actions">
                                    {item.status === 'Draft' ? (
                                        <button type="button" className="btn btn-secondary" onClick={() => resumeDraft(item.id)}>
                                            <RotateCcw size={16} /> Continue
                                        </button>
                                    ) : (
                                        <>
                                            <button type="button" className="btn btn-secondary" onClick={() => viewDetails(item.id)}>
                                                <Eye size={16} /> View Details
                                            </button>
                                            {item.status === 'Rejected' && item.submission_type !== 'Courier Request' && (
                                                <button type="button" className="btn btn-secondary" onClick={() => resumeDraft(item.id)}>
                                                    <RotateCcw size={16} /> Correct &amp; Resubmit
                                                </button>
                                            )}
                                            {item.status === 'Rejected' && item.submission_type === 'Courier Request' && (
                                                <span>Submit a new courier request with corrected information.</span>
                                            )}
                                        </>
                                    )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {!loading && submissions.length === 0 && <tr><td colSpan="9">No verification requests yet.</td></tr>}
                    </tbody>
                </table>
            </div>
            <Pagination
                currentPage={page}
                pageSize={pageSize}
                totalItems={pagination.total}
                onPageChange={setPage}
                onPageSizeChange={updatePageSize}
                itemName="verification requests"
            />
            <Modal
                isOpen={Boolean(selected)}
                onClose={() => setSelected(null)}
                title="Verification Request Details"
                maxWidth="1000px"
                footer={<button type="button" className="btn btn-secondary" onClick={() => setSelected(null)}>Close</button>}
            >
                {selected && (
                    <div className="verification-details">
                        <div className="verification-meta">
                            <span><strong>Status:</strong> {selected.status}</span>
                            <span><strong>Submitted At:</strong> {formatDate(selected.submitted_at)}</span>
                            {selected.decided_at && <span><strong>Reviewed At:</strong> {formatDate(selected.decided_at)}</span>}
                            {selected.rejection_reason && (
                                <div className="my-verification-rejection">
                                    <strong>Rejection reason</strong>
                                    <p>{selected.rejection_reason}</p>
                                    {selected.status === 'Rejected' && (
                                        <span>Correct the submitted details and resubmit this request.</span>
                                    )}
                                </div>
                            )}
                        </div>
                        {detailSections.map(([key, title]) => {
                            const sectionData = getSectionData(selected.submission_data || {}, key);
                            if (!sectionData) return null;
                            const entries = Array.isArray(sectionData) ? sectionData : [sectionData];
                            return (
                                <section className="verification-section" key={key}>
                                    <h3>{title}</h3>
                                    {entries.map((entry, index) => (
                                        <dl key={`${key}-${index}`}>
                                            {Object.entries(entry).map(([field, value]) => (
                                                <div key={field}>
                                                    <dt>{field.replaceAll('_', ' ')}</dt>
                                                    <dd>{formatValue(value)}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    ))}
                                </section>
                            );
                        })}
                    </div>
                )}
            </Modal>
        </div>
    );
};

export default MyCustomerVerificationsPage;
