import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import api from '../services/api';

const AuthContext = createContext(null);
const INACTIVITY_TIMEOUT_MS = 15 * 60 * 1000;
const ACTIVITY_STORAGE_THROTTLE_MS = 250;
const EXPIRY_RECHECK_GRACE_MS = 250;
const ACTIVITY_STORAGE_KEY = 'authLastMeaningfulActivity';
const SESSION_END_STORAGE_KEY = 'authSessionEnded';
const INACTIVITY_MESSAGE = 'You have been logged out due to 15 minutes of inactivity. Please log in again.';

const getSessionIdentity = (token) => {
    if (!token) return null;
    try {
        const encodedPayload = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
        const binaryPayload = atob(encodedPayload);
        const bytes = Uint8Array.from(binaryPayload, (character) => character.charCodeAt(0));
        const payload = JSON.parse(new TextDecoder().decode(bytes));
        return { userId: payload.user_id, sessionId: payload.sid };
    } catch {
        return null;
    }
};

const getLatestSharedActivity = (fallback = 0) => {
    const sharedActivity = Number(localStorage.getItem(ACTIVITY_STORAGE_KEY));
    return Number.isFinite(sharedActivity) ? Math.max(fallback, sharedActivity) : fallback;
};

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
                    skipGlobalError: true,
                    skipAuthLogout: true
                });
            }
        } catch (error) {
            if (error.response?.status !== 401) {
                console.error('Failed to invalidate the server session during logout.', error);
            }
        }
        if (token && localStorage.getItem('token') !== token) return;
        localStorage.setItem(SESSION_END_STORAGE_KEY, JSON.stringify({
            at: Date.now(),
            reason: 'logout',
            ...(getSessionIdentity(token) || {})
        }));
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
        const storedActivity = Number(localStorage.getItem(ACTIVITY_STORAGE_KEY));
        lastActivityRef.current = Number.isFinite(storedActivity) && storedActivity > 0
            ? storedActivity
            : Date.now();
        lastActivityWriteRef.current = 0;
        lastActivitySyncRef.current = 0;

        const endForInactivity = async () => {
            if (isEndingSession) return;
            lastActivityRef.current = getLatestSharedActivity(lastActivityRef.current);
            if (Date.now() - lastActivityRef.current < INACTIVITY_TIMEOUT_MS) {
                scheduleExpiry();
                return;
            }

            isEndingSession = true;
            const token = localStorage.getItem('token');
            let endStatus = 'ended';
            let tokenInvalid = false;
            if (token) {
                try {
                    const response = await api.post('/auth/logout.php', { reason: 'inactivity' }, {
                        skipGlobalError: true,
                        skipAuthLogout: true
                    });
                    const result = response.data?.data;
                    endStatus = result?.status || 'ended';
                    if (endStatus === 'active') {
                        const serverActivity = Number(result.last_activity_at) * 1000;
                        if (Number.isFinite(serverActivity) && serverActivity > lastActivityRef.current) {
                            lastActivityRef.current = serverActivity;
                            localStorage.setItem(ACTIVITY_STORAGE_KEY, String(serverActivity));
                        }
                    }
                } catch (error) {
                    tokenInvalid = error.response?.status === 401;
                    if (!tokenInvalid) {
                        console.error('Unable to confirm inactivity with the server.', error);
                    }
                }
            }

            const activityAfterRequest = getLatestSharedActivity(lastActivityRef.current);
            if (!tokenInvalid && (Date.now() - activityAfterRequest < INACTIVITY_TIMEOUT_MS || endStatus === 'active')) {
                lastActivityRef.current = activityAfterRequest;
                isEndingSession = false;
                scheduleExpiry();
                return;
            }

            if (token && localStorage.getItem('token') !== token) {
                isEndingSession = false;
                return;
            }
            const identity = getSessionIdentity(token);
            const message = endStatus === 'replaced'
                ? 'Your session is no longer valid. Please log in again.'
                : INACTIVITY_MESSAGE;
            sessionStorage.setItem('authLogoutMessage', message);
            localStorage.setItem(SESSION_END_STORAGE_KEY, JSON.stringify({
                at: Date.now(),
                reason: 'inactivity',
                message,
                ...(identity || {})
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
            lastActivityRef.current = getLatestSharedActivity(lastActivityRef.current);
            const remaining = INACTIVITY_TIMEOUT_MS - (Date.now() - lastActivityRef.current);
            if (remaining <= 0) {
                timeoutId = window.setTimeout(endForInactivity, EXPIRY_RECHECK_GRACE_MS);
                return;
            }
            timeoutId = window.setTimeout(scheduleExpiry, remaining);
        };

        const recordMeaningfulActivity = () => {
            const now = Date.now();
            lastActivityRef.current = now;
            if (now - lastActivityWriteRef.current >= ACTIVITY_STORAGE_THROTTLE_MS) {
                localStorage.setItem(ACTIVITY_STORAGE_KEY, String(now));
                lastActivityWriteRef.current = now;
            }
            scheduleExpiry();
            if (now - lastActivitySyncRef.current >= 60_000) {
                lastActivitySyncRef.current = now;
                api.post('/auth/activity.php', {}, { skipGlobalError: true, skipAuthLogout: true }).catch((error) => {
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
                const identity = getSessionIdentity(localStorage.getItem('token'));
                if (endEvent.sessionId && (
                    endEvent.sessionId !== identity?.sessionId
                    || String(endEvent.userId) !== String(identity?.userId)
                )) return;
                if (endEvent.reason === 'inactivity'
                    && Date.now() - getLatestSharedActivity(lastActivityRef.current) < INACTIVITY_TIMEOUT_MS) {
                    return;
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
                let endEvent = {};
                try {
                    endEvent = JSON.parse(localStorage.getItem(SESSION_END_STORAGE_KEY) || '{}');
                } catch (error) {
                    console.error('Unable to read cross-tab session end event.', error);
                }
                const oldIdentity = getSessionIdentity(event.oldValue);
                const matchesEndEvent = endEvent.sessionId === oldIdentity?.sessionId
                    && String(endEvent.userId) === String(oldIdentity?.userId);
                if (matchesEndEvent && endEvent.reason === 'inactivity'
                    && Date.now() - getLatestSharedActivity(lastActivityRef.current) < INACTIVITY_TIMEOUT_MS) {
                    localStorage.setItem('token', event.oldValue);
                    return;
                }
                if (matchesEndEvent) return;
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
        const refreshExpiry = () => scheduleExpiry();
        window.addEventListener('focus', refreshExpiry);
        window.addEventListener('pageshow', refreshExpiry);
        document.addEventListener('visibilitychange', refreshExpiry);
        scheduleExpiry();

        return () => {
            window.clearTimeout(timeoutId);
            activityEvents.forEach((eventName) => {
                window.removeEventListener(eventName, recordMeaningfulActivity);
            });
            window.removeEventListener('storage', onStorage);
            window.removeEventListener('focus', refreshExpiry);
            window.removeEventListener('pageshow', refreshExpiry);
            document.removeEventListener('visibilitychange', refreshExpiry);
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
