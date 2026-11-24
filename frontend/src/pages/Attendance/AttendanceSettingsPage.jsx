import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
import api from '../../services/api';
import './AttendancePage.css';

const initialSettings = {
    office_start_time: '',
    office_end_time: '',
    full_day_hours: '',
    half_day_hours: '',
    inactivity_minutes: '',
    timezone: ''
};

const AttendanceSettingsPage = () => {
    const [settings, setSettings] = useState(initialSettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    useEffect(() => {
        let active = true;
        api.get('/attendance/settings.php')
            .then((response) => {
                if (active) setSettings({ ...initialSettings, ...(response.data?.data?.settings || {}) });
            })
            .catch((requestError) => {
                if (active) setError(requestError.response?.data?.message || 'Unable to load attendance settings.');
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => { active = false; };
    }, []);

    const update = (key, value) => setSettings((current) => ({ ...current, [key]: value }));
    const save = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError('');
        setSuccess('');
        try {
            const response = await api.post('/attendance/settings.php', {
                ...settings,
                full_day_hours: Number(settings.full_day_hours),
                half_day_hours: Number(settings.half_day_hours),
                inactivity_minutes: Number(settings.inactivity_minutes)
            });
            setSettings({ ...initialSettings, ...(response.data?.data?.settings || {}) });
            setSuccess('Attendance settings saved. Existing attendance keeps its original settings snapshot.');
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'Unable to save attendance settings.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="page-container attendance-page attendance-settings-page">
            <div className="page-header"><div><h2>Attendance Settings</h2><p className="page-subtitle">Configure office hours, attendance thresholds, and session expiry.</p></div></div>
            {error && <div className="alert alert-danger">{error}</div>}
            {success && <div className="alert alert-success">{success}</div>}
            <div className="card attendance-settings-card">
                {loading ? <p>Loading attendance settings...</p> : <form onSubmit={save}>
                    <div className="attendance-settings-grid">
                        <div className="attendance-filter-field"><label htmlFor="office-start">Office Start Time</label><input id="office-start" className="form-control" type="time" required value={settings.office_start_time} onChange={(event) => update('office_start_time', event.target.value)} /></div>
                        <div className="attendance-filter-field"><label htmlFor="office-end">Office End Time</label><input id="office-end" className="form-control" type="time" required value={settings.office_end_time} onChange={(event) => update('office_end_time', event.target.value)} /></div>
                        <div className="attendance-filter-field"><label htmlFor="full-day-hours">Full Day Threshold (hours)</label><input id="full-day-hours" className="form-control" type="number" min="0.25" max="24" step="0.25" required value={settings.full_day_hours} onChange={(event) => update('full_day_hours', event.target.value)} /></div>
                        <div className="attendance-filter-field"><label htmlFor="half-day-hours">Half Day Threshold (hours)</label><input id="half-day-hours" className="form-control" type="number" min="0.25" max="24" step="0.25" required value={settings.half_day_hours} onChange={(event) => update('half_day_hours', event.target.value)} /></div>
                        <div className="attendance-filter-field"><label htmlFor="inactivity-minutes">Session Inactivity (minutes)</label><input id="inactivity-minutes" className="form-control" type="number" min="1" max="1440" step="1" required value={settings.inactivity_minutes} onChange={(event) => update('inactivity_minutes', event.target.value)} /></div>
                        <div className="attendance-filter-field"><label htmlFor="attendance-timezone">Timezone</label><input id="attendance-timezone" className="form-control" type="text" required value={settings.timezone} onChange={(event) => update('timezone', event.target.value)} placeholder="Asia/Kolkata" /></div>
                    </div>
                    <p className="attendance-settings-note">Attendance is tracked for Employee and TL accounts only. Workdays are Monday through Saturday; Sunday is excluded. No break time is deducted.</p>
                    <div className="attendance-settings-actions"><button className="btn btn-primary" type="submit" disabled={saving}><Save size={16} /> {saving ? 'Saving...' : 'Save Settings'}</button></div>
                </form>}
            </div>
        </div>
    );
};

export default AttendanceSettingsPage;
