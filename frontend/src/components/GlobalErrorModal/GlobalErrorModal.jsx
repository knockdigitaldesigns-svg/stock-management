import { AlertCircle, X } from 'lucide-react';
import './GlobalErrorModal.css';

const GlobalErrorModal = ({ error, onClose }) => {
    if (!error) return null;

    const { message, title = 'Error' } = error;

    let messageList = [];
    if (Array.isArray(message)) {
        messageList = message.filter(Boolean);
    } else if (typeof message === 'string') {
        messageList = message.split('\n').map((s) => s.trim()).filter(Boolean);
    } else if (message) {
        messageList = [String(message)];
    }

    return (
        <div className="global-error-overlay" onClick={(e) => e.stopPropagation()}>
            <div className="global-error-modal" onClick={(e) => e.stopPropagation()}>
                <div className="global-error-header">
                    <div className="global-error-title">
                        <AlertCircle size={24} className="error-icon" />
                        <h3>{title}</h3>
                    </div>
                    <button
                        type="button"
                        className="global-error-close-btn"
                        onClick={onClose}
                        aria-label="Close error dialog"
                    >
                        <X size={18} />
                    </button>
                </div>

                <div className="global-error-body">
                    {messageList.length <= 1 ? (
                        <p className="global-error-text">{messageList[0] || 'An unexpected error occurred.'}</p>
                    ) : (
                        <ul className="global-error-list">
                            {messageList.map((item, idx) => (
                                <li key={idx}>{item}</li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="global-error-footer">
                    <button
                        type="button"
                        className="btn btn-danger global-error-ok-btn"
                        onClick={onClose}
                        autoFocus
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    );
};

export default GlobalErrorModal;
