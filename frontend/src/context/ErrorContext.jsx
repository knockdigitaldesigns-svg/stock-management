import { createContext, useContext, useState, useCallback, useEffect } from 'react';
import GlobalErrorModal from '../components/GlobalErrorModal/GlobalErrorModal';

const ErrorContext = createContext(null);

let globalShowErrorFn = null;

export const showGlobalError = (message, title = 'Error') => {
    if (!message) return;
    if (globalShowErrorFn) {
        globalShowErrorFn(message, title);
    } else {
        console.error('[Global Error]', message);
    }
};

export const ErrorProvider = ({ children }) => {
    const [errorData, setErrorData] = useState(null);

    const showError = useCallback((message, title = 'Error') => {
        if (!message) return;
        let formattedMessage = message;
        if (typeof message === 'object' && message !== null) {
            formattedMessage = message.message || message.error || JSON.stringify(message);
        }
        setErrorData({ message: formattedMessage, title });
    }, []);

    const clearError = useCallback(() => {
        setErrorData(null);
    }, []);

    useEffect(() => {
        globalShowErrorFn = showError;
        const originalAlert = window.alert;
        window.alert = (msg) => {
            if (msg) {
                showError(msg, 'Alert');
            }
        };
        return () => {
            globalShowErrorFn = null;
            window.alert = originalAlert;
        };
    }, [showError]);

    return (
        <ErrorContext.Provider value={{ showError, clearError, error: errorData }}>
            {children}
            {errorData && <GlobalErrorModal error={errorData} onClose={clearError} />}
        </ErrorContext.Provider>
    );
};

export const useError = () => {
    const context = useContext(ErrorContext);
    if (!context) {
        return {
            showError: showGlobalError,
            clearError: () => {},
            error: null
        };
    }
    return context;
};
