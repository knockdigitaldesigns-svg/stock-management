import { useEffect, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { formatDate } from '../../../utils/date';

const EditTechnicianModal = ({ technician, onClose, onSuccess }) => {
    const [formData, setFormData] = useState({
        technician_name: technician?.technician_name || '',
        mobile_no: technician?.mobile_no || '',
        location: technician?.location || '',
        enrolled_date: technician?.enrolled_date || '',
        notes: technician?.notes || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [allocatedStock, setAllocatedStock] = useState({ devices: [], sims: [] });
    const [stockLoading, setStockLoading] = useState(true);

    useEffect(() => {
        let active = true;
        api.get(`/stock/owner_details.php?owner_type=technician&owner_id=${technician?.id}`)
            .then((response) => {
                if (active && response.data.success) setAllocatedStock(response.data.data || { devices: [], sims: [] });
            })
            .catch(() => { if (active) setError('Unable to load allocated stock.'); })
            .finally(() => { if (active) setStockLoading(false); });
        return () => { active = false; };
    }, [technician?.id]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.technician_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            return setError('All required fields must be filled.');
        }

        if (!/^[0-9+\s-]{10,15}$/.test(formData.mobile_no)) {
            return setError('Mobile number is invalid.');
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
                setError(response.data.message || 'Unable to update technician.');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update technician. Please try again.');
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
                    <input type="text" className="form-control" value={formData.mobile_no} onChange={(e) => setFormData((prev) => ({ ...prev, mobile_no: e.target.value }))} maxLength={15} required />
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
                <h4>Allocated Devices ({allocatedStock.devices.length})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI Number</th><th>Allocation Date</th><th>Status</th><th>Notes</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{formatDate(item.allocation_date)}</td><td>{item.status}</td><td>{item.notes || '-'}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs ({allocatedStock.sims.length})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM Number</th><th>SIM Type</th><th>SIM Validity</th><th>Allocation Date</th><th>Status</th><th>Notes</th></tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{item.sim_validity_months ? `${item.sim_validity_months} Months` : '-'}</td><td>{formatDate(item.allocation_date)}</td><td>{item.status}</td><td>{item.notes || '-'}</td></tr>)}</tbody></table></div>}
            </form>
        </Modal>
    );
};

export default EditTechnicianModal;
