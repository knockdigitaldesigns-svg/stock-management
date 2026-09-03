import { useEffect } from 'react';
import './Modal.css';
import useModalScrollLock from '../../hooks/useModalScrollLock';

const Modal = ({ isOpen, onClose, title, children, footer, maxWidth = '800px', className = '' }) => {
    useModalScrollLock(isOpen);

    useEffect(() => {
        if (!isOpen) return undefined;

        const handleKeydown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        document.addEventListener('keydown', handleKeydown);

        return () => {
            document.removeEventListener('keydown', handleKeydown);
        };
    }, [isOpen, onClose]);

    if (!isOpen) return null;

    return (
        <div className="modal-overlay" onClick={onClose}>
            <div
                className={`modal-shell ${className}`.trim()}
                style={{ maxWidth }}
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={title}
            >
                <div className="modal-header">
                    <h3>{title}</h3>
                    <button type="button" className="close-btn" onClick={onClose} aria-label="Close modal">
                        &times;
                    </button>
                </div>

                <div className="modal-body">{children}</div>

                {footer && <div className="modal-footer">{footer}</div>}
            </div>
        </div>
    );
};

export default Modal;
