import axios from 'axios';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
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
        }

        if (isForbidden && !isAlreadyOnLoginPage) {
            const current = window.location.pathname;
            if (!current.includes('/settings/') && !current.includes('/stock') && !current.includes('/outward') && !current.includes('/inward') && !current.includes('/dashboard')) {
                window.location.href = '/dashboard';
            }
        }

        return Promise.reject(error);
    }
);

export default api;
