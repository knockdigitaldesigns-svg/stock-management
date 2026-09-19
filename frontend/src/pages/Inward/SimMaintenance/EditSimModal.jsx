import { useState } from 'react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
// import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';
import { showGlobalError } from '../../../context/ErrorContext';

const EditSimModal = ({ sim, onClose, onSuccess }) => {
    const [formData, setFormData] = useState({
        purchase_date: sim?.purchase_date || '',
        sim_no: sim?.sim_no || '',
        sim_type: sim?.sim_type || '',
        // sim_validity_id: sim?.sim_validity_id || '',
        notes: sim?.notes || ''
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    // const [validities, setValidities] = useState([]);

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    // useEffect(() => {
    //     api.get('/sim_validities/list.php')
    //         .then((response) => setValidities(response.data.data?.validities || []))
    //         .catch(() => triggerError('Unable to load SIM validities.'));
    // }, []);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError('');

        if (!formData.purchase_date) return triggerError('Purchase date is required.');
        if (!/^(?:[0-9]{10}|[0-9]{13})$/.test(formData.sim_no)) return triggerError('SIM must be exactly 10 or 13 digits.');
        if (!['Voice', 'Non Voice'].includes(formData.sim_type)) return triggerError('SIM type is required.');
        // if (!formData.sim_validity_id) return triggerError('SIM validity is required.');

        setLoading(true);
        try {
            const response = await api.post('/sims/update.php', {
                id: sim.id,
                purchase_date: formData.purchase_date,
                sim_no: formData.sim_no,
                sim_type: formData.sim_type,
                // sim_validity_id: Number(formData.sim_validity_id),
                notes: formData.notes
            });

            if (response.data.success) {
                onSuccess();
            } else {
                triggerError(response.data.message || 'Unable to update SIM.');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Unable to update SIM. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(sim)}
            onClose={onClose}
            title="Edit SIM"
            maxWidth="560px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="edit-sim-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                    </button>
                </>
            }
        >
            <form id="edit-sim-form" onSubmit={handleSubmit}>
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
                    <label className="form-label">SIM Type *</label>
                    <select className="form-control" value={formData.sim_type} onChange={(event) => setFormData((prev) => ({ ...prev, sim_type: event.target.value }))}>
                        <option value="">Select SIM Type</option>
                        <option value="Voice">Voice</option>
                        <option value="Non Voice">Non Voice</option>
                    </select>
                </div>
                {/* <div className="form-group">
                    <label className="form-label">SIM Validity *</label>
                    <SearchableDropdown
                        options={validities.map((v) => ({ value: String(v.id), label: `${v.months} Months` }))}
                        value={String(formData.sim_validity_id)}
                        onChange={(val) => setFormData((prev) => ({ ...prev, sim_validity_id: val }))}
                        placeholder="Select Validity"
                    />
                </div> */}

                <div className="form-group">
                    <label className="form-label">SIM No *</label>
                    <input
                        type="text"
                        className="form-control"
                        value={formData.sim_no}
                        onChange={(event) => {
                            const value = event.target.value.replace(/\D/g, '').slice(0, 13);
                            setFormData((prev) => ({ ...prev, sim_no: value }));
                        }}
                        placeholder="10 or 13-digit SIM"
                        maxLength={13}
                        required
                    />
                </div>
            </form>
        </Modal>
    );
};

export default EditSimModal;
