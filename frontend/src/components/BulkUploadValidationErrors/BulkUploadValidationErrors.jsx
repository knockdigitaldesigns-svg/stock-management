import { AlertCircle } from 'lucide-react';
import { normalizeBulkUploadErrors } from '../../utils/bulkUploadErrors';

const BulkUploadValidationErrors = ({ errors, title = 'Upload failed' }) => {
    const messages = normalizeBulkUploadErrors(errors, '');
    if (!messages.length || (messages.length === 1 && !messages[0])) return null;

    return (
        <section
            className="alert alert-danger"
            role="alert"
            aria-live="assertive"
            style={{ marginTop: '1rem' }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700 }}>
                <AlertCircle size={18} />
                <span>{title} — {messages.length} validation {messages.length === 1 ? 'error' : 'errors'} found.</span>
            </div>
            <ol
                style={{
                    margin: '0.75rem 0 0',
                    paddingLeft: '1.5rem',
                    maxHeight: '280px',
                    overflowY: 'auto'
                }}
            >
                {messages.map((message, index) => (
                    <li key={`${index}-${message}`} style={{ marginBottom: '0.35rem', overflowWrap: 'anywhere' }}>
                        {message}
                    </li>
                ))}
            </ol>
        </section>
    );
};

export default BulkUploadValidationErrors;
