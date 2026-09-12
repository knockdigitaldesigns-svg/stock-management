import axios from 'axios';
import { showGlobalError } from '../context/ErrorContext';

const api = axios.create({
    baseURL: 'http://localhost:8000/api',
    headers: {
        'Content-Type': 'application/json'
    }
});

api.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token');
        if (token) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

api.interceptors.response.use(
    (response) => {
        // If HTTP 200 response contains success: false and a message, trigger global error modal unless suppressed
        if (response.data && response.data.success === false && response.data.message && !response.config?.skipGlobalError) {
            showGlobalError(response.data.message);
        }
        return response;
    },
    (error) => {
        const status = error.response?.status;
        const isUnauthorized = status === 401;
        const isForbidden = status === 403;
        const isLoginRequest = error.config?.url?.includes('/auth/login.php');
        const isAlreadyOnLoginPage = window.location.pathname === '/login';

        if (isUnauthorized && !isLoginRequest && !isAlreadyOnLoginPage) {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('permissions');
            window.location.href = '/login';
            return Promise.reject(error);
        }

        if (isForbidden && !isAlreadyOnLoginPage) {
            const current = window.location.pathname;
            if (!current.includes('/settings/') && !current.includes('/stock') && !current.includes('/outward') && !current.includes('/inward') && !current.includes('/dashboard')) {
                window.location.href = '/dashboard';
            }
        }

        // Show global error modal for API error responses if not explicitly suppressed
        const errorMessage = error.response?.data?.message || error.response?.data?.error || error.message;
        if (errorMessage && !error.config?.skipGlobalError && !isUnauthorized && !isForbidden) {
            showGlobalError(errorMessage);
        }

        return Promise.reject(error);
    }
);

export default api;
