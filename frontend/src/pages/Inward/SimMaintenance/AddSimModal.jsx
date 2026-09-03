import { useState, useEffect } from 'react';
import { Trash2 } from 'lucide-react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import DateInput from '../../../components/DateInput';
import SearchableDropdown from '../../../components/SearchableDropdown/SearchableDropdown';

const AddSimModal = ({ onClose, onSuccess }) => {
    const [simCount, setSimCount] = useState(1);
    const [sims, setSims] = useState([{ id: Date.now(), purchase_date: '', sim_no: '', sim_type: '', sim_validity_id: '', notes: '' }]);
    const [validities, setValidities] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/sim_validities/list.php').then((response) => setValidities(response.data.data?.validities || [])).catch(() => setError('Unable to load SIM validities.'));
    }, []);

    useEffect(() => {
        const count = parseInt(simCount) || 1;
        if (count > sims.length) {
            const newSims = [...sims];
            for (let i = sims.length; i < count; i++) {
                const firstRow = sims[0] || {};
                newSims.push({
                    id: Date.now() + i,
                    purchase_date: firstRow.purchase_date || '',
                    sim_no: ''
                    , sim_type: '', sim_validity_id: ''
                    , notes: ''
                });
            }
            setSims(newSims);
        } else if (count < sims.length && count > 0) {
            setSims(sims.slice(0, count));
        }
    }, [simCount]);

    const handleSimChange = (id, field, value) => {
        setSims(sims.map(s => s.id === id ? { ...s, [field]: value } : s));
    };

    const removeRow = (id) => {
        if (sims.length > 1) {
            setSims(sims.filter(s => s.id !== id));
            setSimCount(prev => prev - 1);
        }
    };

    const validateForm = () => {
        const simRegex = /^(?:[0-9]{10}|[0-9]{13})$/;
        const seenSims = new Set();

        for (let i = 0; i < sims.length; i++) {
            const row = sims[i];
            const rowNum = i + 1;
            
            if (!row.purchase_date) return `Row ${rowNum}: Purchase date is required`;
            if (!['Voice', 'Non Voice'].includes(row.sim_type)) return `Row ${rowNum}: SIM type is required`;
            if (!row.sim_validity_id) return `Row ${rowNum}: SIM validity is required`;
            
            if (!row.sim_no) return `Row ${rowNum}: SIM number is required`;
            if (!simRegex.test(row.sim_no)) return `Row ${rowNum}: SIM number must contain exactly 10 OR exactly 13 digits`;
            
            if (seenSims.has(row.sim_no)) return `Row ${rowNum}: Duplicate SIM number (${row.sim_no}) found in the form`;
            seenSims.add(row.sim_no);
        }
        return null;
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        const validationError = validateForm();
        if (validationError) {
            setError(validationError);
            return;
        }

        setLoading(true);
        try {
            const payload = {
                sims: sims.map(s => ({
                    purchase_date: s.purchase_date,
                    sim_no: s.sim_no
                    , sim_type: s.sim_type, sim_validity_id: Number(s.sim_validity_id)
                    , notes: s.notes
                }))
            };
            
            const response = await api.post('/sims/create.php', payload);
            if (response.data.success) {
                onSuccess();
            } else {
                setError(response.data.message || 'Failed to save SIMs');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Network error occurred');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            isOpen={true}
            onClose={onClose}
            title="Add SIMs"
            maxWidth="760px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button>
                    <button type="submit" form="add-sim-form" className="btn btn-primary" disabled={loading}>
                        {loading ? 'Saving...' : 'Save SIMs'}
                    </button>
                </>
            }
        >
            <form id="add-sim-form" onSubmit={handleSubmit}>
                {error && <div className="alert alert-danger">{error}</div>}

                <div className="form-group" style={{ maxWidth: '200px' }}>
                    <label className="form-label">SIM Count</label>
                    <input 
                        type="number" 
                        min="1" 
                        max="100" 
                        className="form-control"
                        value={simCount}
                        onChange={(e) => setSimCount(e.target.value)}
                    />
                </div>

                <div className="devices-list">
                    {sims.map((sim) => (
                        <div key={sim.id} className="device-row" style={{ gridTemplateColumns: 'repeat(2, 1fr) auto' }}>
                            <div className="form-group">
                                <label className="form-label">Purchase Date *</label>
                                <DateInput 
                                    className="form-control"
                                    value={sim.purchase_date}
                                    onChange={(value) => handleSimChange(sim.id, 'purchase_date', value)}
                                    required
                                />
                            </div>
                            <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                <label className="form-label">Notes</label>
                                <textarea className="form-control" rows="3" placeholder="Enter notes..." value={sim.notes} onChange={(e) => handleSimChange(sim.id, 'notes', e.target.value)} />
                            </div>
                            <div className="form-group">
                                <label className="form-label">SIM Type *</label>
                                <select className="form-control" value={sim.sim_type} onChange={(e) => handleSimChange(sim.id, 'sim_type', e.target.value)} required>
                                    <option value="">Select SIM Type</option><option value="Voice">Voice</option><option value="Non Voice">Non Voice</option>
                                </select>
                            </div>
                            <div className="form-group">
                                <label className="form-label">SIM Validity *</label>
                                <SearchableDropdown
                                    options={validities.map((v) => ({ value: String(v.id), label: `${v.months} Months` }))}
                                    value={String(sim.sim_validity_id)}
                                    onChange={(val) => handleSimChange(sim.id, 'sim_validity_id', val)}
                                    placeholder="Select Validity"
                                />
                            </div>
                            <div className="form-group">
                                <label className="form-label">SIM No *</label>
                                <input 
                                    type="text" 
                                    className="form-control"
                                    value={sim.sim_no}
                                    onChange={(e) => {
                                        const val = e.target.value.replace(/\D/g, '');
                                        if (val.length <= 13) {
                                            handleSimChange(sim.id, 'sim_no', val);
                                        }
                                    }}
                                    placeholder="10 or 13-digit SIM"
                                    maxLength={13}
                                    required
                                />
                            </div>
                            {sims.length > 1 && (
                                <button 
                                    type="button" 
                                    className="icon-btn delete" 
                                    style={{ marginBottom: '1.25rem' }}
                                    onClick={() => removeRow(sim.id)}
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

export default AddSimModal;
