import { useEffect, useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { SOFTWARE_OPTIONS } from '../../../constants/software';
import { showGlobalError } from '../../../context/ErrorContext';

const EditDealerModal = ({ dealer, onClose, onSuccess }) => {
    const getStatusLabel = (item) => item?.status_label || item?.sim_status || (item?.status === 'used' ? 'Used for Customer' : item?.status || 'Allocated');
    
    const initialSoftware = Array.isArray(dealer?.software_list) && dealer.software_list.length > 0
        ? dealer.software_list
        : (dealer?.software ? dealer.software.split(',').map(s => s.trim()).filter(Boolean) : []);

    const [formData, setFormData] = useState({
        dealer_name: dealer?.dealer_name || '',
        mobile_no: dealer?.mobile_no || '',
        location: dealer?.location || '',
        enrolled_date: dealer?.enrolled_date || '',
        installation_status: dealer?.installation_status || 'Onsite',
        threshold_amount: dealer?.threshold_amount ?? '',
        notes: dealer?.notes || '',
        software: initialSoftware
    });
    const [softwareOpen, setSoftwareOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [allocatedStock, setAllocatedStock] = useState({ devices: [], sims: [] });
    const [stockSummary, setStockSummary] = useState({ total_device: 0, used_device: 0, available_device: 0, total_sim: 0, used_sim: 0, available_sim: 0 });
    const [stockLoading, setStockLoading] = useState(true);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const handleMobileChange = (e) => {
        const val = e.target.value.replace(/\D/g, '').slice(0, 10);
        setFormData(prev => ({ ...prev, mobile_no: val }));
    };

    const toggleSoftware = (opt) => {
        setFormData(prev => {
            const current = prev.software || [];
            const updated = current.includes(opt)
                ? current.filter(s => s !== opt)
                : [...current, opt];
            return { ...prev, software: updated };
        });
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

        if (formData.mobile_no.length !== 10) {
            return triggerError('Mobile Number must be exactly 10 digits.');
        }

        if (formData.threshold_amount !== '' && formData.threshold_amount !== null && formData.threshold_amount !== undefined) {
            const num = Number(formData.threshold_amount);
            if (isNaN(num) || num < 0) {
                return triggerError('Threshold Amount must be a valid non-negative number.');
            }
        }

        setLoading(true);
        try {
            const payload = {
                id: dealer.id,
                ...formData,
                threshold_amount: formData.threshold_amount !== '' ? formData.threshold_amount : null
            };
            const response = await api.post('/dealers/update.php', payload);

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
                {error && <div className="alert alert-danger" style={{ marginBottom: '1rem' }}>{error}</div>}

                <div className="form-group">
                    <label className="form-label">Dealer Name *</label>
                    <input type="text" className="form-control" value={formData.dealer_name} onChange={(e) => setFormData((prev) => ({ ...prev, dealer_name: e.target.value }))} required />
                </div>
                <div className="form-group">
                    <label className="form-label">Notes</label>
                    <textarea className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={(e) => setFormData((prev) => ({ ...prev, notes: e.target.value }))} />
                </div>

                <div className="form-group">
                    <label className="form-label">Mobile No * (10 digits)</label>
                    <input type="text" className="form-control" value={formData.mobile_no} onChange={handleMobileChange} maxLength={10} placeholder="Enter 10 digit mobile number" required />
                </div>

                <div className="form-group">
                    <label className="form-label">Location *</label>
                    <input type="text" className="form-control" value={formData.location} onChange={(e) => setFormData((prev) => ({ ...prev, location: e.target.value }))} required />
                </div>

                <div className="form-group">
                    <label className="form-label">Enrolled Date *</label>
                    <DateInput className="form-control" value={formData.enrolled_date} onChange={(value) => setFormData((prev) => ({ ...prev, enrolled_date: value }))} required />
                </div>

                <div className="form-group" style={{ position: 'relative' }}>
                    <label className="form-label">Software</label>
                    <div 
                        className="form-control" 
                        onClick={() => setSoftwareOpen(!softwareOpen)}
                        style={{ cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', minHeight: '38px', userSelect: 'none' }}
                    >
                        <span style={{ color: (formData.software && formData.software.length > 0) ? 'inherit' : '#94a3b8' }}>
                            {(formData.software && formData.software.length > 0) ? formData.software.join(', ') : 'Select software'}
                        </span>
                        <span>{softwareOpen ? '▲' : '▼'}</span>
                    </div>
                    {softwareOpen && (
                        <div style={{
                            position: 'absolute',
                            top: '100%',
                            left: 0,
                            right: 0,
                            zIndex: 30,
                            background: '#fff',
                            border: '1px solid var(--border-color, #cbd5e1)',
                            borderRadius: '0.375rem',
                            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                            maxHeight: '180px',
                            overflowY: 'auto',
                            padding: '0.5rem'
                        }}>
                            {SOFTWARE_OPTIONS.map((opt) => (
                                <label key={opt} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', padding: '0.35rem 0.5rem', cursor: 'pointer', fontSize: '0.875rem' }}>
                                    <input
                                        type="checkbox"
                                        checked={(formData.software || []).includes(opt)}
                                        onChange={() => toggleSoftware(opt)}
                                    />
                                    {opt}
                                </label>
                            ))}
                        </div>
                    )}
                </div>

                <div className="form-group">
                    <label className="form-label">Installation Status *</label>
                    <select className="form-control" value={formData.installation_status} onChange={(e) => {
                        const val = e.target.value;
                        setFormData((prev) => ({
                            ...prev,
                            installation_status: val
                        }));
                    }} required>
                        <option value="Onsite">Onsite</option>
                        <option value="Offsite">Offsite</option>
                        <option value="Not Willing">Not Willing</option>
                    </select>
                </div>

                <div className="form-group">
                    <label className="form-label">Threshold Amount (₹)</label>
                    <input 
                        type="number" 
                        name="threshold_amount"
                        className="form-control"
                        placeholder="Enter threshold amount (e.g. 5000)"
                        value={formData.threshold_amount}
                        onChange={(e) => setFormData((prev) => ({ ...prev, threshold_amount: e.target.value }))}
                        min="0"
                        step="any"
                    />
                </div>

                <hr />
                <h4>Dealer Information</h4>
                <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={dealer?.total_amount > 0 ? dealer?.payment_status : 'No Payment Required'} readOnly /></div>
                <hr />
                <h4>Allocated Devices (Total: {stockSummary.total_device || 0}, Used: {stockSummary.used_device || 0}, Available: {stockSummary.available_device || 0})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI No</th><th>Software</th><th>Device Status</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.allocation_id || item.id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{item.software || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs (Total: {stockSummary.total_sim || 0}, Used: {stockSummary.used_sim || 0}, Available: {stockSummary.available_sim || 0})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM No</th><th>SIM Type</th><th>SIM Status</th></tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.allocation_id || item.id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
            </form>
        </Modal>
    );
};

export default EditDealerModal;
