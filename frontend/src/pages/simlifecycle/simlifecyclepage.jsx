import { useEffect, useState } from 'react';
import { RefreshCw, Save, RotateCcw, Clock } from 'lucide-react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { showGlobalError } from '../../context/ErrorContext';

const SimLifecyclePage = () => {
    const { hasPermission } = useAuth();
    const canEdit = hasPermission('sim_lifecycle.edit');

    const [form, setForm] = useState({
        expired_to_safe_days: '',
        safe_to_deactive_days: ''
    });
    const [initialForm, setInitialForm] = useState({
        expired_to_safe_days: '',
        safe_to_deactive_days: ''
    });

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const triggerError = (msg) => {
        setError(msg);
        showGlobalError(msg);
    };

    const fetchSettings = async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/sim_lifecycle/get.php');
            if (response.data?.success) {
                const data = response.data.data || {};
                const loaded = {
                    expired_to_safe_days: String(data.expired_to_safe_days ?? 10),
                    safe_to_deactive_days: String(data.safe_to_deactive_days ?? 10)
                };
                setForm(loaded);
                setInitialForm(loaded);
            }
        } catch (err) {
            triggerError(err.response?.data?.message || 'Failed to load SIM lifecycle settings.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSettings();
    }, []);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setForm((prev) => ({ ...prev, [name]: value }));
        setError('');
        setSuccess('');
    };

    const validate = () => {
        const exp = form.expired_to_safe_days.trim();
        const safe = form.safe_to_deactive_days.trim();

        if (exp === '' || safe === '') {
            return 'All fields are required.';
        }

        if (!/^\d+$/.test(exp)) {
            return 'Expired → Safe Custody days must be a non-negative whole number (no decimals or negative values).';
        }

        if (!/^\d+$/.test(safe)) {
            return 'Safe Custody → Deactive days must be a non-negative whole number (no decimals or negative values).';
        }

        return null;
    };

    const handleSave = async (e) => {
        e.preventDefault();
        setError('');
        setSuccess('');

        if (!canEdit) {
            triggerError('You do not have permission to edit SIM lifecycle settings.');
            return;
        }

        const validationError = validate();
        if (validationError) {
            triggerError(validationError);
            return;
        }

        try {
            setSaving(true);
            const payload = {
                expired_to_safe_days: Number(form.expired_to_safe_days),
                safe_to_deactive_days: Number(form.safe_to_deactive_days)
            };

            const response = await api.post('/sim_lifecycle/update.php', payload);
            if (response.data?.success) {
                setSuccess('SIM lifecycle settings saved successfully.');
                const updated = {
                    expired_to_safe_days: String(payload.expired_to_safe_days),
                    safe_to_deactive_days: String(payload.safe_to_deactive_days)
                };
                setForm(updated);
                setInitialForm(updated);
            } else {
                throw new Error(response.data?.message || 'Failed to update SIM lifecycle settings.');
            }
        } catch (err) {
            triggerError(err.response?.data?.message || err.message || 'Failed to update SIM lifecycle settings.');
        } finally {
            setSaving(false);
        }
    };

    const handleReset = () => {
        setForm(initialForm);
        setError('');
        setSuccess('');
    };

    return (
        <div className="page-container">
            {/* Header */}
            <div
                className="page-header"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: '20px',
                    marginBottom: '20px'
                }}
            >
                <div>
                    <h2>SIM Lifecycle</h2>
                    <p className="page-subtitle" style={{ marginTop: '4px' }}>
                        Configure SIM status lifecycle timing
                    </p>
                </div>
                <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={fetchSettings}
                    disabled={loading || saving}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: '7px' }}
                >
                    <RefreshCw size={16} className={loading ? 'spin' : ''} />
                    Refresh
                </button>
            </div>

            {/* Error & Success Notifications */}
            {error && <div className="alert alert-danger" style={{ marginBottom: '16px' }}>{error}</div>}
            {success && <div className="alert alert-success" style={{ marginBottom: '16px' }}>{success}</div>}

            {/* Card Content */}
            <div className="card" style={{ maxWidth: '700px' }}>
                <div className="customer-section-title" style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '20px' }}>
                    <Clock size={20} />
                    <h3>Lifecycle Timing Configuration</h3>
                </div>

                <form onSubmit={handleSave}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
                        <div>
                            <label className="form-label" style={{ fontWeight: 600, display: 'block', marginBottom: '6px' }}>
                                Expired → Safe Custody After (Days) <span style={{ color: '#ef4444' }}>*</span>
                            </label>
                            <input
                                type="number"
                                name="expired_to_safe_days"
                                className="form-control"
                                min="0"
                                step="1"
                                value={form.expired_to_safe_days}
                                onChange={handleChange}
                                disabled={loading || saving || !canEdit}
                                placeholder="e.g. 10"
                                style={{ width: '100%', maxWidth: '300px' }}
                            />
                            <small style={{ color: '#64748b', marginTop: '4px', display: 'block' }}>
                                Number of full days after Next Renewal Date before SIM enters Safe Custody.
                            </small>
                        </div>

                        <div>
                            <label className="form-label" style={{ fontWeight: 600, display: 'block', marginBottom: '6px' }}>
                                Safe Custody → Deactive After (Days) <span style={{ color: '#ef4444' }}>*</span>
                            </label>
                            <input
                                type="number"
                                name="safe_to_deactive_days"
                                className="form-control"
                                min="0"
                                step="1"
                                value={form.safe_to_deactive_days}
                                onChange={handleChange}
                                disabled={loading || saving || !canEdit}
                                placeholder="e.g. 10"
                                style={{ width: '100%', maxWidth: '300px' }}
                            />
                            <small style={{ color: '#64748b', marginTop: '4px', display: 'block' }}>
                                Number of full days after entering Safe Custody before SIM is marked Deactive.
                            </small>
                        </div>
                    </div>

                    <div style={{ marginTop: '28px', display: 'flex', gap: '12px' }}>
                        {canEdit && (
                            <button
                                type="submit"
                                className="btn btn-primary"
                                disabled={loading || saving}
                                style={{ display: 'inline-flex', alignItems: 'center', gap: '7px' }}
                            >
                                <Save size={16} />
                                {saving ? 'Saving...' : 'Save'}
                            </button>
                        )}
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={handleReset}
                            disabled={loading || saving}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: '7px' }}
                        >
                            <RotateCcw size={16} />
                            Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

export default SimLifecyclePage;
