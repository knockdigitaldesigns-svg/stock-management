import { useState, useEffect } from 'react';
import api from '../services/api';

const useDeviceModels = () => {
    const [models, setModels] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchModels = async () => {
            try {
                const response = await api.get('/device_types/list.php');
                if (!response.data.success) throw new Error(response.data.message || 'Failed to fetch device types');
                setModels((response.data.data?.device_types || []).map((type) => ({ value: type.id, label: type.device_type })));
            } catch (err) {
                setModels([]);
                setError(err.message || 'Failed to fetch device models');
            } finally {
                setLoading(false);
            }
        };

        fetchModels();
    }, []);

    return { models, loading, error };
};

export default useDeviceModels;
