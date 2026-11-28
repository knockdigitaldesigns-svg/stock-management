import { useState, useEffect } from 'react';
import { fetchAllPaginatedRecords } from '../utils/fetchAllPaginatedRecords';

const useDeviceModels = () => {
    const [models, setModels] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchModels = async () => {
            try {
                const deviceTypes = await fetchAllPaginatedRecords('/device_types/list.php', 'device_types');
                setModels(deviceTypes.map((type) => ({ value: type.id, label: type.device_type })));
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
