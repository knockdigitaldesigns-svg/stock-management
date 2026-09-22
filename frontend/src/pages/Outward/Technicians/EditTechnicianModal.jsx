import { useEffect, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { showGlobalError } from '../../../context/ErrorContext';

const EditTechnicianModal = ({ technician, onClose, onSuccess }) => {
    const getStatusLabel = (item) => item?.status_label || item?.sim_status || (item?.status === 'used' ? 'Used for Customer' : item?.status || 'Allocated');
    const [formData, setFormData] = useState({
        technician_name: technician?.technician_name || '',
        mobile_no: technician?.mobile_no || '',
        alternate_mobile_no: technician?.alternate_mobile_no || '',
        location: technician?.location || '',
        enrolled_date: technician?.enrolled_date || '',
        notes: technician?.notes || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [allocatedStock, setAllocatedStock] = useState({ devices: [], sims: [] });
    const [stockSummary, setStockSummary] = useState({ total_device: 0, used_device: 0, available_device: 0, total_sim: 0, used_sim: 0, available_sim: 0 });
    const [stockLoading, setStockLoading] = useState(true);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    useEffect(() => {
        let active = true;
        api.get(`/stock/owner_details.php?owner_type=technician&owner_id=${technician?.id}`)
            .then((response) => {
                if (active && response.data.success) {
                    setAllocatedStock(response.data.data || { devices: [], sims: [] });
                    if (response.data.data?.summary) {
                        setStockSummary(response.data.data.summary);
                    }
                }
            })
            .catch(() => { if (active) triggerError('Unable to load allocated stock.'); })
            .finally(() => { if (active) setStockLoading(false); });
        return () => { active = false; };
    }, [technician?.id]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.technician_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            return triggerError('All required fields must be filled.');
        }

        const digitsOnly = formData.mobile_no.replace(/\D/g, '');
        if (digitsOnly.length !== 10 || digitsOnly !== formData.mobile_no) {
            return triggerError('Mobile number must contain exactly 10 digits.');
        }

        if (formData.alternate_mobile_no && (formData.alternate_mobile_no.length !== 10 || !/^\d{10}$/.test(formData.alternate_mobile_no))) {
            return triggerError('Alternate mobile number must contain exactly 10 digits.');
        }

        setLoading(true);
        try {
            const response = await api.post('/technicians/update.php', {
                id: technician.id,
                ...formData
            });

            if (response.data.success) {
                onSuccess();
            } else {
                triggerError(response.data.message || 'Unable to update technician.');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to update technician. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(technician)}
            onClose={onClose}
            title="Edit Technician"
            maxWidth="560px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="edit-tech-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            }
        >
            <form id="edit-tech-form" onSubmit={handleSubmit}>
                {error && <div className="alert alert-danger">{error}</div>}

                <div className="form-group">
                    <label className="form-label">Technician Name *</label>
                    <input type="text" className="form-control" value={formData.technician_name} onChange={(e) => setFormData((prev) => ({ ...prev, technician_name: e.target.value }))} required />
                </div>
                <div className="form-group">
                    <label className="form-label">Notes</label>
                    <textarea className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={(e) => setFormData((prev) => ({ ...prev, notes: e.target.value }))} />
                </div>

                <div className="form-group">
                    <label className="form-label">Mobile No *</label>
                    <input type="text" className="form-control" value={formData.mobile_no} onChange={(e) => { const val = e.target.value.replace(/\D/g, '').slice(0, 10); setFormData((prev) => ({ ...prev, mobile_no: val })); }} maxLength={10} placeholder="Enter 10 digit mobile no" required />
                </div>

                <div className="form-group">
                    <label className="form-label">Alternate Mobile No</label>
                    <input type="text" className="form-control" value={formData.alternate_mobile_no} onChange={(e) => { const val = e.target.value.replace(/\D/g, '').slice(0, 10); setFormData((prev) => ({ ...prev, alternate_mobile_no: val })); }} maxLength={10} placeholder="Enter 10 digit mobile no" />
                </div>

                <div className="form-group">
                    <label className="form-label">Location *</label>
                    <input type="text" className="form-control" value={formData.location} onChange={(e) => setFormData((prev) => ({ ...prev, location: e.target.value }))} required />
                </div>

                <div className="form-group">
                    <label className="form-label">Enrolled Date *</label>
                    <DateInput className="form-control" value={formData.enrolled_date} onChange={(value) => setFormData((prev) => ({ ...prev, enrolled_date: value }))} required />
                </div>

                <hr />
                <h4>Technician Information</h4>
                <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={technician?.payment_status || 'Not Paid'} readOnly /></div>
                <hr />
                <h4>Allocated Devices (Total: {stockSummary.total_device || 0}, Used: {stockSummary.used_device || 0}, Available: {stockSummary.available_device || 0})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI No</th><th>Software</th><th>Device Status</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.allocation_id || item.id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{item.software || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs (Total: {stockSummary.total_sim || 0}, Used: {stockSummary.used_sim || 0}, Available: {stockSummary.available_sim || 0})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM No</th><th>SIM Type</th><th>SIM Status</th></tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.allocation_id || item.id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
            </form>
        </Modal>
    );
};

export default EditTechnicianModal;
