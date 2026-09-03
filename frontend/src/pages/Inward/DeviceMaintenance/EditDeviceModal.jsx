import { useMemo, useState } from 'react';
import api from '../../../services/api';
import useDeviceModels from '../../../hooks/useDeviceModels';
import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';

const EditDeviceModal = ({ device, onClose, onSuccess }) => {
    const { models, loading: modelsLoading } = useDeviceModels();
    const [formData, setFormData] = useState({
        purchase_date: device?.purchase_date || '',
        device_model_id: device?.device_model_id || '',
        imei_no: device?.imei_no || ''
        , notes: device?.notes || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const modelOptions = useMemo(() =>
        models.map((model) => ({ value: model.value, label: model.label })),
        [models]
    );

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.purchase_date) return setError('Purchase date is required.');
        if (!formData.device_model_id) return setError('Device model is required.');
        if (!/^[0-9]{15}$/.test(formData.imei_no)) return setError('IMEI must contain exactly 15 digits.');

        setLoading(true);
        try {
            const response = await api.post('/devices/update.php', {
                id: device.id,
                purchase_date: formData.purchase_date,
                device_model_id: Number(formData.device_model_id),
                imei_no: formData.imei_no
                , notes: formData.notes
            });

            if (response.data.success) {
                onSuccess();
            } else {
                setError(response.data.message || 'Unable to update device.');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to update device. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(device)}
            onClose={onClose}
            title="Edit Device"
            maxWidth="640px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="edit-device-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            }
        >
            <form id="edit-device-form" onSubmit={handleSubmit}>
                {error && <div className="alert alert-danger">{error}</div>}

                <div className="form-group">
                    <label className="form-label">Purchase Date *</label>
                    <DateInput
                        className="form-control"
                        value={formData.purchase_date}
                        onChange={(value) => setFormData((prev) => ({ ...prev, purchase_date: value }))}
                        required
                    />
                </div>
                <div className="form-group">
                    <label className="form-label">Notes</label>
                    <textarea className="form-control" rows="3" placeholder="Enter notes..." value={formData.notes} onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))} />
                </div>

                <div className="form-group">
                    <label className="form-label">Device Model *</label>
                    <SearchableDropdown
                        options={modelOptions}
                        value={formData.device_model_id}
                        onChange={(value) => setFormData((prev) => ({ ...prev, device_model_id: value }))}
                        placeholder={modelsLoading ? 'Loading models...' : 'Select device model'}
                    />
                </div>

                <div className="form-group">
                    <label className="form-label">IMEI No *</label>
                    <input
                        type="text"
                        className="form-control"
                        value={formData.imei_no}
                        onChange={(event) => {
                            const value = event.target.value.replace(/\D/g, '').slice(0, 15);
                            setFormData((prev) => ({ ...prev, imei_no: value }));
                        }}
                        placeholder="15-digit IMEI"
                        maxLength={15}
                        required
                    />
                </div>
            </form>
        </Modal>
    );
};

export default EditDeviceModal;
