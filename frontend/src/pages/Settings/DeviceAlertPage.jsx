import { useEffect, useState } from 'react';
import api from '../../services/api';

const DeviceAlertPage = () => {
    const [settings, setSettings] = useState({ alert_type: 'Low Stock', enabled: 1, threshold: 10, notification_status: 'enabled' });

    const fetchAlerts = async () => {
        try {
            const response = await api.get('/device_alert/list.php');
            if (response.data.success && response.data.data?.settings) {
                setSettings(response.data.data.settings);
            }
        } catch (error) {
            console.error('Failed to fetch device alert settings', error);
        }
    };

    useEffect(() => {
        fetchAlerts();
    }, []);

    const saveSettings = async () => {
        try {
            const response = await api.post('/device_alert/update.php', settings);
            if (response.data.success) {
                alert('Device alert settings saved');
            } else {
                alert(response.data.message || 'Failed to save settings');
            }
        } catch (error) {
            alert(error.response?.data?.message || 'Failed to save settings');
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Device Alert</h2>
            </div>
            <div className="card" style={{ maxWidth: '640px' }}>
                <div className="form-group">
                    <label className="form-label">Alert Type</label>
                    <input className="form-control" value={settings.alert_type || ''} onChange={(e) => setSettings({ ...settings, alert_type: e.target.value })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Enable / Disable</label>
                    <select className="form-control" value={settings.enabled} onChange={(e) => setSettings({ ...settings, enabled: Number(e.target.value) })}>
                        <option value={1}>Enabled</option>
                        <option value={0}>Disabled</option>
                    </select>
                </div>
                <div className="form-group">
                    <label className="form-label">Threshold</label>
                    <input type="number" className="form-control" value={settings.threshold || 0} onChange={(e) => setSettings({ ...settings, threshold: Number(e.target.value) })} />
                </div>
                <div className="form-group">
                    <label className="form-label">Notification Status</label>
                    <select className="form-control" value={settings.notification_status || 'enabled'} onChange={(e) => setSettings({ ...settings, notification_status: e.target.value })}>
                        <option value="enabled">Enabled</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <button className="btn btn-primary" type="button" onClick={saveSettings}>Save Alert Settings</button>
            </div>
        </div>
    );
};

export default DeviceAlertPage;
