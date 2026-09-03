import { useEffect, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { formatDate } from '../../../utils/date';
import { SOFTWARE_OPTIONS } from '../../../constants/software';

const EditDealerModal = ({ dealer, onClose, onSuccess }) => {
    const [formData, setFormData] = useState({
        dealer_name: dealer?.dealer_name || '',
        mobile_no: dealer?.mobile_no || '',
        location: dealer?.location || '',
        enrolled_date: dealer?.enrolled_date || '',
        installation_status: dealer?.installation_status || 'Onsite',
        notes: dealer?.notes || '', software: dealer?.software || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [allocatedStock, setAllocatedStock] = useState({ devices: [], sims: [] });
    const [stockLoading, setStockLoading] = useState(true);

    useEffect(() => {
        let active = true;
        api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer?.id}`)
            .then((response) => {
                if (active && response.data.success) setAllocatedStock(response.data.data || { devices: [], sims: [] });
            })
            .catch(() => { if (active) setError('Unable to load allocated stock.'); })
            .finally(() => { if (active) setStockLoading(false); });
        return () => { active = false; };
    }, [dealer?.id]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.dealer_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            return setError('All required fields must be filled.');
        }

        if (!/^[0-9+\s-]{10,15}$/.test(formData.mobile_no)) {
            return setError('Mobile number is invalid.');
        }

        setLoading(true);
        try {
            const response = await api.post('/dealers/update.php', {
                id: dealer.id,
                ...formData
            });

            if (response.data.success) {
                onSuccess();
            } else {
                setError(response.data.message || 'Unable to update dealer.');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update dealer. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(dealer)}
            onClose={onClose}
            title="Edit Dealer"
            maxWidth="560px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="edit-dealer-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            }
        >
            <form id="edit-dealer-form" onSubmit={handleSubmit}>
                {error && <div className="alert alert-danger">{error}</div>}

                <div className="form-group">
                    <label className="form-label">Dealer Name *</label>
                    <input type="text" className="form-control" value={formData.dealer_name} onChange={(e) => setFormData((prev) => ({ ...prev, dealer_name: e.target.value }))} required />
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

                <div className="form-group">
                    <label className="form-label">Software</label><select className="form-control" value={formData.software} onChange={(e) => setFormData((prev) => ({ ...prev, software: e.target.value }))}><option value="">Select software</option>{SOFTWARE_OPTIONS.map((option) => <option key={option}>{option}</option>)}</select>
                </div><div className="form-group">
                    <label className="form-label">Installation Status *</label>
                    <select className="form-control" value={formData.installation_status} onChange={(e) => setFormData((prev) => ({ ...prev, installation_status: e.target.value }))} required>
                        <option value="Onsite">Onsite</option>
                        <option value="Offsite">Offsite</option>
                        <option value="Not Willing">Not Willing</option>
                    </select>
                </div>

                <hr />
                <h4>Dealer Information</h4>
                <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={dealer?.total_amount > 0 ? dealer?.payment_status : 'No Payment Required'} readOnly /></div>
                <hr />
                <h4>Allocated Devices ({allocatedStock.devices.length})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI Number</th><th>Software</th><th>Total</th><th>Paid</th><th>Pending</th><th>Status</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.allocation_id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{item.software || '-'}</td><td>₹{Number(item.total_amount).toFixed(2)}</td><td>₹{Number(item.amount_paid).toFixed(2)}</td><td>₹{Number(item.pending_amount).toFixed(2)}</td><td>{item.payment_status}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs ({allocatedStock.sims.length})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM Number</th><th>Type</th><th>Software</th><th>Total</th><th>Paid</th><th>Pending</th><th>Status</th></tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.allocation_id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{item.software || '-'}</td><td>₹{Number(item.total_amount).toFixed(2)}</td><td>₹{Number(item.amount_paid).toFixed(2)}</td><td>₹{Number(item.pending_amount).toFixed(2)}</td><td>{item.payment_status}</td></tr>)}</tbody></table></div>}
            </form>
        </Modal>
    );
};

export default EditDealerModal;
