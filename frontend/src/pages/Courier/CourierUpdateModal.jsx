import { useState, useEffect } from 'react';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { showGlobalError } from '../../context/ErrorContext';

const CourierUpdateModal = ({ isOpen, onClose, onSuccess, courierData }) => {
    const courierSendViaOptions = [
        'St Courier',
        'Professional',
        'Delhivery',
        'DTDC',
        'Trackon'
    ];

    const courierStatusOptions = [
        'Pending',
        'Reached',
        'Not Reached'
    ];

    const [courierSendVia, setCourierSendVia] = useState('');
    const [trackingId, setTrackingId]         = useState('');
    const [courierStatus, setCourierStatus]   = useState('Pending');
    const [courierReason, setCourierReason]   = useState('');
    const [saving, setSaving]                 = useState(false);
    const [error, setError]                   = useState('');

    useEffect(() => {
        if (isOpen && courierData) {
            // Normalize ST Courier to St Courier if needed
            let initialSendVia = courierData.courier_send_via || '';
            if (initialSendVia.toUpperCase() === 'ST COURIER') {
                initialSendVia = 'St Courier';
            }
            setCourierSendVia(initialSendVia);
            setTrackingId(courierData.tracking_id || '');
            setCourierStatus(courierData.courier_status || 'Pending');
            setCourierReason(courierData.courier_reason || '');
            setError('');
        }
    }, [isOpen, courierData]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!courierSendVia) {
            const msg = 'Please select Courier Send Via.';
            setError(msg);
            showGlobalError(msg);
            return;
        }

        if (!courierStatus) {
            const msg = 'Please select Courier Status.';
            setError(msg);
            showGlobalError(msg);
            return;
        }

        if (courierStatus === 'Not Reached' && !courierReason.trim()) {
            const msg = 'Please enter a Reason for Not Reached status.';
            setError(msg);
            showGlobalError(msg);
            return;
        }

        setSaving(true);
        try {
            const payload = {
                id: courierData.id,
                courier_send_via: courierSendVia,
                tracking_id: trackingId.trim(),
                courier_status: courierStatus,
                courier_reason: courierStatus === 'Not Reached' ? courierReason.trim() : ''
            };

            const res = await api.post('/courier/update_courier.php', payload);
            if (res.data?.success) {
                onClose();
                if (onSuccess) onSuccess();
            } else {
                const msg = res.data?.message || 'Failed to update courier tracking details.';
                setError(msg);
                showGlobalError(msg);
            }
        } catch (err) {
            const msg = err.response?.data?.message || 'An error occurred while updating courier.';
            setError(msg);
            showGlobalError(msg);
        } finally {
            setSaving(false);
        }
    };

    if (!isOpen || !courierData) return null;

    const recipientName = courierData.recipient_name || courierData.dealer_name || courierData.technician_name || courierData.customer_name || 'N/A';

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`Courier Update - ${courierData.request_code || 'CR-' + courierData.id}`}
            maxWidth="600px"
        >
            <form onSubmit={handleSubmit} className="courier-update-form">
                
                {/* Summary Context Bar */}
                <div style={{
                    padding: '0.75rem 1rem',
                    marginBottom: '1.25rem',
                    background: '#f8fafc',
                    borderRadius: '8px',
                    border: '1px solid #e2e8f0',
                    fontSize: '0.875rem'
                }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
                        <span><strong>Recipient:</strong> {recipientName} ({courierData.courier_to_person || 'Dealer'})</span>
                        <span>
                            <strong style={{ marginRight: '4px' }}>Approval:</strong>
                            <span className={`badge ${
                                courierData.approval_status === 'Approved' ? 'badge-success' :
                                courierData.approval_status === 'Rejected' ? 'badge-danger' :
                                'badge-warning'
                            }`}>
                                {courierData.approval_status || 'Pending Approval'}
                            </span>
                        </span>
                    </div>
                    <div>
                        <span><strong>Asset:</strong> {courierData.asset_type ? courierData.asset_type.toUpperCase() : 'DEVICE'}</span>
                        {courierData.imei_no && <span style={{ marginLeft: '1rem' }}><strong>IMEI:</strong> {courierData.imei_no}</span>}
                        {courierData.sim_no && <span style={{ marginLeft: '1rem' }}><strong>SIM:</strong> {courierData.sim_no}</span>}
                    </div>
                </div>

                {error && <div className="alert alert-danger mb-3">{error}</div>}

                {/* 1. Courier Send Via * */}
                <div className="form-group mb-3">
                    <label className="form-label font-weight-bold" htmlFor="courier-send-via-select">
                        Courier Send Via <span className="text-danger">*</span>
                    </label>
                    <select
                        id="courier-send-via-select"
                        className="form-control"
                        value={courierSendVia}
                        onChange={(e) => setCourierSendVia(e.target.value)}
                        disabled={saving}
                        required
                    >
                        <option value="">Select Courier Send Via</option>
                        {courierSendViaOptions.map((opt) => (
                            <option key={opt} value={opt}>
                                {opt}
                            </option>
                        ))}
                    </select>
                </div>

                {/* 2. Tracking ID / Courier ID */}
                <div className="form-group mb-3">
                    <label className="form-label font-weight-bold" htmlFor="tracking-id-input">
                        Tracking ID / Courier ID
                    </label>
                    <input
                        id="tracking-id-input"
                        type="text"
                        className="form-control"
                        placeholder="Enter Tracking ID / Courier ID..."
                        value={trackingId}
                        onChange={(e) => setTrackingId(e.target.value)}
                        disabled={saving}
                    />
                </div>

                {/* 3. Courier Status * */}
                <div className="form-group mb-3">
                    <label className="form-label font-weight-bold" htmlFor="courier-status-select">
                        Courier Status <span className="text-danger">*</span>
                    </label>
                    <select
                        id="courier-status-select"
                        className="form-control"
                        value={courierStatus}
                        onChange={(e) => setCourierStatus(e.target.value)}
                        disabled={saving}
                        required
                    >
                        {courierStatusOptions.map((opt) => (
                            <option key={opt} value={opt}>
                                {opt}
                            </option>
                        ))}
                    </select>
                </div>

                {/* 4. Reason (If Courier Status = Not Reached) */}
                {courierStatus === 'Not Reached' && (
                    <div className="form-group mb-3">
                        <label className="form-label font-weight-bold" htmlFor="courier-reason-input">
                            Reason <span className="text-danger">*</span>
                        </label>
                        <textarea
                            id="courier-reason-input"
                            className="form-control"
                            rows="3"
                            placeholder="Enter reason why courier was not reached (e.g., Wrong Address, In Transit, Refused)..."
                            value={courierReason}
                            onChange={(e) => setCourierReason(e.target.value)}
                            disabled={saving}
                            required
                        />
                    </div>
                )}

                {/* Modal Footer / Actions */}
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem', marginTop: '1.5rem' }}>
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={onClose}
                        disabled={saving}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={saving || !courierSendVia || !courierStatus || (courierStatus === 'Not Reached' && !courierReason.trim())}
                    >
                        {saving ? 'Updating...' : 'Update Courier'}
                    </button>
                </div>

            </form>
        </Modal>
    );
};

export default CourierUpdateModal;
