import { useState, useEffect } from 'react';
import { Trash2 } from 'lucide-react';
import api from '../../../services/api';
import useDeviceModels from '../../../hooks/useDeviceModels';
import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import { showGlobalError } from '../../../context/ErrorContext';

const AddDeviceModal = ({ onClose, onSuccess }) => {
    const { models, loading: modelsLoading } = useDeviceModels();
    const [deviceCount, setDeviceCount] = useState(1);
    const [devices, setDevices] = useState([{ id: Date.now(), purchase_date: '', device_model_id: '', imei_no: '', notes: '' }]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        const count = parseInt(deviceCount) || 1;
        if (count > devices.length) {
            const newDevices = [...devices];
            for (let i = devices.length; i < count; i++) {
                const firstRow = devices[0] || {};
                newDevices.push({
                    id: Date.now() + i,
                    purchase_date: firstRow.purchase_date || '',
                    device_model_id: firstRow.device_model_id || '',
                    imei_no: '',
                    notes: ''
                });
            }
            setDevices(newDevices);
        } else if (count < devices.length && count > 0) {
            setDevices(devices.slice(0, count));
        }
    }, [deviceCount]);

    const handleDeviceChange = (id, field, value) => {
        setDevices(devices.map(d => d.id === id ? { ...d, [field]: value } : d));
    };

    const removeRow = (id) => {
        if (devices.length > 1) {
            setDevices(devices.filter(d => d.id !== id));
            setDeviceCount(prev => prev - 1);
        }
    };

    const validateForm = () => {
        const imeiRegex = /^[0-9]{15}$/;
        const seenImeis = new Set();

        for (let i = 0; i < devices.length; i++) {
            const row = devices[i];
            const rowNum = i + 1;
            
            if (!row.purchase_date) return `Row ${rowNum}: Purchase date is required`;
            if (!row.device_model_id) return `Row ${rowNum}: Device model is required`;
            
            if (!row.imei_no) return `Row ${rowNum}: IMEI number is required`;
            if (!imeiRegex.test(row.imei_no)) return `Row ${rowNum}: IMEI number must contain exactly 15 digits`;
            
            if (seenImeis.has(row.imei_no)) return `Row ${rowNum}: Duplicate IMEI number (${row.imei_no}) found in the form`;
            seenImeis.add(row.imei_no);
        }
        return null;
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        const validationError = validateForm();
        if (validationError) {
            setError(validationError);
            showGlobalError(validationError);
            return;
        }

        setLoading(true);
        try {
            const payload = {
                devices: devices.map(d => ({
                    purchase_date: d.purchase_date,
                    device_model_id: d.device_model_id,
                    imei_no: d.imei_no,
                    notes: d.notes
                }))
            };
            
            const response = await api.post('/devices/create.php', payload);
            if (response.data.success) {
                onSuccess();
            } else {
                const errMsg = response.data.message || 'Failed to save devices';
                setError(errMsg);
                showGlobalError(errMsg);
            }
        } catch (err) {
            const errMsg = err.response?.data?.message || 'Network error occurred';
            setError(errMsg);
            showGlobalError(errMsg);
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={true}
            onClose={onClose}
            title="Add Devices"
            maxWidth="820px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="add-device-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save Devices'}
                    </button>
                </>
            }
        >
            <form id="add-device-form" onSubmit={handleSubmit}>
                <div className="form-group" style={{ maxWidth: '200px' }}>
                    <label className="form-label">Device Count</label>
                    <input 
                        type="number" 
                        min="1" 
                        max="100" 
                        className="form-control"
                        value={deviceCount}
                        onChange={(e) => setDeviceCount(e.target.value)}
                    />
                </div>

                <div className="devices-list">
                    {devices.map((device) => (
                        <div key={device.id} className="device-row">
                            <div className="form-group">
                                <label className="form-label">Purchase Date *</label>
                                <DateInput 
                                    className="form-control"
                                    value={device.purchase_date}
                                    onChange={(value) => handleDeviceChange(device.id, 'purchase_date', value)}
                                    required
                                />
                            </div>
                            <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                <label className="form-label">Notes</label>
                                <textarea className="form-control" rows="3" placeholder="Enter notes..." value={device.notes} onChange={(e) => handleDeviceChange(device.id, 'notes', e.target.value)} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">Device Model *</label>
                                <SearchableDropdown 
                                    options={models}
                                    value={device.device_model_id}
                                    onChange={(val) => handleDeviceChange(device.id, 'device_model_id', val)}
                                    placeholder={modelsLoading ? "Loading..." : "Select Model"}
                                />
                            </div>
                            <div className="form-group">
                                <label className="form-label">IMEI No *</label>
                                <input 
                                    type="text" 
                                    className="form-control"
                                    value={device.imei_no}
                                    onChange={(e) => {
                                        const val = e.target.value.replace(/\D/g, '');
                                        if (val.length <= 15) {
                                            handleDeviceChange(device.id, 'imei_no', val);
                                        }
                                    }}
                                    placeholder="15-digit IMEI"
                                    maxLength={15}
                                    required
                                />
                            </div>
                            {devices.length > 1 && (
                                <button 
                                    type="button" 
                                    className="icon-btn delete" 
                                    style={{ marginBottom: '1.25rem' }}
                                    onClick={() => removeRow(device.id)}
                                >
                                    <Trash2 size={18} />
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            </form>
        </Modal>
    );
};

export default AddDeviceModal;
