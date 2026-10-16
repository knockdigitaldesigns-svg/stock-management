import { Navigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AccessDenied from '../../pages/AccessDenied/AccessDenied';

const ProtectedRoute = ({ permission, role, children }) => {
    const { isAuthenticated, hasPermission, user } = useAuth();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace />;
    }

    const allowed = Array.isArray(permission)
        ? permission.some((key) => hasPermission(key))
        : hasPermission(permission);
    const currentRole = String(user?.role?.name || user?.role || '').trim().toLowerCase();
    const allowedRoles = role
        ? (Array.isArray(role) ? role : [role]).map((value) => String(value).trim().toLowerCase())
        : null;
    const roleAllowed = !allowedRoles || allowedRoles.includes(currentRole);

    if ((permission && !allowed) || !roleAllowed) {
        return <AccessDenied />;
    }

    return children;
};

export default ProtectedRoute;
