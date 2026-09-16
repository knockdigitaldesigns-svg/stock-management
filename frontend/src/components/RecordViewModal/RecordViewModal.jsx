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
            {details.cash_collections?.length > 0 && <>
                <h4>Customer Cash Collections</h4>
                <div className="table-container"><table><thead><tr><th>Customer</th><th>Mobile</th><th>Installation Date</th><th>Software</th><th>Validity</th><th>Overall Amount Collected</th><th>Amount Remitted</th><th>Pending Amount</th><th>Status</th></tr></thead><tbody>{details.cash_collections.map((collection) => <tr key={collection.id}><td>{collection.username || '-'}</td><td>{collection.primary_mobile_no || '-'}</td><td>{collection.installation_date || '-'}</td><td>{collection.platform_name || '-'}</td><td>{collection.validity_months ? `${collection.validity_months} Months` : '-'}</td><td>₹{Number(collection.amount_collected || 0).toFixed(2)}</td><td>₹{Number(collection.amount_remitted || 0).toFixed(2)}</td><td>₹{Number(collection.pending_amount || 0).toFixed(2)}</td><td>{collection.settlement_status || '-'}</td></tr>)}</tbody></table></div>
            </>}
            {renderDetails?.(details)}
        </>}
    </Modal>;
};

export default RecordViewModal;
