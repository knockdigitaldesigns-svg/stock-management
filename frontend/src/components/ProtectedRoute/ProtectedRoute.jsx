import { Navigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AccessDenied from '../../pages/AccessDenied/AccessDenied';

const ProtectedRoute = ({ permission, children }) => {
    const { isAuthenticated, hasPermission } = useAuth();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace />;
    }

    const allowed = Array.isArray(permission)
        ? permission.some((key) => hasPermission(key))
        : hasPermission(permission);

    if (permission && !allowed) {
        return <AccessDenied />;
    }

    return children;
};

export default ProtectedRoute;
