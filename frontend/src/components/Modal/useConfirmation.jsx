import { useCallback, useRef, useState } from 'react';
import Modal from './Modal';

export const useConfirmation = () => {
    const [request, setRequest] = useState(null);
    const resolveRequest = useRef(null);

    const settle = useCallback((confirmed) => {
        resolveRequest.current?.(confirmed);
        resolveRequest.current = null;
        setRequest(null);
    }, []);

    const confirm = useCallback((options) => new Promise((resolve) => {
        if (resolveRequest.current) {
            resolveRequest.current(false);
        }

        resolveRequest.current = resolve;
        setRequest({
            title: options.title || 'Confirm action',
            message: options.message,
            confirmLabel: options.confirmLabel || 'Confirm',
            confirmClassName: options.confirmClassName || 'btn-primary'
        });
    }), []);

    const dialog = request ? (
        <Modal
            isOpen
            onClose={() => settle(false)}
            title={request.title}
            maxWidth="460px"
            footer={(
                <>
                    <button type="button" className="btn btn-outline" onClick={() => settle(false)}>
                        Cancel
                    </button>
                    <button type="button" className={`btn ${request.confirmClassName}`} onClick={() => settle(true)}>
                        {request.confirmLabel}
                    </button>
                </>
            )}
        >
            <p style={{ margin: 0, lineHeight: 1.6, color: '#334155' }}>{request.message}</p>
        </Modal>
    ) : null;

    return { confirm, confirmationDialog: dialog };
};
