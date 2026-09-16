import { useEffect, useState } from 'react';
import { Edit } from 'lucide-react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { formatDate } from '../../../utils/date';
import { SOFTWARE_OPTIONS } from '../../../constants/software';
import { showGlobalError } from '../../../context/ErrorContext';
import StockAllocationEditModal from '../../StockManagement/StockAllocationEditModal';

const EditDealerModal = ({ dealer, onClose, onSuccess }) => {
    const getStatusLabel = (item) => item?.status_label || (item?.status === 'used' ? 'Used for Customer' : item?.status || 'Allocated');
    const [formData, setFormData] = useState({
        dealer_name: dealer?.dealer_name || '',
        mobile_no: dealer?.mobile_no || '',
        location: dealer?.location || '',
        enrolled_date: dealer?.enrolled_date || '',
        installation_status: dealer?.installation_status || 'Onsite',
        notes: dealer?.notes || '',
        software: dealer?.software || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [allocatedStock, setAllocatedStock] = useState({ devices: [], sims: [] });
    const [stockSummary, setStockSummary] = useState({ total_device: 0, used_device: 0, available_device: 0, total_sim: 0, used_sim: 0, available_sim: 0 });
    const [stockLoading, setStockLoading] = useState(true);
    const [editingAllocationId, setEditingAllocationId] = useState(null);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    useEffect(() => {
        let active = true;
        api.get(`/stock/owner_details.php?owner_type=dealer&owner_id=${dealer?.id}`)
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
    }, [dealer?.id]);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.dealer_name || !formData.mobile_no || !formData.location || !formData.enrolled_date) {
            return triggerError('All required fields must be filled.');
        }

        if (!/^[0-9+\s-]{10,15}$/.test(formData.mobile_no)) {
            return triggerError('Mobile number is invalid.');
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
                triggerError(response.data.message || 'Unable to update dealer.');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to update dealer. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
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
                <div className="form-group">
                    <label className="form-label">Dealer Name *</label>
                    <input type="text" className="form-control" value={formData.dealer_name} onChange={(e) => setFormData((prev) => ({ ...prev, dealer_name: e.target.value }))} required />
                </div>
                <div className="form-group">
                    <label className="form-label">Notes</label>
                    <textarea className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={(e) => setFormData((prev) => ({ ...prev, notes: event.target.value }))} />
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
                    <label className="form-label">Software</label>
                    <select className="form-control" value={formData.software} onChange={(e) => setFormData((prev) => ({ ...prev, software: e.target.value }))}>
                        <option value="">Select software</option>
                        {SOFTWARE_OPTIONS.map((option) => <option key={option}>{option}</option>)}
                    </select>
                </div>
                <div className="form-group">
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
                <h4>Allocated Devices (Total: {stockSummary.total_device || 0}, Used: {stockSummary.used_device || 0}, Available: {stockSummary.available_device || 0})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI Number</th><th>Software</th><th>Total</th><th>Paid</th><th>Pending</th><th>Status</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.allocation_id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{item.software || '-'}</td><td>₹{Number(item.total_amount).toFixed(2)}</td><td>₹{Number(item.amount_paid).toFixed(2)}</td><td>₹{Number(item.pending_amount).toFixed(2)}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs (Total: {stockSummary.total_sim || 0}, Used: {stockSummary.used_sim || 0}, Available: {stockSummary.available_sim || 0})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM Number</th><th>SIM Type</th><th>Software</th><th>Given Date</th><th>Activation Date</th><th>Validity</th><th>Expiry / Renewal Date</th><th>Deactivation Date</th><th>Total</th><th>Paid</th><th>Pending</th><th>Status</th>{dealer?.installation_status === 'Not Willing' && <th>Actions</th>}</tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.allocation_id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{item.software || '-'}</td><td>{formatDate(item.given_date)}</td><td>{formatDate(item.activation_date)}</td><td>{item.sim_validity_months ? `${item.sim_validity_months} Months` : '-'}</td><td>{formatDate(item.expiry_date)}</td><td>{formatDate(item.deactivation_date)}</td><td>₹{Number(item.total_amount).toFixed(2)}</td><td>₹{Number(item.amount_paid).toFixed(2)}</td><td>₹{Number(item.pending_amount).toFixed(2)}</td><td>{getStatusLabel(item)}</td>{dealer?.installation_status === 'Not Willing' && <td><button type="button" className="icon-btn edit" aria-label="Edit SIM lifecycle" title="Edit activation date and SIM validity" onClick={() => setEditingAllocationId(item.allocation_id)}><Edit size={16} /></button></td>}</tr>)}</tbody></table></div>}
            </form>
        </Modal>
        {editingAllocationId && <StockAllocationEditModal allocationId={editingAllocationId} onClose={() => setEditingAllocationId(null)} onSuccess={() => { setEditingAllocationId(null); onSuccess(); }} />}
        </>
    );
};

export default EditDealerModal;
