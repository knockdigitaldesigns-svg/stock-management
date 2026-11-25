import { useEffect, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import PersonnelCredentialNotice from '../../../components/PersonnelCredentialNotice';
import { showGlobalError } from '../../../context/ErrorContext';

const EditTechnicianModal = ({ technician, onClose, onSuccess }) => {
    const getStatusLabel = (item) => item?.status_label || item?.sim_status || (item?.status === 'used' ? 'Used for Customer' : item?.status || 'Allocated');
    const [formData, setFormData] = useState({
        technician_name: technician?.technician_name || '',
        mobile_no: technician?.mobile_no || '',
        alternate_mobile_no: technician?.alternate_mobile_no || '',
        location: technician?.location || '',
        installation_service_areas: technician?.installation_service_areas || '',
        enrolled_date: technician?.enrolled_date || '',
        status: technician?.status || 'active',
        notes: technician?.notes || ''
    });
    const [loading, setLoading] = useState(false);
    const [accountLoading, setAccountLoading] = useState(false);
    const [error, setError] = useState('');
    const [accountPassword, setAccountPassword] = useState('');
    const [confirmAccountPassword, setConfirmAccountPassword] = useState('');
    const [showAccountPassword, setShowAccountPassword] = useState(false);
    const [showConfirmAccountPassword, setShowConfirmAccountPassword] = useState(false);
    const [credentials, setCredentials] = useState(null);
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

    const createLoginAccount = async () => {
        setError('');
        if (accountPassword.length < 8) {
            return triggerError('Password must be at least 8 characters long.');
        }
        if (accountPassword !== confirmAccountPassword) {
            return triggerError('Passwords do not match.');
        }
        if (!/^[6-9][0-9]{9}$/.test(String(technician?.mobile_no || ''))) {
            return triggerError('The saved technician mobile number must be a valid 10-digit mobile number.');
        }

        setAccountLoading(true);
        try {
            const response = await api.post('/technicians/create.php', {
                link_existing_id: Number(technician.id),
                password: accountPassword,
                confirm_password: confirmAccountPassword
            });
            if (!response.data.success) {
                throw new Error(response.data.message || 'Unable to create technician login account.');
            }
            setCredentials({
                username: response.data.data?.user?.username || String(technician.technician_name || '').trim().replace(/\s+/g, '').toLowerCase(),
                role: response.data.data?.user?.role || 'Technician',
                password: accountPassword
            });
        } catch (accountError) {
            triggerError(accountError.response?.data?.message || accountError.message || 'Unable to create technician login account.');
        } finally {
            setAccountLoading(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(technician)}
            onClose={credentials ? onSuccess : onClose}
            title={credentials ? 'Technician Login Created' : 'Edit Technician'}
            maxWidth="560px"
            footer={credentials ? null : (
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading || accountLoading}>Cancel</button>
                    <button type="submit" form="edit-tech-form" className="btn btn-primary" disabled={loading || accountLoading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            )}
        >
            {credentials ? (
                <PersonnelCredentialNotice credentials={credentials} onDone={onSuccess} />
            ) : (
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
                    <label className="form-label">Installation Service Areas</label>
                    <textarea className="form-control" rows="2" value={formData.installation_service_areas} onChange={(e) => setFormData((prev) => ({ ...prev, installation_service_areas: e.target.value }))} placeholder="Coimbatore, Pollachi, Mettupalayam" />
                    <small>Enter only cities/areas this technician explicitly serves, separated by commas or new lines.</small>
                </div>

                <div className="form-group">
                    <label className="form-label">Enrolled Date *</label>
                    <DateInput className="form-control" value={formData.enrolled_date} onChange={(value) => setFormData((prev) => ({ ...prev, enrolled_date: value }))} required />
                </div>
                <div className="form-group">
                    <label className="form-label">Status *</label>
                    <select className="form-control" value={formData.status} onChange={(event) => setFormData((prev) => ({ ...prev, status: event.target.value }))} required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <hr />
                <h4>Login Account</h4>
                {technician?.user_id ? (
                    <p>This technician is linked to login account #{technician.user_id}. Ordinary profile updates do not change the linked account.</p>
                ) : (
                    <>
                        <p>Create a separate account using the saved employee name and mobile number. The Technician role is assigned automatically.</p>
                        <div className="form-group">
                            <label className="form-label">Employee Name</label>
                            <input className="form-control" value={technician?.technician_name || ''} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Mobile Number</label>
                            <input className="form-control" value={technician?.mobile_no || ''} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Generated Username</label>
                            <input className="form-control" value={String(technician?.technician_name || '').trim().replace(/\s+/g, '').toLowerCase()} readOnly />
                        </div>
                        <div className="form-group">
                            <label className="form-label">Password *</label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input type={showAccountPassword ? 'text' : 'password'} className="form-control" autoComplete="new-password" value={accountPassword} onChange={(event) => setAccountPassword(event.target.value)} />
                                <button type="button" className="btn btn-outline" aria-label={showAccountPassword ? 'Hide password' : 'Show password'} onClick={() => setShowAccountPassword((visible) => !visible)}>
                                    {showAccountPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
                        </div>
                        <div className="form-group">
                            <label className="form-label">Confirm Password *</label>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <input type={showConfirmAccountPassword ? 'text' : 'password'} className="form-control" autoComplete="new-password" value={confirmAccountPassword} onChange={(event) => setConfirmAccountPassword(event.target.value)} />
                                <button type="button" className="btn btn-outline" aria-label={showConfirmAccountPassword ? 'Hide confirmation password' : 'Show confirmation password'} onClick={() => setShowConfirmAccountPassword((visible) => !visible)}>
                                    {showConfirmAccountPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                                </button>
                            </div>
                        </div>
                        <button type="button" className="btn btn-outline" onClick={createLoginAccount} disabled={loading || accountLoading}>
                            {accountLoading ? 'Creating Login...' : 'Create Login Account'}
                        </button>
                    </>
                )}

                <hr />
                <h4>Technician Information</h4>
                <div className="form-group"><label className="form-label">Payment Status</label><input className="form-control" value={technician?.payment_status || 'Not Paid'} readOnly /></div>
                <hr />
                <h4>Allocated Devices (Total: {stockSummary.total_device || 0}, Used: {stockSummary.used_device || 0}, Available: {stockSummary.available_device || 0})</h4>
                {stockLoading ? <p>Loading allocated devices...</p> : allocatedStock.devices.length === 0 ? <p>No devices currently allocated.</p> : <div className="table-container"><table><thead><tr><th>Device Model</th><th>IMEI No</th><th>Software</th><th>Device Status</th></tr></thead><tbody>{allocatedStock.devices.map((item) => <tr key={item.allocation_id || item.id}><td>{item.model_name}</td><td>{item.imei_no}</td><td>{item.software || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
                <h4>Allocated SIMs (Total: {stockSummary.total_sim || 0}, Used: {stockSummary.used_sim || 0}, Available: {stockSummary.available_sim || 0})</h4>
                {stockLoading ? <p>Loading allocated SIMs...</p> : allocatedStock.sims.length === 0 ? <p>No SIMs currently allocated.</p> : <div className="table-container"><table><thead><tr><th>SIM No</th><th>SIM Type</th><th>SIM Status</th></tr></thead><tbody>{allocatedStock.sims.map((item) => <tr key={item.allocation_id || item.id}><td>{item.sim_no}</td><td>{item.sim_type || '-'}</td><td>{getStatusLabel(item)}</td></tr>)}</tbody></table></div>}
            </form>
            )}
        </Modal>
    );
};

export default EditTechnicianModal;
