import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import api from '../services/api';

const AuthContext = createContext(null);
const INACTIVITY_TIMEOUT_MS = 30 * 60 * 1000;
const ACTIVITY_STORAGE_KEY = 'authLastMeaningfulActivity';
const SESSION_END_STORAGE_KEY = 'authSessionEnded';
const INACTIVITY_MESSAGE = 'You have been logged out due to 30 minutes of inactivity. Please log in again.';

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(() => {
        const saved = localStorage.getItem('user');
        return saved ? JSON.parse(saved) : null;
    });
    const [permissions, setPermissions] = useState(() => {
        const saved = localStorage.getItem('permissions');
        return saved ? JSON.parse(saved) : [];
    });
    const lastActivityRef = useRef(0);
    const lastActivityWriteRef = useRef(0);
    const lastActivitySyncRef = useRef(0);

    useEffect(() => {
        if (user) {
            localStorage.setItem('user', JSON.stringify(user));
        } else {
            localStorage.removeItem('user');
        }
    }, [user]);

    useEffect(() => {
        if (permissions.length) {
            localStorage.setItem('permissions', JSON.stringify(permissions));
        } else {
            localStorage.removeItem('permissions');
        }
    }, [permissions]);

    const login = (userData, permissionList = []) => {
        localStorage.removeItem(SESSION_END_STORAGE_KEY);
        localStorage.setItem(ACTIVITY_STORAGE_KEY, String(Date.now()));
        setUser(userData);
        setPermissions(permissionList);
    };

    const updatePermissions = useCallback((permissionList) => {
        setPermissions(Array.isArray(permissionList) ? permissionList : []);
    }, []);

    const refreshPermissions = useCallback(async () => {
        const response = await api.get('/auth/permissions.php');
        const permissionList = response.data?.data?.permissions;
        if (!Array.isArray(permissionList)) {
            throw new Error('Unable to refresh user permissions.');
        }
        setPermissions(permissionList);
        return permissionList;
    }, []);

    const logout = async () => {
        const token = localStorage.getItem('token');
        try {
            if (token) {
                await api.post('/auth/logout.php', {}, {
                    headers: { Authorization: `Bearer ${token}` },
                    skipGlobalError: true
                });
            }
        } catch (error) {
            if (error.response?.status !== 401) {
                console.error('Failed to invalidate the server session during logout.', error);
            }
        }
        setUser(null);
        setPermissions([]);
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        localStorage.removeItem('permissions');
        localStorage.removeItem(ACTIVITY_STORAGE_KEY);
    };

    useEffect(() => {
        if (!user || !localStorage.getItem('token')) return undefined;

        let timeoutId;
        let isEndingSession = false;
        lastActivityRef.current = Number(localStorage.getItem(ACTIVITY_STORAGE_KEY)) || Date.now();
        lastActivityWriteRef.current = 0;
        lastActivitySyncRef.current = 0;

        const endForInactivity = () => {
            if (isEndingSession) return;
            isEndingSession = true;
            sessionStorage.setItem('authLogoutMessage', INACTIVITY_MESSAGE);
            localStorage.setItem(SESSION_END_STORAGE_KEY, JSON.stringify({
                at: Date.now(),
                message: INACTIVITY_MESSAGE
            }));
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('permissions');
            localStorage.removeItem(ACTIVITY_STORAGE_KEY);
            setUser(null);
            setPermissions([]);
            if (window.location.pathname !== '/login') {
                window.location.assign('/login');
            }
        };

        const scheduleExpiry = () => {
            window.clearTimeout(timeoutId);
            const sharedLastActivity = Number(localStorage.getItem(ACTIVITY_STORAGE_KEY));
            if (Number.isFinite(sharedLastActivity) && sharedLastActivity > lastActivityRef.current) {
                lastActivityRef.current = sharedLastActivity;
            }
            const remaining = INACTIVITY_TIMEOUT_MS - (Date.now() - lastActivityRef.current);
            if (remaining <= 0) {
                endForInactivity();
                return;
            }
            timeoutId = window.setTimeout(scheduleExpiry, remaining);
        };

        const recordMeaningfulActivity = () => {
            if (isEndingSession) return;
            const now = Date.now();
            lastActivityRef.current = now;
            if (now - lastActivityWriteRef.current >= 1000) {
                localStorage.setItem(ACTIVITY_STORAGE_KEY, String(now));
                lastActivityWriteRef.current = now;
            }
            scheduleExpiry();
            if (now - lastActivitySyncRef.current >= 60_000) {
                lastActivitySyncRef.current = now;
                api.post('/auth/activity.php', {}, { skipGlobalError: true }).catch((error) => {
                    if (error.response?.status !== 401) {
                        console.error('Unable to record authenticated user activity.', error);
                    }
                });
            }
        };

        const onStorage = (event) => {
            if (event.key === ACTIVITY_STORAGE_KEY && event.newValue) {
                const activityAt = Number(event.newValue);
                if (Number.isFinite(activityAt) && activityAt > lastActivityRef.current) {
                    lastActivityRef.current = activityAt;
                    scheduleExpiry();
                }
            }
            if (event.key === SESSION_END_STORAGE_KEY && event.newValue) {
                let endEvent = {};
                try {
                    endEvent = JSON.parse(event.newValue);
                } catch (error) {
                    console.error('Unable to read cross-tab session end event.', error);
                }
                if (endEvent.message) {
                    sessionStorage.setItem('authLogoutMessage', endEvent.message);
                }
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                localStorage.removeItem('permissions');
                localStorage.removeItem(ACTIVITY_STORAGE_KEY);
                setUser(null);
                setPermissions([]);
                if (window.location.pathname !== '/login') {
                    window.location.assign('/login');
                }
            }
            if (event.key === 'token' && !event.newValue && window.location.pathname !== '/login') {
                setUser(null);
                setPermissions([]);
                window.location.assign('/login');
            }
        };

        const activityEvents = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
        activityEvents.forEach((eventName) => {
            window.addEventListener(eventName, recordMeaningfulActivity, { passive: true });
        });
        window.addEventListener('storage', onStorage);
        scheduleExpiry();

        return () => {
            window.clearTimeout(timeoutId);
            activityEvents.forEach((eventName) => {
                window.removeEventListener(eventName, recordMeaningfulActivity);
            });
            window.removeEventListener('storage', onStorage);
        };
    }, [user]);

    const hasPermission = useCallback((permissionKey) => {
        if (!permissionKey) return true;
        const requested = String(permissionKey).trim();
        const allowed = Array.isArray(permissions) ? permissions.map((item) => String(item).trim()) : [];

        if (!requested || !allowed.length) {
            return false;
        }

        if (allowed.includes(requested)) {
            return true;
        }

        const [module, action] = requested.split('.');
        if (!module || !action) {
            return false;
        }

        const singularModule = module.endsWith('s') ? module.slice(0, -1) : module;
        const pluralModule = module.endsWith('s') ? module : `${module}s`;

        return allowed.includes(`${singularModule}.${action}`) || allowed.includes(`${pluralModule}.${action}`);
    }, [permissions]);

    const isAuthenticated = Boolean(user && localStorage.getItem('token'));

    const value = useMemo(() => ({
        user,
        permissions,
        isAuthenticated,
        login,
        logout,
        updatePermissions,
        refreshPermissions,
        hasPermission,
        role: user?.role ?? null,
        currentUser: user
    }), [user, permissions, isAuthenticated, updatePermissions, refreshPermissions, hasPermission]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

export const useAuth = () => {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used inside an AuthProvider');
    }
    return context;
};

export default AuthContext;
