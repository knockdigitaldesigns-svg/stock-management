import { createContext, useContext, useEffect, useMemo, useState } from 'react';

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

    const logout = () => {
        setUser(null);
        setPermissions([]);
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        localStorage.removeItem('permissions');
    };

    const hasPermission = (permissionKey) => {
        if (!permissionKey) return true;
        if (!permissions || !Array.isArray(permissions)) return false;
        return permissions.includes(permissionKey);
    };

    const isAuthenticated = Boolean(user && localStorage.getItem('token'));

    const value = useMemo(() => ({
        user,
        permissions,
        isAuthenticated,
        login,
        logout,
        hasPermission,
        role: user?.role ?? null,
        currentUser: user
    }), [user, permissions, isAuthenticated]);

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
