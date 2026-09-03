import { useEffect, useState } from 'react';
import Modal from '../Modal/Modal';

const RecordViewModal = ({ isOpen, onClose, title, record, fetchRecord, fields = [], renderDetails }) => {
    const [details, setDetails] = useState(record);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        let active = true;
        if (!isOpen) return undefined;
        setDetails(record);
        setError('');
        if (!fetchRecord) return undefined;
        setLoading(true);
        fetchRecord(record).then((result) => {
            if (active) setDetails(result);
        }).catch(() => {
            if (active) setError('Unable to load complete record details.');
        }).finally(() => {
            if (active) setLoading(false);
        });
        return () => { active = false; };
    }, [isOpen, record?.id]);

    return <Modal isOpen={isOpen} onClose={onClose} title={title} maxWidth="760px" footer={<button type="button" className="btn btn-outline" onClick={onClose}>Close</button>}>
        {loading && <p>Loading details...</p>}
        {error && <div className="alert alert-danger">{error}</div>}
        {details && !loading && <>
            <div className="details-grid">{fields.map(({ label, key, format }) => <div key={key}><strong>{label}:</strong> {format ? format(details[key], details) : (details[key] ?? '-')}</div>)}</div>
            {renderDetails?.(details)}
        </>}
    </Modal>;
};

export default RecordViewModal;
