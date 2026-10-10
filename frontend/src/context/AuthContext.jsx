import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(() => {
        const saved = localStorage.getItem('user');
        return saved ? JSON.parse(saved) : null;
    });
    const [permissions, setPermissions] = useState(() => {
        const saved = localStorage.getItem('permissions');
        return saved ? JSON.parse(saved) : [];
    });

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
        } catch {}
        setUser(null);
        setPermissions([]);
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        localStorage.removeItem('permissions');
    };

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
