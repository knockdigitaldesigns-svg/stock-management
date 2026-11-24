import { useState } from 'react';
import { Copy } from 'lucide-react';

const PersonnelCredentialNotice = ({ credentials, onDone }) => {
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState('');

    const copyPassword = async () => {
        setCopyError('');
        try {
            await navigator.clipboard.writeText(credentials.password);
            setCopied(true);
        } catch {
            setCopyError('Unable to copy the password. Please copy it manually.');
        }
    };

    return (
        <>
            <p>Save these credentials securely. The password will not be shown again after closing this notice.</p>
            <div className="form-group">
                <label className="form-label">Username</label>
                <input className="form-control" readOnly value={credentials.username} />
            </div>
            <div className="form-group">
                <label className="form-label">Role</label>
                <input className="form-control" readOnly value={credentials.role} />
            </div>
            <div className="form-group">
                <label className="form-label">Password</label>
                <input className="form-control" readOnly value={credentials.password} />
            </div>
            {copyError && <div className="text-danger" role="alert">{copyError}</div>}
            <div className="modal-footer" style={{ padding: 0, borderTop: 0 }}>
                <button type="button" className="btn btn-outline" onClick={copyPassword}>
                    <Copy size={16} /> {copied ? 'Copied' : 'Copy Password'}
                </button>
                <button type="button" className="btn btn-primary" onClick={onDone}>Done</button>
            </div>
        </>
    );
};

export default PersonnelCredentialNotice;
